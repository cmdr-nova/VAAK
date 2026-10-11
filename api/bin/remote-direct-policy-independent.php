<?php
declare(strict_types=1);
/** Current actual PHP direct policy/card oracle, never native-generated HTML. */
// Declare production-shaped role and moderation columns before the inherited
// corpus first calls the real cached administrator lookup. Only fixture SQL
// transport is changed; actual PHP helpers and painter remain untouched.
$roleBootstrap=(string)file_get_contents(__DIR__.'/remote-profile-policy-baseline.php');
$transportDeclaration=<<<'PHP'
$transport=str_replace("'ap_users'=>'id INTEGER,actor_id TEXT'", "'ap_users'=>'id INTEGER,actor_id TEXT,is_admin INTEGER DEFAULT 0,actor_key TEXT'",$transport);
$transport=str_replace('INSERT INTO ap_users VALUES(', 'INSERT INTO ap_users(id,actor_id) VALUES(',$transport);
$transport=str_replace("INSERT INTO ap_users(id,actor_id) VALUES(1,'https://mkultra.monster/users/fixture'),(2,'https://mkultra.monster/users/other')", "INSERT INTO ap_users(id,actor_id,actor_key) VALUES(1,'https://mkultra.monster/users/fixture','fixture'),(2,'https://mkultra.monster/users/other','other')",$transport);
$transport=str_replace("'fixture_blocks'=>'owner_user_id INTEGER,scope TEXT,value TEXT'", "'fixture_blocks'=>'owner_user_id INTEGER,scope TEXT,value TEXT,id INTEGER DEFAULT 7,created_at TEXT DEFAULT NULL'",$transport);
$transport=str_replace('INSERT INTO fixture_blocks VALUES(', 'INSERT INTO fixture_blocks(owner_user_id,scope,value) VALUES(',$transport);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1'){
 $transport=str_replace(" \$q=ap_db()->prepare('SELECT payload FROM fixture_status_cache", " \$GLOBALS['fixture_status_lookup_requests'][]=['url'=>\$url,'depth'=>\$owner,'allow_fetch'=>\$fetch,'allow_hidden'=>\$allowEmpty];\n \$q=ap_db()->prepare('SELECT payload FROM fixture_status_cache",$transport);
 $transport=str_replace('return is_string($raw)?json_decode($raw,true):null;',"\$result=is_string(\$raw)?json_decode(\$raw,true):null;\$GLOBALS['fixture_status_lookup_requests'][array_key_last(\$GLOBALS['fixture_status_lookup_requests'])]['returned_source']=\$result;return \$result;",$transport);
 foreach(['ap_user_blocks_list_cached','ap_mutes_set_cached','ap_muted_words_phrases_cached','ap_block_list_cached','ap_server_blocked_actors_set'] as $helper)$transport=str_replace('function '.$helper.'(', 'function unused_inherited_'.$helper.'(',$transport);
 $transport=str_replace('$captures = [];', 'require '.var_export(__DIR__.'/remote-direct-production-helper-bootstrap.php',true).';'."\n".'$captures = [];',$transport);
 $transport=str_replace("unset(\$GLOBALS['ap_masto_flag_memo']);","unset(\$GLOBALS['ap_masto_flag_memo'],\$GLOBALS['ap_relset_memo']);",$transport);
 $transport=str_replace(' ob_start();', ' direct_production_prepare_moderation(); ob_start();',$transport);
 foreach(['following'=>'owner_actor_id,actor_id','events'=>'id,object_id,type,action_taken,visibility,actor_id','masto_favourites'=>'owner_user_id,status_id,object_id','masto_bookmarks'=>'owner_user_id,status_id,object_id','masto_reblogs'=>'owner_user_id,status_id,object_id'] as $table=>$columns)$transport=str_replace('INSERT INTO '.$table.' VALUES(', 'INSERT INTO '.$table.'('.$columns.') VALUES(',$transport);
 $transport=str_replace("'INSERT INTO '.\$table.' VALUES(","'INSERT INTO '.\$table.'(owner_user_id,status_id,object_id) VALUES(",$transport);
 $ddlGuard=<<<'GUARD'
  $sql=preg_replace_callback('/ALTER TABLE ([a-z_]+) ADD COLUMN ([a-z_]+) (TEXT|INTEGER|BIGINT);?/i',function($column){
   $existing=array_column($this->query('PRAGMA table_info('.$column[1].')')->fetchAll(PDO::FETCH_ASSOC),'name');
   return in_array($column[2],$existing,true)?'':$column[0];
  },$sql);
  if(trim($sql)==='')return 0;
