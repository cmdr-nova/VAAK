-- Same statements as vaak_migrate_recommendation_indexes() in
-- deploy/remote/migrate.php. Deploy runs them automatically, outside a
-- transaction. CREATE INDEX CONCURRENTLY cannot run inside the additive
-- loop (vaak_migrate_deploy_sql_files), which also refuses indexes on
-- existing tables. Re-running is a no-op once an index is valid. The
-- helpers match the old quote/signal extraction on valid JSON. Invalid
-- JSON yields no key, so one bad row cannot fail the index build.
-- A page load must not build these indexes.
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
  BEGIN
    doc := raw::jsonb;
  EXCEPTION WHEN others THEN
    RETURN '';
  END;
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
  BEGIN
    doc := raw::jsonb;
  EXCEPTION WHEN others THEN
    RETURN NULL;
  END;
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
