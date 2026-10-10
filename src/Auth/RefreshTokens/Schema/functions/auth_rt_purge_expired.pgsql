-- auth_rt_purge_expired - delete up to p_batch rows that expired more than p_older_than_seconds ago.
-- Batched on purpose: call repeatedly until it returns 0 (many small transactions, resumable;
-- never one giant locking delete); rows locked by a concurrent purge/reap are skipped, never waited on. Run by `php stone auth:purge-refresh-tokens`.
CREATE OR REPLACE FUNCTION auth_rt_purge_expired(p_batch INT, p_older_than_seconds INT)
RETURNS TABLE (o_deleted INT) LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    DELETE FROM auth_refresh_tokens
    WHERE token_hash IN (
        SELECT t.token_hash FROM auth_refresh_tokens t
        WHERE LEAST(t.expires_at, t.session_expires_at) <= NOW() - make_interval(secs => GREATEST(p_older_than_seconds, 0))
        ORDER BY t.expires_at
        LIMIT GREATEST(p_batch, 1)
        FOR UPDATE SKIP LOCKED
    );
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN QUERY SELECT n;
END;
$$;
