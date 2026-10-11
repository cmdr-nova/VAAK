<?php
declare(strict_types=1);
/** Actual PHP complete direct-card reference only; no native expected output. */
// Reuse the independently reviewed actual-policy fixture's bootstrap and
// transports, stopping before its executable PostgreSQL cases. No production
// painter or source value is substituted or rewritten here.
$bootstrapPath=__DIR__.'/remote-profile-policy-baseline.php';
$bootstrap=(string)file_get_contents($bootstrapPath);
$boundary=strpos($bootstrap,"\n\$socket=getenv('LIBRARY_FIXTURE_SOCKET');");
if($boundary===false)throw new RuntimeException('policy bootstrap boundary missing');
$bootstrap=substr($bootstrap,0,$boundary);
$resolved='';foreach(token_get_all($bootstrap) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);
$bootstrap=substr($resolved,5);
ob_start();eval($bootstrap);$bootstrapOutput=(string)ob_get_clean();
if($bootstrapOutput!=='')throw new RuntimeException('unexpected policy bootstrap output');
reference_load(__DIR__.'/search-native-callers-capture.php',['caller_capture']);
require_once dirname(__DIR__).'/ap-native-quote-boundary.php';
$socket=getenv('LIBRARY_FIXTURE_SOCKET');
if(!$socket||!str_starts_with($socket,'/tmp/'))throw new RuntimeException('isolated local socket required');
$pg=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$modes=[
 'profile'=>['owner'=>1,'route'=>'remote_profile','return_view'=>'remote_profile','focused'=>false],
 'focus_home'=>['owner'=>1,'route'=>'status','return_view'=>'home','focused'=>true],
 'focus_search'=>['owner'=>1,'route'=>'status','return_view'=>'search','focused'=>true],
 'focus_bookmarks'=>['owner'=>1,'route'=>'status','return_view'=>'bookmarks','focused'=>true],
 'focus_favourites'=>['owner'=>1,'route'=>'status','return_view'=>'favourites','focused'=>true],
 'ancestor_home'=>['owner'=>1,'route'=>'status','return_view'=>'home','focused'=>false],
 'profile_hidden_no_open'=>['owner'=>1,'route'=>'remote_profile','return_view'=>'remote_profile','focused'=>false,'show_open'=>false,'hide_header'=>true],
 'focus_hidden_no_open'=>['owner'=>1,'route'=>'status','return_view'=>'home','focused'=>true,'show_open'=>false,'hide_header'=>true],
 'ancestor_hidden_no_open'=>['owner'=>1,'route'=>'status','return_view'=>'home','focused'=>false,'show_open'=>false,'hide_header'=>true],
 'unfocused_literal_status'=>['owner'=>1,'route'=>'status','return_view'=>'status','focused'=>false],
 'profile_target_override'=>['owner'=>1,'route'=>'remote_profile','return_view'=>'remote_profile','focused'=>false,'get_actor'=>'https://profile-target.test/users/different'],
];
$cases=[];
foreach(['plain','cw_media','own','poll_open','ask','sensitive_auto_unblur','cw_auto_unblur','saved_flags','boost','quote_accepted','quote_pending','quote_fallback'] as $name)$cases[$name]=$corpus['status_'.$name];
foreach(['warning_changed','literal_zero'] as $shape){
 $case=$corpus['status_own'];$case['status']['spoiler_text']='Cached warning marker';$case['status']['sensitive']=true;
 foreach($case['context']['local_status_rows'] as &$row)if($row['note_id']===$case['status']['uri']){$row['spoiler_text']='Current warning marker';$row['sensitive']=1;$row['content_text']=$shape==='literal_zero'?'0':'Current own body marker';}unset($row);
 $case['declared_own_shape']=$shape;$cases['own_'.$shape]=$case;
}
$quote=$corpus['status_quote_accepted'];
if(!is_array($quote['status']['quote']['quoted_status']??null))throw new RuntimeException('complete quote corpus missing');
foreach(['supplied_media','supplied_card','dynamic_plain','dynamic_url'] as $shape){
 $case=$quote;$child=&$case['status']['quote']['quoted_status'];
 // These are declared test inputs. The actual painter receives the exact
 // constructed input; captured expected output is never normalized/repaired.
 unset($child['card']);$child['media_attachments']=[];$child['content']='Direct complete quote marker';
 if($shape==='supplied_media')$child['media_attachments']=[['type'=>'image','url'=>'https://media.test/direct-quote.jpg','preview_url'=>'https://media.test/direct-quote.jpg']];
 if($shape==='supplied_card')$child['card']=['url'=>'https://article.test/direct','title'=>'Direct supplied card','description'=>'Supplied description','type'=>'link'];
 if($shape==='dynamic_url')$child['content'].=' https://article.test/direct';
 unset($child);$case['declared_quote_shape']=$shape;$cases['quote_'.$shape]=$case;
}
function direct_reference_forms(string $html):array {
 // Supplemental exact form fragments only; full HTML is the oracle. This
 // inventory does not parse, repair, or normalize captured presentation.
 preg_match_all('/<form\b[^>]*>.*?<\/form>/s',$html,$matches);return $matches[0];
}
$rows=[];$checks=0;
foreach(['INTEGER','BIGINT'] as $width){
 $schema='remote_direct_cards_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $GLOBALS['fixture_db']=$pg;
  foreach($cases as $name=>$case)foreach($modes as $mode=>$options)foreach([false,true] as $lazy){
   caller_restore($case['context']);direct_bind($options['owner']);$GLOBALS['view']=$options['route'];$GLOBALS['admin_bookmark_lazy_media']=$lazy;$_GET=isset($options['get_actor'])?['actor'=>$options['get_actor']]:[];
   $status=$case['status'];$before=json_encode($status,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   $capture=caller_capture(static function()use($status,$options){admin_render_masto_status_card($status,[],$options['return_view'],$options['focused'],$options['show_open']??true,$options['hide_header']??false);});
   // Classify only after the independent canonical PHP expectation is fully
   // captured: quote classification retains policy globals, so it must not
   // precede or contaminate this oracle's painter/effect baseline.
   caller_restore($case['context']);direct_bind($options['owner']);$GLOBALS['view']=$options['route'];$GLOBALS['admin_bookmark_lazy_media']=$lazy;$_GET=isset($options['get_actor'])?['actor'=>$options['get_actor']]:[];
   $fallback=ap_native_quote_tree_requires_php($status);
   $classifierEffects=['preview_http'=>$GLOBALS['preview_http_requests'],'preview_warm'=>$GLOBALS['preview_warm_requests'],'fetch_budget'=>$GLOBALS['admin_library_fetch_budget'],'quote_budget'=>$GLOBALS['admin_quote_link_preview_budget'],'retained_policy_source_count'=>count($GLOBALS['vaak_quote_policy_statuses']??[])];
   if(!str_contains($capture['html'],'<article'))throw new RuntimeException('required complete direct card denied '.$width.'/'.$name.'/'.$mode);
   if($before!==json_encode($status,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))throw new RuntimeException('caller source mutated');$checks+=2;
   if(!empty($case['declared_quote_shape'])){if($fallback!==str_starts_with($case['declared_quote_shape'],'dynamic_'))throw new RuntimeException('quote boundary differs '.$name);$checks++;}
   if(!empty($case['declared_own_shape'])){
    $expectedWarning=$options['return_view']==='search'?'Current warning marker':'Cached warning marker';
    if(!str_contains($capture['html'],'class="cw-text">'.$expectedWarning.'</span>'))throw new RuntimeException('direct own warning origin mismatch '.$mode);
    if($case['declared_own_shape']==='literal_zero'&&!preg_match('/class="body feed-body"[^>]*>0<\/div>/',$capture['html']))throw new RuntimeException('direct own literal zero missing');$checks++;
   }
   $rows[]=['width'=>$width,'case'=>$name,'mode'=>$mode,'context'=>$options+['lazy_media'=>$lazy,'rendered_at'=>$GLOBALS['fixture_render_clock'],'csrf'=>$case['context']['csrf_token']??''],'source'=>$status,'authoritative_context'=>$case['context'],'direct_authority_allowed'=>admin_direct_status_allowed($status,1),'quote_preview_php_fallback'=>$fallback,'classifier_effects_separate_from_php'=>$classifierEffects,'php'=>$capture,'forms'=>direct_reference_forms($capture['html'])];
  }
  // Guest local profile uses the actual provider and exact canonical shared
  // attempt/legacy fallback, rather than claiming native guest shared parity.
  $status=direct_source($corpus['status_plain']['context']);direct_bind(0);$GLOBALS['view']='remote_profile';
  $page=ap_local_profile_tab_page('other','https://mkultra.monster/users/other','posts',20,0);
  $guest=caller_capture(static function()use($page){foreach($page['items'] as $row){if(!admin_try_render_outbox_shared_card($row,[],'remote_profile'))admin_render_outbox_card($row,'remote_profile');}});
  if(!str_contains($guest['html'],'Direct policy marker')||str_contains($guest['html'],'name="csrf"'))throw new RuntimeException('guest canonical boundary');$checks++;
  $rows[]=['width'=>$width,'case'=>'guest_local_public','context'=>['owner'=>0,'route'=>'remote_profile'],'provider'=>$page,'php'=>$guest,'forms'=>direct_reference_forms($guest['html']),'native_support'=>'requires guest legacy contract; not certified by shared-card corpus'];
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-direct-card-reference.json';
file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$rows,'bootstrap_tables'=>$tables,'native_parity_proven'=>false,'production_request'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'remote-direct-card-reference PASS '.$checks.' checks; '.count($rows).' actual PHP captures; '.$path."\n";
