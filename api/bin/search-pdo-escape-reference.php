<?php
declare(strict_types=1);
/** Actual patched Search helper declarations + actual PDO PG/SQLite execution. */
$escapeArtifact=$argv[1]??'/tmp/vaak-search-pdo-escape-reference.json';
putenv('ROOT_CAPTURE_FRESH=1');putenv('ROOT_LIBRARY_SEARCH_PRODUCER_BOOTSTRAP=1');
$argv[1]='/tmp/vaak-search-escape-bootstrap-'.getmypid().'.json';ob_start();require __DIR__.'/root-preview-callers-production-reference.php';ob_end_clean();unlink($argv[1]);
$results=[];$decl=[];$pdoConfiguration=[];$entities=file_get_contents(dirname(__DIR__).'/ap-masto-entities.php');
foreach(['ap_masto_search_like_escape_sql','ap_masto_search_accounts','ap_masto_search_statuses','ap_masto_timeline_hashtag_local','ap_masto_followed_tag_event_rows','ap_masto_search_tag_seen'] as $name){preg_match('/^function '.preg_quote($name,'/').'\(.*?^}/ms',$entities,$match);if(!$match)throw new RuntimeException($name);$decl[$name]=hash('sha256',$match[0]);}
foreach(['SQLITE','INTEGER','BIGINT'] as $width){
 $db=$width==='SQLITE'?$sqlite:$pg;$schema='search_escape_'.strtolower($width).'_'.getmypid();
 if($width!=='SQLITE'){$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}}
 $pdoConfiguration[$width]=['driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'emulate_prepares'=>$width==='SQLITE'?null:$db->getAttribute(PDO::ATTR_EMULATE_PREPARES)];
 $GLOBALS['fixture_db']=$db;$GLOBALS['view']='search';direct_bind(1);direct_production_prepare_moderation();
 try{
  foreach(['percent'=>'literal%needle','underscore'=>'literal_needle','backslash'=>'literal\\needle','unicode'=>'caféneedle','quote'=>"literal'needle"] as $label=>$query){
   $db->prepare('UPDATE masto_statuses SET content_text=?,spoiler_text=\'\' WHERE local_id=456')->execute([$query]);
   $db->prepare('UPDATE outbox_notes SET content=?')->execute([$query]);
   $db->prepare('UPDATE ap_users SET username=? WHERE id=1')->execute([$query]);
   $status=ap_masto_search_statuses($query,20);$accounts=ap_masto_search_accounts($query,false,20);
   // Existing LIKE backslash policy consumes a single slash as its escape.
   // Match a literal backslash by supplying the existing doubled query, rather
   // than changing query normalization or inventing a new policy in this fix.
   $escapedQuery=str_replace('\\','\\\\',$query);
   $backslashPositive=$label==='backslash'?['statuses'=>count(ap_masto_search_statuses($escapedQuery,20)),'accounts'=>count(ap_masto_search_accounts($escapedQuery,false,20))]:null;
   $negative=ap_masto_search_statuses($query.'-absent',20);
   $results[]=['width'=>$width,'kind'=>'text','label'=>$label,'query'=>$query,'statuses'=>count($status),'accounts'=>count($accounts),'backslash_positive'=>$backslashPositive,'negative_statuses'=>count($negative),'escape_sql'=>ap_masto_search_like_escape_sql()];
  }
  $db->exec('DROP TABLE IF EXISTS masto_followed_tags;CREATE TABLE masto_followed_tags(owner_user_id INTEGER,name TEXT,followed_at TEXT)');
  foreach(['plain'=>'fixtag','underscore'=>'fix_tag','unicode'=>'café','quote'=>"fix'tag",'percent'=>'fix%tag','backslash'=>'fix\\tag'] as $label=>$tag){
   $normalized=ap_masto_normalize_tag_name($tag);
   $db->prepare('UPDATE masto_statuses SET content_text=?,spoiler_text=\'\' WHERE local_id=456')->execute(['#'.$normalized]);
   $db->prepare('UPDATE outbox_notes SET content=?')->execute(['#'.$normalized]);
   $db->prepare('UPDATE events SET summary=?')->execute(['#'.$normalized]);
   $db->exec('DELETE FROM masto_followed_tags');$db->prepare('INSERT INTO masto_followed_tags(owner_user_id,name,followed_at) VALUES(1,?,?)')->execute([$normalized,'2026-10-10T12:00:00Z']);ap_masto_followed_tags_cache_clear(1);
   $followedRows=ap_masto_followed_tag_event_rows(20,1);
   $results[]=['followed_count'=>count($followedRows),'width'=>$width,'kind'=>'tag','label'=>$label,'input'=>$tag,'normalized'=>$normalized,'seen'=>ap_masto_search_tag_seen($tag),'local_count'=>count(ap_masto_timeline_hashtag_local([$tag],20)),'absent_seen'=>ap_masto_search_tag_seen($normalized.'absent')];
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;if($width!=='SQLITE')$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$comparison=[];foreach($results as $row){$key=$row['kind'].'/'.$row['label'];$copy=$row;unset($copy['width'],$copy['escape_sql']);if(isset($comparison[$key])&&$comparison[$key]!==$copy)throw new RuntimeException('PDO driver mismatch '.$key);$comparison[$key]=$copy;
 if($row['kind']==='text'){if($row['negative_statuses']!==0)throw new RuntimeException('negative literal query');if($row['label']!=='backslash'&&($row['statuses']!==1||$row['accounts']!==1))throw new RuntimeException('literal match missing');if($row['label']==='backslash'&&$row['backslash_positive']!==['statuses'=>1,'accounts'=>1])throw new RuntimeException('existing escaped backslash query');}
 else{if(!$row['seen']||$row['absent_seen']||$row['local_count']!==1||$row['followed_count']<1)throw new RuntimeException('actual tag helper branch missing');}}
file_put_contents($escapeArtifact,json_encode(['pdo_configuration'=>$pdoConfiguration,'helper_sha256'=>$decl,'production_pdo'=>'ap_db direct native prepares; no SQL adapter','scope'=>'Actual PHP helper declarations and real PDO SQLite/PostgreSQL INTEGER/BIGINT. Existing source mapper/session/network bootstrap declared. Backslash query policy retained.','results'=>$results],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo count($results).' actual PDO controls: '.$escapeArtifact."\n";
