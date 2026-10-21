#!/usr/bin/env python3
"""Python alternative WebSocket proxy for WP GPT Automation Pro MCP server.

This script mirrors the behaviour of ``tools/mcp-ws-server.php`` but is implemented in
Python. It accepts secure WebSocket connections (``wss://``), validates the bearer token
against the WordPress REST endpoint and forwards JSON-RPC requests to
``/wp-json/wp-gpt/v1/mcp``.

The script requires the ``websockets`` and ``requests`` packages. Install them with:

    pip install websockets requests
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import os
import signal
import ssl
import sys
import time
from typing import Any, Dict, Iterable, Optional, Tuple
from urllib.parse import parse_qs, urlparse

try:  # pragma: no cover - dependency guard executed only at runtime
    import requests
except ImportError:  # pragma: no cover - handled at runtime
    from . import _requests_stub as requests  # type: ignore[no-redef]

try:  # pragma: no cover - dependency guard executed only at runtime
    from websockets.exceptions import ConnectionClosed, ConnectionClosedOK
    from websockets.server import WebSocketServerProtocol, serve
except ImportError:  # pragma: no cover - handled at runtime
    from ._websocket_stub import (  # type: ignore[no-redef]
        ConnectionClosed,
        ConnectionClosedOK,
        WebSocketServerProtocol,
        serve,
    )


JSON_RPC_VERSION = "2.0"
DEFAULT_PATH = "/mcp"
DEFAULT_TIMEOUT = 30.0
DEFAULT_MAX_MESSAGE_SIZE = 8 * 1024 * 1024  # 8 MiB, generous enough for MCP payloads


def env_flag(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default

    value = value.strip().lower()
    return value in {"1", "true", "yes", "on"}


def env_int(name: str, default: int) -> int:
    value = os.getenv(name)
    if value is None or value.strip() == "":
        return default

    try:
        return int(value)
    except ValueError as exc:
        raise SystemExit(f"Environment variable {name} must be an integer") from exc


def env_float(name: str, default: float) -> float:
    value = os.getenv(name)
    if value is None or value.strip() == "":
        return default

    try:
        return float(value)
    except ValueError as exc:
        raise SystemExit(f"Environment variable {name} must be a number") from exc


class WordPressRequestError(Exception):
    """Raised when the proxy cannot reach WordPress."""


class UnauthorizedError(Exception):
    """Raised when the WordPress endpoint denies access."""


class MCPServerProtocol(WebSocketServerProtocol):
    """Custom protocol that stores metadata about the client connection."""

    def __init__(self, proxy: "MCPProxyServer", *args: Any, **kwargs: Any) -> None:
        self.proxy = proxy
        self.token: Optional[str] = None
        self.user_agent: Optional[str] = None
        self.client_ip: Optional[str] = None
        self.last_validation: float = 0.0
        super().__init__(*args, **kwargs)

    async def process_request(  # type: ignore[override]
        self,
        path: str,
        request_headers: "headers.Headers",
    ) -> Optional[Tuple[int, Iterable[Tuple[str, str]], bytes]]:
        return await self.proxy.process_request(self, path, request_headers)


class MCPProxyServer:
    """WebSocket-to-HTTP bridge that forwards MCP JSON-RPC calls to WordPress."""

    def __init__(
        self,
        host: str,
        port: int,
        path: str,
        wp_endpoint: str,
        *,
        timeout: float = DEFAULT_TIMEOUT,
        verify: bool | str = True,
        max_message_size: int = DEFAULT_MAX_MESSAGE_SIZE,
        ssl_context: Optional[ssl.SSLContext] = None,
        initial_validation: bool = True,
    ) -> None:
        self.host = host
        self.port = port
        self.path = normalize_path(path)
        self.wp_endpoint = wp_endpoint
        self.timeout = timeout
        self.verify = verify
        self.max_message_size = max_message_size
        self.ssl_context = ssl_context
        self.initial_validation = initial_validation

        self.session = requests.Session()
        self.session.verify = verify
        self.logger = logging.getLogger("wp-gpt-mcp-proxy")

    async def process_request(
        self,
        protocol: MCPServerProtocol,
        raw_path: str,
        request_headers: "headers.Headers",
    ) -> Optional[Tuple[int, Iterable[Tuple[str, str]], bytes]]:
        parsed = urlparse(raw_path)
        normalized_path = normalize_path(parsed.path)
        if normalized_path != self.path:
            self.logger.warning(
                "Rejected connection with invalid path %s from %s",
                parsed.path,
                protocol.remote_address,
            )
            return http_error_response(404, "Not Found")

        token = extract_token(request_headers, parsed.query)
        if not token:
            self.logger.warning(
                "Rejected connection without bearer token from %s", protocol.remote_address
            )
            return http_error_response(401, "Missing bearer token")

        protocol.token = token
        protocol.user_agent = request_headers.get("User-Agent")
        protocol.client_ip = format_remote(protocol.remote_address)

        if not self.initial_validation:
            return None

        try:
            await self._validate_token(protocol)
        except UnauthorizedError as exc:
            return http_error_response(401, str(exc) or "Unauthorized")
        except WordPressRequestError as exc:
            return http_error_response(502, str(exc))

        protocol.last_validation = time.monotonic()
        return None

    async def handler(self, websocket: MCPServerProtocol) -> None:
        client = websocket.client_ip or "unknown"
        self.logger.info("Client connected: %s", client)
        try:
            async for message in websocket:
                if isinstance(message, bytes):
                    self.logger.warning(
                        "Closing connection with %s: binary frames not supported", client
                    )
                    await websocket.close(code=1003, reason="Binary frames not supported")
                    return

                await self._handle_text_message(websocket, message)
        except (ConnectionClosedOK, ConnectionClosed):
            self.logger.info("Connection closed by client: %s", client)
        except Exception as exc:  # pragma: no cover - defensive logging
            self.logger.exception("Unexpected error while handling %s: %s", client, exc)
            await websocket.close(code=1011, reason="Internal server error")
        finally:
            self.logger.info("Client disconnected: %s", client)

    async def _handle_text_message(self, websocket: MCPServerProtocol, payload_text: str) -> None:
        try:
            payload = json.loads(payload_text)
        except json.JSONDecodeError as exc:
            error = build_json_rpc_error(None, False, -32700, f"Parse error: {exc.msg}")
            await websocket.send(json.dumps(error))
            return

        if isinstance(payload, list):
            error = build_json_rpc_error(None, False, -32600, "Batch requests are not supported")
            await websocket.send(json.dumps(error))
            return

        if not isinstance(payload, dict):
            error = build_json_rpc_error(None, False, -32600, "Invalid request payload")
            await websocket.send(json.dumps(error))
            return

        rpc_id = payload.get("id")
        has_id = "id" in payload

        method = payload.get("method")
        if not isinstance(method, str) or method == "":
            error = build_json_rpc_error(rpc_id, has_id, -32600, "Missing or invalid method")
            await websocket.send(json.dumps(error))
            return

        try:
            response = await self._call_wordpress(payload, websocket)
        except UnauthorizedError:
            error = build_json_rpc_error(rpc_id, has_id, -32001, "Unauthorized", {"status": 401})
            await websocket.send(json.dumps(error))
            await websocket.close(code=4003, reason="Unauthorized")
            return
        except WordPressRequestError as exc:
            error = build_json_rpc_error(
                rpc_id,
                has_id,
                -32002,
                "Failed to contact WordPress",
                {"detail": str(exc)},
            )
            await websocket.send(json.dumps(error))
            return

        if response.status_code >= 400:
            message = extract_error_message(response)
            error = build_json_rpc_error(
                rpc_id,
                has_id,
                -32002,
                message,
                {"status": response.status_code},
            )
            await websocket.send(json.dumps(error))
            return

        text = response.text
        if not text:
            error = build_json_rpc_error(rpc_id, has_id, -32002, "Empty response from WordPress")
            await websocket.send(json.dumps(error))
            return

        if not is_valid_json(text):
            snippet = text[:2000]
            error = build_json_rpc_error(
                rpc_id,
                has_id,
                -32002,
                "Invalid JSON payload returned by WordPress",
                {"preview": snippet},
            )
            await websocket.send(json.dumps(error))
            return

        await websocket.send(text)
        websocket.last_validation = time.monotonic()

    async def _validate_token(self, protocol: MCPServerProtocol) -> None:
        payload = {
            "jsonrpc": JSON_RPC_VERSION,
            "id": "__handshake__",
            "method": "ping",
            "params": {},
        }

        response = await self._perform_request(payload, protocol)

        if response.status_code == 401:
            raise UnauthorizedError("Unauthorized")

        if response.status_code >= 400:
            raise WordPressRequestError(extract_error_message(response))

    async def _call_wordpress(self, payload: Dict[str, Any], protocol: MCPServerProtocol) -> requests.Response:
        response = await self._perform_request(payload, protocol)

        if response.status_code == 401:
            raise UnauthorizedError("Unauthorized")

        return response

    async def _perform_request(
        self,
        payload: Dict[str, Any],
        protocol: MCPServerProtocol,
    ) -> requests.Response:
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(
            None,
            self._sync_request,
            payload,
            protocol,
        )

    def _sync_request(self, payload: Dict[str, Any], protocol: MCPServerProtocol) -> requests.Response:
        headers: Dict[str, str] = {
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

        if protocol.token:
            headers["Authorization"] = f"Bearer {protocol.token}"

        if protocol.user_agent:
            headers["User-Agent"] = protocol.user_agent
        else:
            headers["User-Agent"] = "WP-GPT-MCP-Python/1.0"

        if protocol.client_ip:
            headers["X-Forwarded-For"] = protocol.client_ip

        try:
            response = self.session.post(
                self.wp_endpoint,
                headers=headers,
                json=payload,
                timeout=self.timeout,
            )
        except requests.RequestException as exc:  # pragma: no cover - network failure
            raise WordPressRequestError(str(exc)) from exc

        return response

    async def run(self) -> None:
        server = await serve(
            self.handler,
            self.host,
            self.port,
            create_protocol=lambda *args, **kwargs: MCPServerProtocol(self, *args, **kwargs),
            ssl=self.ssl_context,
            max_size=self.max_message_size,
        )

        display_host = self.host if self.host != "0.0.0.0" else "[all interfaces]"
        self.logger.info(
            "Python MCP proxy listening on wss://%s:%s%s",
            display_host,
            self.port,
            self.path,
        )

        loop = asyncio.get_running_loop()
        stop: asyncio.Future[None] = loop.create_future()

        def _stop() -> None:
            if not stop.done():
                stop.set_result(None)

        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(sig, _stop)
            except NotImplementedError:  # pragma: no cover - platform specific
                pass

        try:
            await stop
        finally:
            server.close()
            await server.wait_closed()
            self.session.close()


def normalize_path(path: Optional[str]) -> str:
    if path is None:
        return DEFAULT_PATH

    trimmed = path.strip()
    if trimmed == "":
        return DEFAULT_PATH

    normalized = "/" + trimmed.lstrip("/")
    while "//" in normalized:
        normalized = normalized.replace("//", "/")

    if normalized != "/" and normalized.endswith("/"):
        normalized = normalized.rstrip("/")

    return normalized or "/"


def extract_token(headers: "headers.Headers", query: str) -> Optional[str]:
    auth_header = headers.get("Authorization")
    if auth_header and auth_header.lower().startswith("bearer "):
        token = auth_header[7:].strip()
        if token:
            return token

    params = parse_qs(query)
    token_param = params.get("token")
    if token_param:
        return token_param[0]

    return None


def format_remote(remote: Optional[Tuple[str, int] | str]) -> Optional[str]:
    if remote is None:
        return None

    if isinstance(remote, tuple) and remote:
        return remote[0]

    if isinstance(remote, str):
        return remote

    return None


def build_json_rpc_error(
    rpc_id: Any,
    has_id: bool,
    code: int,
    message: str,
    data: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    error: Dict[str, Any] = {
        "jsonrpc": JSON_RPC_VERSION,
        "error": {
            "code": code,
            "message": message,
        },
    }

    if data:
        error["error"]["data"] = data

    error["id"] = rpc_id if has_id else None

    return error


def is_valid_json(payload: str) -> bool:
    try:
        json.loads(payload)
    except json.JSONDecodeError:
        return False
    return True


def extract_error_message(response: requests.Response) -> str:
    try:
        data = response.json()
        if isinstance(data, dict):
            message = data.get("message") or data.get("error")
            if isinstance(message, str) and message:
                return message
    except ValueError:
        pass

    text = response.text.strip()
    if text:
        return text

    return f"HTTP {response.status_code}"


def http_error_response(status: int, message: str) -> Tuple[int, Iterable[Tuple[str, str]], bytes]:
    body = message.encode("utf-8")
    headers = [
        ("Content-Type", "text/plain; charset=utf-8"),
        ("Content-Length", str(len(body))),
        ("Connection", "close"),
    ]
    return status, headers, body


def build_ssl_context(cert_path: str, key_path: str, passphrase: Optional[str], ca_path: Optional[str]) -> ssl.SSLContext:
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.options |= ssl.OP_NO_TLSv1 | ssl.OP_NO_TLSv1_1

    context.load_cert_chain(certfile=cert_path, keyfile=key_path, password=passphrase)

    if ca_path:
        context.load_verify_locations(cafile=ca_path)

    context.verify_mode = ssl.CERT_NONE
    return context


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    env_host = os.getenv("WP_GPT_WS_HOST", "0.0.0.0")
    env_port = env_int("WP_GPT_WS_PORT", 8901)
    env_path = os.getenv("WP_GPT_WS_PATH", DEFAULT_PATH)
    env_endpoint = os.getenv("WP_GPT_MCP_HTTP_URL")
    env_timeout = env_float("WP_GPT_MCP_HTTP_TIMEOUT", DEFAULT_TIMEOUT)
    env_cert = os.getenv("WP_GPT_WS_CERT")
    env_key = os.getenv("WP_GPT_WS_KEY")
    env_passphrase = os.getenv("WP_GPT_WS_PASSPHRASE")
    env_ca = os.getenv("WP_GPT_WS_CA")
    env_http_ca = os.getenv("WP_GPT_MCP_HTTP_CA")
    env_max_size = env_int("WP_GPT_WS_MAX_MESSAGE_SIZE", DEFAULT_MAX_MESSAGE_SIZE)
    env_skip_validation = env_flag("WP_GPT_WS_SKIP_INITIAL_VALIDATION", False)
    env_insecure_http = env_flag("WP_GPT_WS_ALLOW_SELF_SIGNED", False)
    env_log_level = os.getenv("WP_GPT_WS_LOG_LEVEL")
    if not env_log_level and env_flag("WP_GPT_WS_VERBOSE", False):
        env_log_level = "DEBUG"

    parser = argparse.ArgumentParser(description="Python WebSocket proxy for WP GPT MCP server")
    parser.add_argument("--host", default=env_host, help="IP address to bind (default: 0.0.0.0)")
    parser.add_argument("--port", type=int, default=env_port, help="TCP port to bind (default: 8901)")
    parser.add_argument("--path", default=env_path, help="WebSocket path (default: /mcp)")
    parser.add_argument(
        "--wp-endpoint",
        default=env_endpoint,
        help="Full URL to the WordPress MCP REST endpoint (e.g. https://example.com/wp-json/wp-gpt/v1/mcp)",
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=env_timeout,
        help=f"HTTP timeout when calling WordPress (default: {DEFAULT_TIMEOUT}s)",
    )
    parser.add_argument(
        "--cert",
        default=env_cert,
        help="Path to the TLS certificate (PEM)",
    )
    parser.add_argument(
        "--key",
        default=env_key,
        help="Path to the TLS private key (PEM)",
    )
    parser.add_argument("--passphrase", default=env_passphrase, help="Passphrase for the TLS private key")
    parser.add_argument("--ca", default=env_ca, help="Optional CA bundle for the server certificate")
    parser.add_argument(
        "--http-ca",
        default=env_http_ca,
        help="Custom CA bundle to validate the WordPress HTTPS endpoint",
    )
    parser.add_argument(
        "--max-message-size",
        type=int,
        default=env_max_size,
        help="Maximum accepted frame payload in bytes",
    )
    parser.add_argument(
        "--skip-initial-validation",
        action="store_true",
        default=env_skip_validation,
        help="Skip the handshake ping used to validate the bearer token",
    )
    parser.add_argument(
        "--insecure-http",
        action="store_true",
        default=env_insecure_http,
        help="Disable TLS validation when contacting WordPress (allow self-signed certificates)",
    )
    parser.add_argument("--log-level", default=env_log_level or "INFO", help="Logging level (default: INFO)")

    return parser.parse_args(argv)


def configure_logging(level: str) -> None:
    logging.basicConfig(
        level=getattr(logging, level.upper(), logging.INFO),
        format="[%(asctime)s] %(levelname)s %(name)s: %(message)s",
    )


def ensure_required_arguments(args: "argparse.Namespace") -> None:
    missing = []
    if not args.wp_endpoint:
        missing.append("--wp-endpoint or WP_GPT_MCP_HTTP_URL")
    if not args.cert:
        missing.append("--cert or WP_GPT_WS_CERT")
    if not args.key:
        missing.append("--key or WP_GPT_WS_KEY")

    if missing:
        raise SystemExit("Missing required options: " + ", ".join(missing))


def ensure_readable_file(path: str, description: str) -> None:
    if not os.path.isfile(path):
        raise SystemExit(f"{description} not found: {path}")
    if not os.access(path, os.R_OK):
        raise SystemExit(f"{description} is not readable: {path}")


def validate_wp_endpoint(url: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"}:
        raise SystemExit("The WordPress endpoint must use http:// or https://")
    if not parsed.netloc:
        raise SystemExit("The WordPress endpoint must include a hostname")


async def main(argv: Optional[list[str]] = None) -> None:
    args = parse_args(argv)
    configure_logging(args.log_level)

    args.host = (args.host or "0.0.0.0").strip()
    args.path = (args.path or "").strip()
    args.wp_endpoint = (args.wp_endpoint or "").strip()
    args.cert = (args.cert or "").strip()
    args.key = (args.key or "").strip()
    args.passphrase = args.passphrase.strip() if args.passphrase else None
    args.ca = args.ca.strip() if args.ca else None
    args.http_ca = args.http_ca.strip() if args.http_ca else None

    ensure_required_arguments(args)
    validate_wp_endpoint(args.wp_endpoint)

    ensure_readable_file(args.cert, "TLS certificate")
    ensure_readable_file(args.key, "TLS private key")
    if args.ca:
        ensure_readable_file(args.ca, "TLS CA bundle")
    if args.http_ca:
        ensure_readable_file(args.http_ca, "WordPress CA bundle")

    if args.port <= 0 or args.port > 65535:
        raise SystemExit("Port must be between 1 and 65535")

    if args.timeout <= 0:
        raise SystemExit("Timeout must be greater than zero")

    if args.max_message_size <= 0:
        raise SystemExit("Max message size must be greater than zero")

    path = normalize_path(args.path)

    if args.http_ca:
        verify: bool | str = args.http_ca
    elif args.insecure_http:
        verify = False
    else:
        verify = True

    ssl_context = build_ssl_context(args.cert, args.key, args.passphrase, args.ca)

    server = MCPProxyServer(
        host=args.host,
        port=args.port,
        path=path,
        wp_endpoint=args.wp_endpoint,
        timeout=args.timeout,
        verify=verify,
        max_message_size=args.max_message_size,
        ssl_context=ssl_context,
        initial_validation=not args.skip_initial_validation,
    )

    await server.run()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:  # pragma: no cover - graceful shutdown
        sys.exit(0)
