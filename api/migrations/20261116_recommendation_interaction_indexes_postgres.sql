-- Helper and CREATE INDEX text for the recommendation probes.
-- Deploy does not run this file (it is not in vaak_migrate_deploy_sql_files).
-- deploy/remote/migrate.php runs the same helpers and CREATE INDEX
-- statements outside a transaction. A failed CREATE INDEX CONCURRENTLY
-- leaves an INVALID index, and IF NOT EXISTS below would skip it forever.
-- migrate.php checks pg_index.indisvalid, drops that index, and only then
-- replaces the helpers, so the replace is not blocked by the dependency.
-- The helpers stay IMMUTABLE and PARALLEL SAFE. They do not use an
-- EXCEPTION block: entering one starts a subtransaction, and a parallel
-- index build cannot. Invalid JSON is rejected with pg_input_is_valid
-- (PostgreSQL 16). Results match the previous helpers. A page load must
-- not build these indexes.
CREATE OR REPLACE FUNCTION vaak_reco_quote_key(raw text) RETURNS text
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE
SET search_path = pg_catalog
AS $fn$
DECLARE
  doc jsonb;
BEGIN
  IF raw IS NULL OR strpos(raw, 'quote') <= 0 THEN
    RETURN '';
  END IF;
  IF NOT pg_catalog.pg_input_is_valid(raw, 'jsonb') THEN
    RETURN '';
  END IF;
  doc := raw::jsonb;
  RETURN rtrim(COALESCE(doc #>> '{object,quote}', doc #>> '{object,quoteUrl}', doc #>> '{object,_misskey_quote}', ''), '/');
END;
$fn$;
CREATE OR REPLACE FUNCTION vaak_reco_signal_key(raw text, field text) RETURNS text
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE
SET search_path = pg_catalog
AS $fn$
DECLARE
  doc jsonb;
  val text;
BEGIN
  IF raw IS NULL OR raw = '' OR field IS NULL OR field = '' THEN
    RETURN NULL;
  END IF;
  IF NOT pg_catalog.pg_input_is_valid(raw, 'jsonb') THEN
    RETURN NULL;
  END IF;
  doc := raw::jsonb;
  val := doc ->> field;
  IF val IS NULL THEN
    RETURN NULL;
  END IF;
  RETURN rtrim(val, '/');
END;
$fn$;
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_favourites_owner_object ON masto_favourites (owner_user_id, (rtrim(object_id,'/')));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_bookmarks_owner_object ON masto_bookmarks (owner_user_id, (rtrim(object_id,'/')));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_reblogs_owner_object ON masto_reblogs (owner_user_id, (rtrim(object_id,'/')));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_outbox_reply_key ON outbox_notes ((rtrim(in_reply_to,'/')), id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_outbox_quote_key ON outbox_notes ((vaak_reco_quote_key(raw_create_json)), id) WHERE strpos(raw_create_json,'quote')>0 AND vaak_reco_quote_key(raw_create_json)<>'';
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_quote_auth_actor_target ON quote_authorizations ((rtrim(requester_actor,'/')), (rtrim(quoted_note_id,'/')));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_signals_owner_target ON ap_user_signals (owner_user_id, (rtrim(target_key,'/'))) WHERE weight>0 AND signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark');
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_signals_owner_meta_object ON ap_user_signals (owner_user_id, (vaak_reco_signal_key(metadata_json,'object_id'))) WHERE weight>0 AND signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark');
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_signals_owner_meta_uri ON ap_user_signals (owner_user_id, (vaak_reco_signal_key(metadata_json,'uri'))) WHERE weight>0 AND signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark');
