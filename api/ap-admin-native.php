<?php
/** Default-off complete Admin read body; PHP retains sessions, shell and all actions. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);header('Cache-Control: no-store');exit('Library only');
}
function ap_admin_native_enabled(): bool
{
    return getenv('VAAK_ADMIN_READ_NATIVE') === '1' && strlen((string)getenv('VAAK_ADMIN_READ_SECRET')) >= 32;
}
function ap_admin_native_url_allowed(string $url,string $path='/internal/admin/read'): bool
{
    $p=parse_url($url);
    return is_array($p) && ($p['scheme']??'')==='http' && in_array($p['host']??'', ['127.0.0.1','[::1]'],true)
        && ($p['path']??'')===$path
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
}
function ap_admin_native_reply(array $reply,array $request): ?string
{
    $keys=array_keys($reply);sort($keys);$expected=['protocol_version','ok','request_binding','source_count','rows','html'];sort($expected);
    if($keys!==$expected || $reply['protocol_version']!==1 || $reply['ok']!==true || !is_string($reply['html'])
        || strlen($reply['html'])>2*1024*1024 || !is_int($reply['source_count']) || !is_array($reply['rows'])
        || !array_is_list($reply['rows']) || count($reply['rows'])!==$reply['source_count'] || count($reply['rows'])>match($request['view']){'users'=>20,'blocks'=>1000,'settings_lists'=>3000,'settings_follow_requests'=>1000,'settings_mutes'=>1000,'settings_featured'=>40,'settings_profile'=>1,'settings_links'=>2,'settings_ai'=>1,'settings_tokens'=>40,'settings_two_factor'=>1,'settings_aliases'=>1000,'settings_move'=>1,'stats'=>1000,'queue_health'=>6,'downranking'=>1000,'downranked'=>300,'moderation'=>80,default=>100}) return null;
    $binding=[];foreach(['owner_id','view','mode','offset','approvals_enabled'] as $key)$binding[$key]=$request[$key];
    if($request['view']==='users')$binding['search']=$request['search'];
    if(str_starts_with($request['view'],'settings_'))$binding['viewer_admin']=$request['viewer_admin'];
    if($request['view']==='moderation')$binding['filter']=$request['filter'];
    if(!is_array($reply['request_binding']))return null;
    $bindingKeys=array_keys($reply['request_binding']);sort($bindingKeys);$requiredBinding=array_keys($binding);sort($requiredBinding);if($bindingKeys!==$requiredBinding)return null;
    foreach($binding as $key=>$value)if($reply['request_binding'][$key]!==$value)return null;
    if($request['view']==='settings_lists')return ap_settings_native_rows_valid($reply['rows'])?$reply['html']:null;
    if(str_starts_with($request['view'],'settings_'))return ap_settings_native_account_rows_valid($request['view'],$reply['rows'])?$reply['html']:null;
    if($request['view']==='moderation')return ap_moderation_native_rows_valid($reply['rows'])?$reply['html']:null;
    if($request['view']==='stats')return ap_admin_stats_native_rows_valid($reply['rows'])?$reply['html']:null;
    if($request['view']==='queue_health')return ap_admin_queue_health_native_rows_valid($reply['rows'])?$reply['html']:null;
    if($request['view']==='downranking')return ap_admin_downranking_native_rows_valid($reply['rows'])?$reply['html']:null;
    if($request['view']==='downranked')return ap_admin_downranked_native_rows_valid($reply['rows'])?$reply['html']:null;
    if($request['view']==='friend_servers')return ap_admin_friend_servers_native_rows_valid($reply['rows'])?$reply['html']:null;
    $required=match($request['view']){'users'=>['id','username','email','actor_key','actor_id','is_admin','created_at','updated_at','disabled_at','profile_name','icon_url'],
        'invites'=>['id','code','created_at','used_at','note'],'relays'=>['id','inbox_url','state','follow_activity_id','created_at','updated_at'],'policies'=>['id','text'],'blocks'=>['id','scope','value','kind','reason','created_at'],
        default=>['id','username','email','status','created_at','decided_at']};sort($required);
    foreach($reply['rows'] as $row){
        if(!is_array($row))return null;$rowKeys=array_keys($row);sort($rowKeys);if($rowKeys!==$required || !is_int($row['id']) || $row['id']<1)return null;
        $mandatory=match($request['view']){'users'=>['username','actor_key','actor_id','created_at','updated_at'],'invites'=>['code','created_at'],
            'relays'=>['inbox_url','state','created_at','updated_at'],'policies'=>['text'],'blocks'=>['scope','value','kind','created_at'],default=>['username','status','created_at']};
        foreach($row as $key=>$value){if($key==='id')continue;if($request['view']==='users'&&$key==='is_admin'){if(!is_int($value))return null;continue;}if(!is_string($value)&&($value!==null||in_array($key,$mandatory,true)))return null;}
        if($request['view']==='approvals'&&!in_array($row['status'],['pending','approved','rejected'],true))return null;
    }
    return $reply['html'];
}
/** Wafrn friend override rows: host, optional note and update time, no duplicate hosts. */
function ap_admin_friend_servers_native_rows_valid(array $rows): bool
{
    $required=['host','note','updated_at'];$seen=[];
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        if(!is_string($row['host']) || $row['host']==='' || isset($seen[$row['host']]))return false;$seen[$row['host']]=true;
        foreach(['note','updated_at'] as $key)if($row[$key]!==null && !is_string($row[$key]))return false;
    }
    return true;
}
/** AP stats per-user rows: account identity, four counts and two flags. */
function ap_admin_stats_native_rows_valid(array $rows): bool
{
    $required=['id','username','posts','followers','following','mentions','is_admin','disabled'];sort($required);$last=0;
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        if(!is_int($row['id']) || $row['id']<=$last || !is_string($row['username']) || !is_bool($row['is_admin']) || !is_bool($row['disabled']))return false;$last=$row['id'];
        foreach(['posts','followers','following','mentions'] as $key)if(!is_int($row[$key]) || $row[$key]<0)return false;
    }
    return true;
}
/** Queue health rows: the six PHP queue classes in order, with their counts and availability. */
function ap_admin_queue_health_native_rows_valid(array $rows): bool
{
    $names=['User actions','Federation delivery','Federation fan-out','Remote media warming','Scheduled posts','Actor/profile refresh'];
    $required=['name','ok','queued','active','failed','retries'];sort($required);
    if(count($rows)!==count($names))return false;
    foreach($rows as $i=>$row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required || $row['name']!==$names[$i] || !is_bool($row['ok']))return false;
        foreach(['queued','active','failed','retries'] as $key)if(!is_int($row[$key]) || $row[$key]<0 || (!$row['ok'] && $row[$key]!==0))return false;
    }
    return true;
}
/** Home downranking vocabulary rows: one distinct term id each. */
function ap_admin_downranking_native_rows_valid(array $rows): bool
{
    $seen=[];
    foreach($rows as $row){
        if(!is_array($row) || array_keys($row)!==['id'] || !is_int($row['id']) || $row['id']<1 || isset($seen[$row['id']]))return false;$seen[$row['id']]=true;
    }
    return true;
}
/** Downranked rows: one distinct remote actor each, with its viewer and evidence counts. */
function ap_admin_downranked_native_rows_valid(array $rows): bool
{
    $required=['actor_id','viewers','evidence'];sort($required);$seen=[];
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        if(!is_string($row['actor_id']) || !str_starts_with($row['actor_id'],'https://') || isset($seen[$row['actor_id']]))return false;$seen[$row['actor_id']]=true;
        if(!is_int($row['viewers']) || $row['viewers']<1 || !is_int($row['evidence']) || $row['evidence']<0 || $row['evidence']>5)return false;
    }
    return true;
}
/** Personal list rows: deprioritized accounts carry no id; muted words and personal blocks do. */
function ap_settings_native_rows_valid(array $rows): bool
{
    $required=['list','id','value','note','scope','kind','created_at'];sort($required);
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        if(!in_array($row['list'],['deprioritized','muted_words','user_blocks'],true) || !is_string($row['value']) || !is_string($row['created_at']))return false;
        if($row['list']==='deprioritized'?$row['id']!==null:(!is_int($row['id']) || $row['id']<1))return false;
        foreach(['note','scope','kind'] as $key)if($row[$key]!==null&&!is_string($row[$key]))return false;
        if(($row['list']==='user_blocks')!==(is_string($row['scope'])&&is_string($row['kind'])))return false;
    }
    return true;
}
/** Follow request, Featured, Muted account, profile form, linked-avatar, AI key presence, OAuth token, 2FA presence, alias and Move state rows: closed key sets, ids where the table has them. */
function ap_settings_native_account_rows_valid(string $view,array $rows): bool
{
    $required=match($view){'settings_follow_requests'=>['id','actor_id','username'],'settings_featured'=>['id','actor_id','acct','display_name','avatar'],'settings_mutes'=>['actor_id','created_at'],'settings_profile'=>['actor_key','source'],'settings_links'=>['panel','state'],'settings_ai'=>['panel','configured'],'settings_tokens'=>['id','status'],'settings_two_factor'=>['panel','enabled'],'settings_aliases'=>['id','verified'],'settings_move'=>['panel','state'],default=>null};
    if($required===null || (in_array($view,['settings_profile','settings_ai','settings_two_factor','settings_move'],true) && count($rows)!==1))return false;sort($required);
    if($view==='settings_move' && (!is_array($rows[0]) || ($rows[0]['panel']??null)!=='move'))return false;
    if($view==='settings_links' && array_map(fn($r)=>is_array($r)?($r['panel']??null):null,$rows)!==['second_life','wow'])return false;
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        foreach($row as $key=>$value){
            if($key==='id'){if(!is_int($value)||$value<1)return false;continue;}
            if($key==='username'&&$value===null)continue;
            if($key==='source'&&!in_array($value,['cache','db'],true))return false;
            if($key==='configured'){if(!is_bool($value)||$row['panel']!=='ai_alt_text')return false;continue;}
            if($key==='enabled'){if(!is_bool($value)||$row['panel']!=='two_factor')return false;continue;}
            if($key==='verified'){if(!is_bool($value))return false;continue;}
            if($key==='status'&&!in_array($value,['revoked','expired','active'],true))return false;
            if($key==='state'&&!in_array($value,match($row['panel']){'second_life'=>['linked','pending','unlinked'],'wow'=>['linked','unlinked'],'move'=>['active','none'],default=>[]},true))return false;
            if(!is_string($value))return false;
        }
    }
    return true;
}
/** Moderation report rows: the list's identity, routing and triage hint only. */
function ap_moderation_native_rows_valid(array $rows): bool
{
    $required=['id','direction','state','reporter_actor_id','target_actor_id','priority_score','created_at'];sort($required);
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        if(!is_int($row['id'])||$row['id']<1||!is_int($row['priority_score'])||$row['priority_score']<0||$row['priority_score']>13)return false;
        foreach(['direction','state','reporter_actor_id','created_at'] as $key)if(!is_string($row[$key]))return false;
        if($row['target_actor_id']!==null&&!is_string($row['target_actor_id']))return false;
    }
    return true;
}
/** @param-out list<array<string,mixed>>|null $rows the validated reply rows when HTML is returned */
function ap_admin_native_post(string $url,array $request,?array &$rows=null): ?string
{
    try{
        $payload=json_encode($request,JSON_THROW_ON_ERROR);
        $curl=curl_init($url);if($curl===false)return null;$body='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>250,CURLOPT_TIMEOUT_MS=>2000,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>2*1024*1024)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Vaak-Admin-Token: '.(string)getenv('VAAK_ADMIN_READ_SECRET')]]);
        $success=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($success!==true || $status!==200 || !is_string($body) || strlen($body)>2*1024*1024)return null;
        $reply=json_decode($body,true,512,JSON_THROW_ON_ERROR);if(!is_array($reply))return null;
        $html=ap_admin_native_reply($reply,$request);$rows=$html===null?null:$reply['rows'];return $html;
    }catch(Throwable $e){$rows=null;return null;}
}
/**
 * Settings personal lists (Deprioritized, Muted words, Your blocks) for the signed-in
 * account. Open to every active account; the worker refuses when the account is
 * disabled or its admin role no longer matches the one this page shows.
 */
