"""Minimal asyncio-based WebSocket implementation for offline test environments.

The real project depends on the third-party :mod:`websockets` package. In the
CI sandbox used for the kata the dependency cannot always be installed, so this
module provides a small subset of its API that is sufficient for the loopback
tests. It supports TLS, text frames and basic connection management.
"""

from __future__ import annotations

import asyncio
import base64
import hashlib
import logging
import os
import struct
from contextlib import suppress
from dataclasses import dataclass
from typing import Awaitable, Callable, Dict, Iterable, Optional
from urllib.parse import urlparse


GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
logger = logging.getLogger("websocket-stub")


class ConnectionClosed(Exception):
    """Raised when the WebSocket connection is terminated."""


class ConnectionClosedOK(ConnectionClosed):
    """Raised when the connection ends without errors."""


class Headers:
    """Case-insensitive mapping for HTTP headers."""

    def __init__(self, data: Dict[str, str]) -> None:
        self._data = {key.lower(): value for key, value in data.items()}

    def get(self, key: str, default: Optional[str] = None) -> Optional[str]:
        return self._data.get(key.lower(), default)

    def items(self) -> Iterable[tuple[str, str]]:
        return ((key, value) for key, value in self._data.items())


@dataclass
class WebSocketServer:
    """Lightweight wrapper mimicking :class:`websockets.server.Serve`."""

    _server: asyncio.AbstractServer

    def close(self) -> None:
        self._server.close()

    async def wait_closed(self) -> None:
        await self._server.wait_closed()


class WebSocketServerProtocol:
    """Simplified protocol compatible with the project requirements."""

    def __init__(self, *, max_size: Optional[int] = None) -> None:
        self.max_size = max_size
        self.reader: Optional[asyncio.StreamReader] = None
        self.writer: Optional[asyncio.StreamWriter] = None
        self.remote_address: Optional[tuple[str, int]] = None
        self.path: str = "/"
        self._incoming: Optional[asyncio.Queue[Optional[str]]] = None
        self._reader_task: Optional[asyncio.Task[None]] = None
        self._closed = False

        # Attributes consumed by ``MCPProxyServer``
        self.token: Optional[str] = None
        self.user_agent: Optional[str] = None
        self.client_ip: Optional[str] = None
        self.last_validation: float = 0.0

    async def process_request(  # noqa: D401 - signature mirrors ``websockets``
        self,
        path: str,
        request_headers: Headers,
    ) -> Optional[tuple[int, Iterable[tuple[str, str]], bytes]]:
        """Hook for subclasses to validate the HTTP handshake."""

        return None

    async def _accept(
        self,
        reader: asyncio.StreamReader,
        writer: asyncio.StreamWriter,
        handler: Callable[["WebSocketServerProtocol"], Awaitable[None]],
    ) -> None:
        self.reader = reader
        self.writer = writer
        self.remote_address = writer.get_extra_info("peername")

        request_line = await reader.readline()
        if not request_line:
            await self._abort_handshake(400, "Bad Request")
            return

        try:
            method, path, _ = request_line.decode("utf-8").strip().split(" ", 2)
        except ValueError:
            await self._abort_handshake(400, "Bad Request")
            return

        headers: Dict[str, str] = {}
        while True:
            line = await reader.readline()
            if line in (b"", b"\r\n"):
                break
            try:
                key, value = line.decode("utf-8").split(":", 1)
            except ValueError:
                continue
            headers[key.strip()] = value.strip()

        if method.upper() != "GET":
            await self._abort_handshake(405, "Method Not Allowed")
            return

        headers_obj = Headers(headers)
        logger.debug("Received handshake for %s", path)
        response = await self.process_request(path, headers_obj)
        if response is not None:
            logger.debug("Process request returned custom response: %s", response[0])
            status, response_headers, body = response
            await self._send_handshake_response(status, response_headers, body)
            return

        key = headers_obj.get("Sec-WebSocket-Key")
        if not key:
            await self._abort_handshake(400, "Bad Request")
            return

        accept = base64.b64encode(
            hashlib.sha1((key + GUID).encode("utf-8")).digest()
        ).decode("ascii")

        logger.debug("Sending handshake 101 response")
        await self._send_handshake_response(
            101,
            [
                ("Upgrade", "websocket"),
                ("Connection", "Upgrade"),
                ("Sec-WebSocket-Accept", accept),
            ],
            b"",
        )

        self.path = path
        logger.debug("Handshake complete, starting reader loop")
        self._incoming = asyncio.Queue()
        self._reader_task = asyncio.create_task(self._reader_loop())
        try:
            await handler(self)
        finally:
            await self.close()

    async def _send_handshake_response(
        self,
        status: int,
        headers: Iterable[tuple[str, str]],
        body: bytes,
    ) -> None:
        if self.writer is None:
            return

        reason = {
            101: "Switching Protocols",
            200: "OK",
            400: "Bad Request",
            401: "Unauthorized",
            404: "Not Found",
            405: "Method Not Allowed",
            502: "Bad Gateway",
        }.get(status, "OK")

        response_lines = [f"HTTP/1.1 {status} {reason}"]
        for key, value in headers:
            response_lines.append(f"{key}: {value}")
        if body:
            response_lines.append(f"Content-Length: {len(body)}")
        response = "\r\n".join(response_lines).encode("utf-8") + b"\r\n\r\n"
        message = response + body
        self.writer.write(message)
        await self.writer.drain()

    async def _abort_handshake(self, status: int, message: str) -> None:
        await self._send_handshake_response(status, [], message.encode("utf-8"))
        await self.close()

    async def _reader_loop(self) -> None:
        if self.reader is None or self._incoming is None:
            return
        try:
            while not self._closed:
                frame = await _read_frame(self.reader)
                if frame is None:
                    break
                opcode, data = frame
                logger.debug("Server received opcode %s with %d bytes", opcode, len(data))
                if opcode == 0x1:
                    if self.max_size is not None and len(data) > self.max_size:
                        break
                    await self._incoming.put(data.decode("utf-8", "replace"))
                elif opcode == 0x8:
                    break
                elif opcode == 0x9:  # ping
                    await self._send_frame(0xA, data)
                else:
                    continue
        except ConnectionClosed:
            pass
        except asyncio.IncompleteReadError:
            pass
        finally:
            self._closed = True
            await self._enqueue_close_marker()

    async def _enqueue_close_marker(self) -> None:
        if self._incoming is not None:
            await self._incoming.put(None)

    async def _send_frame(self, opcode: int, data: bytes) -> None:
        if self.writer is None:
            return
        frame = _build_frame(opcode, data, mask=False)
        self.writer.write(frame)
        await self.writer.drain()

    async def send(self, message: str) -> None:
        if not isinstance(message, str):
            raise TypeError("Only text messages are supported in the stub")
        await self._send_frame(0x1, message.encode("utf-8"))

    async def close(self, code: int = 1000, reason: str | bytes = "") -> None:
        if self._closed:
            return
        self._closed = True
        if self.writer is None:
            return

        payload = struct.pack("!H", code)
        if isinstance(reason, str):
            payload += reason.encode("utf-8")
        else:
            payload += reason

        with suppress(Exception):
            await self._send_frame(0x8, payload)

        if self._reader_task:
            self._reader_task.cancel()
            with suppress(asyncio.CancelledError):
                await self._reader_task

        self.writer.close()
        with suppress(Exception):
            await self.writer.wait_closed()
        await self._enqueue_close_marker()

    def __aiter__(self) -> "WebSocketServerProtocol":
        return self

    async def __anext__(self) -> str:
        if self._incoming is None:
            raise StopAsyncIteration
        message = await self._incoming.get()
        if message is None:
            raise StopAsyncIteration
        return message


