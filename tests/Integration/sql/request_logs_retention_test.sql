-- Behaviour test for rl_purge_request_logs. Runs in one session against pg_temp objects (see the runner).
-- (functions living in pg_temp must be called schema-qualified)
CREATE OR REPLACE FUNCTION pg_temp.assert(ok boolean, msg text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN IF NOT ok THEN RAISE EXCEPTION 'ASSERT FAILED: %', msg; END IF; END $$;

DO $$
DECLARE n INT; remaining INT;
BEGIN
    -- 10 old rows (40 days), 5 rows inside the window (5 days), 1 row exactly at the edge (29 days)
    INSERT INTO request_logs (request_id, occurred_at, status, duration_ms)
    SELECT 'old-' || g, now() - interval '40 days', 200, 1 FROM generate_series(1, 10) g;
    INSERT INTO request_logs (request_id, occurred_at, status, duration_ms)
    SELECT 'new-' || g, now() - interval '5 days', 200, 1 FROM generate_series(1, 5) g;
    INSERT INTO request_logs (request_id, occurred_at, status, duration_ms)
    VALUES ('edge', now() - interval '29 days', 200, 1);

    SELECT o_deleted INTO n FROM pg_temp.rl_purge_request_logs(30, 4);
    PERFORM pg_temp.assert(n = 4, 'batch size honoured (4 of 10 old rows)');
    SELECT o_deleted INTO n FROM pg_temp.rl_purge_request_logs(30, 4);
    PERFORM pg_temp.assert(n = 4, 'second batch');
    SELECT o_deleted INTO n FROM pg_temp.rl_purge_request_logs(30, 4);
    PERFORM pg_temp.assert(n = 2, 'last short batch signals completion');
    SELECT o_deleted INTO n FROM pg_temp.rl_purge_request_logs(30, 4);
    PERFORM pg_temp.assert(n = 0, 'nothing left to purge');

    SELECT count(*) INTO remaining FROM request_logs;
    PERFORM pg_temp.assert(remaining = 6, 'in-window rows and the 29-day row survive');
    PERFORM pg_temp.assert(NOT EXISTS (SELECT 1 FROM request_logs WHERE request_id LIKE 'old-%'), 'no old row remains');

    -- window is honoured: a 3-day window now takes the 5-day and 29-day rows
    SELECT o_deleted INTO n FROM pg_temp.rl_purge_request_logs(3, 1000);
    PERFORM pg_temp.assert(n = 6, 'smaller window deletes more');

    -- guard: never "delete everything" through a bad window
    BEGIN
        PERFORM pg_temp.rl_purge_request_logs(0, 10);
        PERFORM pg_temp.assert(false, 'days=0 must raise');
    EXCEPTION WHEN raise_exception THEN
        IF SQLERRM LIKE 'ASSERT FAILED%' THEN RAISE; END IF;
    END;
    BEGIN
        PERFORM pg_temp.rl_purge_request_logs(NULL, 10);
        PERFORM pg_temp.assert(false, 'days=NULL must raise');
    EXCEPTION WHEN raise_exception THEN
        IF SQLERRM LIKE 'ASSERT FAILED%' THEN RAISE; END IF;
    END;

    -- the occurred_at index exists (declared by the table file)
    PERFORM pg_temp.assert(EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_request_logs_occurred_at' AND tablename = 'request_logs'), 'occurred_at index');
    RAISE NOTICE 'request_logs retention: all assertions passed';
END $$;
