-- auth_refresh_tokens - framework-owned persistence for every refresh token the
-- framework (or a platform through RefreshTokenIssuer) mints.
-- See StoneScriptPHP\Auth\RefreshTokens\PostgresRefreshTokenStore.
--
--   token_hash  SHA-256 hex of the token. The raw token is NEVER stored.
--   family_id   one login session = one family; a rotated refresh token stays in its
--               family. Reuse of an already-rotated token revokes the whole family
--               (OAuth 2.0 Security BCP, RFC 9700 section 4.14.2; RFC 6819 section 5.2.2.3).
--   rotated_at  set when the token was exchanged for a successor. The row is kept (as a
--               reuse tombstone) until expires_at; it is no longer a valid credential.
--   replaced_by hash of the successor.
--   grace_used  the ONE extra exchange a spent token gets inside the grace window has been taken
--               (a second one is treated as reuse).
--   session_expires_at  absolute cap on the whole session family, set once at login and NEVER slid by
--               rotation: a stolen token cannot be kept alive forever by refreshing it.
--
-- Idempotent: IF NOT EXISTS everywhere; safe to re-run on any DB at any state.

CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
    token_hash   TEXT PRIMARY KEY,
    family_id    UUID NOT NULL,
    subject      TEXT NOT NULL,
    purpose      TEXT NOT NULL CHECK (purpose IN ('authentication', 'authorization')),
    issued_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at   TIMESTAMPTZ NOT NULL,
    session_expires_at TIMESTAMPTZ NOT NULL,
    rotated_at   TIMESTAMPTZ,
    replaced_by  TEXT,
    grace_used   BOOLEAN NOT NULL DEFAULT FALSE,
    last_used_at TIMESTAMPTZ,
    metadata     JSONB NOT NULL DEFAULT '{}'::jsonb
);

-- revokeAllForSubject(subject, purpose?) - leftmost prefix serves both shapes.
CREATE INDEX IF NOT EXISTS auth_refresh_tokens_subject_idx ON auth_refresh_tokens (subject, purpose);
-- revoke a whole session / reuse response.
CREATE INDEX IF NOT EXISTS auth_refresh_tokens_family_idx ON auth_refresh_tokens (family_id);
-- batched expiry purge.
CREATE INDEX IF NOT EXISTS auth_refresh_tokens_expires_idx ON auth_refresh_tokens (expires_at);
