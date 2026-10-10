#!/usr/bin/env php
<?php
declare(strict_types=1);
// Exercise the actual PHP quote serializer and connected-account lookup without bootstrap/network.
$source = (string) file_get_contents(dirname(__DIR__) . '/ap-masto-entities.php');
foreach (['ap_masto_quote_entity', 'ap_masto_notif_bsky_owner_user_id'] as $name) {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\([^\n]*\).*?^\}/ms', $source, $m)) throw new RuntimeException('missing ' . $name);
    eval($m[0]);
}
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_masto_lookup_status_by_object_url(...$args): ?array { return null; }
function ap_db_owner_actor_id_for_user_id(int $owner): string { return 'https://mkultra.monster/users/alice'; }
function ap_masto_account_for_local_url(?string $url): array {
    if ($url !== 'https://mkultra.monster/users/alice') throw new RuntimeException('wrong local owner');
    return ['avatar'=>'https://example.test/local.webp','avatar_static'=>'https://example.test/local.webp','display_name'=>'Alice'];
}
function ap_bsky_post_preview_from_url(string $url, int $owner, bool $fetch): array {
    if ($fetch) throw new RuntimeException('unexpected network');
    return ['handle'=>$GLOBALS['fixture_handle'],'url'=>$url,'text'=>'A quote','display'=>'Old name'];
}
$db=new PDO('sqlite::memory:');$GLOBALS['fixture_db']=$db;
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE bsky_sessions(owner_user_id INTEGER,handle TEXT,did TEXT);
INSERT INTO bsky_sessions VALUES(201,'alice.bsky.test','did:plc:alice');");
$n=0;
$check=function(bool $ok,string $label) use (&$n): void {if(!$ok) throw new RuntimeException($label);$n++;};
foreach (['alice.bsky.test','did:plc:alice'] as $ref) {
    $GLOBALS['fixture_handle']='alice.bsky.test';
    $url='https://bsky.app/profile/'.$ref.'/post/abc';
    $q=ap_masto_quote_entity($url)['quoted_status'];
    $check($q['account']['avatar']==='https://example.test/local.webp','saved avatar');
    $check($q['account']['avatar_static']===$q['account']['avatar'],'static avatar');
    $check($q['account']['display_name']==='Alice','saved display');
    $check($q['account']['acct']==='alice.bsky.test','Bluesky identity preserved');
    $check($q['uri']===$url,'post identity preserved');
}
$GLOBALS['fixture_handle']='unlinked.test';
$q=ap_masto_quote_entity('https://bsky.app/profile/unlinked.test/post/xyz')['quoted_status'];
$check(!isset($q['account']['avatar']),'unlinked author not replaced');
$check(ap_masto_quote_entity('https://bsky.app/profile/alice.bsky.test/post/x',0,false,false)['state']==='pending','authorization retained');
printf("quote-avatar-behavior: PASS (%d checks)\n",$n);
