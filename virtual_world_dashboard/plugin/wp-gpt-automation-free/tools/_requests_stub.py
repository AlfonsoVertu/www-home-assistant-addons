"""Minimal fallback implementation of the :mod:`requests` API used in tests.

This stub is intentionally lightweight: it implements only the features
required by ``tools.mcp_ws_proxy`` so that the integration tests can run in
restricted environments where installing the real ``requests`` dependency is
not possible. The public surface mirrors the subset of the API that the proxy
relies on (``Session.post`` and ``RequestException``).
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Dict, Optional
from urllib import error as urlerror
from urllib import request as urlrequest


class RequestException(Exception):
    """Raised when the stub cannot perform the HTTP request."""


@dataclass
class Response:
    """Lightweight response object compatible with ``requests.Response``."""

    status_code: int
    _text: str
    headers: Dict[str, str]

    @property
    def text(self) -> str:
        return self._text

    def json(self) -> object:
        return json.loads(self._text)


class Session:
    """Minimal session that sends JSON payloads via :mod:`urllib`."""

    def __init__(self) -> None:
        self.verify: bool | str = True

    def close(self) -> None:
        """Provided for API compatibility with :mod:`requests`."""

    def post(
        self,
        url: str,
        *,
        headers: Optional[Dict[str, str]] = None,
        json: Optional[object] = None,
        timeout: Optional[float] = None,
    ) -> Response:
        payload = b""
        request_headers: Dict[str, str] = {}
        if headers:
            request_headers.update(headers)

        if json is not None:
            payload = json_dumps(json)
            request_headers.setdefault("Content-Type", "application/json")

        req = urlrequest.Request(url, data=payload, headers=request_headers, method="POST")

        try:
            with urlrequest.urlopen(req, timeout=timeout) as response:
                body = response.read().decode("utf-8", "replace")
                return Response(response.getcode(), body, dict(response.headers))
        except urlerror.HTTPError as exc:
            body = exc.read().decode("utf-8", "replace")
            headers_dict = dict(exc.headers.items()) if exc.headers else {}
            return Response(exc.code, body, headers_dict)
        except urlerror.URLError as exc:
            raise RequestException(str(exc)) from exc


def json_dumps(payload: object) -> bytes:
    return json.dumps(payload).encode("utf-8")


__all__ = ["RequestException", "Response", "Session"]
