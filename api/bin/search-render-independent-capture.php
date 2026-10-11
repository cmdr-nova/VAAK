<?php
declare(strict_types=1);
/** Independent presentation cases. Uses actual painters and current helper decisions.
 * SQLite is SQL transport, not an admission replacement. No always-allow policy.
 */
ob_start();require __DIR__.'/search-render-reference-capture.php';$original=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$base=['id'=>'','uri'=>'https://remote.test/users/alice','url'=>'https://remote.test/@alice','username'=>'alice','acct'=>'alice@remote.test','display_name'=>'Alice'];
$cases=[
 'uri_wins'=>[array_replace($base,['uri'=>'https://remote.test/users/alice///','url'=>'https://else.test/@alice']),[],['username'=>'fixture'],'fixture'],
 'url_only'=>[array_replace($base,['uri'=>'','url'=>'https://remote.test/@alice/']),[],['username'=>'fixture'],'fixture'],
 'local_case'=>[array_replace($base,['uri'=>'https://mkultra.monster/users/FiXtUrE/','username'=>'FIXTURE','acct'=>'wrong']),[],['username'=>'fixture'],'fixture'],
 'own_url_descendant'=>[array_replace($base,['uri'=>vaak_actor_id().'/notes/7']),[],['username'=>'fixture'],'fixture'],
 'numeric_self'=>[array_replace($base,['id'=>'1','uri'=>'https://mkultra.monster/users/fixture','username'=>'fixture']),[],['username'=>'fixture'],'fixture'],
 'guest_key_fallback'=>[array_replace($base,['acct'=>'FaLlBaCk@mkultra.monster']),[],['username'=>'guest'],'fallback'],
 'guest_empty'=>[array_replace($base,['acct'=>'fixture']),[],['username'=>'guest'],'__guest__'],
 'follow_alias'=>[$base,['https://remote.test/@alice/'=>true],['username'=>'fixture'],'fixture'],
 'numeric_uri_no_alias'=>[array_replace($base,['uri'=>'https://remote.test/users/123','url'=>'https://else.test/@alice']),['https://remote.test/@123'=>true],['username'=>'fixture'],'fixture'],
 'unicode_display'=>[array_replace($base,['display_name'=>"\u{00A0}:wave: Alice\u{2009}"]),[],['username'=>'fixture'],'fixture'],
 'fresh_emoji'=>[array_replace($base,['uri'=>'https://fresh-emoji.test/users/alice','url'=>'https://fresh-emoji.test/@alice','display_name'=>':wave: & <alice>']),[],['username'=>'fixture'],'fixture'],
 'false_display'=>[array_replace($base,['display_name'=>'0','username'=>'fallback']),[],['username'=>'fixture'],'fixture'],
];
$captures=[];
foreach($cases as $name=>[$acc,$following,$user,$key]) {
 $GLOBALS['vaak_user']=array_replace(['id'=>1],$user);$GLOBALS['vaak_actor_key']=$key;
 if($name==='fresh_emoji')ap_db()->exec("INSERT INTO remote_custom_emojis VALUES('fresh-emoji.test','wave','https://fresh-emoji.test/current.png?a=1&b=2')");
 $uri=rtrim((string)$acc['uri'],'/');$url=rtrim((string)$acc['url'],'/');$target=$uri!==''?$uri:$url;$local='';$acct=$acc['acct'];
 if(preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#',$target,$m)){$local=strtolower($m[1]);$target='https://mkultra.monster/users/'.rawurlencode($local);$acct=$acc['username']??$local;}
 $viewer=admin_search_viewer_username();
 $me=($target!==''&&vaak_is_own_url($target))||($uri!==''&&vaak_is_own_url($uri))||($url!==''&&vaak_is_own_url($url))||($acct!==''&&(strtolower($acct)===strtolower($viewer)||strtolower($acct)===strtolower($viewer.'@mkultra.monster')));
 $already=$target!==''&&admin_is_following($following,$target,$url,$uri);
 $facts=['is_me'=>$me,'following'=>$already,'profile_href'=>$local!==''?ap_vaak_pretty_profile_path($local,['from'=>'search']):'?view=remote_profile&actor='.urlencode($target).'&from=search','open_label'=>admin_open_profile_label($target!==''?$target:$url),'emoji_map'=>ap_remote_emoji_map_for_host((string)(parse_url($target,PHP_URL_HOST)??''))];
 ob_start();admin_search_render_account($acc,$following);$html=ob_get_clean();
 if($name==='numeric_self'&&!$me)throw new RuntimeException('numeric self must bypass remote relationship fallback');
 if($name==='numeric_uri_no_alias'&&$already)throw new RuntimeException('numeric URI must not alias /@123');
 if($name==='guest_empty'&&$me)throw new RuntimeException('guest empty viewer must not create username ownership');
 if($name==='fresh_emoji'&&!str_contains($html,'current.png'))throw new RuntimeException('actual emoji helper failed fresh current SQL URL');
 $captures[$name]=['kind'=>'account','input'=>$acc,'facts'=>$facts,'following_ids'=>$following,'viewer_username'=>$viewer,'html'=>$html];
}
$GLOBALS['vaak_user']=['id'=>1,'username'=>'fixture'];$GLOBALS['vaak_actor_key']='fixture';
foreach(['status_cw_media','status_quote','status_quote_pending','status_saved_flags','status_sensitive_auto_unblur','status_cw_auto_unblur'] as $name) {
 $case=$original['captures'][$name];if(($case['context']['return_view']??'')!=='search')throw new RuntimeException('wrong presentation return view');
 if(empty($case['html'])||!str_contains($case['html'],'<article'))throw new RuntimeException('mandatory fullcard missing');
}
echo json_encode(['captures'=>$captures,'case_count'=>count($captures),'admission_verified'=>false,'promotion_allowed'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
