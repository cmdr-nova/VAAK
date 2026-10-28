<?php
declare(strict_types=1);
if (!extension_loaded("mbstring")) { fwrite(STDERR,"Actual PHP card references require real mbstring; use api/bin/php-policy-test.\n"); exit(2); }
/** Actual PHP renderer and admission reference capture. No live bootstrap/network.
 * Executable feature corpus is partial coverage, not complete migration parity.
 */
require_once __DIR__ . '/migration-reference-functions.php';
// Read only the genuine public presentation declarations. Loading painters
// without these constants incorrectly exercises their legacy .jpg fallback.
$publicPresentationSource = (string)file_get_contents(dirname(__DIR__) . '/ap-db.php');
foreach (['AP_REMOTE_AVATAR_FALLBACK', 'AP_LOCAL_AVATAR_FALLBACK', 'AP_WAFRN_APPROVALS_AVATAR'] as $publicConstantName) {
 if (!preg_match('/^const ' . preg_quote($publicConstantName, '/') . ' = (\x27[^\x27]*\x27);/m', $publicPresentationSource, $publicConstantDeclaration)) throw new RuntimeException('missing actual public presentation constant: ' . $publicConstantName);
 if (!defined($publicConstantName)) eval($publicConstantDeclaration[0]);
}
stream_wrapper_unregister('http'); stream_wrapper_unregister('https');
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_auth_csrf_token(): string { return 'fixture-csrf-token'; }
function ap_bsky_graph_sync_migrate(): void {} // DDL transport: fixture graph tables are already provisioned.
function ap_masto_lookup_status_by_object_url(string $url,int $owner=0,bool $fetch=false,bool $allowEmpty=false): ?array {
 $q=ap_db()->prepare('SELECT payload FROM fixture_status_cache WHERE object_id=?');$q->execute([rtrim($url,'/')]);$raw=$q->fetchColumn();return is_string($raw)?json_decode($raw,true):null;
}
function ap_db_now(): string { return gmdate('c'); }
function ap_link_preview_http_get(string $url,int $timeoutSec=4,int $maxBytes=524288): ?string { $GLOBALS['preview_http_requests'][]=$url; return null; }
function ap_link_preview_warm_async(string $url): void { $GLOBALS['preview_warm_requests'][]=$url; }
// DNS transport: fixture hosts (*.test) never resolve. Literal addresses use the real guard.
function ap_net_host_is_public(string $host): bool { $h=strtolower(trim($host,'[]')); return filter_var($h,FILTER_VALIDATE_IP) ? ap_net_ip_is_public($h) : !in_array($h,['localhost'],true) && !str_ends_with($h,'.localhost') && !str_ends_with($h,'.local'); }
function ap_block_list_cached(bool $refresh=false): array { return ap_db()->query('SELECT * FROM fixture_server_blocks')->fetchAll(); }
function ap_server_blocked_actors_set(bool $refresh=false): array { $out=[]; foreach(ap_block_list_cached() as $b) if($b['scope']==='actor' && $b['kind']!=='mute') foreach(ap_moderation_syntactic_refs($b['value']) as $ref) $out[$ref]=true; return $out; }
function ap_user_blocks_list_cached(int $owner,bool $refresh=false): array { $q=ap_db()->prepare('SELECT * FROM fixture_blocks WHERE owner_user_id=?');$q->execute([$owner]);return $q->fetchAll(); }
function ap_mutes_set_cached(int $owner,bool $refresh=false): array { $q=ap_db()->prepare('SELECT actor_id FROM fixture_mutes WHERE owner_user_id=?');$q->execute([$owner]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $actor) foreach(ap_moderation_syntactic_refs($actor) as $ref) $out[$ref]=true;return $out; }
function ap_muted_words_phrases_cached(int $owner,bool $refresh=false): array { $q=ap_db()->prepare('SELECT phrase FROM fixture_words WHERE owner_user_id=?');$q->execute([$owner]);return $q->fetchAll(PDO::FETCH_COLUMN); }
function vaak_actor_id(): string { return 'https://mkultra.monster/users/fixture'; }
class CaptureFixtureDatabase extends PDO {
 public function exec(string $sql): int|false {
  // DDL compatibility is transport-only: all declared fixture tables already exist.
  if(preg_match('/^CREATE INDEX IF NOT EXISTS /i',$sql)) return 0;
  if(preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z_]+)/i',$sql,$m)) {
   $q=$this->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"); $q->execute([$m[1]]);
   if($q->fetchColumn()) return 0;
  }
  return parent::exec($sql);
 }
}
$GLOBALS['fixture_db'] = new CaptureFixtureDatabase('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$GLOBALS['vaak_owner_id'] = 1; $GLOBALS['vaak_actor_key'] = 'fixture'; $GLOBALS['vaak_user'] = ['id'=>1,'username'=>'fixture'];
$file = dirname(__DIR__) . '/ap-admin.php';
$source = (string)file_get_contents($file);
// Freeze only the clock dependency of the actual relative-time helper. The
// painter can cross a wall-clock second while loading policy/facts; native
// output receives this same captured clock rather than parsing its HTML.
if (!preg_match('/^function relative_time\(.*?^}/ms',$source,$clockHelper)) throw new RuntimeException('missing actual relative_time helper');
eval(str_replace('time()', '($GLOBALS[\'fixture_render_clock\'] ?? time())', $clockHelper[0]));
preg_match_all('/^function\s+((?:admin_|vaak_)[a-zA-Z_0-9]+|h|relative_time|actor_handle|short_host|block_quick_actions)\s*\(/m',$source,$matches);
reference_load($file,array_values(array_unique($matches[1])));
$api = dirname(__DIR__);
// Ordinary canonical remote links use this production helper, rather than
// admin_remote_object_href's missing-module fallback. Numeric Threads metadata
// and cold actor lookup remain outside this bounded fixture's coverage.
reference_load($api.'/ap-db.php',['ap_remote_object_web_url','ap_is_threads_url','ap_threads_profile_web_url']);
reference_load($api.'/ap-inbox.php',['ap_text_looks_like_as2_json','ap_text_scrub_as2_dump','ap_plain_strip_raw_quote_lines']);
reference_load($api.'/ap-db.php',['ap_db_session_bound','ap_db_session_owner_user_id','ap_db_default_owner_user_id','ap_masto_object_id_lookup_keys','ap_masto_status_is_favourited','ap_masto_status_is_bookmarked','ap_masto_status_is_reblogged','ap_profile_defaults','ap_profile_get','ap_profile_supporter_awarded','ap_profile_authorized_badges','ap_profile_normalize_badges','ap_profile_badge_catalog','ap_fix_utf8','ap_profile_sanitize_https_url']);
reference_load($api.'/ap-db.php',['ap_db_owner_actor_id_for_user_id','ap_user_is_blocked','ap_is_muted_actor','ap_moderation_syntactic_refs','ap_moderation_path_segment_forms','ap_muted_words_match','ap_muted_words_normalize_haystack','ap_is_blocked_actor','ap_is_blocked_host','ap_is_globally_muted_actor','ap_actor_moderation_aliases','ap_actor_moderation_refs_match','ap_row_is_hidden','ap_row_is_blocked']);
reference_load($api.'/ap-db.php',['ap_ordinary_note_owned','ap_ordinary_ask_questions_muted','ap_visibility_ordinary_status_hidden']);
reference_load($api.'/ap-visibility.php',['ap_visibility_actor_hidden','ap_visibility_status_hidden','ap_visibility_status_audience_allowed']);
reference_load($api.'/ap-asks.php',['ap_ask_card_html','ap_ask_card_html_from_row','ap_ask_parse_compact_text','ap_ask_context_for_object']);
reference_load($api.'/ap-net-guard.php',['ap_net_ip_is_public']);
reference_load($api.'/ap-link-preview.php',['ap_masto_preview_card','ap_link_preview_html','ap_link_preview_youtube_id','ap_link_preview_normalize_url','ap_link_preview_extract_url','ap_link_preview_extract_all_urls','ap_link_preview_canonicalize_url','ap_link_preview_card_for_status_text','ap_link_preview_for_url','ap_link_preview_url_allowed','ap_link_preview_cache_get','ap_link_preview_cache_put','ap_link_preview_youtube_fallback','ap_link_preview_fetch_og','ap_link_preview_spotify_url','ap_link_preview_fetch_spotify']);
reference_load($api.'/ap-normalize.php',['ap_normalize_attach_ask','ap_normalize_apply_bsky_sensitivity','ap_normalize_scrub_quote_pollution','ap_normalize_status','ap_normalize_status_has_visible_body']);
foreach(['masto_favourites','masto_bookmarks','masto_reblogs'] as $table) ap_db()->exec('CREATE TABLE '.$table.'(owner_user_id INTEGER,status_id TEXT,object_id TEXT)');
ap_db()->exec('CREATE TABLE masto_statuses(note_id TEXT,content_text TEXT,local_id INTEGER,spoiler_text TEXT,sensitive INTEGER,visibility TEXT)');
ap_db()->exec('CREATE TABLE masto_polls(note_id TEXT,local_id INTEGER,multiple INTEGER,votes_count INTEGER,voters_count INTEGER,expires_at TEXT,options_json TEXT,voters_json TEXT)');
ap_db()->exec('CREATE TABLE fixture_status_cache(object_id TEXT PRIMARY KEY,payload TEXT)');
ap_db()->exec('CREATE TABLE link_preview_cards(url TEXT PRIMARY KEY,title TEXT,description TEXT,image TEXT,provider_name TEXT,provider_url TEXT,type TEXT,status TEXT,fetched_at TEXT,expires_at TEXT)');
ap_db()->exec('CREATE TABLE outbox_notes(id TEXT,raw_create_json TEXT,content TEXT,visibility TEXT,to_json TEXT,cc_json TEXT)');
ap_db()->exec('CREATE TABLE mentions(id INTEGER,object_id TEXT,ask_actor TEXT,ask_question TEXT,ask_answer TEXT,deleted_at TEXT,owner_user_id INTEGER,activity_type TEXT)');
ap_db()->exec("INSERT INTO masto_statuses VALUES('https://mkultra.monster/users/fixture/notes/456','Fixture plain post',456,'',0,'public')");
foreach(['fixture_mutes'=>'owner_user_id INTEGER,actor_id TEXT','fixture_blocks'=>'owner_user_id INTEGER,scope TEXT,value TEXT','fixture_words'=>'owner_user_id INTEGER,phrase TEXT','fixture_server_blocks'=>'scope TEXT,value TEXT,kind TEXT','ap_users'=>'id INTEGER,actor_id TEXT','events'=>'id INTEGER,object_id TEXT,type TEXT,action_taken TEXT,visibility TEXT,actor_id TEXT','following'=>'owner_actor_id TEXT,actor_id TEXT','followers'=>'owner_actor_id TEXT,actor_id TEXT'] as $table=>$columns) ap_db()->exec('CREATE TABLE '.$table.'('.$columns.')');
ap_db()->exec("INSERT INTO ap_users VALUES(1,'https://mkultra.monster/users/fixture'),(2,'https://mkultra.monster/users/other')");
ap_db()->exec("INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/fixture/notes/456','','Fixture plain post','public','[]','[]')");
ap_db()->exec('CREATE TABLE rss_feeds(id INTEGER,owner_user_id INTEGER,feed_url TEXT,site_url TEXT,title TEXT,favicon_url TEXT,enabled INTEGER)');
ap_db()->exec('CREATE TABLE rss_items(id INTEGER,feed_id INTEGER,guid TEXT,url TEXT,title TEXT,summary_text TEXT,image_url TEXT,published_at TEXT,ingested_at TEXT,mirror_note_id TEXT)');
ap_db()->exec("INSERT INTO rss_feeds VALUES(1,1,'https://article.test/feed','https://article.test','Fixture RSS','https://article.test/icon.png',1),(2,2,'https://private.test/feed','https://private.test','Private RSS','',1)");
ap_db()->exec("INSERT INTO rss_items VALUES(11,1,'one','https://article.test/rss-story','Fixture plain post','Fixture RSS summary','','2026-10-10T12:00:00Z','2026-10-10T12:00:00Z',NULL),(22,2,'two','https://private.test/rss-story','Other owner secret','','','2026-10-10T12:00:00Z','2026-10-10T12:00:00Z',NULL)");
reference_load($api.'/ap-rss.php',['ap_rss_migrate','ap_rss_item_by_id','ap_rss_parse_local_status_id','ap_rss_local_status_id','ap_rss_item_to_masto_status','ap_rss_display_summary','ap_rss_strip_reddit_boilerplate','ap_rss_display_title','ap_rss_item_is_media_forward','ap_rss_favicon_for_url','ap_rss_upgrade_image_url','ap_rss_clean_item_media','ap_rss_image_host_unusable']);
reference_load($api.'/ap-normalize.php',['ap_normalize_from_rss_item','ap_normalize_from_bsky_post']);
reference_load($api.'/ap-bsky.php',['ap_bsky_bookmark_owner_state','ap_bsky_bookmark_owner_state_set','ap_bsky_access_owner_register','ap_bsky_authenticated_viewer_response','ap_bsky_viewer_for_owner']);
reference_load($api.'/ap-bsky-urls.php',['ap_bsky_https_url_from_at_uri','ap_bsky_normalize_web_url','ap_bsky_actor_profile_url']);
reference_load($api.'/ap-bsky.php',['ap_bsky_post_is_sensitive','ap_bsky_sensitive_label_vals','ap_bsky_post_label_vals','ap_bsky_compact_label_list','ap_bsky_at_uri_from_any_url','ap_bsky_at_uri_from_https','ap_bsky_avatar_public_url','ap_bsky_avatar_cid_ok','ap_bsky_enrich_feed_item_authors','ap_bsky_enrich_author_view','ap_bsky_post_text','ap_bsky_post_image_urls','ap_bsky_post_external','ap_bsky_post_url','ap_bsky_hide_did_set']);
reference_load($api.'/ap-masto-entities.php',['ap_masto_bsky_trend_status','ap_masto_bsky_media_attachments','ap_masto_animated_media_from_url']);
ap_db()->exec('CREATE TABLE bsky_hide_dids(owner_user_id INTEGER,did TEXT)');
if(ap_rss_item_by_id(22,1)!==null || ap_rss_item_by_id(11,2)!==null || ap_rss_item_by_id(11,1)===null) throw new RuntimeException('actual RSS lookup owner isolation failed');
$profile = ap_profile_defaults('fixture');
$profile['attachment_json'] = json_encode($profile['attachment']); unset($profile['attachment']);
$profile['profile_badges'] = '[]';
$columns = implode(',',array_map(static fn($k)=>$k.' TEXT',array_keys($profile)));
ap_db()->exec('CREATE TABLE actor_profile('.$columns.')');
$values = array_map(static fn($v)=>is_bool($v) ? (int)$v : $v,array_values($profile));
ap_db()->prepare('INSERT INTO actor_profile VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute($values);
$status = ['id'=>'123','uri'=>'https://remote.test/users/alice/statuses/123','url'=>'https://remote.test/@alice/123','created_at'=>gmdate('c'),'content'=>'<p>Fixture plain post</p>','account'=>['id'=>'https://remote.test/users/alice','uri'=>'https://remote.test/users/alice','url'=>'https://remote.test/@alice','acct'=>'alice@remote.test','display_name'=>'Alice','avatar'=>'https://remote.test/avatar.png'],'visibility'=>'public','media_attachments'=>[],'mentions'=>[],'spoiler_text'=>'','sensitive'=>false,'favourites_count'=>3,'reblogs_count'=>2,'replies_count'=>1];
$cases = ['plain'=>$status,
 'cw_media'=>array_replace($status,['spoiler_text'=>'Custom warning','sensitive'=>true,'media_attachments'=>[['type'=>'image','url'=>'https://remote.test/photo.png','preview_url'=>'https://remote.test/thumb.png','description'=>'Fixture image alt']]]),
 'boost'=>array_replace($status,['id'=>'announce-7','content'=>'','reblog'=>$status]),
 'quote'=>array_replace($status,['quote'=>['state'=>'ready','quoted_status'=>$status]]),
 'private'=>array_replace($status,['visibility'=>'private']),
 'own'=>array_replace($status,['id'=>'456','uri'=>'https://mkultra.monster/users/fixture/notes/456','url'=>'https://mkultra.monster/users/fixture/notes/456','account'=>['id'=>'1','uri'=>'https://mkultra.monster/users/fixture','url'=>'https://mkultra.monster/users/fixture','acct'=>'fixture','display_name'=>'Fixture owner']]),
 'ask'=>array_replace($status,['vaak_ask'=>['question'=>'Fixture question?','asker_label'=>'Tester']]),
 'poll_open'=>array_replace($status,['uri'=>'https://remote.test/polls/open']),
 'poll_voted'=>array_replace($status,['uri'=>'https://remote.test/polls/voted']),
 'poll_expired'=>array_replace($status,['uri'=>'https://remote.test/polls/expired']),
 'link_preview'=>array_replace($status,['card'=>['url'=>'https://article.test/story','title'=>'Fixture story','description'=>'Fixture excerpt','image'=>'https://article.test/image.png']]),
 'sensitive_collapsed'=>array_replace($status,['sensitive'=>true]),
 'sensitive_auto_unblur'=>array_replace($status,['sensitive'=>true]),
 'cw_auto_unblur'=>array_replace($status,['sensitive'=>true,'spoiler_text'=>'Custom warning']),
 'saved_flags'=>$status,
 'rss'=>['id'=>'rss:11','uri'=>'rss:11']];
foreach(['denied_block','denied_word','denied_private','denied_direct','allowed_direct','denied_nested','denied_deleted_local'] as $caseName) $cases[$caseName]=$status;
$cases['denied_nested']=array_replace($status,['quote'=>['state'=>'ready','quoted_status'=>array_replace($status,['account'=>['uri'=>'https://blocked.test/users/nested']])]]);
$cases['denied_deleted_local']=array_replace($status,['uri'=>'https://mkultra.monster/users/fixture/notes/deleted']);
$cases['denied_rss_other_owner']=['id'=>'rss:22','uri'=>'rss:22'];
foreach(['denied_late_private_quote','denied_late_deleted_quote','denied_late_blocked_quote','allowed_late_quote'] as $caseName) $cases[$caseName]=array_replace($status,['quote'=>['state'=>'pending'],'quote_url'=>'https://quote.test/users/author/statuses/99']);
$cases['denied_late_deleted_quote']['quote_url']='https://mkultra.monster/users/deleted/notes/99';
$bskyPost=['uri'=>'at://did:plc:fixture/app.bsky.feed.post/abc123','cid'=>'fixture-cid','author'=>['did'=>'did:plc:fixture','handle'=>'alice.bsky.test','displayName'=>'BSky Alice','avatar'=>'https://bsky.test/avatar.png'],'record'=>['text'=>'Fixture plain post','createdAt'=>gmdate('c')],'viewer'=>['bookmarked'=>false],'likeCount'=>3,'repostCount'=>2];
$cases['bsky_normalized']=['post'=>$bskyPost];
$cases['bsky_sensitive']=['post'=>array_replace($bskyPost,['labels'=>[['val'=>'porn']]])];
$cases['bsky_fallback']=['post'=>array_replace($bskyPost,['author'=>['handle'=>'alice.bsky.test','displayName'=>'BSky Alice','avatar'=>'https://bsky.test/avatar.png']])];
$cases['denied_bsky_native']=['post'=>$bskyPost];
$cases['denied_bsky_nested']=['post'=>array_replace($bskyPost,['embed'=>['record'=>['author'=>['did'=>'did:plc:blocked']]]])];
for($i=1;$i<=4;$i++) $cases['preview_budget'.$i]=array_replace($status,['content'=>'<p>Fixture plain post https://1.1.1.1/failure'.$i.'</p>']);
foreach (['open','voted','expired'] as $poll) ap_db()->prepare('INSERT INTO masto_polls VALUES(?,?,?,?,?,?,?,?)')->execute(['https://remote.test/polls/'.$poll,9,$poll==='open'?1:0,3,2,$poll==='expired'?'2000-01-01T00:00:00Z':'2099-01-01T00:00:00Z',json_encode([['title'=>'Choice A','votes_count'=>2],['title'=>'Choice B','votes_count'=>1]]),$poll==='voted'?'["https://mkultra.monster/users/fixture"]':'[]']);
$captures = [];
foreach ($cases as $name=>$case) {
 ap_db()->exec('DELETE FROM bsky_hide_dids');
 unset($GLOBALS['ap_bsky_hide_did_cache']);
 if($name==='denied_bsky_native') ap_db()->exec("INSERT INTO bsky_hide_dids VALUES(1,'did:plc:fixture')");
 if($name==='denied_bsky_nested') ap_db()->exec("INSERT INTO bsky_hide_dids VALUES(1,'did:plc:blocked')");
 foreach(['fixture_mutes','fixture_blocks','fixture_words','fixture_server_blocks','mentions'] as $table) ap_db()->exec('DELETE FROM '.$table);
 if($name==='denied_block') ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','https://remote.test/users/alice')");
 if($name==='denied_nested') ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','https://blocked.test/users/nested')");
 if($name==='denied_word') ap_db()->exec("INSERT INTO fixture_words VALUES(1,'fixture plain')");
 ap_db()->exec('DELETE FROM fixture_status_cache');
 ap_db()->exec('DELETE FROM events'); ap_db()->exec('DELETE FROM following');
 if($name==='private') {
  ap_db()->prepare('INSERT INTO events VALUES(1,?,"Create","log","private",?)')->execute([$case['uri'],$case['account']['uri']]);
  ap_db()->prepare('INSERT INTO following VALUES(?,?)')->execute([vaak_actor_id(),$case['account']['uri']]);
 }
 if(in_array($name,['denied_private','denied_direct','allowed_direct'],true)) ap_db()->prepare('INSERT INTO events VALUES(1,?,"Create","log",?,?)')->execute([$case['uri'],$name==='denied_private'?'private':'direct',$case['account']['uri']]);
 if($name==='allowed_direct') ap_db()->prepare('INSERT INTO mentions(id,object_id,deleted_at,owner_user_id,activity_type) VALUES(1,?,NULL,1,"Create")')->execute([$case['uri']]);
 if(str_contains($name,'late_')) {
  $quoted=array_replace($status,['id'=>'99','uri'=>$case['quote_url'],'url'=>$case['quote_url'],'account'=>['acct'=>'quote@quote.test','uri'=>'https://quote.test/users/author'],'content'=>'<p>Fixture recovered quote</p>']);
  ap_db()->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$case['quote_url'],json_encode($quoted)]);
  if($name==='denied_late_private_quote') ap_db()->prepare('INSERT INTO events VALUES(1,?,"Create","log","private",?)')->execute([$case['quote_url'],$quoted['account']['uri']]);
  if($name==='denied_late_blocked_quote') ap_db()->prepare('INSERT INTO fixture_blocks VALUES(1,"actor",?)')->execute([$quoted['account']['uri']]);
 }
 if($name==='preview_budget1') { $GLOBALS['admin_status_link_preview_budget']=3; $GLOBALS['preview_http_requests']=[]; $GLOBALS['preview_warm_requests']=[]; }
 unset($GLOBALS['ap_masto_flag_memo']);
 ap_db()->exec("UPDATE actor_profile SET auto_unblur_sensitive=" . (int)in_array($name,['sensitive_auto_unblur','cw_auto_unblur'],true));
 foreach(['masto_favourites','masto_bookmarks','masto_reblogs'] as $table) ap_db()->exec('DELETE FROM '.$table);
 if($name==='saved_flags') foreach(['masto_favourites','masto_bookmarks','masto_reblogs'] as $table) ap_db()->prepare('INSERT INTO '.$table.' VALUES(?,?,?)')->execute([1,'123',$case['uri']]);
 $renderedAt=time();$GLOBALS['fixture_render_clock']=$renderedAt;
 ob_start();
 try { if(isset($case['post'])) admin_render_bsky_feed_item($case,'following','bookmarks'); else admin_render_library_status_card($case,'bookmarks'); $html = ob_get_clean(); }
 catch (Throwable $e) { ob_end_clean(); fwrite(STDERR,'capture incomplete '.$name.': '.$e->getMessage()."\n"); exit(1); }
 $expectedDenied=str_starts_with($name,'denied_');
 if($expectedDenied && trim($html)!=='') throw new RuntimeException('denied card rendered '.$name);
 if (!$expectedDenied && (!str_contains($html, 'Fixture plain post') || !str_contains($html, 'fixture-csrf-token') || (!str_contains($html, 'bookmark_status') && !str_contains($html, 'unbookmark_status') && !str_contains($html, 'data-bsky-action="bookmark"')))) throw new RuntimeException('missing actual card content/action transport: ' . $name);
 if ($name==='bsky_sensitive' && !str_contains($html,'cw-gate')) throw new RuntimeException('native Bluesky label CW missing');
 if ($name === 'cw_media' && (!str_contains($html, 'Custom warning') || !str_contains($html, 'https://remote.test/photo.png'))) throw new RuntimeException('missing CW/media feature');
 if ($name==='own' && (!str_contains($html,'delete') || !str_contains($html,'edit'))) throw new RuntimeException('own edit/delete missing');
 if ($name==='ask' && !str_contains($html,'Fixture question?')) throw new RuntimeException('Ask painter missing');
 if ($name==='poll_open' && !str_contains($html,'poll_vote')) throw new RuntimeException('poll vote missing');
 if (in_array($name,['poll_voted','poll_expired'],true) && str_contains($html,'value="poll_vote"')) throw new RuntimeException('closed/voted poll exposes vote');
 if ($name==='link_preview' && !str_contains($html,'Fixture story')) throw new RuntimeException('preview painter missing');
 if ($name==='sensitive_collapsed' && !str_contains($html,'cw-gate')) throw new RuntimeException('sensitive collapse missing');
 if ($name==='sensitive_auto_unblur' && str_contains($html,'cw-gate')) throw new RuntimeException('saved sensitive preference ignored');
 if ($name==='cw_auto_unblur' && !str_contains($html,'cw-gate')) throw new RuntimeException('explicit CW must remain');
 if ($name==='saved_flags' && (!str_contains($html,'unfavourite_status') || !str_contains($html,'unbookmark_status') || !str_contains($html,'unreblog_status'))) throw new RuntimeException('durable saved flags missing');
 if ($name==='rss' && (!str_contains($html,'rss_remove_feed') || !str_contains($html,'rss_quote'))) throw new RuntimeException('RSS dedicated actions missing');
 if($name==='preview_budget4' && (count($GLOBALS['preview_http_requests'])!==3 || count($GLOBALS['preview_warm_requests'])!==4 || $GLOBALS['admin_status_link_preview_budget']!==0)) throw new RuntimeException('actual preview budget/async fallback mismatch');
 $captures[$name] = ['normalized_status'=>isset($case['post'])?ap_normalize_from_bsky_post($case['post']):null,'status'=>$case,'expected_admitted'=>!$expectedDenied,'context'=>[
  'rendered_at'=>$renderedAt,'owner_id'=>(int)$GLOBALS['vaak_owner_id'],'owner_actor'=>vaak_actor_id(),'owner_actor_key'=>$GLOBALS['vaak_actor_key'],'return_view'=>'bookmarks',
  // Pure production helper output, captured independently of painted HTML.
  // Action components receive an already canonicalized link from the caller.
  'canonical_remote_link'=>['object'=>(string)($case['uri']??''),'url_hint'=>$case['url']??null,'href'=>admin_remote_object_href((string)($case['uri']??''),$case['url']??null)],
  'profile'=>ap_profile_get('fixture'),
  'saved_favourites'=>ap_db()->query('SELECT * FROM masto_favourites')->fetchAll(),
  'saved_bookmarks'=>ap_db()->query('SELECT * FROM masto_bookmarks')->fetchAll(),
  'saved_reblogs'=>ap_db()->query('SELECT * FROM masto_reblogs')->fetchAll(),
  'poll_rows'=>ap_db()->query('SELECT * FROM masto_polls')->fetchAll(),
  'rss_item_rows'=>ap_db()->query('SELECT * FROM rss_items')->fetchAll(),'rss_feed_rows'=>ap_db()->query('SELECT * FROM rss_feeds')->fetchAll(),
  'local_status_rows'=>ap_db()->query('SELECT * FROM masto_statuses')->fetchAll(),
  'ask_metadata'=>$case['vaak_ask']??null,'csrf_token'=>ap_auth_csrf_token(),'preview_http_requests'=>$GLOBALS['preview_http_requests']??[],'preview_warm_requests'=>$GLOBALS['preview_warm_requests']??[],'preview_budget_remaining'=>$GLOBALS['admin_status_link_preview_budget']??null,
  'resolved_author_avatar'=>admin_actor_avatar_url((string)($case['account']['uri']??'')),
  'cached_status_payloads'=>ap_db()->query('SELECT * FROM fixture_status_cache')->fetchAll(),
  'feature_modules_loaded'=>['actual_admin_painters','actual_profile_get','actual_saved_interaction_sql','actual_ask_painters','actual_link_preview_painter','actual_visibility_audience','actual_moderation_predicates','actual_bsky_normalizer_and_legacy_painter','actual_rss_lookup_normalizer_painter'],
  'admission_verified'=>true,'moderation_predicates_loaded'=>true,
  'audience_events'=>ap_db()->query('SELECT * FROM events')->fetchAll(),'audience_outbox'=>ap_db()->query('SELECT * FROM outbox_notes')->fetchAll(),'following'=>ap_db()->query('SELECT * FROM following')->fetchAll(),
  'native_bsky_hide_dids'=>ap_db()->query('SELECT * FROM bsky_hide_dids')->fetchAll(),
  'moderation_mutes'=>ap_db()->query('SELECT * FROM fixture_mutes')->fetchAll(),'moderation_blocks'=>ap_db()->query('SELECT * FROM fixture_blocks')->fetchAll(),'moderation_words'=>ap_db()->query('SELECT * FROM fixture_words')->fetchAll(),'server_moderation'=>ap_db()->query('SELECT * FROM fixture_server_blocks')->fetchAll()
 ],'html'=>$html];
}
unset($GLOBALS['fixture_render_clock']);
echo json_encode(['coverage'=>'Actual PHP Library, Mastodon, Bluesky and RSS painters; real SQLite saved flags, profile preference and audience/moderation predicates; cache/HTTP/DDL/session fixture transports; controller, browser and full PostgreSQL parity remain separate gates', 'case_count'=>count($captures), 'captures'=>$captures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
