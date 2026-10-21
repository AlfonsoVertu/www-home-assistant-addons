#!/usr/bin/env python3
"""Local integration test for the MCP WebSocket proxy.

This script spins up a stub WordPress REST endpoint, launches the Python
``MCPProxyServer`` from :mod:`tools.mcp_ws_proxy` with a self-signed TLS
certificate and exercises it with both Python and PHP clients. It verifies
that the handshake succeeds, that JSON-RPC traffic is forwarded to the
stub endpoint and that the payloads exchanged by the clients match the
OpenAI "Connectors and MCP servers" documentation (e.g. producing
``mcp_list_tools`` and ``mcp_call`` items).

Usage::

    python scripts/mcp_wss_loopback.py

By default the script runs both the asyncio-based Python client and the CLI
PHP client located at ``scripts/mcp_wss_php_client.php``. Use
``--skip-python-client`` or ``--skip-php-client`` to disable either test.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import os
import ssl
import subprocess
import sys
import tempfile
import threading
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Dict, List, Optional

ROOT_DIR = Path(__file__).resolve().parents[1]
if str(ROOT_DIR) not in sys.path:
    sys.path.insert(0, str(ROOT_DIR))

from tools.mcp_ws_proxy import (
    MCPProxyServer,
    MCPServerProtocol,
    build_ssl_context,
)  # noqa: E402

try:
    import websockets
    from websockets.server import WebSocketServer
except ImportError:  # pragma: no cover - handled during execution
    from tools import _websocket_stub as websockets
    from tools._websocket_stub import WebSocketServer


ENDPOINT_PATH = "/wp-json/wp-gpt/v1/mcp"
DEFAULT_TOKEN = "demo-token"
DEFAULT_MESSAGE = "loopback-compliance"
LOOPBACK_TOOL_NAME = "loopback_echo"


class StubWordPressServer(HTTPServer):
    """Minimal HTTP server that mimics the WordPress MCP endpoint."""

    allow_reuse_address = True

    def __init__(self, host: str, port: int, token: str) -> None:
        self.expected_token = token
        self.requests: list[Dict[str, object]] = []
        super().__init__((host, port), self._handler_factory())

    def _handler_factory(self):  # type: ignore[override]
        token = self.expected_token

        class Handler(BaseHTTPRequestHandler):
            server_version = "StubWordPress/1.0"
            protocol_version = "HTTP/1.1"

            def log_message(self, format: str, *args: object) -> None:  # pragma: no cover
                logging.getLogger("stub-wordpress").debug(format, *args)

            def do_POST(self) -> None:  # noqa: N802 - required by BaseHTTPRequestHandler
                if self.path != ENDPOINT_PATH:
                    self.send_error(HTTPStatus.NOT_FOUND)
                    return

                content_length = int(self.headers.get("Content-Length", "0"))
                raw_payload = self.rfile.read(content_length)

                try:
                    payload = json.loads(raw_payload)
                except json.JSONDecodeError:
                    self.send_error(HTTPStatus.BAD_REQUEST, "Invalid JSON payload")
                    return

                auth_header = self.headers.get("Authorization") or ""
                if auth_header.strip() != f"Bearer {token}":
                    self.send_response(HTTPStatus.UNAUTHORIZED)
                    self._send_json({
                        "jsonrpc": "2.0",
                        "error": {
                            "code": 401,
                            "message": "Unauthorized",
                        },
                        "id": payload.get("id"),
                    })
                    return

                method = payload.get("method")
                params = payload.get("params") or {}
                if method == "ping":
                    response = {
                        "jsonrpc": "2.0",
                        "id": payload.get("id"),
                        "result": {"pong": True},
                    }
                elif method == "list_tools":
                    response = {
                        "jsonrpc": "2.0",
                        "id": payload.get("id"),
                        "result": {
                            "type": "mcp_list_tools",
                            "server_label": params.get("server_label", "loopback"),
                            "tools": [
                                {
                                    "name": LOOPBACK_TOOL_NAME,
                                    "description": "Loopback tool used for compliance validation.",
                                    "input_schema": {
                                        "$schema": "https://json-schema.org/draft/2020-12/schema",
                                        "type": "object",
                                        "properties": {
                                            "message": {"type": "string"},
                                            "source": {"type": "string"},
                                        },
                                        "required": ["message"],
                                        "additionalProperties": False,
                                    },
                                }
                            ],
                        },
                    }
                elif method == "call_tool":
                    arguments = params.get("arguments") or {}
                    response = {
                        "jsonrpc": "2.0",
                        "id": payload.get("id"),
                        "result": {
                            "type": "mcp_call",
                            "server_label": params.get("server_label", "loopback"),
                            "name": params.get("name", LOOPBACK_TOOL_NAME),
                            "arguments": json.dumps(arguments),
                            "output": json.dumps(
                                {
                                    "message": arguments.get("message"),
                                    "source": arguments.get("source"),
                                }
                            ),
                        },
                    }
                else:
                    response = {
                        "jsonrpc": "2.0",
                        "id": payload.get("id"),
                        "result": {
                            "method": method,
                            "params": params,
                        },
                    }

                logging.getLogger("stub-wordpress").info(
                    "Handled %s request with id %s", method, payload.get("id")
                )
                self.server.requests.append({  # type: ignore[attr-defined]
                    "method": method,
                    "raw": raw_payload.decode("utf-8"),
                    "headers": {
                        "authorization": auth_header,
                        "user_agent": self.headers.get("User-Agent"),
                        "content_type": self.headers.get("Content-Type"),
                    },
                    "response": response,
                })
                self.send_response(HTTPStatus.OK)
                self._send_json(response)

            def _send_json(self, payload: Dict[str, object]) -> None:
                body = json.dumps(payload).encode("utf-8")
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        return Handler


class ServerContext:
    """Utility wrapper to manage background services."""

    def __init__(
        self,
        stub: StubWordPressServer,
        proxy: MCPProxyServer,
        ws_server: WebSocketServer,
    ) -> None:
        self.stub = stub
        self.proxy = proxy
        self.ws_server = ws_server
        self._http_thread = threading.Thread(target=self.stub.serve_forever, daemon=True)

    def start(self) -> None:
        self._http_thread.start()

    async def close(self) -> None:
        self.stub.shutdown()
        self._http_thread.join(timeout=5)
        self.stub.server_close()
        self.ws_server.close()
        await self.ws_server.wait_closed()
        self.proxy.session.close()


def generate_self_signed_certificate(directory: Path) -> tuple[Path, Path]:
    """Create a short-lived self-signed certificate using OpenSSL."""

    cert_path = directory / "loopback-cert.pem"
    key_path = directory / "loopback-key.pem"

    cmd = [
        "openssl",
        "req",
        "-x509",
        "-nodes",
        "-newkey",
        "rsa:2048",
        "-days",
        "1",
        "-keyout",
        str(key_path),
        "-out",
        str(cert_path),
        "-subj",
        "/CN=localhost",
    ]

    try:
        subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except FileNotFoundError as exc:  # pragma: no cover - environment specific
        raise SystemExit("OpenSSL is required to generate a self-signed certificate") from exc
    return cert_path, key_path


async def run_python_client(url: str, token: str, message: str) -> Dict[str, object]:
    """Connect to the proxy using the asyncio websockets client."""

    logging.info("Running Python client against %s", url)
    ssl_context = ssl.create_default_context()
    ssl_context.check_hostname = False
    ssl_context.verify_mode = ssl.CERT_NONE

    async with websockets.connect(
        url,
        extra_headers={"Authorization": f"Bearer {token}", "User-Agent": "WP-GPT-MCP-Python-Test/1.0"},
        ssl=ssl_context,
    ) as websocket:
        logging.info("Python client connected, sending payload")
        payload = {
            "jsonrpc": "2.0",
            "id": "python-list-tools",
            "method": "list_tools",
            "params": {
                "server_label": "loopback",
                "allowed_tools": [LOOPBACK_TOOL_NAME],
                "timestamp": time.time(),
            },
        }
        await websocket.send(json.dumps(payload))
        logging.info("Python client waiting for response")
        raw_response = await websocket.recv()

    logging.info("Python client received: %s", raw_response)
    return json.loads(raw_response)


def run_php_client(url: str, token: str, message: str, php_binary: str) -> Dict[str, object]:
    """Invoke the PHP CLI client to perform a WebSocket round-trip."""

    script_path = ROOT_DIR / "scripts" / "mcp_wss_php_client.php"
    if not script_path.is_file():
        raise FileNotFoundError(f"PHP client script not found at {script_path}")

    payload = json.dumps(
        {
            "jsonrpc": "2.0",
            "id": "php-call-tool",
            "method": "call_tool",
            "params": {
                "server_label": "loopback",
                "name": LOOPBACK_TOOL_NAME,
                "arguments": {"message": message, "source": "php"},
            },
        }
    )

    cmd = [
        php_binary,
        str(script_path),
        f"--url={url}",
        f"--token={token}",
        f"--payload={payload}",
    ]

    logging.info("Running PHP client: %s", " ".join(cmd))
    completed = subprocess.run(cmd, check=True, capture_output=True, text=True)
    logging.info("PHP client output:\n%s", completed.stdout.strip())
    return json.loads(completed.stdout.strip())


async def launch_tls_services(host: str, port: int, path: str, token: str, cert_path: Path, key_path: Path) -> tuple[ServerContext, str]:
    stub = StubWordPressServer(host, 0, token)
    endpoint = f"http://{host}:{stub.server_port}{ENDPOINT_PATH}"

    ssl_context = build_ssl_context(str(cert_path), str(key_path), None, None)

    proxy = MCPProxyServer(
        host=host,
        port=port,
        path=path,
        wp_endpoint=endpoint,
        timeout=5.0,
        verify=False,
        ssl_context=ssl_context,
        initial_validation=True,
    )

    ws_server = await websockets.serve(
        proxy.handler,
        host,
        port,
        create_protocol=lambda *args, **kwargs: MCPServerProtocol(proxy, *args, **kwargs),
        ssl=ssl_context,
        max_size=proxy.max_message_size,
    )

    context = ServerContext(stub, proxy, ws_server)
    context.start()
    return context, f"wss://{host}:{port}{path}"


def build_compliance_report(
    forwarded_requests: List[Dict[str, object]],
    client_results: Dict[str, Optional[Dict[str, object]]],
    expected_token: str,
) -> Dict[str, object]:
    """Produce a structured compliance report for MCP connection checks."""

    checks: List[Dict[str, object]] = []

    executed_clients = [name for name, payload in client_results.items() if payload is not None]
    expected_calls = len(executed_clients)
    non_handshake_requests = [
        request for request in forwarded_requests if request.get("method") != "ping"
    ]
    actual_calls = len(non_handshake_requests)
    checks.append(
        {
            "name": "forwarded_call_count",
            "passed": actual_calls == expected_calls,
            "detail": f"Forwarded {actual_calls} JSON-RPC calls (expected {expected_calls}).",
        }
    )

    auth_failures = []
    for idx, request in enumerate(forwarded_requests):
        headers = request.get("headers") or {}
        if headers.get("authorization") != f"Bearer {expected_token}":
            auth_failures.append(idx)
    checks.append(
        {
            "name": "authorization_header_forwarded",
            "passed": not auth_failures,
            "detail": "All requests propagated the Bearer token"
            if not auth_failures
            else f"Missing/invalid Authorization header for requests: {auth_failures}",
        }
    )

    ua_failures = []
    for idx, request in enumerate(forwarded_requests):
        headers = request.get("headers") or {}
        if not headers.get("user_agent"):
            ua_failures.append(idx)
    checks.append(
        {
            "name": "user_agent_present",
            "passed": not ua_failures,
            "detail": "User-Agent header present on all forwarded calls"
            if not ua_failures
            else f"Missing User-Agent header for requests: {ua_failures}",
        }
    )

    malformed_json: List[int] = []
    wrong_version: List[int] = []
    for idx, request in enumerate(forwarded_requests):
        raw = request.get("raw", "")
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            malformed_json.append(idx)
            continue
        if payload.get("jsonrpc") != "2.0":
            wrong_version.append(idx)
    checks.append(
        {
            "name": "jsonrpc_structure_valid",
            "passed": not malformed_json and not wrong_version,
            "detail": "All forwarded payloads comply with JSON-RPC 2.0"
            if not malformed_json and not wrong_version
            else {
                "malformed": malformed_json,
                "wrong_version": wrong_version,
            },
        }
    )

    client_failures: Dict[str, str] = {}
    for name, payload in client_results.items():
        if payload is None:
            continue
        if payload.get("jsonrpc") != "2.0":
            client_failures[name] = "jsonrpc"
            continue
        result = payload.get("result")
        if not isinstance(result, dict):
            client_failures[name] = "missing result"

    checks.append(
        {
            "name": "client_responses_valid",
            "passed": not client_failures,
            "detail": "Client responses follow JSON-RPC 2.0 format"
            if not client_failures
            else {"invalid_clients": client_failures},
        }
    )

    required_methods = {"list_tools", "call_tool"}
    observed_methods = {
        request.get("method")
        for request in non_handshake_requests
        if request.get("method")
    }
    missing_methods = sorted(required_methods - observed_methods)
    checks.append(
        {
            "name": "required_mcp_methods_invoked",
            "passed": not missing_methods,
            "detail": "Observed required MCP methods"
            if not missing_methods
            else {"missing": missing_methods},
        }
    )

    expected_result_types = {"python": "mcp_list_tools", "php": "mcp_call"}
    type_failures: Dict[str, object] = {}
    for name, payload in client_results.items():
        if payload is None:
            continue
        result = payload.get("result") if isinstance(payload, dict) else None
        expected_type = expected_result_types.get(name)
        if not isinstance(result, dict) or result.get("type") != expected_type:
            type_failures[name] = {
                "expected": expected_type,
                "actual": result.get("type") if isinstance(result, dict) else None,
            }
    checks.append(
        {
            "name": "client_result_type_matches_doc",
            "passed": not type_failures,
            "detail": "Client payloads return documented MCP item types"
            if not type_failures
            else {"mismatch": type_failures},
        }
    )

    summary = {
        "total": len(checks),
        "passed": sum(1 for check in checks if check["passed"]),
        "failed": [check["name"] for check in checks if not check["passed"]],
    }

    return {"checks": checks, "summary": summary}


async def main() -> int:
    parser = argparse.ArgumentParser(description="End-to-end loopback test for the MCP WSS proxy")
    parser.add_argument("--host", default="127.0.0.1", help="Host to bind the proxy (default: 127.0.0.1)")
    parser.add_argument("--port", type=int, default=8901, help="Port for the WebSocket proxy (default: 8901)")
    parser.add_argument("--path", default="/mcp", help="WebSocket path (default: /mcp)")
    parser.add_argument("--token", default=DEFAULT_TOKEN, help="Bearer token used by the clients (default: demo-token)")
    parser.add_argument("--message", default=DEFAULT_MESSAGE, help="Message payload for the echo test")
    parser.add_argument("--php-bin", default=os.environ.get("PHP_BIN", "php"), help="PHP executable to use for the CLI client")
    parser.add_argument("--skip-python-client", action="store_true", help="Skip the asyncio Python client test")
    parser.add_argument("--skip-php-client", action="store_true", help="Skip the PHP client test")
    parser.add_argument("--debug-logs", action="store_true", help="Enable verbose logs for troubleshooting")
    args = parser.parse_args()

    log_level = logging.DEBUG if args.debug_logs else logging.INFO
    logging.basicConfig(level=log_level, format="[%(levelname)s] %(message)s", stream=sys.stderr)
    logging.getLogger("websocket-stub").setLevel(logging.DEBUG)
    logging.getLogger("stub-wordpress").setLevel(logging.INFO)

    with tempfile.TemporaryDirectory() as tmpdir:
        cert_path, key_path = generate_self_signed_certificate(Path(tmpdir))
        context, ws_url = await launch_tls_services(
            host=args.host,
            port=args.port,
            path=args.path,
            token=args.token,
            cert_path=cert_path,
            key_path=key_path,
        )

        logging.info("Proxy listening at %s", ws_url)

        results: Dict[str, Optional[Dict[str, object]]] = {"python": None, "php": None}
        forwarded_requests: List[Dict[str, object]] = []
        try:
            if not args.skip_python_client:
                results["python"] = await run_python_client(ws_url, args.token, args.message)

            if not args.skip_php_client:
                loop = asyncio.get_running_loop()
                results["php"] = await loop.run_in_executor(
                    None,
                    run_php_client,
                    ws_url,
                    args.token,
                    args.message,
                    args.php_bin,
                )
        finally:
            forwarded_requests = list(context.stub.requests)
            await context.close()

    logging.info("Test results: %s", json.dumps(results, indent=2))
    compliance_report = build_compliance_report(forwarded_requests, results, args.token)
    logging.info("Compliance report: %s", json.dumps(compliance_report, indent=2))

    output = {"clients": results, "compliance": compliance_report}
    print(json.dumps(output))
    return 0


if __name__ == "__main__":
    try:
        exit_code = asyncio.run(main())
    except KeyboardInterrupt:  # pragma: no cover - CLI convenience
        exit_code = 1
    sys.exit(exit_code)
