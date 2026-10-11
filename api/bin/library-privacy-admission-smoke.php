<?php
/** Isolated actual Library ingress + audience/moderation helpers; no bootstrap/network.
 * SQLite supplies durable policy and RSS/native moderation. Cache set transport,
 * migrations and painters are fixtures; this proves admission, not full rendering.
 */
declare(strict_types=1);
require __DIR__ . '/migration-reference-functions.php';
$api = dirname(__DIR__);
if (!extension_loaded('mbstring')) throw new RuntimeException('This actual Unicode policy fixture requires mbstring (no polyfill).');
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE ap_users(id INTEGER,actor_id TEXT);
CREATE TABLE events(id INTEGER,object_id TEXT,type TEXT,action_taken TEXT,visibility TEXT,actor_id TEXT);
CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);
CREATE TABLE mentions(id INTEGER,owner_user_id INTEGER,deleted_at TEXT,activity_type TEXT,object_id TEXT);
CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT);
CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT);
CREATE TABLE fixture_mutes(owner_user_id INTEGER,actor_id TEXT);
CREATE TABLE fixture_blocks(owner_user_id INTEGER,scope TEXT,value TEXT);
CREATE TABLE fixture_words(owner_user_id INTEGER,phrase TEXT);
CREATE TABLE bsky_hide_dids(owner_user_id INTEGER,did TEXT);
CREATE TABLE rss_feeds(id INTEGER,owner_user_id INTEGER,title TEXT,favicon_url TEXT,site_url TEXT,feed_url TEXT);
CREATE TABLE rss_items(id INTEGER,feed_id INTEGER,title TEXT);
INSERT INTO ap_users VALUES(201,'https://mkultra.monster/users/viewer'),(202,'https://mkultra.monster/users/other');
INSERT INTO events VALUES(1,'https://remote.test/private','Create','log','private','https://remote.test/users/author');
INSERT INTO following VALUES('https://mkultra.monster/users/viewer','https://remote.test/users/author');
INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/author/notes/direct','direct','[\"https://mkultra.monster/users/viewer\"]','[]');
INSERT INTO rss_feeds VALUES(1,201,'Own feed','','',''),(2,202,'Other feed','','','');
INSERT INTO rss_items VALUES(1,1,'Own RSS'),(2,2,'Foreign RSS');");
function ap_db(): PDO { if (!empty($GLOBALS['db_failure'])) throw new RuntimeException('policy unavailable'); return $GLOBALS['db']; }
function admin_owner_user_id(): int { return 201; }
function admin_render_masto_status_card(array $status, ...$args): void { $GLOBALS['painted'][] = $status; }
function admin_render_rss_item(array $row, string $view): void { $GLOBALS['rss_painted'][] = $row; }
function ap_rss_migrate(): void {}
function ap_bsky_graph_sync_migrate(): void {}
function ap_mutes_set_cached(int $owner): array { $q=ap_db()->prepare('SELECT actor_id FROM fixture_mutes WHERE owner_user_id=?');$q->execute([$owner]);return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true); }
function ap_blocks_actor_id_set(int $owner): array { $q=ap_db()->prepare("SELECT value FROM fixture_blocks WHERE owner_user_id=? AND scope='actor'");$q->execute([$owner]);return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true); }
function ap_user_blocks_list_cached(int $owner): array { $q=ap_db()->prepare('SELECT * FROM fixture_blocks WHERE owner_user_id=?');$q->execute([$owner]);return $q->fetchAll(); }
function ap_muted_words_phrases_cached(int $owner): array { $q=ap_db()->prepare('SELECT phrase FROM fixture_words WHERE owner_user_id=?');$q->execute([$owner]);return $q->fetchAll(PDO::FETCH_COLUMN); }
reference_load($api.'/ap-db.php',['ap_db_owner_actor_id_for_user_id','ap_moderation_syntactic_refs','ap_moderation_path_segment_forms','ap_is_muted_actor','ap_user_is_blocked','ap_muted_words_match','ap_muted_words_normalize_haystack']);
reference_load($api.'/ap-visibility.php',['ap_visibility_actor_hidden','ap_visibility_status_hidden','ap_visibility_status_audience_allowed']);
reference_load($api.'/ap-bsky.php',['ap_bsky_hide_did_set','ap_bsky_at_uri_from_any_url','ap_bsky_post_as_quote_preview','ap_bsky_media_items_from_embeds','ap_bsky_post_is_sensitive','ap_bsky_sensitive_label_vals','ap_bsky_post_label_vals','ap_bsky_compact_label_list']);
reference_load($api.'/ap-bsky-urls.php',['ap_bsky_https_url_from_at_uri','ap_bsky_normalize_web_url']);
reference_load($api.'/ap-rss.php',['ap_rss_parse_local_status_id','ap_rss_item_by_id','ap_rss_item_by_mirror_note_id']);
reference_load($api.'/ap-admin.php',['admin_library_status_is_thin','admin_library_status_enrich','admin_library_bind_rss_sources','admin_library_status_not_deleted','admin_library_quote_policy_key','admin_library_status_allowed','admin_library_render_context_allowed','admin_library_quote_policy_key','admin_library_bsky_item_allowed','admin_render_library_status_card','admin_library_fragment_axum_fetch','admin_quote_opts_from_bsky','admin_quote_opts_from_status']);
$count=0;
function check(bool $v,string $label): void { if (!$v) throw new RuntimeException($label); $GLOBALS['count']++; }
function paint(array $st): array { $GLOBALS['painted']=[];admin_render_library_status_card($st,'bookmarks');return $GLOBALS['painted']; }
$st=['id'=>'original-undo-id','uri'=>'https://remote.test/private','content'=>'Saved body','visibility'=>'public','bookmarked'=>true,'favourited'=>true,'account'=>['uri'=>'https://remote.test/users/author']];
check(count(paint($st))===1,'current follower retains saved private despite stale public snapshot');
check(paint($st)[0]['id']==='original-undo-id' && paint($st)[0]['bookmarked'] && paint($st)[0]['favourited'],'saved action ID and flags unchanged');
check(!admin_library_status_allowed($st,202),'other owner denied');
$db->exec('DELETE FROM following');check(paint($st)===[],'revoked follower denied at actual ingress');
$direct=$st;$direct['uri']='https://mkultra.monster/users/author/notes/direct';
check(count(paint($direct))===1,'explicit direct recipient retained');
check(!admin_library_status_allowed($direct,202),'foreign direct recipient denied');
$db->exec('DELETE FROM outbox_notes');check(paint($direct)===[],'deleted local direct note denied');
$public=$st;$public['uri']='https://remote.test/public';$public['visibility']='unlisted';check(count(paint($public))===1,'saved unlisted remains allowed');
$public['quote']=$st;check(paint($public)===[],'revoked nested quote denied');unset($public['quote']);
$db->exec("INSERT INTO fixture_mutes VALUES(201,'https://remote.test/users/author');");check(paint($public)===[],'current personal mute applies to Library');
$db->exec('DELETE FROM fixture_mutes');check(count(paint($public))===1,'unmute restores saved card without deleting interaction');
$db->exec("INSERT INTO fixture_blocks VALUES(201,'domain','remote.test');");$sub=$public;$sub['account']['uri']='https://sub.remote.test/users/author';check(paint($sub)===[],'domain block includes subdomain');$db->exec('DELETE FROM fixture_blocks');
$db->exec("INSERT INTO fixture_words VALUES(201,'café & tea');");$words=$public;$words['spoiler_text']='CAFÉ &amp; tea';check(paint($words)===[],'actual Unicode/entity normalized CW phrase hidden');
check(count(paint($public))===1,'nonmatching phrase does not hide allowed card');
$db->exec('DELETE FROM fixture_words');check(count(paint($words))===1,'removed phrase restores saved CW card');
$GLOBALS['rss_painted']=[];paint(['id'=>'rss:1','content'=>'owner snapshot']);check(count($GLOBALS['rss_painted'])===1 && $GLOBALS['rss_painted'][0]['owner_user_id']==201,'RSS resolves actual owner row');
$GLOBALS['rss_painted']=[];check(paint(['id'=>'rss:2','content'=>'forged foreign snapshot'])===[] && $GLOBALS['rss_painted']===[],'foreign RSS cannot fallback shared snapshot');
$db->exec('DELETE FROM rss_items WHERE id=1');$GLOBALS['rss_painted']=[];check(paint(['id'=>'rss:1','content'=>'stale deleted snapshot'])===[] && $GLOBALS['rss_painted']===[],'deleted RSS cannot fallback');
check(admin_library_fragment_axum_fetch('favourites_fedi',201,'offset=0|limit=20')===null,'raw HTML transport refused');
$bsky=['post'=>['uri'=>'at://did:plc:allowed/app.bsky.feed.post/key','author'=>['did'=>'did:plc:allowed'],'record'=>['text'=>'allowed'],'labels'=>[['val'=>'porn']]]];
check(admin_library_bsky_item_allowed($bsky,201),'sensitive labels preserve existing CW rather than hiding post');
$db->exec("INSERT INTO bsky_hide_dids VALUES(201,'did:plc:hidden');");$nested=$bsky;$nested['post']['embed']['record']['record']=['author'=>['did'=>'did:plc:hidden'],'value'=>['text'=>'quoted']];check(!admin_library_bsky_item_allowed($nested,201),'current DB native nested quote DID hidden');
$reason=$bsky;$reason['reason']['by']['did']='did:plc:hidden';check(!admin_library_bsky_item_allowed($reason,201),'current native repost author hidden');
check(admin_library_bsky_item_allowed($reason,202),'native moderation owner scoped');
$db->exec('DELETE FROM bsky_hide_dids');check(admin_library_bsky_item_allowed($reason,201),'native unmute reflected even after cached helper warmed');
$stale=$bsky;$stale['post']['author']['viewer']=['muted'=>true,'blocking'=>'at://old/block'];$stale['post']['viewer']['blocked']=true;
check(admin_library_bsky_item_allowed($stale,201),'owner unmute/unblock overrides stale AppView viewer flags');
$stale['post']['author']['viewer']['blockedBy']=true;check(!admin_library_bsky_item_allowed($stale,201),'remote blockedBy remains restricted');
$db->exec("INSERT INTO fixture_words VALUES(201,'nested word');");$nested=$bsky;$nested['post']['embed']['record']['value']['text']='NESTED word';check(!admin_library_bsky_item_allowed($nested,201),'native nested quote words hidden');
check(!admin_library_render_context_allowed($public,201,[$st],[], 'Saved body'),'late hydrated revoked quote fails final gate');
check(!admin_library_render_context_allowed($public,201,[$direct],[], 'Saved body'),'late hydrated deleted local quote fails final gate');
check(!admin_library_render_context_allowed($public,201,[],[['url'=>$direct['uri'],'text'=>'cached direct snippet']], 'Saved body'),'late cached preview of deleted local note fails final gate');
$latePost=['uri'=>'at://did:plc:latehidden/app.bsky.feed.post/key','author'=>['did'=>'did:plc:latehidden','handle'=>'late.test'],'record'=>['text'=>'Late quote']];
$late=ap_bsky_post_as_quote_preview($latePost);
check(!isset($late['did']) && !isset($late['post']) && !str_contains(json_encode($late), 'blockedBy'),'actual compact converter exposes no policy payload');
$db->exec("INSERT INTO bsky_hide_dids VALUES(201,'did:plc:latehidden');");
check(!admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'actual late preview converter native hidden DID denied');
check(admin_library_render_context_allowed($public,202,[],[$late], 'Saved body'),'late preview native moderation owner scoped');
$lateOpts=admin_quote_opts_from_bsky($late);
check(!admin_library_render_context_allowed($public,201,[],[$lateOpts], 'Saved body'),'quote options dropping AT URI still retain request-local native admission');
$db->exec('DELETE FROM bsky_hide_dids');
check(admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'warm compact preview unmute/unblock reads current native rows');
$latePost['author']['viewer']['blocking']='old-owner-block';$latePost['author']['viewer']['muted']=true;
$late=ap_bsky_post_as_quote_preview($latePost);
check(admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'retained warm native owner viewer flags do not override current unmute');
$latePost['author']['viewer']['blockedBy']=true;$late=ap_bsky_post_as_quote_preview($latePost);
check(!admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'actual compact converter retains remote blockedBy policy outside JSON');
check(!str_contains(json_encode($late), 'blockedBy') && !isset($late['post']),'remote policy evidence not exported in compact JSON');
unset($latePost['author']['viewer']);
$latePost['embed']=['$type'=>'app.bsky.embed.record#view','record'=>['author'=>['did'=>'did:plc:nestedhidden'],'value'=>['text'=>'Nested quote']]];
$late=ap_bsky_post_as_quote_preview($latePost);$db->exec("INSERT INTO bsky_hide_dids VALUES(201,'did:plc:nestedhidden');");
check(!admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'actual preview retains native nested quote moderation');
$db->exec('DELETE FROM bsky_hide_dids');check(admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'nested unmute restores warm saved quote');
$latePost['labels']=[['val'=>'porn']];$late=ap_bsky_post_as_quote_preview($latePost);$lateOpts=admin_quote_opts_from_bsky($late);
check($lateOpts['sensitive'] && $lateOpts['spoiler_text']==='Sensitive content','actual compact converter retains label CW evidence for quote options');
$GLOBALS['vaak_bsky_quote_policy_posts']=[];$db->exec("INSERT INTO bsky_hide_dids VALUES(201,'did:plc:latehidden');");
check(!admin_library_render_context_allowed($public,201,[],[$late], 'Saved body'),'legacy compact AT URI derives DID without retained payload');
$db->exec('DELETE FROM bsky_hide_dids');$db->exec("INSERT INTO fixture_blocks VALUES(201,'domain','blocked.test');");
check(!admin_library_render_context_allowed($public,201,[],[['url'=>'https://sub.blocked.test/posts/q','acct'=>'@author@blocked.test','text'=>'flat quote']], 'Saved body'),'no-cache flattened quote target subdomain blocked');
$db->exec('DELETE FROM fixture_blocks');
$db->exec("ALTER TABLE rss_items ADD COLUMN mirror_note_id TEXT;
ALTER TABLE rss_items ADD COLUMN summary_text TEXT;
ALTER TABLE rss_items ADD COLUMN url TEXT;
ALTER TABLE rss_items ADD COLUMN image_url TEXT;
INSERT INTO rss_items(id,feed_id,title,mirror_note_id,summary_text,url) VALUES(3,1,'Own mirror','https://mkultra.monster/users/viewer/notes/rss','Own summary','https://private-feed.test/own');
UPDATE rss_items SET mirror_note_id='https://mkultra.monster/users/other/notes/rss',summary_text='Other owner secret',url='https://private-feed.test/secret' WHERE id=2;
INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/viewer/notes/rss','private','[]','[]');
INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/other/notes/rss','private','[]','[]');");
$mirror=['id'=>'mirror','uri'=>'https://mkultra.monster/users/viewer/notes/rss','url'=>'https://mkultra.monster/users/viewer/notes/rss','content'=>'Public AS2 body','account'=>['uri'=>'https://mkultra.monster/users/viewer']];
$ownOpts=admin_quote_opts_from_status($mirror);
check(ap_rss_item_by_mirror_note_id($mirror['uri'],0)===null && ap_rss_item_by_mirror_note_id($mirror['uri'],202)===null,'actual mirror SQL rejects missing and foreign owner');
check(!empty($ownOpts['is_rss']) && str_contains($ownOpts['text'],'Own summary'),'actual mirror enrichment resolves owner subscription only');
check(admin_library_render_context_allowed($public,201,[],[$ownOpts], 'Saved body'),'owner private RSS mirror quote retained');
$foreign=$mirror;$foreign['uri']=$foreign['url']='https://mkultra.monster/users/other/notes/rss';$foreign['account']['uri']='https://mkultra.monster/users/other';
$foreignOpts=admin_quote_opts_from_status($foreign);
check(empty($foreignOpts['is_rss']) && !str_contains(json_encode($foreignOpts),'Other owner secret') && !str_contains(json_encode($foreignOpts),'private-feed.test/secret'),'foreign mirror never enriches from another subscription');
check(!admin_library_render_context_allowed($public,201,[],[$foreignOpts], 'Saved body'),'foreign private AS2 mirror denied');
$db->exec("UPDATE outbox_notes SET visibility='public' WHERE id='https://mkultra.monster/users/other/notes/rss'");
$foreignOpts=admin_quote_opts_from_status($foreign);
check(admin_library_render_context_allowed($public,201,[],[$foreignOpts], 'Saved body') && $foreignOpts['text']==='Public AS2 body','authorized public foreign AS2 body readable without subscription enrichment');
$db->exec('DELETE FROM rss_items WHERE id=3');
check(!admin_library_render_context_allowed($public,201,[],[$ownOpts], 'Saved body'),'warm RSS mirror options denied after subscription item deleted');
check(!admin_library_render_context_allowed($public,201,[],[['url'=>'https://remote.test/public','is_rss'=>true,'text'=>'forged']], 'Saved body'),'forged RSS marker cannot bypass current owner row');
$GLOBALS['vaak_rss_quote_policy_rows']['https://remote.test/public']=['id'=>2,'owner_user_id'=>201];
check(!admin_library_render_context_allowed($public,201,[],[['url'=>'https://remote.test/public','is_rss'=>true,'text'=>'forged']], 'Saved body'),'malformed retained owner evidence cannot override actual subscription owner');
$db->exec("DELETE FROM outbox_notes WHERE id='https://mkultra.monster/users/other/notes/rss'");
check(!admin_library_render_context_allowed($public,201,[],[$foreignOpts], 'Saved body'),'deleted local public AS2 mirror denied');
$authority=['id'=>'unknown','uri'=>'https://unknown.test/objects/authority','url'=>'https://unknown.test/@alice/authority','visibility'=>'private','content'=>'Restricted uncached quote','account'=>['uri'=>'https://unknown.test/users/alice','acct'=>'alice@unknown.test']];
$authorityOpts=admin_quote_opts_from_status($authority);
check(!admin_library_status_allowed($authority,201) && !admin_library_render_context_allowed($public,201,[],[$authorityOpts], 'Saved body'),'distinct AP URI/web URL preserves private source authority');
$authority['visibility']='public';$authorityOpts=admin_quote_opts_from_status($authority);
check(admin_library_status_allowed($authority,201) && admin_library_render_context_allowed($public,201,[],[$authorityOpts], 'Saved body'),'distinct AP URI/web URL public control stays allowed');
$db->exec("INSERT INTO fixture_blocks VALUES(201,'actor','https://unknown.test/users/alice');");
check(!admin_library_render_context_allowed($public,201,[],[$authorityOpts], 'Saved body'),'distinct AP URI/web URL preserves blocked author source');
$db->exec('DELETE FROM fixture_blocks');
$authority['visibility']='private';$authority['uri'].='/#cached';$authority['url'].='/#web';$authorityOpts=admin_quote_opts_from_status($authority);
$authorityOpts['url']='https://unknown.test/objects/authority#alternate';
check(!admin_library_render_context_allowed($public,201,[],[$authorityOpts], 'Saved body'),'private source retains normalized fragment/trailing slash alias');
$authorityOpts['url']='https://unknown.test/@alice/authority/';
check(!admin_library_render_context_allowed($public,201,[],[$authorityOpts], 'Saved body'),'private web alias shares normalized policy evidence');
$authority['visibility']='public';$authorityOpts=admin_quote_opts_from_status($authority);$authorityOpts['url']='https://unknown.test/objects/authority/';
check(admin_library_render_context_allowed($public,201,[],[$authorityOpts], 'Saved body'),'normalized public fragment/slash alias control stays allowed');
$GLOBALS['db_failure']=true;check(paint($public)===[],'policy unavailable fails closed');check(!admin_library_bsky_item_allowed($bsky,201),'native policy unavailable fails closed');
echo "library-privacy-admission: PASS ($count actual-helper admission cases; painters/cache transport/migrations are isolated fixtures)\n";
