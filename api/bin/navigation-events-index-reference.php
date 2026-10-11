<?php
/** Actual Rust query bytes evaluated before/after the proposed index only. */
declare(strict_types=1);
$queries=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket;dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_NUM]);
$db->exec("SET statement_timeout='4s'; SET lock_timeout='1s'");$results=[];
foreach(['INTEGER','BIGINT'] as $width){
 $schema='navigation_events_index_'.getmypid().'_'.strtolower($width);
 $db->exec("CREATE SCHEMA $schema;SET search_path TO $schema");
 try{
  $db->exec("CREATE TABLE events(id $width PRIMARY KEY,type TEXT,actor_id TEXT,object_id TEXT,summary TEXT,media_urls TEXT,created_at TEXT,sensitive INTEGER,spoiler_text TEXT,host TEXT,target_actor TEXT,in_reply_to TEXT,action_taken TEXT,visibility TEXT);
   INSERT INTO events SELECT n,'Create','https://filler.invalid/users/filler','https://filler.invalid/statuses/'||n,'content','[]','2026-10-11',0,'','filler.invalid','','','log','public' FROM generate_series(1000,20999) n;
   CREATE INDEX events_raw_object ON events(object_id); CREATE INDEX events_type ON events(type)");
  $vectors=[
   ['Create','live','log','public'],['Update','live/','log','public'],
   ['Create','deleted','log','public'],['Delete','deleted/','log','public'],
   ['Create','tombstone/','log','public'],['Tombstone','tombstone','log','public'],
   ['Update','withdrawn/','deleted','private'],
   ['Announce','orphan-private/','log','private'],['Announce','orphan-null-action',null,'unlisted'],
   ['Announce','public-announcement/','log','public'],['Announce','deleted-announcement','deleted','private'],
   ['Create','original-private','log','private'],['Announce','original-private/','log','private'],
   ['Create','self-reply','log','public'],['Create','self-reply/','log','public'],
   ['Announce','explicit-public/','log','public'],['Announce','wrong-target/','log','public'],
   ['Create','empty-content','log','public'],['Update','empty-content/','log','public'],
   ['Note','note/','local_observe','public'],['Quote','quote-only','log','private'],
   ['Create','multi-slash//','local_fav_update','public'],['Update','multi-slash/','log','unlisted'],
  ];
  $insert=$db->prepare('INSERT INTO events VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$keys=[];
  foreach($vectors as $i=>$v){[$type,$key,$action,$visibility]=$v;$uri='https://fixture.invalid/statuses/'.$key;$keys[]=rtrim($uri,'/');$keys[]=$uri;
   $insert->execute([$i+1,$type,'https://fixture.invalid/users/actor',$uri,$key==='empty-content/'?'':'content','[]','2026-10-11',0,'','fixture.invalid',$key==='wrong-target/'?'https://wrong.invalid':'https://fixture.invalid',$key==='self-reply/'?'https://fixture.invalid/statuses/live':'',$action,$visibility]);
  }
  $keys[]='https://fixture.invalid/statuses/missing';$keys=array_values(array_unique($keys));
  $array='ARRAY['.implode(',',array_map(fn($v)=>$db->quote($v),$keys)).']::text[]';
  $db->exec('ANALYZE events');$before=[];$timings=[];
  foreach($queries as $i=>$q){$sql=str_replace('$1',$array,$q['sql']);$start=hrtime(true);$rows=$db->query($sql)->fetchAll();$timings[$i]=['before_ms'=>(hrtime(true)-$start)/1e6];
   // DISTINCT denial sets have no order contract; retain ordered vectors otherwise.
   if(str_starts_with($sql,'SELECT DISTINCT ')&&!str_starts_with($sql,'SELECT DISTINCT ON'))sort($rows);
   $before[$i]=$rows;$plans[$i]['before']=$db->query('EXPLAIN (FORMAT JSON) '.$sql)->fetchColumn();
  }
  $db->exec("CREATE INDEX idx_events_object_key_id ON events(rtrim(object_id,'/'),id DESC)");
  foreach($queries as $i=>$q){$sql=str_replace('$1',$array,$q['sql']);$start=hrtime(true);$rows=$db->query($sql)->fetchAll();$timings[$i]['after_ms']=(hrtime(true)-$start)/1e6;
   if(str_starts_with($sql,'SELECT DISTINCT ')&&!str_starts_with($sql,'SELECT DISTINCT ON'))sort($rows);
   if($rows!==$before[$i])throw new RuntimeException("Parity mismatch $width query$i");
   $plan=$db->query('EXPLAIN (FORMAT JSON) '.$sql)->fetchColumn();
   if(!str_contains($plan,'idx_events_object_key_id'))throw new RuntimeException("Index unused $width query$i");
   $results[]=['width'=>$width,'source'=>$q['source'],'sha256'=>$q['sha256'],'query'=>$q['sql'],'row_count'=>count($rows),'vector_sha256'=>hash('sha256',json_encode($rows)),'timing'=>$timings[$i],'before_plan'=>json_decode($plans[$i]['before'],true),'after_plan'=>json_decode($plan,true)];
  }
 }finally{$db->exec("SET search_path TO public;DROP SCHEMA $schema CASCADE");}
}
echo json_encode(['passed'=>count($results),'filler_rows'=>20000,'vectors'=>count($vectors),'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
