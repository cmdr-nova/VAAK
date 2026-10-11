<?php
declare(strict_types=1);
/** Real isolated PHP SAPI/session/form decoding through unchanged auth/CSRF/save source. */
$socket=(string)getenv('LIBRARY_FIXTURE_SOCKET');if(!str_starts_with($socket,'/tmp/'))throw new RuntimeException('isolated socket required');
$pg=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$source=json_decode((string)file_get_contents('/tmp/vaak-settings-postgres-native-reference.json'),true,512,JSON_THROW_ON_ERROR);
$schema='settings_e2e_'.getmypid();$sessions='/tmp/vaak-settings-e2e-sessions-'.getmypid();mkdir($sessions,0700);
$worker=dirname(__DIR__,2).'/rust/target/debug/vaak-worker';$procs=[];$captures=[];$checks=0;
$runId=bin2hex(random_bytes(16));$reservations=[];$ports=[];
// Hold both kernel-selected ports together, then release immediately before launch.
// Liveness and per-run response identity detect any competitor winning the bind race.
foreach(range(0,1) as $i){$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if($listener===false)throw new RuntimeException('reserve isolated listener: '.$error);$reservations[]=$listener;$address=stream_socket_get_name($listener,false);$ports[]=(int)substr($address,strrpos($address,':')+1);}
function e2e_assert_processes():void{foreach($GLOBALS['procs'] as $proc){if(!proc_get_status($proc)['running'])throw new RuntimeException('owned fixture process exited; refusing another listener');}}
function echeck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function request_e2e(string $base,array $data=[],?string $cookie=null,string $query=''):array{
 e2e_assert_processes();
 $headers=['Accept: application/json','X-Requested-With: XMLHttpRequest'];if($cookie!==null)$headers[]='Cookie: vaak_sess='.$cookie;
 if($data)$headers[]='Content-Type: application/x-www-form-urlencoded';
 $ctx=stream_context_create(['http'=>['method'=>$data?'POST':'GET','header'=>implode("\r\n",$headers),'content'=>$data?http_build_query($data):'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
 $raw=file_get_contents($base.'/?view=profile'.$query,false,$ctx);e2e_assert_processes();preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
 return ['status'=>(int)($m[1]??0),'headers'=>$http_response_header,'raw'=>$raw,'body'=>json_decode((string)$raw,true)];
}
function ecapture(string $case,array $reply,bool $success=true):array{
 $GLOBALS['captures'][]=['case'=>$case,'http'=>$reply,'rows'=>$GLOBALS['pg']->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll(),'users'=>$GLOBALS['pg']->query('SELECT id,actor_key,email,disabled_at FROM ap_users ORDER BY id')->fetchAll()];
 if(!$success)return $reply['body']??[];
 echeck($reply['status']===200,$case.' HTTP success '.(string)$reply['raw']);$body=$reply['body'];
 if(($body['fixture_run_id']??null)!==$GLOBALS['runId'])throw new RuntimeException('wrong per-run SAPI identity');
 echeck(is_array($body['native']??null),$case.' actual bridge reply');echeck(($body['native']['actor_key']??null)===$body['actor_key'],$case.' current owner identity');
 $fields=$body['native']['fields'];$php=$body['php'];
 foreach($fields as $key=>$value){$expected=match($key){'attachment_json'=>json_encode($php['attachment'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'profile_badges'=>json_encode($php['profile_badges'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),default=>$php[$key]??null};
 if(in_array($key,['attachment_json','profile_badges'],true))echeck(json_decode($value,true)===json_decode($expected,true),$case.' '.$key);else echeck($value===$expected,$case.' '.$key);}
 echeck($body['collection_consent']===$fields['collection_consent'],$case.' genuine consent getter');echeck($body['webmention_enabled']===$fields['webmentions_enabled'],$case.' genuine Webmention target gate');
 foreach(['reply_policy'=>'canReply','quote_policy'=>'canQuote'] as $field=>$policy){$approval=match($fields[$field]){'followers'=>['https://www.w3.org/ns/activitystreams#Followers'],'nobody'=>[],default=>['https://www.w3.org/ns/activitystreams#Public']};echeck($body['interaction_policy'][$policy]['automaticApproval']===$approval,$case.' actual interaction policy '.$field);}
 return $body;
}
try{
 $pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 foreach(['ap_users','actor_profile'] as $table){$columns=array_values(array_filter($source['schema_columns']['INTEGER'],fn($r)=>$r['table_name']===$table));$defs=[];foreach($columns as $column)$defs[]=$column['column_name'].' '.$column['data_type'].($column['is_nullable']==='NO'?' NOT NULL':'').($column['column_default']!==null?' DEFAULT '.$column['column_default']:'');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}
 $pg->exec('ALTER TABLE ap_users ADD PRIMARY KEY(id);ALTER TABLE ap_users ADD UNIQUE(actor_key);ALTER TABLE actor_profile ADD PRIMARY KEY(actor_key)');
 foreach([1=>'cmdr_nova',201=>'settings_alice',202=>'settings_bob'] as $id=>$key)$pg->prepare('INSERT INTO ap_users(id,username,actor_key,actor_id,is_admin,email) VALUES(?,?,?,?,?,?)')->execute([$id,$key,$key,'https://mkultra.monster/users/'.$key,0,$key.'@fixture.test']);
 foreach(['settings_alice','settings_bob'] as $key){$row=$source['captures'][0]['current_row'];$row['actor_key']=$key;$row['name']=$key.'_'.$schema;$cols=array_keys($row);$pg->prepare('INSERT INTO actor_profile('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));}
 foreach(['alice'=>201,'bob'=>202] as $name=>$id){$sid='settingse2e'.$name.getmypid();file_put_contents($sessions.'/sess_'.$sid,'vaak_user_id|i:'.$id.';_vaak_csrf|s:64:"'.str_repeat($name==='alice'?'a':'b',64).'";');$cookies[$name]=$sid;}
 $env=['PATH'=>getenv('PATH'),'LIBRARY_FIXTURE_SOCKET'=>$socket,'VAAK_SETTINGS_E2E_SCHEMA'=>$schema,'VAAK_SETTINGS_E2E_SESSIONS'=>$sessions,'VAAK_SETTINGS_E2E_RUN_ID'=>$runId,'VAAK_SHADOW_HTTP'=>'http://127.0.0.1:'.$ports[0],'LD_LIBRARY_PATH'=>'/usr/lib/rustlib/x86_64-unknown-linux-gnu/lib','VAAK_ENV_FILE'=>'/tmp/no-settings-e2e-env','VAAK_PHYRIAN_BRIDGE_ENV_FILE'=>'/tmp/no-settings-e2e-bridge-env','VAAK_DATABASE_URL'=>'host='.$socket.' user=postgres dbname=postgres options=\'-csearch_path='.$schema.'\'','VAAK_SHADOW_OWNER_ID'=>'201','VAAK_REDIS_URL'=>'redis://127.0.0.1:1/0','VAAK_REDIS_QUEUE_URL'=>'redis://127.0.0.1:1/1','VAAK_BSKY_PUBLIC_API'=>'http://127.0.0.1:1','VAAK_LIBRARY_NATIVE_RENDER'=>'0','VAAK_SEARCH_NATIVE_RENDER'=>'0'];
 $commands=[[$worker,'serve','--bind','127.0.0.1:'.$ports[0]],[__DIR__.'/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','-S','127.0.0.1:'.$ports[1],__DIR__.'/settings-e2e-router.php']];
 foreach($reservations as $listener)fclose($listener);$reservations=[];
 foreach($commands as $i=>$cmd){$p=[];$proc=proc_open($cmd,[['pipe','r'],['file','/tmp/vaak-settings-e2e-'.$i.'.log','a'],['file','/tmp/vaak-settings-e2e-'.$i.'.log','a']],$p,null,$env);if(!is_resource($proc))throw new RuntimeException('start fixture');fclose($p[0]);$procs[]=$proc;}
 foreach($ports as $port){$ready=false;for($i=0;$i<50;$i++){usleep(100000);e2e_assert_processes();$conn=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($conn){fclose($conn);$ready=true;break;}}if(!$ready)throw new RuntimeException('owned fixture listener readiness timeout');}
 e2e_assert_processes();$base='http://127.0.0.1:'.$ports[1];$alice=ecapture('authenticated_initial',request_e2e($base,[],$cookies['alice']));$bob=ecapture('second_owner_initial',request_e2e($base,[],$cookies['bob']));
 $form=['action'=>'save_profile','csrf'=>str_repeat('a',64),'name'=>'Alice Ω','summary'=>'<p>Form Ω</p>','email'=>'settings_alice@fixture.test','reply_policy'=>'followers','quote_policy'=>'nobody','field_name'=>['Pronouns'],'field_value'=>['she/her Ω'],'forum_signature'=>'Signature Ω','profile_badges'=>['transgender','gamer']];
 $flags=array_values(array_diff($source['canonical_fields'],['name','summary','icon_url','image_url','attachment_json','reply_policy','quote_policy','forum_signature','profile_badges','updated_at']));
 $all=$form;foreach($flags as $flag)$all[$flag]='1';
 $all['owner_id']=202;$all['actor_key']='settings_bob';$all['is_admin']='1';
 $enabled=ecapture('all_form_boxes_enabled_owner_smuggling',request_e2e($base,$all,$cookies['alice'],'&owner_id=202'));
 foreach($flags as $flag)echeck($enabled['native']['fields'][$flag]===($flag!=='anti_ai_marker'),'actual checkbox '.$flag);
 echeck($enabled['owner']===201&&$enabled['native']['actor_key']==='settings_alice','forged owner ignored');
 echeck(ecapture('other_owner_after_save',request_e2e($base,[],$cookies['bob']))['native']===$bob['native'],'other account unchanged');
 $off=ecapture('all_form_boxes_omitted',request_e2e($base,$form,$cookies['alice']));foreach($flags as $flag)echeck($off['native']['fields'][$flag]===false,'unchecked box clears '.$flag);
 foreach($flags as $flag){if($flag==='anti_ai_marker')continue;$one=$form;$one[$flag]='1';$body=ecapture('single_form_box_'.$flag,request_e2e($base,$one,$cookies['alice']));foreach($flags as $other)echeck($body['native']['fields'][$other]===($flag===$other),'single form exact checkbox '.$flag.'/'.$other);}
 foreach(['anyone','followers','nobody'] as $policy)ecapture('form_policy_'.$policy,request_e2e($base,array_replace($form,['reply_policy'=>$policy,'quote_policy'=>$policy]),$cookies['alice']));
 $before=$pg->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll();$beforeUsers=$pg->query('SELECT * FROM ap_users ORDER BY id')->fetchAll();
 foreach(['anonymous'=>[null,$form],'missing_csrf'=>[$cookies['alice'],array_diff_key($form,['csrf'=>true])],'wrong_csrf'=>[$cookies['alice'],array_replace($form,['csrf'=>'wrong'])],'cross_session_csrf'=>[$cookies['bob'],$form]] as $case=>[$cookie,$post]){$reply=request_e2e($base,$post,$cookie);ecapture($case,$reply,false);echeck($reply['status']===($case==='anonymous'?401:403),$case.' rejection');echeck($pg->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll()===$before,$case.' no profile write');echeck($pg->query('SELECT * FROM ap_users ORDER BY id')->fetchAll()===$beforeUsers,$case.' no user write');}
 $invalid=ecapture('invalid_email',request_e2e($base,array_replace($form,['email'=>'not-email']),$cookies['alice']));echeck($invalid['error']!==null&&$invalid['save']===null,'email rejection prevents profile save');echeck($pg->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll()===$before,'invalid email no profile mutation');
 $pg->exec("UPDATE ap_users SET disabled_at='2026-10-11' WHERE id=201");$disabled=request_e2e($base,$form,$cookies['alice']);ecapture('disabled_existing_session',$disabled,false);echeck($disabled['status']===401,'disabled session rejected');echeck($pg->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll()===$before,'disabled owner no mutation');
 file_put_contents('/tmp/vaak-settings-e2e-reference.json',json_encode(['fixture_run_id'=>$runId,'listener_ports'=>$ports,'checks'=>$checks,'captures'=>$captures,'schema_source_sha256'=>hash_file('sha256','/tmp/vaak-settings-postgres-native-reference.json'),'worker_sha256'=>hash_file('sha256',$worker),'router_sha256'=>hash_file('sha256',__DIR__.'/settings-e2e-router.php'),'runtime_source_sha256'=>['ap-admin'=>hash_file('sha256',dirname(__DIR__).'/ap-admin.php'),'ap-auth'=>hash_file('sha256',dirname(__DIR__).'/ap-auth.php'),'ap-db'=>hash_file('sha256',dirname(__DIR__).'/ap-db.php')],'substitutions'=>['database'=>'disposable schema seeded from prior actual types/profile row','sessions'=>'isolated path; established-user sessions preseeded, no password login claim','redis'=>'request-local recorder','bootstrap'=>'top-level application/module includes omitted; genuine auth bootstrap seeded cmdr_nova early return','save'=>'unchanged pre-publish action body; stops before success/errors toast/publish/Bluesky effects','native'=>'actual helper called explicitly by fixture; production declaration currently has no callers','uploads'=>'no files; no upload/storage assertion']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 echo 'settings-e2e-reference PASS '.$checks.' checks / '.count($captures)." captures\n";
}finally{foreach($reservations as $listener)fclose($listener);foreach($procs as $proc){proc_terminate($proc);proc_close($proc);}$pg->exec('SET search_path=public;DROP SCHEMA IF EXISTS '.$schema.' CASCADE');foreach(glob($sessions.'/*')?:[] as $file)unlink($file);rmdir($sessions);}
