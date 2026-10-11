-- REVIEW ONLY: not executed. PostgreSQL, explicit maintenance outside BEGIN.
-- First verify isolated result parity and live index name/definition/validity.
-- Build one at a time; observe load and configure bounded lock timeout in the
-- execution session. IF NOT EXISTS does not fix invalid or wrong-name indexes.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_object_key_id
    ON public.events (rtrim(object_id, '/'), id DESC);

-- Defer this second index until duplicate handle-to-DID ambiguity is tested.
-- The existing caller has LIMIT 1 without ORDER BY, so arbitrary winners can
-- change with its access path. This keeps its predicate and lower() semantics.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_handle_did_expr
    ON public.bsky_posts (lower(author_handle))
    WHERE author_did IS NOT NULL AND author_did <> '';

-- Rollback, only after confirming these exact names belong to this change:
-- DROP INDEX CONCURRENTLY IF EXISTS public.idx_events_object_key_id;
-- DROP INDEX CONCURRENTLY IF EXISTS public.idx_bsky_posts_handle_did_expr;
