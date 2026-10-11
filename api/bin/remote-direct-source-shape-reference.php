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
PHP;
$anchor="\$transport=str_replace('function vaak_actor_id(): string'";
$offset=strpos($roleBootstrap,$anchor);if($offset===false)throw new RuntimeException('fixture transport declaration boundary');
$roleBootstrap=substr_replace($roleBootstrap,$transportDeclaration."\n",$offset,0);
$captureAnchor="\$capture=file_get_contents(__DIR__.'/search-render-reference-capture.php');";
 $captureOffset=strpos($roleBootstrap,$captureAnchor);if($captureOffset===false)throw new RuntimeException('search SQL transport boundary');
 $captureEnd=strpos($roleBootstrap,"\n",$captureOffset);
 $skipDuplicate=<<<'PHP'
$capture=str_replace("ap_db()->exec('ALTER TABLE ap_users ADD COLUMN actor_key TEXT');",'',$capture);
PHP;
 $roleBootstrap=substr_replace($roleBootstrap,"\n".$skipDuplicate,$captureEnd,0);
$roleResolved='';foreach(token_get_all($roleBootstrap) as $token)$roleResolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);
$rolePath='/tmp/vaak-remote-direct-policy-role-bootstrap.php';file_put_contents($rolePath,$roleResolved);
$path=__DIR__.'/remote-direct-card-reference.php';$code=(string)file_get_contents($path);$code=str_replace("\$bootstrapPath=__DIR__.'/remote-profile-policy-baseline.php';",'$bootstrapPath='.var_export($rolePath,true).';',$code);$end=strpos($code,"\n\$rows=[];\$checks=0;");if($end===false)throw new RuntimeException('setup boundary');$resolved='';foreach(token_get_all(substr($code,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
reference_load(dirname(__DIR__).'/ap-db.php',['ap_events_mark_object_deleted','ap_events_mark_interaction_undone','ap_user_block_find','ap_html_to_plain_text','ap_acct_from_actor_href','ap_plain_unglue_mentions','ap_plain_expand_bare_mentions_from_urls','ap_plain_unglue_hashtags']);
$rows=[];$checks=0;
foreach(['INTEGER','BIGINT'] as $width){$schema='remote_direct_source_shape_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
try{foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}$GLOBALS['fixture_db']=$pg;
foreach(['normal','unicode','uri_null','uri_int','uri_array','url_null','url_int','url_array','mixed_uri_null','mixed_url_array'] as $state){$case=$corpus['status_plain'];caller_restore($case['context']);direct_bind(1);$source=$case['status'];$field=str_contains($state,'url_')?'url':'uri';$value=str_contains($state,'null')?null:(str_contains($state,'int')?42:[]);if(!in_array($state,['normal','unicode'],true)){$source['url']=$source['uri'];$source[$field]=$value;}$source['content']=$state==='unicode'?'<p>Unicode café é 👩‍🚀 end</p><p>second line</p>':$source['content'];$sources=str_starts_with($state,'mixed_')?[$case['status'],$source]:[$source];$GLOBALS['view']='status';$_GET=[];$GLOBALS['admin_status_link_preview_budget']=3;$original=json_encode($sources,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$warnings=[];$exception=null;set_error_handler(static function($severity,$message)use(&$warnings){$warnings[]=$message;return true;});try{$capture=caller_capture(static function()use($sources){foreach($sources as $status)admin_render_direct_status_card($status,[],'home',true,true);});}catch(Throwable $error){$exception=get_class($error).': '.$error->getMessage();$capture=['html'=>'','effects'=>[]];}finally{restore_error_handler();}$capture['effects']['status_link_preview_budget']=$GLOBALS['admin_status_link_preview_budget'];if($original!==json_encode($sources,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))throw new RuntimeException('source changed');if($state==='uri_null'&&(!str_contains($capture['html'],'Fixture plain post')||$exception!==null))throw new RuntimeException('actual null URI canonical URL did not paint');$checks++;
$current=[];foreach($pg->query("SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema()")->fetchAll(PDO::FETCH_COLUMN) as $table)$current[$table]=$pg->query('SELECT * FROM '.$table)->fetchAll();$rows[]=['width'=>$width,'case'=>$state,'context'=>['owner'=>1,'csrf'=>'fixture-csrf-token','route'=>'status','return_view'=>'home','focused'=>true,'show_open'=>true,'rendered_at'=>$GLOBALS['fixture_render_clock']],'source'=>$source,'review_sources'=>$sources,'initial_status_budget'=>3,'current_tables'=>$current,'php'=>$capture,'php_warnings'=>$warnings,'php_exception'=>$exception];}
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
$path=$argv[1]??'/tmp/vaak-remote-direct-source-shape-reference.json';file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$rows,'bootstrap_tables'=>$tables],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo 'remote-direct-source-shape-reference PASS '.$checks.' controls; '.count($rows).' captures; '.$path."\n";
