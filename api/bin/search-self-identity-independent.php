<?php
declare(strict_types=1);
/** Real AS2 cache producer and account builder; isolated fetch/cache-write IO. */
function ap_remote_actor_get(string $actor):?array{$q=ap_db()->prepare('SELECT * FROM remote_actors WHERE actor_id=?');$q->execute([$actor]);$r=$q->fetch();return is_array($r)?$r:null;}
function ap_fetch_as2_object(string $actor):?array{return $GLOBALS['identity_docs'][$actor]??null;}
function ap_db_execute_retry(string $sql,array $params):bool{$q=ap_db()->prepare($sql);return $q->execute($params);}
function ap_remote_actor_flat_put(string $actor,array $row):void{}
// Persistence of numeric account mappings is transport only; identity fields
// still come from the unchanged canonical cache producer and account builder.
function ap_masto_account_actor_remember(string $id,string $actor):void{}
$argv[1]='--setup-only';require __DIR__.'/search-cache-privacy-independent.php';
ap_db()->exec('CREATE TABLE remote_actors(actor_id TEXT PRIMARY KEY,username TEXT,display_name TEXT,host TEXT,icon_source_url TEXT,image_source_url TEXT,updated_at TEXT,summary TEXT,profile_json TEXT)');
reference_load(dirname(__DIR__).'/ap-db.php',['ap_remote_actor_normalize_username','ap_remote_actor_username_is_placeholder','ap_remote_actor_id_is_unfederated_local','ap_remote_actor_upsert','ap_remote_actor_ensure','ap_remote_actor_label']);
reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_remote_account_acct_key','ap_masto_remote_account_id','ap_masto_actor_id_aliases','ap_masto_remote_account']);
$out=[];
foreach(['https://mkultra.monster/users/fixture','https://vaak.monster/users/fixture','https://mkultra.monster/@fixture'] as $i=>$url){
 $actor='https://remote.test/users/alice'.$i;
 $GLOBALS['identity_docs'][$actor]=['id'=>$actor,'preferredUsername'=>'fixture@mkultra.monster','name'=>'Remote identity marker','url'=>$url];
 ap_remote_actor_ensure($actor,true);
 $account=ap_masto_remote_account($actor,false);
 ap_db()->exec('DELETE FROM fixture_blocks');$q=ap_db()->prepare("INSERT INTO fixture_blocks VALUES(?,'actor',?)");$q->execute([1,$actor]);
 $hidden=ap_search_account_hidden($account,1);
 if($account['acct']!=='fixture@remote.test'||$account['uri']!==$actor||str_contains($account['url'],'mkultra.monster')||!$hidden)throw new RuntimeException('Actual producer identity conflict: '.json_encode($account));
 ap_db()->exec("DELETE FROM fixture_blocks;INSERT INTO fixture_blocks VALUES(1,'domain','remote.test')");
 $domainHidden=ap_search_account_hidden($account,1);
 if(!$domainHidden)throw new RuntimeException('Actual producer escaped remote domain block');
 $out['actual_as2_url_'.$i]=['acct'=>$account['acct'],'uri'=>$account['uri'],'url'=>$account['url'],'actor_hidden'=>$hidden,'domain_hidden'=>$domainHidden];
 // Explicit adversarial cache input: actual builder reproduces the ambiguity,
 // but normal AS2 producers above do not manufacture this conflicting host.
 $bad='https://remote.test/users/malformed'.$i;
 ap_remote_actor_upsert($bad,['username'=>'fixture','host'=>'mkultra.monster']);
 $badAccount=ap_masto_remote_account($bad,false);$q->execute([1,$bad]);
 $out['explicit_conflicting_cache_'.$i]=['acct'=>$badAccount['acct'],'uri'=>$badAccount['uri'],'url'=>$badAccount['url'],'hidden'=>ap_search_account_hidden($badAccount,1)];
}
foreach(['https://mkultra.monster/users/fixture','https://vaak.monster/users/fixture','https://www.vaak.monster/users/fixture'] as $ref){if(ap_search_account_hidden(['uri'=>$ref,'acct'=>'fixture'],1))throw new RuntimeException('Local self alias lost');}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
