-- rl_purge_request_logs: retention for request_logs. Deletes up to p_batch rows whose occurred_at is older than
-- p_older_than_days days and returns how many it deleted.
--
-- Batched on purpose: call it repeatedly until it returns fewer rows than p_batch (many short transactions,
-- resumable, never one giant locking delete). Rows locked by a concurrent purge are skipped, never waited on.
-- Run by `php stone request-logs:purge` (schedule it daily - see docs/REQUEST-LOGGING.md).
--
-- Guard: p_older_than_days must be >= 1, so a mistyped 0 can never wipe the whole table. The batch size is
-- clamped to 1..100000. No DDL.
CREATE OR REPLACE FUNCTION rl_purge_request_logs(p_older_than_days INT, p_batch INT)
RETURNS TABLE (o_deleted INT) LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    IF p_older_than_days IS NULL OR p_older_than_days < 1 THEN
        RAISE EXCEPTION 'rl_purge_request_logs: p_older_than_days must be >= 1';
    END IF;

    DELETE FROM request_logs
    WHERE id IN (
        SELECT r.id FROM request_logs r
        WHERE r.occurred_at < NOW() - make_interval(days => p_older_than_days)
        ORDER BY r.occurred_at
        LIMIT LEAST(GREATEST(COALESCE(p_batch, 1000), 1), 100000)
        FOR UPDATE SKIP LOCKED
    );
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN QUERY SELECT n;
END;
$$;
