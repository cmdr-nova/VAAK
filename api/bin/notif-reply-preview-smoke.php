<?php
declare(strict_types=1);
/** Actual producer teaser helper + paragraph formatter + notification stripper.
 * Empty actor discovery is a fixture boundary; no live bootstrap/network/PG.
 */
$api=getenv('VAAK_NOTIF_PREVIEW_API')?:dirname(__DIR__);
if (!function_exists('reference_load')) require __DIR__.'/migration-reference-functions.php';
stream_wrapper_unregister('http');stream_wrapper_unregister('https');
reference_load($api.'/ap-masto-entities.php',['ap_notification_projection_payload','ap_notification_projection_item','ap_notification_projection_read','ap_masto_notifications_list_redis_key','ap_masto_reply_parent_preview','ap_masto_content_with_mentions','ap_masto_repair_at_hash_tags','ap_masto_unglue_bare_handles','ap_masto_unglue_host_mentions','ap_masto_resolve_bare_username','ap_masto_linkify_hashtags_escaped','ap_masto_tag_url']);
reference_load($api.'/ap-admin.php',['h','admin_html_to_plain','admin_notif_strip_reply_context_plain','admin_notif_status_strip_reply_bake']);
$checks=0;
function preview_check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);$GLOBALS['checks']++;}
$parent="First parent paragraph.\n\nSecond parent paragraph with enough text to reproduce a retained teaser continuation.";
$reply="Authored reply about the subject.\n\n↩ Authored paragraph starts with an arrow.\n\nSecond parent paragraph is also legitimate author text here.";
// The actual old producer cut preserved blank lines. Actual formatter/stripper
// reproduce the observed retained second parent paragraph; no expected-string substitution.
$legacy=ap_masto_content_with_mentions('↩ '.mb_substr($parent,0,140)."\n\n".$reply);
$old=admin_notif_status_strip_reply_bake(['content'=>$legacy['content']]);
preview_check(str_contains($old['content'],'Second parent paragraph with enough text'),'old producer/strip mismatch reproduced');
$expected=ap_masto_content_with_mentions($reply)['content'];
foreach([$parent,str_replace("\n","\r\n",$parent)," Unicode é parent\tpreview\n\nnext line ",str_repeat('界',150), 'Encoded&#10;&#10;parent continuation&#13;&#13;third', 'Unicode&#8233;paragraph separator', 'Nested&amp;#10;&amp;#10;encoded literal'] as $source){
 $prefix=ap_masto_reply_parent_preview($source);
 preview_check(!preg_match('/\R/u',html_entity_decode($prefix, ENT_QUOTES | ENT_HTML5, 'UTF-8'))&&mb_strlen(html_entity_decode($prefix, ENT_QUOTES | ENT_HTML5, 'UTF-8'))<=140,'generated teaser stays one line inside original budget');
 $pack=ap_masto_content_with_mentions('↩ '.$prefix."\n\n".$reply);
 $fresh=admin_notif_status_strip_reply_bake(['content'=>$pack['content'],'vaak_in_reply_to_url'=>'https://mkultra.monster/users/fixture/notes/1']);
 preview_check($fresh['content']===$expected,'only generated paragraph removed; every authored paragraph exact');
}
$legitimate=['content'=>$expected,'visibility'=>'private'];
preview_check(admin_notif_status_strip_reply_bake($legitimate)===$legitimate,'ordinary author text unchanged');
preview_check(str_contains($expected,'↩ Authored paragraph'),'authored arrow remains');
preview_check(str_contains($expected,'Second parent paragraph is also legitimate author text'),'repeated parent-like author text remains');
$dto=['id'=>'1','type'=>'mention','status'=>['content'=>$expected]];
$serialized=ap_notification_projection_payload($dto);
preview_check(is_string($serialized),'actual writer serializer emits payload');
$fresh=json_decode($serialized,true);
preview_check(($fresh['_vaak_reply_preview_version']??null)===2,'actual writer records strict integer provenance');
preview_check(ap_notification_projection_item($fresh)===$dto,'actual reader strips private metadata, authored DTO exact');
preview_check(ap_notification_projection_item($dto)===null,'legacy body rejected rather than stripped');
foreach(['2',1,2.0,null] as $invalid){$bad=$fresh;$bad['_vaak_reply_preview_version']=$invalid;preview_check(ap_notification_projection_item($bad)===null,'invalid provenance rejected');}
// The real bounded reader must reject an entire mixed window, even if a valid
// first row already satisfies limit=1. No DB bootstrap or authored-text rewrite.
class PreviewStatement {public function execute(array $args):void{} public function fetchAll():array{return $GLOBALS['projection_rows'];}}
class PreviewDb {public function prepare(string $sql):PreviewStatement{return new PreviewStatement;}}
function ap_db():PreviewDb{return new PreviewDb;}
$GLOBALS['projection_rows']=[['payload_json'=>$serialized],['payload_json'=>json_encode($dto)]];
preview_check(ap_notification_projection_read(1,1,['mention'],[])===[],'later legacy row rejects whole mixed window before page-limit break');
$GLOBALS['projection_rows']=[['payload_json'=>$serialized]];
preview_check(ap_notification_projection_read(1,1,['mention'],[])===[$dto],'versioned projection returns exact public DTO');
preview_check(str_starts_with(ap_masto_notifications_list_redis_key(1,30,null,null,['mention']),'vaak:notifications:v2:1:'),'actual PHP list namespace bypasses every legacy envelope');
echo "notif-reply-preview-smoke PASS $checks; legacy multi-paragraph leak reproduced, actual new producer helper/formatter/stripper preserve exact authored HTML\n";
