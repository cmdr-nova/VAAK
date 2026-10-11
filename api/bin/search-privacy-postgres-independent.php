<?php
/** Actual PHP Search guard + final painter on disposable INTEGER/BIGINT schemas. */
declare(strict_types=1);
if(!extension_loaded('pdo_pgsql'))throw new RuntimeException('Requires genuine PDOpgsql');
$argv[1]='--setup-only';require __DIR__.'/search-cache-privacy-independent.php';
$sqlite=ap_db();$tables=$sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'ap_search_%' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$socket=getenv('LIBRARY_FIXTURE_SOCKET');if(!is_string($socket)||!str_starts_with($socket,'/tmp/'))throw new RuntimeException('Disposable local fixture socket required');
$pg=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$checks=0;
foreach(['INTEGER','BIGINT'] as $width){
 $schema='search_privacy_review_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table){
   $cols=$sqlite->query('PRAGMA table_info('.$table.')')->fetchAll();$names=array_column($cols,'name');$defs=[];
   foreach($cols as $col)$defs[]=$col['name'].' '.(str_contains(strtoupper($col['type']),'INT')?$width:'TEXT');
   $pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');
   $insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');
   foreach($sqlite->query('SELECT * FROM '.$table)->fetchAll() as $row)$insert->execute(array_values($row));
  }
  $GLOBALS['fixture_db']=$pg;$GLOBALS['vaak_owner_id']=1;
  $status=$GLOBALS['review_statuses'][700];
  $paint=static function(bool $expected)use($status,&$checks):void{
   $rows=ap_search_filter_statuses(ap_masto_search_sanitize_statuses([$status]),1,20);ob_start();admin_search_render_sections(['statuses'=>$rows],'postgres','statuses',false,20,[]);$html=ob_get_clean();
   if((count($rows)===1)!==$expected||str_contains($html,'Search private marker')!==$expected)throw new RuntimeException('Actual PG Search current-state/fullHTML mismatch');$checks++;
  };
  $paint(false);$pg->exec("UPDATE actor_profile SET indexable=1 WHERE actor_key='other'");$paint(true);
  $pg->exec("UPDATE outbox_notes SET visibility='private' WHERE id LIKE '%/700'");$paint(false);
  $pg->exec("INSERT INTO followers VALUES('https://mkultra.monster/users/other','https://mkultra.monster/users/fixture')");$paint(true);$pg->exec('DELETE FROM followers');$paint(false);
  $pg->exec("UPDATE outbox_notes SET visibility='direct',to_json='[\"https://mkultra.monster/users/fixture\"]' WHERE id LIKE '%/700'");$paint(true);
  $pg->exec("UPDATE outbox_notes SET to_json='[]',cc_json='[\"https://mkultra.monster/users/fixture\"]' WHERE id LIKE '%/700'");$paint(true);
  $pg->exec("UPDATE outbox_notes SET cc_json='[]' WHERE id LIKE '%/700'");$paint(false);
  $pg->exec("UPDATE outbox_notes SET visibility='unlisted' WHERE id LIKE '%/700'");$paint(true);
  $pg->exec('ALTER TABLE actor_profile RENAME TO unavailable_policy');$paint(false);$pg->exec('ALTER TABLE unavailable_policy RENAME TO actor_profile');$paint(true);
  $pg->exec("DELETE FROM masto_statuses WHERE local_id=700;DELETE FROM outbox_notes WHERE id LIKE '%/700'");$paint(false);
  echo "search-privacy-postgres-independent $width PASS\n";
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
echo "search-privacy-postgres-independent: PASS ($checks genuine PDOpgsql current-state and actual full Search HTML controls)\n";