GUARD;
 $transport=str_replace('  return parent::exec($sql);', $ddlGuard."\n".'  return parent::exec($sql);',$transport);
}
PHP;
$anchor="\$transport=str_replace('function vaak_actor_id(): string'";
$offset=strpos($roleBootstrap,$anchor);if($offset===false)throw new RuntimeException('fixture transport declaration boundary');
$roleBootstrap=substr_replace($roleBootstrap,$transportDeclaration."\n",$offset,0);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1')$roleBootstrap=str_replace(['CREATE TABLE remote_actors(','CREATE TABLE masto_pins('],['CREATE TABLE IF NOT EXISTS remote_actors(','CREATE TABLE IF NOT EXISTS masto_pins('],$roleBootstrap);
$captureAnchor="\$capture=file_get_contents(__DIR__.'/search-render-reference-capture.php');";
 $captureOffset=strpos($roleBootstrap,$captureAnchor);if($captureOffset===false)throw new RuntimeException('search SQL transport boundary');
 $captureEnd=strpos($roleBootstrap,"\n",$captureOffset);
 $skipDuplicate=<<<'PHP'
$capture=str_replace("ap_db()->exec('ALTER TABLE ap_users ADD COLUMN actor_key TEXT');",'',$capture);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1')$capture=str_replace("unset(\$GLOBALS['ap_masto_flag_memo']);","unset(\$GLOBALS['ap_masto_flag_memo'],\$GLOBALS['ap_relset_memo']);",$capture);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1')$capture=str_replace('INSERT INTO remote_custom_emojis VALUES(', 'INSERT INTO remote_custom_emojis(host,shortcode,url) VALUES(',$capture);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1')$capture=str_replace('INSERT INTO bsky_posts VALUES(', 'INSERT INTO bsky_posts(bsky_uri) VALUES(',$capture);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1')$capture=str_replace('INSERT INTO events VALUES(', 'INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(',$capture);
if(getenv('DIRECT_PRODUCTION_HELPERS')==='1')$capture=str_replace('ob_start();',"if(function_exists('direct_production_prepare_moderation'))direct_production_prepare_moderation();ob_start();",$capture);
PHP;
 $roleBootstrap=substr_replace($roleBootstrap,"\n".$skipDuplicate,$captureEnd,0);
$roleResolved='';foreach(token_get_all($roleBootstrap) as $token)$roleResolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);
$rolePath='/tmp/vaak-remote-direct-policy-role-bootstrap.php';file_put_contents($rolePath,$roleResolved);
$path=__DIR__.'/remote-direct-card-reference.php';$code=(string)file_get_contents($path);$code=str_replace("\$bootstrapPath=__DIR__.'/remote-profile-policy-baseline.php';",'$bootstrapPath='.var_export($rolePath,true).';',$code);$end=strpos($code,"\n\$rows=[];\$checks=0;");if($end===false)throw new RuntimeException('setup boundary');$resolved='';foreach(token_get_all(substr($code,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
reference_load(dirname(__DIR__).'/ap-db.php',['ap_events_mark_object_deleted','ap_events_mark_interaction_undone','ap_user_block_find','ap_html_to_plain_text','ap_acct_from_actor_href','ap_plain_unglue_mentions','ap_plain_expand_bare_mentions_from_urls','ap_plain_unglue_hashtags']);
$captures=[];$checks=0;$retainedPolicy=[];if(is_file('/tmp/vaak-remote-direct-policy-full-helpers-independent.json'))foreach(json_decode((string)file_get_contents('/tmp/vaak-remote-direct-policy-full-helpers-independent.json'),true,512,JSON_THROW_ON_ERROR)['captures'] as $old)$retainedPolicy[$old['width'].'/'.$old['case']]=$old['source'];
function independent_direct_snapshot():array {
 $snapshot=[];foreach(ap_db()->query("SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema()")->fetchAll(PDO::FETCH_COLUMN) as $table)$snapshot[$table]=ap_db()->query('SELECT * FROM '.$table)->fetchAll();return $snapshot;
}
function independent_direct_capture(array $source,string $name,string $route='status',string $origin='home',int $owner=1):void {
 if(isset($GLOBALS['retainedPolicy'][$GLOBALS['review_width'].'/'.$name]))$source=$GLOBALS['retainedPolicy'][$GLOBALS['review_width'].'/'.$name];direct_bind($owner);$GLOBALS['view']=$route;$_GET=[];$GLOBALS['admin_bookmark_lazy_media']=false;$GLOBALS['fixture_render_clock']=1791673200;
 if(function_exists('direct_production_prepare_moderation'))direct_production_prepare_moderation();foreach(['fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests'] as $key)$GLOBALS[$key]=[];
 $baseName=$name;if(($variant=getenv('DIRECT_POLICY_PREVIEW_VARIANT'))!==false&&$variant!==''){$name.='_'.$variant;$source['content']=$variant==='plain'?'Late denied plain marker':'Late denied URL https://article.test/late-denied';unset($source['card']);$source['media_attachments']=[];if($variant==='cached')ap_db()->exec("DELETE FROM link_preview_cards;INSERT INTO link_preview_cards(url,title,description,type,status,expires_at) VALUES('https://article.test/late-denied','Actual late cached','Declared current metadata','link','ok','2099-01-01T00:00:00Z')");if($variant==='media')$source['media_attachments']=[['type'=>'image','url'=>'https://media.test/late.jpg','preview_url'=>'https://media.test/late.jpg']];if($variant==='card')$source['card']=['url'=>'https://article.test/late-denied','title'=>'Actual supplied late card','type'=>'link'];}
 $options=['owner'=>$owner,'route'=>$route,'return_view'=>$origin,'focused'=>$route==='status','show_open'=>true,'hide_header'=>false,'lazy_media'=>false,'rendered_at'=>$GLOBALS['fixture_render_clock'],'csrf'=>'fixture-csrf-token'];
 if(getenv('DIRECT_POLICY_STATUS_EFFECTS')==='1')$GLOBALS['admin_status_link_preview_budget']=3;$before=json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 // Real Status wrapper is the final corrected controller path. Profile root
 // block remains controller-owned and is recorded, not imposed on this painter.
 $capture=caller_capture(static function()use($source,$options){if($options['route']==='status')admin_render_direct_status_card($source,[],$options['return_view'],true,true);else admin_render_masto_status_card($source,[],$options['return_view'],false,true);});
 if(function_exists('direct_production_prepare_moderation'))foreach(['fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests'] as $key)$capture['effects'][$key]=$GLOBALS[$key];
 if(getenv('DIRECT_POLICY_STATUS_EFFECTS')==='1')$capture['effects']['status_link_preview_budget']=['present'=>array_key_exists('admin_status_link_preview_budget',$GLOBALS),'value'=>$GLOBALS['admin_status_link_preview_budget']??null,'type'=>get_debug_type($GLOBALS['admin_status_link_preview_budget']??null)];
 if($before!==json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))throw new RuntimeException('PHP original source mutated');$GLOBALS['checks']++;
 $GLOBALS['captures'][]=['width'=>$GLOBALS['review_width'],'initial_status_budget'=>getenv('DIRECT_POLICY_STATUS_EFFECTS')==='1'?3:null,'case'=>$name,'base_case'=>$baseName,'source'=>$source,'context'=>$options,'current_tables'=>independent_direct_snapshot(),'direct_authority_allowed'=>admin_direct_status_allowed($source,$owner),'root_content_blocked'=>admin_direct_status_content_blocked($source),'php'=>$capture,'forms'=>direct_reference_forms($capture['html'])];
}
foreach(['INTEGER','BIGINT'] as $width){$GLOBALS['review_width']=$width;$schema='remote_direct_policy_independent_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}$GLOBALS['fixture_db']=$pg;
  foreach(['public','unlisted','private_author','private_follower','private_revoked','direct_recipient','direct_revoked','no_outbox','no_outbox_with_event','no_outbox_author','no_outbox_author_with_event','missing_row'] as $grant){
   $status=direct_source($corpus['status_plain']['context'],str_starts_with($grant,'private')?'private':(str_starts_with($grant,'direct')?'direct':($grant==='unlisted'?'unlisted':'public')));direct_bind(1);
   if($grant==='private_follower')$pg->prepare('INSERT INTO followers(owner_actor_id,actor_id) VALUES(?,?)')->execute([$status['account']['uri'],vaak_actor_id()]);
   if($grant==='direct_recipient')$pg->prepare('UPDATE outbox_notes SET to_json=? WHERE id=?')->execute([json_encode([vaak_actor_id()]),$status['uri']]);
   if(str_starts_with($grant,'no_outbox'))$pg->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$status['uri']]);
   if(str_contains($grant,'with_event'))$pg->prepare("INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(199,?,'Create','log','public',?)")->execute([$status['uri'],$status['account']['uri']]);
   if($grant==='missing_row')$pg->prepare('DELETE FROM masto_statuses WHERE note_id=?')->execute([$status['uri']]);
   independent_direct_capture($status,'local_'.$grant,'status','home',in_array($grant,['private_author','no_outbox_author','no_outbox_author_with_event'],true)?2:1);
  }
  foreach(['public','private_follower','private_revoked','direct_recipient','direct_revoked','quote_undo','announce_undo','create_deleted','true_delete','tombstone','private_with_public_quote','legacy_quote_only','legacy_quote_undo'] as $state){
   $case=$corpus['status_plain'];caller_restore($case['context']);direct_bind(1);$status=$case['status'];$uri=$status['uri'];$actor=$status['account']['uri'];$pg->exec('DELETE FROM events;DELETE FROM following;DELETE FROM mentions');
   $visibility=str_starts_with($state,'private')?'private':(str_starts_with($state,'direct')?'direct':'public');
   $type=str_starts_with($state,'legacy_quote')?'Quote':'Create';$pg->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([100,$uri,$type,'log',$visibility,$actor]);
   if($state==='private_follower')$pg->prepare('INSERT INTO following(owner_actor_id,actor_id) VALUES(?,?)')->execute([vaak_actor_id(),$actor]);
   if($state==='direct_recipient')$pg->prepare("INSERT INTO mentions(id,object_id,owner_user_id,activity_type) VALUES(101,?,1,'Create')")->execute([$uri]);
   if(in_array($state,['quote_undo','announce_undo','private_with_public_quote'],true)){$pg->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([101,$uri,$state==='announce_undo'?'Announce':'Quote','log','public','https://quoter.test/users/bob']);if($state!=='private_with_public_quote')ap_events_mark_interaction_undone($state==='announce_undo'?'Announce':'Quote','https://quoter.test/users/bob',$uri);}
   if($state==='legacy_quote_undo')ap_events_mark_interaction_undone('Quote',$actor,$uri);
   if($state==='create_deleted')ap_events_mark_object_deleted($uri);
   if(in_array($state,['true_delete','tombstone'],true))$pg->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([102,$uri.'/',$state==='true_delete'?'Delete':'Tombstone','log','public',$actor]);
   independent_direct_capture($status,'remote_'.$state);
  }
  foreach(['none','personal_block','server_block','personal_mute','server_mute','word'] as $rule)foreach(['status','remote_profile'] as $route){
   $case=$corpus['status_plain'];caller_restore($case['context']);direct_bind(1);$status=$case['status'];$actor=$status['account']['uri'];
   if($rule==='personal_block')$pg->prepare('INSERT INTO fixture_blocks(owner_user_id,scope,value,id) VALUES(?,?,?,7)')->execute([1,'actor',$actor]);
   if($rule==='personal_mute')$pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$actor]);
   if($rule==='word')$pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,'fixture plain']);
   if(str_starts_with($rule,'server_'))$pg->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute(['actor',$actor,$rule==='server_block'?'block':'mute']);
   independent_direct_capture($status,'moderation_'.$rule.'_'.$route,$route,$route==='status'?'home':'remote_profile');
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-direct-policy-complete-schema-independent.json';file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$captures,'bootstrap_tables'=>$tables,'native_proof'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo 'remote-direct-policy-independent PASS '.$checks.' controls; '.count($captures).' actual captures; '.$path."\n";
