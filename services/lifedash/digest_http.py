"""Fetch one changelog URL with a timeout, conditional headers, and backoff."""

from __future__ import annotations

import ipaddress
import socket
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Callable
from urllib.parse import urlsplit

USER_AGENT = "life-dashboard digest (personal changelog monitor)"
TIMEOUT = 15
MAX_BYTES = 4_000_000
MAX_ATTEMPTS = 3

Transport = Callable[[str, dict[str, str]], "FetchResult"]


class UrlRejected(Exception):
    """The URL is not a public https page."""


@dataclass
class FetchResult:
    status: int = 0
    headers: dict[str, str] | None = None
    body: str = ""
    error: str | None = None
    content_type: str = ""

    def header(self, name: str) -> str | None:
        if not self.headers:
            return None
        want = name.lower()
        for key, value in self.headers.items():
            if key.lower() == want and value:
                return value
        return None


def assert_public_url(url: str) -> str:
    parts = urlsplit(url.strip())
    if parts.scheme != "https":
        raise UrlRejected("Only https URLs can be fetched.")
    if parts.username or parts.password:
        raise UrlRejected("URLs with a username are not fetched.")
    host = (parts.hostname or "").lower().rstrip(".")
    if host == "" or host == "localhost" or host.endswith(".local") or host.endswith(".internal"):
        raise UrlRejected("That host is not fetched.")
    try:
        addresses = [ipaddress.ip_address(host)]
    except ValueError:
        try:
            infos = socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
        except socket.gaierror as exc:
            raise UrlRejected("The host name did not resolve.") from exc
        addresses = []
        for info in infos:
            try:
                addresses.append(ipaddress.ip_address(info[4][0]))
            except ValueError:
                continue
        if not addresses:
            raise UrlRejected("The host name did not resolve.")
    for ip in addresses:
        if not _public_ip(ip):
            raise UrlRejected("That host is not fetched.")
    return url


def _public_ip(ip: ipaddress.IPv4Address | ipaddress.IPv6Address) -> bool:
    return not (
        ip.is_private
        or ip.is_loopback
        or ip.is_link_local
        or ip.is_reserved
        or ip.is_multicast
        or ip.is_unspecified
    )


def fetch_with_backoff(
    transport: Transport,
    url: str,
    etag: str | None,
    modified: str | None,
    sleep: Callable[[float], None] = lambda _seconds: None,
) -> FetchResult:
    headers = {
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,application/rss+xml,application/atom+xml,application/xml;q=0.9,*/*;q=0.8",
    }
    if etag:
        headers["If-None-Match"] = etag
    if modified:
        headers["If-Modified-Since"] = modified
    try:
        assert_public_url(url)
    except UrlRejected as exc:
        return FetchResult(error=str(exc))
    delay = 0.0
    last = FetchResult(error="The fetch failed.")
    for attempt in range(MAX_ATTEMPTS):
        if delay:
            sleep(delay)
        try:
            result = transport(url, headers)
        except UrlRejected as exc:
            return FetchResult(error=str(exc))
        except TimeoutError:
            last = FetchResult(error="The request timed out.")
            delay = float(2 ** attempt)
            continue
        except OSError:
            last = FetchResult(error="The host could not be reached.")
            delay = float(2 ** attempt)
            continue
        if result.status in (429, 502, 503, 504):
            last = result
            if result.error is None:
                last.error = _status_error(result.status)
            delay = _retry_delay(result, attempt)
            continue
        return result
    return last


def urllib_transport(url: str, headers: dict[str, str]) -> FetchResult:
    assert_public_url(url)

    class GuardRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, hdrs, newurl):  # type: ignore[no-untyped-def]
            assert_public_url(newurl)
            return super().redirect_request(req, fp, code, msg, hdrs, newurl)

    opener = urllib.request.build_opener(GuardRedirect)
    request = urllib.request.Request(url, headers=headers, method="GET")
    try:
        with opener.open(request, timeout=TIMEOUT) as response:
            return _from_response(response.status, response.headers, response.read(MAX_BYTES + 1))
    except urllib.error.HTTPError as exc:
        raw = exc.read(MAX_BYTES + 1) if exc.fp is not None else b""
        return _from_response(exc.code, exc.headers, raw)
    except TimeoutError as exc:
        raise TimeoutError("The request timed out.") from exc
    except urllib.error.URLError as exc:
        reason = exc.reason
        if isinstance(reason, TimeoutError) or "timed out" in str(reason).lower():
            raise TimeoutError("The request timed out.") from exc
        raise OSError("The host could not be reached.") from exc


def _from_response(status: int, headers: object, raw: bytes) -> FetchResult:
    pairs = {}
    if headers is not None:
        items = headers.items() if hasattr(headers, "items") else []
        pairs = {str(key): str(value) for key, value in items}
    if len(raw) > MAX_BYTES:
        raw = raw[:MAX_BYTES]
    body = raw.decode("utf-8", "replace")
    content_type = ""
    for key, value in pairs.items():
        if key.lower() == "content-type":
            content_type = value
            break
    return FetchResult(status=status, headers=pairs, body=body, content_type=content_type)


def _status_error(status: int) -> str:
    if status == 429:
        return "The host asked us to slow down (HTTP 429)."
    return f"The host returned HTTP {status}."


def _retry_delay(result: FetchResult, attempt: int) -> float:
    raw = result.header("Retry-After") or ""
    if raw.isdigit():
        seconds = int(raw)
        if 0 < seconds <= 30:
            return float(seconds)
    return float(2 ** attempt)
