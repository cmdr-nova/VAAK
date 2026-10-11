<?php
declare(strict_types=1);
/** Independent actual PHP request-provenance checks; no HTTP or DB writes. */
require __DIR__.'/migration-reference-functions.php';
$GLOBALS['review_sessions']=[1=>['did'=>'did:plc:one'],2=>['did'=>'did:plc:two']];
function ap_bsky_session_row(int $owner): ?array {return $GLOBALS['review_sessions'][$owner]??null;}
reference_load(dirname(__DIR__).'/ap-bsky.php',[
    'ap_bsky_bookmark_owner_state','ap_bsky_bookmark_owner_state_set',
    'ap_bsky_access_owner_register','ap_bsky_authenticated_viewer_response',
    'ap_bsky_viewer_for_owner',
]);
$uri='at://did:plc:post/app.bsky.feed.post/one';$checks=0;
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
$viewer=['bookmarked'=>true,'bookmark'=>true,'blockedBy'=>true,'like'=>'at://did:plc:one/app.bsky.feed.like/external'];
$raw=ap_bsky_viewer_for_owner($viewer,1,$uri);
check(!isset($raw['bookmarked'],$raw['bookmark']),'unproven shared bookmark stripped');
check(($raw['blockedBy']??false)===true,'policy preserved');
check(($raw['like']??'')===$viewer['like'],'legitimate owner external record retained');
ap_bsky_access_owner_register('private-owner-one-token',1,'did:plc:one');
$post=['uri'=>$uri,'viewer'=>['bookmarked'=>true]];
ap_bsky_authenticated_viewer_response(['posts'=>[$post]],'unknown-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($uri,1)===null,'unregistered token cannot establish provenance');
ap_bsky_authenticated_viewer_response(['posts'=>[$post]],'private-owner-one-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($uri,1)===true,'authenticated PostView true retained');
check(ap_bsky_bookmark_owner_state($uri,2)===null,'other owner cannot inherit bookmark');
$unknown=$post;$unknown['viewer']=['like'=>$viewer['like']];
ap_bsky_authenticated_viewer_response(['posts'=>[$unknown]],'private-owner-one-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($uri,1)===true,'omitted optional key preserves proven true');
$wrongUri='at://did:plc:post/app.bsky.feed.post/wrong-endpoint';
$wrongPost=['uri'=>$wrongUri,'viewer'=>['bookmarked'=>true]];
ap_bsky_authenticated_viewer_response(['feed'=>[['post'=>$wrongPost]],'posts'=>[$wrongPost],'thread'=>['post'=>$wrongPost],'bookmarks'=>[['item'=>$wrongPost]]],'private-owner-one-token','com.atproto.repo.getRecord');
check(ap_bsky_bookmark_owner_state($wrongUri,1)===null,'disallowed endpoint cannot establish state from arbitrary response-shaped fields');
foreach (['https://bsky.app/profile/example.test/post/test', 'at://did:plc:post/app.bsky.feed.like/wrong', 'at://did:plc:post/app.bsky.feed.post/query?x=1', 'at://did:plc:post/app.bsky.feed.post/fragment#x', ''] as $invalidUri) {
    ap_bsky_authenticated_viewer_response(['posts'=>[['uri'=>$invalidUri,'viewer'=>['bookmarked'=>true]]]],'private-owner-one-token','app.bsky.feed.getPosts');
    check(ap_bsky_bookmark_owner_state($invalidUri,1)===null,'invalid bookmark target cannot establish state '.$invalidUri);
}
$nonBooleanUri='at://did:plc:post/app.bsky.feed.post/non-boolean';
ap_bsky_authenticated_viewer_response(['posts'=>[['uri'=>$nonBooleanUri,'viewer'=>['bookmarked'=>1,'bookmark'=>'true']]]],'private-owner-one-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($nonBooleanUri,1)===null,'non-boolean optional bookmark fields remain unknown');
$foreign=ap_bsky_viewer_for_owner(['like'=>'at://did:plc:two/app.bsky.feed.like/foreign','repost'=>'at://did:plc:one/app.bsky.feed.like/wrong-collection','blockedBy'=>true],1,$uri);
check(!isset($foreign['like'],$foreign['repost'])&&$foreign['blockedBy']===true,'foreign and wrong collection actions removed while policy retained');
check(isset(ap_bsky_viewer_for_owner($viewer,1,$uri)['bookmarked']),'owner action display restores current bookmark');
check(!isset(ap_bsky_viewer_for_owner($viewer,2,$uri)['bookmarked']),'foreign action display absent');
// Current false is explicit authority and blocks lower-priority warm cache.
$post['viewer']['bookmarked']=false;
ap_bsky_authenticated_viewer_response(['posts'=>[$post]],'private-owner-one-token','app.bsky.feed.getPosts');
ap_bsky_bookmark_owner_state_set($uri,1,true,1);
check(ap_bsky_bookmark_owner_state($uri,1)===false,'explicit authenticated false wins warm cache true');
ap_bsky_bookmark_owner_state_set($uri,1,false,3);
$post['viewer']['bookmarked']=true;
ap_bsky_authenticated_viewer_response(['posts'=>[$post]],'private-owner-one-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($uri,1)===false,'current mutation false wins late warm response true');
ap_bsky_bookmark_owner_state_set($uri,1,true,3);
check(ap_bsky_bookmark_owner_state($uri,1)===true,'later same-priority mutation true wins');
// Arbitrary record JSON must never act as a PostView policy source.
$recordUri='at://did:plc:post/app.bsky.feed.post/arbitrary';
ap_bsky_authenticated_viewer_response(['record'=>['uri'=>$recordUri,'viewer'=>['bookmarked'=>true]],'posts'=>[['record'=>['uri'=>$recordUri,'viewer'=>['bookmarked'=>true]]]]],'private-owner-one-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($recordUri,1)===null,'record payload cannot forge request provenance');
// Authenticated nested thread PostViews are legitimate current state.
$threadUri='at://did:plc:post/app.bsky.feed.post/thread';
ap_bsky_authenticated_viewer_response(['thread'=>['replies'=>[['post'=>['uri'=>$threadUri,'viewer'=>['bookmarked'=>true]]]]]],'private-owner-one-token','app.bsky.feed.getPostThread');
check(ap_bsky_bookmark_owner_state($threadUri,1)===true,'nested thread PostView provenance retained');
$GLOBALS['review_sessions'][1]=['did'=>'did:plc:replacement'];
check(ap_bsky_bookmark_owner_state($uri,1)===null,'changed connection invalidates old bookmark authority');
ap_bsky_authenticated_viewer_response(['posts'=>[$post]],'private-owner-one-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($uri,1)===null,'old token cannot restore after connection change');
$GLOBALS['review_sessions'][1]=['did'=>'did:plc:one'];
ap_bsky_access_owner_register('collision-token',1,'did:plc:one');
ap_bsky_access_owner_register('collision-token',2,'did:plc:two');
$collisionUri='at://did:plc:post/app.bsky.feed.post/collision';
ap_bsky_authenticated_viewer_response(['posts'=>[['uri'=>$collisionUri,'viewer'=>['bookmarked'=>true]]]],'collision-token','app.bsky.feed.getPosts');
check(ap_bsky_bookmark_owner_state($collisionUri,1)===null&&ap_bsky_bookmark_owner_state($collisionUri,2)===null,'ambiguous token ownership establishes no provenance');
echo "library-bookmark-provenance-independent: PASS ($checks actual-helper controls; actions/network persistence excluded)\n";
