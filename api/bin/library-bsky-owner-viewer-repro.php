<?php
declare(strict_types=1);
/** Actual global post lookup -> actual normalized PHP painter, two owners.
 * Fixture replaces only schema migration transport; no live/network mutation.
 */
function ap_bsky_posts_migrate(): void {}
ob_start();
require __DIR__.'/library-bsky-enabled-reference-capture.php';
$reference=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_post_item_by_uri']);
ap_db()->exec('CREATE TABLE bsky_posts(bsky_uri TEXT PRIMARY KEY,raw_json TEXT,reason_json TEXT,author_did TEXT,author_handle TEXT,author_display TEXT,author_avatar TEXT)');
$post=$reference['captures']['bsky_normalized']['status']['post'];
$post['viewer']['like']='at://did:plc:owner-one/app.bsky.feed.like/owner-one-like';
$post['viewer']['repost']='at://did:plc:owner-one/app.bsky.feed.repost/owner-one-repost';
ap_db()->prepare('INSERT INTO bsky_posts VALUES(?,?,NULL,?,?,?,?)')->execute([$post['uri'],json_encode($post,JSON_THROW_ON_ERROR),$post['author']['did'],$post['author']['handle'],$post['author']['displayName'],$post['author']['avatar']]);
$GLOBALS['vaak_owner_id']=2;
$GLOBALS['vaak_user']=['id'=>2,'username'=>'other'];
$GLOBALS['vaak_actor_key']='other';
$GLOBALS['fixture_bsky_session']=['did'=>'did:plc:owner-two'];
$item=ap_bsky_post_item_by_uri($post['uri']);
if(!is_array($item)) throw new RuntimeException('actual global lookup did not return row');
ob_start();admin_render_bsky_feed_item($item,'following','bookmarks');$html=(string)ob_get_clean();
$leaks=str_contains($html,'data-record-uri="'.$post['viewer']['like'].'"') && str_contains($html,'data-record-uri="'.$post['viewer']['repost'].'"');
if ($leaks) throw new RuntimeException('shared cache leaked another owner engagement records');
$checks = [];
foreach (['foreign'=>['did:plc:owner-one',false],'same_owner_unknown'=>['did:plc:owner-two',true]] as $name=>[$recordDid,$expected]) {
    $viewer=['like'=>'at://'.$recordDid.'/app.bsky.feed.like/external-unknown','repost'=>'at://'.$recordDid.'/app.bsky.feed.repost/external-unknown','blockedBy'=>true,'metadata'=>['policy'=>'retained']];
    $filtered=ap_bsky_viewer_for_owner($viewer,2);
    $has=isset($filtered['like'],$filtered['repost']);
    if($has!==$expected || ($filtered['blockedBy']??null)!==true || ($filtered['metadata']??null)!==$viewer['metadata'])throw new RuntimeException('owner engagement/policy control failed '.$name);
    $cached=$post;$cached['viewer']=$viewer;
    ap_db()->prepare('UPDATE bsky_posts SET raw_json=? WHERE bsky_uri=?')->execute([json_encode($cached,JSON_THROW_ON_ERROR),$post['uri']]);
    $fetched=ap_bsky_post_item_by_uri($post['uri']);
    if(isset($fetched['post']['viewer']['like'],$fetched['post']['viewer']['repost'])!==$expected)throw new RuntimeException('actual cache owner control failed '.$name);
    // blockedBy remains source admission evidence; clear just this fixture flag
    // when checking visible engagement transport through the actual painter.
    $fetched['post']['viewer']['blockedBy']=false;
    ob_start();admin_render_bsky_feed_item($fetched,'following','bookmarks');$painted=(string)ob_get_clean();
    $shown=str_contains($painted,'data-record-uri="'.$viewer['like'].'"')&&str_contains($painted,'data-record-uri="'.$viewer['repost'].'"');
    if($shown!==$expected)throw new RuntimeException('actual painter owner control failed '.$name);
    $checks[$name]=['cache_records_preserved'=>$has,'render_records_present'=>$shown,'policy_fields_preserved'=>true];
}
foreach (['like'=>'app.bsky.feed.repost','repost'=>'app.bsky.feed.like'] as $key=>$wrong) {
    if(isset(ap_bsky_viewer_for_owner([$key=>'at://did:plc:owner-two/'.$wrong.'/wrong'],2)[$key]))throw new RuntimeException('wrong record collection accepted');
}
echo json_encode(['owner'=>2,'session_did'=>'did:plc:owner-two','global_viewer_did'=>'did:plc:owner-one','cross_owner_engagement_rendered'=>$leaks,'controls'=>$checks,'html'=>$html,'promotion_allowed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