async def serve(
    handler: Callable[[WebSocketServerProtocol], Awaitable[None]],
    host: str,
    port: int,
    *,
    create_protocol: Optional[Callable[..., WebSocketServerProtocol]] = None,
    ssl=None,
    max_size: Optional[int] = None,
) -> WebSocketServer:
    if create_protocol is None:
        create_protocol = lambda *args, **kwargs: WebSocketServerProtocol(max_size=max_size)

    async def _on_connect(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        protocol = create_protocol(max_size=max_size)
        await protocol._accept(reader, writer, handler)

    server = await asyncio.start_server(_on_connect, host, port, ssl=ssl)
    return WebSocketServer(server)


class WebSocketClient:
    """Minimal client used by the loopback test."""

    def __init__(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        self.reader = reader
        self.writer = writer
        self._incoming: asyncio.Queue[Optional[str]] = asyncio.Queue()
        self._closed = False
        self._reader_task = asyncio.create_task(self._reader_loop())

    async def _reader_loop(self) -> None:
        try:
            while not self._closed:
                frame = await _read_frame(self.reader)
                if frame is None:
                    break
                opcode, data = frame
                logger.debug("Client received opcode %s with %d bytes", opcode, len(data))
                if opcode == 0x1:
                    await self._incoming.put(data.decode("utf-8", "replace"))
                elif opcode == 0x8:
                    break
                elif opcode == 0x9:
                    await self._send_frame(0xA, data, mask=True)
        except ConnectionClosed:
            pass
        except asyncio.IncompleteReadError:
            pass
        finally:
            self._closed = True
            await self._incoming.put(None)

    async def _send_frame(self, opcode: int, data: bytes, mask: bool) -> None:
        frame = _build_frame(opcode, data, mask=mask)
        self.writer.write(frame)
        await self.writer.drain()

    async def send(self, message: str) -> None:
        if not isinstance(message, str):
            raise TypeError("Only text messages are supported in the stub")
        await self._send_frame(0x1, message.encode("utf-8"), mask=True)

    async def recv(self) -> str:
        message = await self._incoming.get()
        if message is None:
            raise ConnectionClosedOK("Connection closed")
        return message

    async def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        with suppress(Exception):
            await self._send_frame(0x8, struct.pack("!H", 1000), mask=True)
        self.writer.close()
        with suppress(Exception):
            await self.writer.wait_closed()
        if self._reader_task:
            self._reader_task.cancel()
            with suppress(asyncio.CancelledError):
                await self._reader_task

    async def __aenter__(self) -> "WebSocketClient":
        return self

    async def __aexit__(self, exc_type, exc, tb) -> None:
        await self.close()


async def _connect_impl(
    url: str,
    *,
    extra_headers: Optional[Dict[str, str]] = None,
    ssl=None,
) -> WebSocketClient:
    parsed = urlparse(url)
    if parsed.scheme not in {"ws", "wss"}:
        raise ValueError("Only ws:// or wss:// URLs are supported")

    host = parsed.hostname or "localhost"
    port = parsed.port or (443 if parsed.scheme == "wss" else 80)
    path = parsed.path or "/"
    if parsed.query:
        path += f"?{parsed.query}"

    ssl_context = ssl if parsed.scheme == "wss" else None
    reader, writer = await asyncio.open_connection(host, port, ssl=ssl_context)

    key = base64.b64encode(os.urandom(16)).decode("ascii")
    headers = {
        "Host": f"{host}:{port}",
        "Upgrade": "websocket",
        "Connection": "Upgrade",
        "Sec-WebSocket-Key": key,
        "Sec-WebSocket-Version": "13",
    }
    if extra_headers:
        headers.update(extra_headers)

    request_lines = [f"GET {path} HTTP/1.1"]
    for header, value in headers.items():
        request_lines.append(f"{header}: {value}")
    request = ("\r\n".join(request_lines) + "\r\n\r\n").encode("utf-8")
    writer.write(request)
    await writer.drain()

    status_line = await reader.readline()
    logger.debug("Client handshake status: %s", status_line)
    if not status_line.startswith(b"HTTP/1.1 101"):
        body = await reader.read()
        raise ConnectionClosed(
            f"Handshake failed: {status_line.decode().strip()} {body.decode(errors='ignore')}"
        )

    while True:
        line = await reader.readline()
        if line in (b"", b"\r\n"):
            break

    logger.debug("Client handshake complete")
    return WebSocketClient(reader, writer)


class _ClientConnection:
    def __init__(self, coro: Awaitable[WebSocketClient]) -> None:
        self._coro = coro
        self._client: Optional[WebSocketClient] = None

    def __await__(self):  # pragma: no cover - small delegator
        return self._ensure_client().__await__()

    async def _ensure_client(self) -> WebSocketClient:
        if self._client is None:
            self._client = await self._coro
        return self._client

    async def __aenter__(self) -> WebSocketClient:
        return await self._ensure_client()

    async def __aexit__(self, exc_type, exc, tb) -> None:
        if self._client is not None:
            await self._client.close()


def connect(
    url: str,
    *,
    extra_headers: Optional[Dict[str, str]] = None,
    ssl=None,
):
    return _ClientConnection(_connect_impl(url, extra_headers=extra_headers, ssl=ssl))


def _build_frame(opcode: int, data: bytes, *, mask: bool) -> bytes:
    fin_opcode = 0x80 | (opcode & 0x0F)
    length = len(data)

    if length < 126:
        header = bytearray([fin_opcode, length])
    elif length < 65536:
        header = bytearray([fin_opcode, 126])
        header.extend(struct.pack("!H", length))
    else:
        header = bytearray([fin_opcode, 127])
        header.extend(struct.pack("!Q", length))

    if mask:
        header[1] |= 0x80
        mask_key = os.urandom(4)
        masked = bytes(b ^ mask_key[i % 4] for i, b in enumerate(data))
        return bytes(header) + mask_key + masked

    return bytes(header) + data


async def _read_exact(reader: asyncio.StreamReader, n: int) -> bytes:
    data = await reader.readexactly(n)
    if not data:
        raise ConnectionClosed("Connection closed")
    return data


async def _read_frame(reader: asyncio.StreamReader) -> Optional[tuple[int, bytes]]:
    try:
        header = await _read_exact(reader, 2)
    except asyncio.IncompleteReadError:
        return None

    first, second = header
    opcode = first & 0x0F
    masked = (second & 0x80) == 0x80
    length = second & 0x7F

    if length == 126:
        extended = await _read_exact(reader, 2)
        length = struct.unpack("!H", extended)[0]
    elif length == 127:
        extended = await _read_exact(reader, 8)
        length = struct.unpack("!Q", extended)[0]

    mask_key = b""
    if masked:
        mask_key = await _read_exact(reader, 4)

    payload = await _read_exact(reader, length) if length else b""

    if masked and mask_key:
        payload = bytes(b ^ mask_key[i % 4] for i, b in enumerate(payload))

    return opcode, payload


__all__ = [
    "ConnectionClosed",
    "ConnectionClosedOK",
    "WebSocketServer",
    "WebSocketServerProtocol",
    "serve",
    "connect",
]
