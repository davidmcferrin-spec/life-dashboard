"""Spotify HTTP with the same retry rules as the PHP client."""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Callable

from .spotify_plan import backoff_seconds

API = "https://api.spotify.com/v1"
TOKEN_URL = "https://accounts.spotify.com/api/token"

Transport = Callable[[str, str, str | None, dict[str, str]], tuple[int, str, dict[str, str]]]
Sleeper = Callable[[int], None]


class SpotifyHttpError(Exception):
    def __init__(self, message: str, status: int, reauth: bool = False) -> None:
        super().__init__(message)
        self.status = status
        self.reauth = reauth


def error_message(status: int, path: str, body: str) -> str:
    lower = path.lower()
    if "restriction violated" in body.lower():
        if "/volume" in lower:
            return "This device does not support volume."
        return "This device does not support that playback command."
    if status == 403 and ("recommendation" in lower or "audio-features" in lower or "audio-analysis" in lower):
        return "Spotify does not offer recommendations or audio features to this app."
    if status == 403:
        return "Spotify refused that request (403)."
    err = ""
    try:
        decoded = json.loads(body) if body else {}
    except json.JSONDecodeError:
        decoded = {}
    if isinstance(decoded, dict):
        code = decoded.get("error")
        if isinstance(code, str):
            err = code
        elif isinstance(code, dict) and isinstance(code.get("message"), str):
            err = code["message"]
    err = scrub(err)
    if not err:
        return f"Spotify request failed ({status})."
    return f"Spotify request failed ({status}): {err}"


def with_length(method: str, body: str | None, headers: dict[str, str]) -> tuple[str | None, dict[str, str]]:
    """PUT and POST with no Content-Length are rejected with 411."""
    headers = dict(headers)
    if body is None and method.upper() in {"POST", "PUT", "PATCH", "DELETE"}:
        body = ""
    if body is not None and "Content-Length" not in headers:
        headers["Content-Length"] = str(len(body.encode("utf-8")))
    return body, headers


def scrub(text: str) -> str:
    import re

    text = re.sub(r"Bearer\s+\S+", "Bearer [redacted]", text, flags=re.I)
    text = re.sub(
        r"(access_token|refresh_token|client_secret|code_verifier|authorization)\s*[:=]\s*\S+",
        r"\1=[redacted]",
        text,
        flags=re.I,
    )
    return text.strip()


def urllib_transport(method: str, url: str, body: str | None, headers: dict[str, str]) -> tuple[int, str, dict[str, str]]:
    data = body.encode("utf-8") if body is not None else None
    if data is None and method.upper() in {"POST", "PUT", "DELETE"}:
        data = b""
    request = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            raw = response.read().decode("utf-8", errors="replace")
            hdrs = {key.lower(): value for key, value in response.headers.items()}
            return response.status, raw, hdrs
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        hdrs = {key.lower(): value for key, value in exc.headers.items()} if exc.headers else {}
        return exc.code, raw, hdrs


class SpotifyHttp:
    def __init__(self, transport: Transport | None = None, sleeper: Sleeper | None = None) -> None:
        self.transport = transport or urllib_transport
        self.sleeper = sleeper or time.sleep

    def token(self, form: dict[str, str], client_id: str, client_secret: str) -> dict:
        import base64

        basic = base64.b64encode(f"{client_id}:{client_secret}".encode("utf-8")).decode("ascii")
        body = "&".join(f"{key}={urllib.parse.quote(value)}" for key, value in form.items())
        encoded, headers = with_length(
            "POST",
            body,
            {
                "Authorization": f"Basic {basic}",
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
            },
        )
        return self._send("POST", TOKEN_URL, encoded, headers)

    def api(self, method: str, path: str, access_token: str, body: dict | None = None, query: dict | None = None) -> dict:
        url = path if path.startswith("https://") else API + "/" + path.lstrip("/")
        if query:
            joined = "&".join(f"{key}={urllib.parse.quote(str(value))}" for key, value in query.items())
            url += ("&" if "?" in url else "?") + joined
        encoded = json.dumps(body) if body is not None else None
        headers = {"Authorization": f"Bearer {access_token}", "Accept": "application/json"}
        if encoded is not None:
            headers["Content-Type"] = "application/json"
        encoded, headers = with_length(method, encoded, headers)
        return self._send(method, url, encoded, headers)

    def _send(self, method: str, url: str, body: str | None, headers: dict[str, str]) -> dict:
        attempt = 0
        while True:
            attempt += 1
            status, raw, response_headers = self.transport(method, url, body, headers)
            if 200 <= status < 300:
                if not raw or not raw.strip():
                    return {}
                try:
                    decoded = json.loads(raw)
                except json.JSONDecodeError:
                    return {}
                return decoded if isinstance(decoded, dict) else {}
            retry_after = response_headers.get("retry-after")
            parsed = int(retry_after) if retry_after is not None and str(retry_after).isdigit() else None
            wait = backoff_seconds(status, parsed, attempt)
            if wait is not None:
                self.sleeper(wait)
                continue
            reauth = status == 401 or (status == 400 and "invalid_grant" in raw)
            raise SpotifyHttpError(error_message(status, url, raw), 409 if reauth else status, reauth)
