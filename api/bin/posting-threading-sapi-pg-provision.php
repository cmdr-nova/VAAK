<?php
/** Fixture DDL from empty migrated legacy schema; no production copies or application-function stubs. */
$db=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema='posting_threading_sapi';$db->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');if(getenv('POSTING_SAPI_DROP_ONLY')==='1')exit;
$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);
$src=new PDO('sqlite:'.getenv('POSTING_SAPI_SQLITE'));$src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach($src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table){
 $name=$table['name'];if(in_array($name,['rss_feeds','rss_items','phyrian_bridge_links','phyrian_bridge_challenges'],true))continue;
 $cols=$src->query('PRAGMA table_info("'.$name.'")')->fetchAll();$pk=array_filter($cols,fn($r)=>$r['pk']);$parts=[];
 foreach($cols as $r){$type=strtoupper($r['type']);$type=str_contains($type,'INT')?'BIGINT':(str_contains($type,'REAL')||str_contains($type,'FLOAT')?'DOUBLE PRECISION':(str_contains($type,'BLOB')?'BYTEA':'TEXT'));
 if(count($pk)===1&&$r['pk']&&$type==='BIGINT')$type='BIGSERIAL';
 $def='"'.$r['name'].'" '.$type;if($r['notnull'])$def.=' NOT NULL';if($r['dflt_value']!==null)$def.=' DEFAULT '.$r['dflt_value'];$parts[]=$def;
 }
 if($pk)$parts[]='PRIMARY KEY('.implode(',',array_map(fn($r)=>'"'.$r['name'].'"',$pk)).')';
 $db->exec('CREATE TABLE "'.$name.'"('.implode(',',$parts).')');
 foreach($src->query('PRAGMA index_list("'.$name.'")')->fetchAll() as $idx){if(!$idx['unique']||$idx['origin']==='pk')continue;$ics=$src->query('PRAGMA index_info("'.$idx['name'].'")')->fetchAll();if(in_array(null,array_column($ics,'name'),true))continue;$db->exec('CREATE UNIQUE INDEX ON "'.$name.'"('.implode(',',array_map(fn($r)=>'"'.$r['name'].'"',$ics)).')');}
}
foreach (['ap-instance-docs.php'=>['ap_instance_docs','ap_instance_rules'],'ap-site-publish.php'=>['site_syndications'],'ap-webpush.php'=>['push_subscriptions']] as $file=>$tables) {
 $source=file_get_contents((getenv('POSTING_SAPI_API_SOURCE')?:dirname(__DIR__)).'/'.$file);
 foreach($tables as $table){preg_match('/CREATE TABLE IF NOT EXISTS '.preg_quote($table,'/').' \(.*?\);/s',$source,$m);$ddl=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','BIGSERIAL PRIMARY KEY',$m[0]);$ddl=str_replace('{$idColumn}','BIGSERIAL PRIMARY KEY',$ddl);$db->exec($ddl);}
}
$db->exec('ALTER TABLE ap_search_docs ADD COLUMN body_tsv TSVECTOR');$db->exec('CREATE INDEX idx_ap_search_docs_body_tsv ON ap_search_docs USING GIN(body_tsv)');
echo "Fixture schema provisioned from declared empty column/unique-key inventory\n";
