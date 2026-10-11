<?php
declare(strict_types=1);
/** Separate actual reblog producer/caller fixture; original 360 retained. */
$argv[1]??='/tmp/vaak-library-search-boost-wrapper-reference.json';
$path=__DIR__.'/library-search-boost-state-reference.php';$code=(string)file_get_contents($path);
$code=str_replace("'search_soft','search_append','raw_explicit_prefetch'","'search_soft','search_append'",$code);
$code=str_replace("foreach([1,2] as \$owner)","\$pg->exec('ALTER TABLE mentions ADD COLUMN IF NOT EXISTS actor_id TEXT');foreach(['id'=>'INTEGER','boost_status_id'=>'TEXT','announce_activity_id'=>'TEXT','owner_actor_id'=>'TEXT'] as \$col=>\$type)\$pg->exec('ALTER TABLE masto_reblogs ADD COLUMN IF NOT EXISTS '.\$col.' '.(\$type==='INTEGER'?\$width:\$type));foreach([1,2] as \$owner)",$code);
$anchor="\$GLOBALS['boost_ranked_sources']=[\$source];";
$insert=<<<'BODY'
$innerSource=$source;$outerActor=ap_db()->query('SELECT actor_id FROM ap_users WHERE id='.$owner)->fetchColumn();$outerSid='900000000000000'.$owner;$announce=rtrim($outerActor,'/').'/announces/'.$outerSid;
$pg->prepare('INSERT INTO masto_reblogs(owner_user_id,status_id,object_id,created_at,id,boost_status_id,announce_activity_id,owner_actor_id) VALUES(?,?,?,?,410,?,?,?)')->execute([$owner,$outerSid,$innerSource['uri'],'2026-10-10T15:00:00Z',$outerSid,$announce,$outerActor]);
$wrapperRow=$pg->query('SELECT * FROM masto_reblogs WHERE id=410')->fetch();$source=ap_masto_status_from_reblog($wrapperRow,$innerSource);if($source['content']!==''||$source['id']===$innerSource['id'])throw new RuntimeException('actual wrapper producer shape');
BODY;
$code=str_replace($anchor,$insert."\n".$anchor,$code);
$code=str_replace("if(count(\$window['statuses'])!==1||!ap_visibility_status_audience_allowed(\$source,\$owner))throw new RuntimeException('actual lawful Search source rejected');", "\$searchAdmitted=count(\$window['statuses'])===1;if(!ap_visibility_status_audience_allowed(\$source,\$owner))throw new RuntimeException('actual wrapper audience denied');",$code);
$code=str_replace("if(count(\$forms)!==1)throw new RuntimeException", "if(str_starts_with(\$caller,'search')&&!\$searchAdmitted){if(\$forms!==[])throw new RuntimeException('denied wrapper Search rendered boost');}elseif(count(\$forms)!==1)throw new RuntimeException",$code);
$code=str_replace("if((\$forms[0]['action']==='unreblog_status')!==\$expected)", "if(\$forms!==[]&&(\$forms[0]['action']==='unreblog_status')!==\$expected)",$code);
$code=str_replace("'wrapper_producer_row'=>\$wrapperRow", "'actual_search_admitted'=>\$searchAdmitted,'wrapper_producer_row'=>\$wrapperRow",$code);

$code=str_replace("\$exact->execute([\$owner,(string)\$source['id']]);","\$exact->execute([\$owner,(string)\$innerSource['id']]);",$code);
$code=str_replace("'provider_source'=>\$provider", "'wrapper_producer_row'=>\$wrapperRow,'displayed_inner_source'=>\$innerSource,'provider_source'=>\$provider",$code);
$schemaAnchor="$"."sql=boost_snapshot();";
$code=str_replace($schemaAnchor,$schemaAnchor."\$GLOBALS['boost_wrapper_schema'][\$width]=[];foreach(array_keys(\$sql) as \$table)\$GLOBALS['boost_wrapper_schema'][\$width][\$table]=\$pg->query(\"SELECT column_name,data_type,ordinal_position FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='\$table' ORDER BY ordinal_position\")->fetchAll();",$code);
$resolved='';foreach(token_get_all($code) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
file_put_contents('/tmp/vaak-library-search-boost-wrapper-schema.json',json_encode($GLOBALS['boost_wrapper_schema'],JSON_THROW_ON_ERROR));
