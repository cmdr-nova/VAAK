<?php
declare(strict_types=1);
/** Actual PHP static-count helper, with a declared disposable content root. */
$source=(string)file_get_contents(dirname(__DIR__).'/ap-db.php');
if(!preg_match('/^function ap_site_content_counts\(.*?^}/ms',$source,$match))throw new RuntimeException('actual count helper declaration');
$root='/tmp/vaak-profile-static-count-'.getmypid();mkdir($root.'/api',0700,true);mkdir($root.'/assets/data',0700,true);
$resolved='';foreach(token_get_all('<?php '.$match[0]) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export($root.'/api',true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
$cases=['missing'=>null,'invalid'=>'{','null'=>'null','scalar'=>'42','array'=>'[5,6]','ordinary'=>'{"blog_posts":7,"notes":9}','negative'=>'{"blog_posts":-5,"notes":-2}','numeric'=>'{"blog_posts":" 12.9rest","notes":"2e1"}','mixed'=>'{"blog_posts":true,"notes":[1]}','empty'=>'{}','float'=>'{"blog_posts":12.9,"notes":2.1}','ascii_prefix'=>'{"blog_posts":" \t12rest","notes":"+7foo"}','nbsp'=>'{"blog_posts":"\u00a012","notes":"\u20037"}'];$captures=[];
try{foreach($cases as $name=>$bytes){$path=$root.'/assets/data/site-counts.json';if(is_file($path))unlink($path);if($bytes!==null)file_put_contents($path,$bytes);$captures[]=['case'=>$name,'bytes'=>$bytes,'counts'=>ap_site_content_counts()];}}
finally{if(is_file($root.'/assets/data/site-counts.json'))unlink($root.'/assets/data/site-counts.json');rmdir($root.'/assets/data');rmdir($root.'/assets');rmdir($root.'/api');rmdir($root);}
$compose=[];
if(getenv('LIBRARY_FIXTURE_DB')){
 $conn=(string)getenv('LIBRARY_FIXTURE_DB');if(!str_contains($conn,'/tmp/'))throw new RuntimeException('disposable local PostgreSQL only');
 $pg=new PDO('pgsql:'.$conn,'postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$GLOBALS['profile_count_fixture_db']=$pg;
 function ap_db(): PDO{return $GLOBALS['profile_count_fixture_db'];}
 if(!preg_match('/^function ap_compose_post_count\(.*?^}/ms',$source,$m))throw new RuntimeException('compose declaration');eval($m[0]);
 $schema='profile_compose_count_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{$pg->exec('CREATE TABLE outbox_notes(id TEXT,kind TEXT,visibility TEXT)');$rows=[['id'=>'owner-one','kind'=>'compose','visibility'=>'public'],['id'=>'owner-two','kind'=>null,'visibility'=>null],['id'=>'private','kind'=>'compose','visibility'=>'private'],['id'=>'unlisted','kind'=>'compose','visibility'=>'unlisted'],['id'=>'blog','kind'=>'blog','visibility'=>'public'],['id'=>'empty','kind'=>'','visibility'=>'public']];$st=$pg->prepare('INSERT INTO outbox_notes VALUES(?,?,?)');foreach($rows as $r)$st->execute(array_values($r));$compose[]=['rows'=>$rows,'count'=>ap_compose_post_count()];}
 finally{$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$out=$argv[1]??'/tmp/vaak-profile-static-count-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'compose'=>$compose,'declaration_sha256'=>hash('sha256',$match[0]),'scope'=>'Actual helper declaration; only __DIR__ fixture root transport changed. No PHP expectations rewritten.'],JSON_THROW_ON_ERROR));echo count($captures)." actual static count cases: $out\n";
