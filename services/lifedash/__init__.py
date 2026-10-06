"""life-dashboard Python services.

Phase 0 ships the shared foundation every later service builds on:

- ``lifedash.config``      — reads the same ``.env`` as the PHP app (identical parsing rules)
- ``lifedash.db``          — PostgreSQL connections (Unix-socket peer auth by default)
- ``lifedash.log``         — JSON-lines logging for journald
- ``lifedash.healthcheck`` — ``lifedash-healthcheck`` CLI used by deploy and monitoring
"""

__version__ = "0.1.0"
