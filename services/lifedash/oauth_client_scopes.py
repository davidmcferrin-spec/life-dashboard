"""Scope cap for an edited OAuth client.

PHP applies the same cap on authenticate and refresh. A null allow list means
the client has not been edited.
"""

from __future__ import annotations

EXPLICIT = ("system:read", "system:deploy")
CORE = ("metrics", "widgets", "life", "pages", "money", "invest", "search")


class RefreshDenied(Exception):
    """A refresh asked for a scope the client is no longer allowed to keep."""


def within_allowed(scope: str, allowed: list[str]) -> bool:
    if scope in allowed:
        return True
    if "*" in allowed and scope not in EXPLICIT:
        return True
    if scope.endswith(":read") and (scope[:-5] + ":write") in allowed:
        return True
    if scope == "spotify:read" and "spotify:playback" in allowed:
        return True
    return False


def effective_scopes(token_scopes: list[str], allowed: list[str] | None) -> list[str]:
    if allowed is None:
        return list(token_scopes)
    out: list[str] = []
    for scope in token_scopes:
        if within_allowed(scope, allowed) and scope not in out:
            out.append(scope)
    return out


def refresh_scopes(token_scopes: list[str], allowed: list[str] | None, requested: list[str]) -> list[str]:
    current = effective_scopes(token_scopes, allowed)
    if not requested:
        return current
    for scope in requested:
        if scope not in current:
            raise RefreshDenied("Refresh cannot add a scope that was not granted.")
    out: list[str] = []
    for scope in requested:
        if scope not in out:
            out.append(scope)
    return out


def call_allowed(granted: list[str], required: str) -> bool:
    """Whether the next MCP call with these scopes may use required."""
    if required in EXPLICIT:
        if required in granted:
            return True
        return required == "system:read" and "system:deploy" in granted
    if "*" in granted or _has_core(granted, "write"):
        return True
    if required.endswith(":read") and _has_core(granted, "read"):
        return True
    if required in granted:
        return True
    if required == "spotify:read" and "spotify:playback" in granted:
        return True
    if required.endswith(":read"):
        return (required[:-5] + ":write") in granted
    return False


def _has_core(granted: list[str], verb: str) -> bool:
    return all(f"{area}:{verb}" in granted for area in CORE)
