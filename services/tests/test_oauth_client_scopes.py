from lifedash.oauth_client_scopes import RefreshDenied, call_allowed, effective_scopes, refresh_scopes
import pytest


def test_narrowed_client_denies_removed_scope_and_refresh_cannot_widen():
    stored = ["pantry:read", "pantry:write", "spotify:read", "spotify:write", "spotify:playback"]
    allowed = ["pantry:read", "spotify:read", "spotify:write"]

    effective = effective_scopes(stored, allowed)
    assert effective == ["pantry:read", "spotify:read", "spotify:write"]
    assert call_allowed(effective, "pantry:read")
    assert not call_allowed(effective, "pantry:write")
    assert not call_allowed(effective, "spotify:playback")

    assert refresh_scopes(stored, allowed, []) == ["pantry:read", "spotify:read", "spotify:write"]
    assert refresh_scopes(stored, allowed, ["spotify:read"]) == ["spotify:read"]

    with pytest.raises(RefreshDenied):
        refresh_scopes(stored, allowed, ["pantry:read", "pantry:write"])
    with pytest.raises(RefreshDenied):
        refresh_scopes(stored, allowed, ["spotify:playback"])
    with pytest.raises(RefreshDenied):
        refresh_scopes(stored, allowed, ["pantry:write", "spotify:playback"])


def test_unedited_client_keeps_the_granted_scopes():
    stored = ["pantry:read", "pantry:write", "spotify:playback"]
    assert effective_scopes(stored, None) == stored
    assert refresh_scopes(stored, None, ["pantry:read", "pantry:write"]) == ["pantry:read", "pantry:write"]
    assert call_allowed(stored, "spotify:playback")
    with pytest.raises(RefreshDenied):
        refresh_scopes(stored, None, ["money:read"])
