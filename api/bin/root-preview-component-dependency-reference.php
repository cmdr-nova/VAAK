<?php
declare(strict_types=1);
/** Reproduce actual native component SQL against the retained old schema. */
$old=json_decode((string)file_get_contents('/tmp/vaak-root-preview-callers-production-warm-effects-reference.json'),true,512,JSON_THROW_ON_ERROR)['captures'][0];
$socket=getenv('LIBRARY_FIXTURE_SOCKET');if(!$socket||!str_starts_with($socket,'/tmp/'))throw new RuntimeException('isolated socket only');
$db=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$schema='root_component_dependency_'.getmypid();$db->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);$records=[];
try{
 foreach($old['current_schema'] as $table=>$columns){$defs=[];foreach($columns as $column){if(!in_array($column['data_type'],['text','integer','bigint'],true))throw new RuntimeException('unexpected captured type');$defs[]=$column['column_name'].' '.$column['data_type'];}$db->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}
 // These are the real ComponentContext::load query texts, not predicates.
 foreach([['SELECT actor_id,host FROM following WHERE owner_actor_id=$1 OR owner_actor_id=$2',['https://mkultra.monster/users/fixture','https://mkultra.monster/users/fixture/']],['SELECT target_did FROM bsky_graph_sync WHERE owner_user_id=$1::bigint AND kind=\'follow\'',[1]],['SELECT actor_ref,did,profile_json FROM bsky_actor_profiles WHERE actor_ref=ANY($1) OR did=ANY($2) ORDER BY updated_at DESC',['{}','{}']]] as [$sql,$parameters]){
  $actual=$sql;foreach(array_keys($parameters) as $index)$actual=str_replace('$'.($index+1),'?',$actual);
  try{$query=$db->prepare($actual);$query->execute($parameters);$records[]=['actual_native_sql'=>$sql,'sqlstate'=>null,'rows'=>$query->fetchAll()];}
  catch(PDOException $error){$records[]=['actual_native_sql'=>$sql,'sqlstate'=>$error->getCode(),'message'=>$error->getMessage()];}
 }
}finally{$db->exec('DROP SCHEMA '.$schema.' CASCADE');}
$artifact='/tmp/vaak-root-preview-component-dependency-errors.json';file_put_contents($artifact,json_encode(['old_schema_source_sha256'=>hash('sha256',json_encode($old['current_schema'],JSON_THROW_ON_ERROR)),'records'=>$records],JSON_THROW_ON_ERROR));foreach($records as $record)echo ($record['sqlstate']??'success')." ".$record['actual_native_sql']."\n";
