<?php
/** Independent actual canonical full-card Unicode edge references. */
declare(strict_types=1);
ob_start();require __DIR__.'/migration-card-reference-capture.php';$refs=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
foreach(['fixture_words','fixture_mutes','fixture_blocks','fixture_server_blocks','mentions'] as $table)ap_db()->exec('DELETE FROM '.$table);
$own=$refs['captures']['own']['status'];$result=[];
foreach(['(media)','(quote)','(poll)'] as $token){
 $body="\u{00A0}".$token."\u{00A0}";
 ap_db()->prepare('UPDATE masto_statuses SET content_text=? WHERE local_id=456')->execute([$body]);$own['content']=$body;
 ob_start();admin_render_library_status_card($own,'bookmarks');$html=ob_get_clean();
 if(!str_contains($html,$body))throw new RuntimeException('Canonical full own card lost Unicode placeholder '.$token);
 $result[$token]=['body'=>$body,'canonical_preserves_body'=>true];
}
// Only local actor lookup is a transport fixture; identity formatting and profile
// reading are the actual canonical PHP functions.
if(!function_exists('ap_local_user_by_actor_id')){function ap_local_user_by_actor_id(string $actor):?array{return $actor===vaak_actor_id()?['actor_key'=>'fixture']:null;}}
reference_load(dirname(__DIR__).'/ap-asks.php',['ap_ask_actor_identity']);
$name="\u{00A0}Current Unicode name\u{2009}";ap_db()->prepare('UPDATE actor_profile SET name=?')->execute([$name]);
$identity=ap_ask_actor_identity(vaak_actor_id());if($identity['display_name']!==$name)throw new RuntimeException('Canonical current local identity unexpectedly stripped Unicode');
$ask=$refs['captures']['plain']['status'];$ask['vaak_ask']=['question'=>'Unicode identity?','asker_actor'=>vaak_actor_id(),'asker_display'=>$identity['display_name'],'asker_handle'=>$identity['handle']];
ob_start();admin_render_library_status_card($ask,'bookmarks');$html=ob_get_clean();if(!str_contains($html,$name))throw new RuntimeException('Canonical full Ask card lost current identity Unicode');
$result['identity']=['display_name'=>$identity['display_name'],'canonical_full_card_preserves'=>true];
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
