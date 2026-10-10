#!/usr/bin/env php
<?php
declare(strict_types=1);
// Execute the canonical save function against SQLite fixtures. Stub only IO and
// text helpers (ASCII fixtures), never load production bootstrap/configuration.
$source = (string) file_get_contents(dirname(__DIR__) . '/ap-db.php');
function fixture_load_function(string $source, string $name): void {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\([^\n]*\).*?^\}/ms', $source, $m)) throw new RuntimeException('missing ' . $name);
    eval($m[0]);
}
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_profile_get(string $key): array {
    $st=ap_db()->prepare('SELECT * FROM actor_profile WHERE actor_key=?'); $st->execute([$key]);
    $row=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    $row['attachment']=json_decode($row['attachment_json'] ?? '[]',true);
    return $row;
}
function ap_fix_utf8(?string $s): string { return $s ?? ''; }
if (!function_exists('mb_strlen')) { function mb_strlen(string $s): int { return strlen($s); } }
function ap_profile_verify_attachments(array $a, bool $fetch, string $key): array { return $a; }
function ap_alias_also_known_as_urls(string $key): array { return []; }
function ap_db_now(): string { return '2026-10-10T12:00:00Z'; }
function ap_redis_delete(string ...$keys): void { foreach ($keys as $k) $GLOBALS['fixture_deletes'][]=$k; }
function ap_profile_export_public_cache(string $key): void {
    if (!in_array('vaak:profile:v1:' . hash('sha256',$key),$GLOBALS['fixture_deletes'],true)) throw new RuntimeException('export before invalidation');
}
function ap_timeline_cache_invalidate_owner(int $owner): void { $GLOBALS['fixture_ranked'][]=$owner; }
function ap_timeline_home_hydrate_invalidate_owner(int $owner): void { $GLOBALS['fixture_hydrated'][]=$owner; }
foreach (['ap_profile_save_unlocked','ap_profile_sanitize_https_url','ap_profile_normalize_summary_html','ap_profile_normalize_badges','ap_profile_badge_catalog','ap_actor_as2_document','ap_as2_federation_plaintext','ap_cmdr_actor_attachments_with_policies','ap_webmention_target_enabled','ap_profile_collection_consent'] as $name) fixture_load_function($source,$name);
$db=new PDO('sqlite::memory:'); $GLOBALS['fixture_db']=$db;
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$flags=['manually_approves','discoverable','indexable','collection_consent','vanity_verified','auto_follow_back','anti_ai_marker','auto_unblur_sensitive','auto_delete_posts_7d','automated','hide_profile_replies','hide_profile_boosts','algorithm_enabled','downranking_enabled','asks_enabled','webmentions_enabled'];
$db->exec('CREATE TABLE actor_profile (actor_key TEXT PRIMARY KEY,name TEXT,summary TEXT,attachment_json TEXT,icon_url TEXT,image_url TEXT,reply_policy TEXT,quote_policy TEXT,forum_signature TEXT,profile_badges TEXT,updated_at TEXT,' . implode(',',array_map(static fn($f)=>"$f INTEGER NOT NULL DEFAULT 0",$flags)) . ')');
$db->exec("CREATE TABLE ap_users (id INTEGER,actor_key TEXT,actor_id TEXT);
 INSERT INTO ap_users VALUES (201,'alice','https://mkultra.monster/users/alice'),(202,'bob','https://mkultra.monster/users/bob');
 INSERT INTO actor_profile (actor_key,name,summary,attachment_json,profile_badges,reply_policy,quote_policy,forum_signature) VALUES ('alice','Alice','<p>Bio</p>','[]','[]','followers','nobody','Keep this signature'),('bob','Bob','<p>Other</p>','[]','[]','anyone','anyone','Other signature');");
$GLOBALS['fixture_deletes']=[]; $GLOBALS['fixture_ranked']=[]; $GLOBALS['fixture_hydrated']=[];
$count=0;
$check=static function(bool $ok,string $label) use (&$count): void { if (!$ok) throw new RuntimeException($label); $count++; };
$base=['name'=>'Alice','summary'=>'<p>Bio</p>'];
$check(ap_profile_save_unlocked($base+['asks_enabled'=>true],'alice')['ok'],'partial save');
$p=ap_profile_get('alice');
foreach ($flags as $flag) $check((bool)$p[$flag]===($flag==='asks_enabled'),'preserve '.$flag);
$check($p['forum_signature']==='Keep this signature','preserve signature');
$check($p['reply_policy']==='followers' && $p['quote_policy']==='nobody','preserve interaction policies');
$check(ap_profile_save_unlocked($base+['algorithm_enabled'=>true],'alice')['ok'],'enable algorithm');
$check($GLOBALS['fixture_ranked']===[201] && $GLOBALS['fixture_hydrated']===[201],'invalidate correct owner caches');
$check(ap_profile_save_unlocked($base+['webmentions_enabled'=>true],'alice')['ok'],'other partial save');
$check((bool)ap_profile_get('alice')['algorithm_enabled'],'preserve algorithm');
$check(count($GLOBALS['fixture_ranked'])===1,'no unnecessary invalidation');
$check(ap_profile_save_unlocked($base+['discoverable'=>true,'indexable'=>true,'collection_consent'=>true],'alice')['ok'],'explicit consent');
foreach (['discoverable','indexable','collection_consent'] as $flag) $check((bool)ap_profile_get('alice')[$flag],'enable '.$flag);
$check(ap_profile_get('bob')['name']==='Bob' && !(bool)ap_profile_get('bob')['algorithm_enabled'],'owner isolation');
$check(ap_profile_save_unlocked($base+['algorithm_enabled'=>false],'alice')['ok'],'disable algorithm');
$check($GLOBALS['fixture_ranked']===[201,201],'invalidate on both transitions');
$check(ap_profile_save_unlocked($base+['discoverable'=>false,'collection_consent'=>false,'automated'=>true],'alice')['ok'],'save actor preferences');
$actor=ap_actor_as2_document('alice','fixture-public-key');
$check($actor['type']==='Service' && $actor['bot']===true,'federated bot type');
$check($actor['discoverable']===false,'directory opt-out advertised');
$check($actor['interactionPolicy']['canFeature']['automaticApproval']===['https://mkultra.monster/users/alice'],'collection consent advertised');
$check(!ap_profile_collection_consent('alice'),'collection consent gate');
$check(ap_webmention_target_enabled('https://mkultra.monster/users/alice/notes/abcdef'),'webmentions enabled');
$check(!ap_webmention_target_enabled('https://mkultra.monster/users/bob/notes/abcdef'),'webmentions disabled for other owner');
$check(ap_profile_save_unlocked($base+['automated'=>false],'alice')['ok'],'unset automated');
$check(ap_actor_as2_document('alice','fixture-public-key')['type']==='Person','restore person type');
// Seven-day retention query executes only against opted-in own namespaces.
$db->exec("CREATE TABLE masto_statuses (local_id INTEGER,note_id TEXT,published TEXT);
 INSERT INTO masto_statuses VALUES (1,'https://mkultra.monster/users/alice/notes/old','2026-10-01'),(2,'https://mkultra.monster/users/alice/notes/new','2026-10-09'),(3,'https://mkultra.monster/users/bob/notes/old','2026-10-01');");
if (!preg_match('/SELECT local_id, note_id FROM masto_statuses.*?LIMIT 50/s', (string)file_get_contents(dirname(__DIR__) . '/ap-maintain.php'), $retentionSql)) throw new RuntimeException('retention SQL missing');
$st=$db->prepare($retentionSql[0]);
$st->execute(['https://mkultra.monster/users/alice/notes/','https://mkultra.monster/users/alice/notes/','2026-10-03']);
$check($st->fetchAll(PDO::FETCH_COLUMN)===[1],'retention age and owner');
$db->exec("INSERT INTO masto_statuses VALUES (4,'https://mkultra.monster/users/alice_1/notes/old','2026-10-01'),(5,'https://mkultra.monster/users/alicex1/notes/old','2026-10-01');");
$st->execute(['https://mkultra.monster/users/alice_1/notes/','https://mkultra.monster/users/alice_1/notes/','2026-10-03']);
$check($st->fetchAll(PDO::FETCH_COLUMN)===[4],'retention underscore is literal');
echo "settings-behavior: PASS ($count checks; isolated SQLite, no network)\n";