function ap_settings_native_lists_body(bool $viewerAdmin): ?string
{
    return ap_settings_native_body('settings_lists',$viewerAdmin);
}
/** One Settings section for the signed-in account: settings_lists, settings_follow_requests, settings_featured, settings_mutes, settings_profile, settings_links, settings_ai, settings_tokens, settings_two_factor, settings_aliases or settings_move. */
function ap_settings_native_body(string $view,bool $viewerAdmin): ?string
{
    $flag=match($view){'settings_lists'=>'VAAK_SETTINGS_LISTS_NATIVE','settings_follow_requests','settings_featured','settings_mutes'=>'VAAK_SETTINGS_ACCOUNTS_NATIVE','settings_profile'=>'VAAK_SETTINGS_PROFILE_NATIVE','settings_links'=>'VAAK_SETTINGS_LINKS_NATIVE','settings_ai'=>'VAAK_SETTINGS_AI_NATIVE','settings_tokens','settings_two_factor'=>'VAAK_SETTINGS_SECURITY_NATIVE','settings_aliases','settings_move'=>'VAAK_SETTINGS_MIGRATION_NATIVE',default=>null};
    if($flag===null || !ap_admin_native_enabled() || getenv($flag)!=='1')return null;
    $user=ap_auth_current_user();$owner=(int)($user['id']??0);
    if($owner<1 || !function_exists('admin_owner_user_id') || admin_owner_user_id()!==$owner || ap_auth_is_admin($user)!==$viewerAdmin)return null;
    $url=(string)(getenv('VAAK_ADMIN_READ_URL')?:'http://127.0.0.1:8787/internal/admin/read');if(!ap_admin_native_url_allowed($url))return null;
    $request=['protocol_version'=>1,'owner_id'=>$owner,'csrf'=>ap_auth_csrf_token(),'view'=>$view,
        'mode'=>isset($_GET['partial'])&& (string)$_GET['partial']==='1'?'shell':'full','offset'=>0,
        'approvals_enabled'=>ap_registration_approvals_enabled(),'now'=>time(),'php_timezone'=>date_default_timezone_get(),'viewer_admin'=>$viewerAdmin];
    $html=ap_admin_native_post($url,$request);if($html===null)return null;
    try{$current=ap_auth_current_user();}catch(Throwable $e){return null;}
    if((int)($current['id']??0)!==$owner || ap_auth_is_admin($current)!==$viewerAdmin)return null;
    return $html;
}
function ap_admin_native_read_body(string $view,int $offset=0,?string $filter=null,?array &$rows=null): ?string
{
    $rows=null;
    if(!ap_admin_native_enabled() || !in_array($view,['invites','approvals','users','relays','policies','blocks','moderation','stats','queue_health','downranking','downranked','friend_servers'],true) || $offset<0 || $offset>PHP_INT_MAX-100)return null;
    if($view==='users' && getenv('VAAK_ADMIN_USERS_NATIVE')!=='1')return null;
    if($view==='relays' && (getenv('VAAK_ADMIN_RELAYS_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='policies' && (getenv('VAAK_ADMIN_POLICIES_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='blocks' && (getenv('VAAK_ADMIN_BLOCKS_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='stats' && (getenv('VAAK_ADMIN_STATS_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='queue_health' && (getenv('VAAK_ADMIN_QUEUE_HEALTH_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='downranking' && (getenv('VAAK_ADMIN_DOWNRANKING_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='downranked' && (getenv('VAAK_ADMIN_DOWNRANKED_NATIVE')!=='1' || $offset!==0))return null;
    // The worker cannot see PHP-FPM's env, so a host override keeps the page in PHP.
    if($view==='friend_servers' && (getenv('VAAK_ADMIN_FRIEND_SERVERS_NATIVE')!=='1' || $offset!==0 || (is_string($wafrnHosts=getenv('VAAK_WAFRN_FRIEND_HOSTS')) && trim($wafrnHosts)!=='')))return null;
    if($view==='moderation' && (getenv('VAAK_ADMIN_MODERATION_NATIVE')!=='1' || $offset!==0 || !in_array($filter,['open','about_us','outbound','closed','all'],true)))return null;
    if($view!=='moderation' && $filter!==null)return null;
    if(!ap_registration_current_admin(ap_db()))return null;
    $user=ap_auth_current_user();$owner=(int)($user['id']??0);if($owner<1)return null;
    $url=(string)(getenv('VAAK_ADMIN_READ_URL')?:'http://127.0.0.1:8787/internal/admin/read');if(!ap_admin_native_url_allowed($url))return null;
    $request=['protocol_version'=>1,'owner_id'=>$owner,'csrf'=>ap_auth_csrf_token(),'view'=>$view,
        'mode'=>isset($_GET['partial'])&& (string)$_GET['partial']==='1'?'shell':'full','offset'=>$offset,
        'approvals_enabled'=>ap_registration_approvals_enabled(),'now'=>time(),'php_timezone'=>date_default_timezone_get()];
    if($view==='moderation')$request['filter']=$filter;
    if($view==='users'){
        $request['offset']=max(1,(int)($_GET['page']??$_POST['users_page']??1));
        $request['search']=trim((string)($_GET['q']??$_POST['users_q']??''));
        if(strlen($request['search'])>4096 || $request['offset']>PHP_INT_MAX-100)return null;
    }
    $html=ap_admin_native_post($url,$request,$replyRows);if($html===null)return null;
    try{
        $current=ap_auth_current_user();
        if((int)($current['id']??0)!==$owner || !ap_registration_current_admin(ap_db()))return null;
    }catch(Throwable $e){return null;}
    $rows=$replyRows;
    return $html;
}
/**
 * Settings > Profile save through the worker (S4a). $fields is the array the
 * save_profile action passes to ap_profile_save, after uploads. Returns null so
 * PHP saves itself (switch off, worker refusal, anything unproven), otherwise
 * ['ok','error','email_saved','trends_changed','algorithm_changed','after_save'].
 * The caller has checked the session and CSRF, and runs ap_profile_after_save
 * on success unless the worker already did (after_save).
 */
function ap_settings_profile_save_native(int $owner,string $actorKey,string $email,array $fields): ?array
{
    if(!ap_admin_native_enabled() || getenv('VAAK_SETTINGS_PROFILE_SAVE_NATIVE')!=='1' || $owner<1 || $actorKey==='')return null;
    $strings=['name','summary','icon_url','image_url','reply_policy','quote_policy','forum_signature'];
    $flags=['manually_approves','discoverable','indexable','collection_consent','vanity_verified','auto_follow_back','anti_ai_marker','auto_unblur_sensitive',
        'auto_delete_posts_7d','automated','hide_profile_replies','hide_profile_boosts','algorithm_enabled','trending_enabled','downranking_enabled','asks_enabled','webmentions_enabled'];
    $keys=array_keys($fields);sort($keys);$expected=array_merge($strings,$flags,['attachment','profile_badges']);sort($expected);if($keys!==$expected)return null;
    foreach($strings as $key)if(!is_string($fields[$key]))return null;
    foreach($flags as $key)if(!is_bool($fields[$key]))return null;
    if(!is_array($fields['attachment']) || !array_is_list($fields['attachment']) || count($fields['attachment'])>8 || !is_array($fields['profile_badges']))return null;
    foreach($fields['attachment'] as $item){if(!is_array($item) || array_keys($item)!==['name','value'] || !is_string($item['name']) || !is_string($item['value']))return null;}
    $fields['profile_badges']=array_values($fields['profile_badges']);
    $user=ap_auth_current_user();if((int)($user['id']??0)!==$owner || (string)($user['actor_key']??'')!==$actorKey)return null;
    $url=(string)(getenv('VAAK_SETTINGS_PROFILE_SAVE_URL')?:'http://127.0.0.1:8787/internal/settings/profile-save');
    if(!ap_admin_native_url_allowed($url,'/internal/settings/profile-save'))return null;
    try{
        $payload=json_encode(['protocol_version'=>1,'owner_id'=>$owner,'actor_key'=>$actorKey,'email'=>$email,'fields'=>$fields],JSON_THROW_ON_ERROR);
        $curl=curl_init($url);if($curl===false)return null;$body='';
        // rel=me checks fetch each linked page, so this waits longer than a read.
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>250,CURLOPT_TIMEOUT_MS=>60000,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Vaak-Admin-Token: '.(string)getenv('VAAK_ADMIN_READ_SECRET')]]);
        $success=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($success!==true || $status!==200)return null;
        $reply=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    }catch(Throwable $e){return null;}
    return ap_settings_profile_save_native_reply($reply,$owner,$actorKey);
}
/** The closed reply shape of /internal/settings/profile-save, bound to this request. */
function ap_settings_profile_save_native_reply(mixed $reply,int $owner,string $actorKey): ?array
{
    if(!is_array($reply))return null;
    $keys=array_keys($reply);sort($keys);$expected=['protocol_version','ok','error','email_saved','trends_changed','algorithm_changed','after_save','request_binding'];sort($expected);
    if($keys!==$expected || $reply['protocol_version']!==1 || !is_bool($reply['ok']) || !is_bool($reply['email_saved'])
        || !is_bool($reply['trends_changed']) || !is_bool($reply['algorithm_changed']) || !is_bool($reply['after_save']))return null;
    $binding=$reply['request_binding'];
    if(!is_array($binding) || count($binding)!==2 || ($binding['owner_id']??null)!==$owner || ($binding['actor_key']??null)!==$actorKey)return null;
    if($reply['ok']){if($reply['error']!==null || !$reply['email_saved'])return null;}
    elseif(!is_string($reply['error']) || $reply['error']==='' || strlen($reply['error'])>200 || $reply['trends_changed'] || $reply['algorithm_changed'] || $reply['after_save'])return null;
    return ['ok'=>$reply['ok'],'error'=>$reply['error'],'email_saved'=>$reply['email_saved'],'trends_changed'=>$reply['trends_changed'],'algorithm_changed'=>$reply['algorithm_changed'],'after_save'=>$reply['after_save']];
}
/**
 * Default-off native admin action (A10): null means PHP runs the action itself
 * (switch off, worker refusal, anything unproven), otherwise
 * ['outcome','preview','php_epoch_bump','count']. The caller has checked the session,
 * CSRF and the admin role; the worker checks the role again in its write.
 */
function ap_admin_action_native(string $action,int $owner,string $actorKey,array $fields): ?array
{
    $families=['downrank_term_add'=>['VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE',['phrase'=>'string','category'=>'string']],
        'downrank_term_delete'=>['VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE',['term_id'=>'integer']],
        'downrank_signal_clear'=>['VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE',['actor_id'=>'string']],
        'downrank_term_preview'=>['VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE',['preview_phrase'=>'string']],
        'policies_save_privacy'=>['VAAK_ADMIN_POLICIES_ACTIONS_NATIVE',['title'=>'string','body'=>'string']],
        'policies_save_conduct'=>['VAAK_ADMIN_POLICIES_ACTIONS_NATIVE',['title'=>'string','body'=>'string']],
        'policies_save_rules'=>['VAAK_ADMIN_POLICIES_ACTIONS_NATIVE',['rules_text'=>'string']]];
    foreach(['report_note','report_dismiss','report_ignore','report_reopen'] as $reportAction)
        $families[$reportAction]=['VAAK_ADMIN_REPORTS_ACTIONS_NATIVE',['report_id'=>'integer','admin_note'=>'string','admin_handle'=>'string']];
    $families['user_ban']=$families['user_unban']=['VAAK_ADMIN_USERS_ACTIONS_NATIVE',['user_id'=>'integer']];
    if(!isset($families[$action]) || !ap_admin_native_enabled() || getenv($families[$action][0])!=='1' || $owner<1 || $actorKey==='')return null;
    $shape=$families[$action][1];if(array_keys($fields)!==array_keys($shape))return null;
    foreach($shape as $key=>$type)if(gettype($fields[$key])!==$type)return null;
    $user=ap_auth_current_user();if((int)($user['id']??0)!==$owner || (string)($user['actor_key']??'')!==$actorKey || !ap_auth_is_admin($user))return null;
    $url=(string)(getenv('VAAK_ADMIN_ACTION_URL')?:'http://127.0.0.1:8787/internal/admin/action');
    if(!ap_admin_native_url_allowed($url,'/internal/admin/action'))return null;
    try{
        $payload=json_encode(['protocol_version'=>1,'owner_id'=>$owner,'actor_key'=>$actorKey,'action'=>$action,'fields'=>$fields],JSON_THROW_ON_ERROR);
        if(strlen($payload)>524288)return null;
        $curl=curl_init($url);if($curl===false)return null;$body='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>250,CURLOPT_TIMEOUT_MS=>15000,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Vaak-Admin-Token: '.(string)getenv('VAAK_ADMIN_READ_SECRET')]]);
        $success=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($success!==true || $status!==200)return null;
        $reply=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    }catch(Throwable $e){return null;}
    return ap_admin_action_native_reply($reply,$action,$owner,$actorKey);
}
/** The closed reply shape of /internal/admin/action, bound to this request. */
function ap_admin_action_native_reply(mixed $reply,string $action,int $owner,string $actorKey): ?array
{
    if(!is_array($reply))return null;
    $keys=array_keys($reply);sort($keys);$expected=['protocol_version','ok','outcome','preview','php_epoch_bump','count','request_binding'];sort($expected);
    if($keys!==$expected || $reply['protocol_version']!==1 || $reply['ok']!==true || !is_string($reply['outcome']) || !is_bool($reply['php_epoch_bump']))return null;
    $binding=$reply['request_binding'];
    if(!is_array($binding) || count($binding)!==3 || ($binding['owner_id']??null)!==$owner || ($binding['actor_key']??null)!==$actorKey || ($binding['action']??null)!==$action)return null;
    $outcomes=['downrank_term_add'=>['term_saved','bad_phrase'],'downrank_term_delete'=>['term_removed','invalid_term'],
        'downrank_signal_clear'=>['signal_cleared','missing_actor'],'downrank_term_preview'=>['previewed','bad_preview_phrase'],
        'policies_save_privacy'=>['doc_saved','title_too_long','body_too_long'],'policies_save_conduct'=>['doc_saved','title_too_long','body_too_long'],
        'policies_save_rules'=>['rules_saved','rule_too_long','too_many_rules','no_rules'],
        'report_note'=>['note_saved','note_empty','note_report_not_found'],'report_dismiss'=>['state_set','report_not_found'],
        'report_ignore'=>['state_set','report_not_found'],'report_reopen'=>['state_set','report_not_found'],
        'user_ban'=>['banned','invalid_user','user_not_found','protected_user'],'user_unban'=>['unbanned','invalid_user','user_not_found','protected_user']];
    if(!in_array($reply['outcome'],$outcomes[$action]??[],true))return null;
    if($reply['outcome']==='rules_saved'){if(!is_int($reply['count']) || $reply['count']<1 || $reply['count']>40)return null;}
    elseif($reply['count']!==null)return null;
    if($reply['php_epoch_bump'] && $reply['outcome']!=='signal_cleared')return null;
    $preview=$reply['preview'];
    if($action!=='downrank_term_preview'){if($preview!==null)return null;}
    else{
        $previewKeys=is_array($preview)?array_keys($preview):[];sort($previewKeys);
        if($previewKeys!==['count','phrase','samples'] || !is_string($preview['phrase']) || strlen($preview['phrase'])>16384
            || !is_int($preview['count']) || $preview['count']<0 || $preview['count']>2000 || !is_array($preview['samples']) || !array_is_list($preview['samples'])
            || count($preview['samples'])>min(8,$preview['count']))return null;
        $phraseOk=$preview['phrase']!=='' && mb_strlen($preview['phrase'])<=120;
        if($reply['outcome']==='bad_preview_phrase' && ($preview['count']!==0 || $preview['samples']!==[] || $phraseOk))return null;
        if($reply['outcome']==='previewed' && !$phraseOk)return null;
        foreach($preview['samples'] as $sample){
            $sampleKeys=is_array($sample)?array_keys($sample):[];sort($sampleKeys);
            if($sampleKeys!==['actor_id','created_at','host'])return null;
            foreach($sample as $value)if(!is_string($value))return null;
        }
    }
    if(is_array($preview)){
        $preview=['phrase'=>$preview['phrase'],'count'=>$preview['count'],'samples'=>array_map(static fn(array $x):array=>['actor_id'=>$x['actor_id'],'host'=>$x['host'],'created_at'=>$x['created_at']],$preview['samples'])];
    }
    return ['outcome'=>$reply['outcome'],'preview'=>$preview,'php_epoch_bump'=>$reply['php_epoch_bump'],'count'=>$reply['count']];
}
