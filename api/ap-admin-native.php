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
function ap_admin_native_url_allowed(string $url): bool
{
    $p=parse_url($url);
    return is_array($p) && ($p['scheme']??'')==='http' && in_array($p['host']??'', ['127.0.0.1','[::1]'],true)
        && ($p['path']??'')==='/internal/admin/read'
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
}
function ap_admin_native_reply(array $reply,array $request): ?string
{
    $keys=array_keys($reply);sort($keys);$expected=['protocol_version','ok','request_binding','source_count','rows','html'];sort($expected);
    if($keys!==$expected || $reply['protocol_version']!==1 || $reply['ok']!==true || !is_string($reply['html'])
        || strlen($reply['html'])>2*1024*1024 || !is_int($reply['source_count']) || !is_array($reply['rows'])
        || !array_is_list($reply['rows']) || count($reply['rows'])!==$reply['source_count'] || count($reply['rows'])>match($request['view']){'users'=>20,'blocks'=>1000,'settings_lists'=>3000,'settings_follow_requests'=>1000,'settings_mutes'=>1000,'settings_featured'=>40,'settings_profile'=>1,'settings_links'=>2,'moderation'=>80,default=>100}) return null;
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
/** Follow request, Featured, Muted account, profile form and linked-avatar rows: closed key sets, ids where the table has them. */
function ap_settings_native_account_rows_valid(string $view,array $rows): bool
{
    $required=match($view){'settings_follow_requests'=>['id','actor_id','username'],'settings_featured'=>['id','actor_id','acct','display_name','avatar'],'settings_mutes'=>['actor_id','created_at'],'settings_profile'=>['actor_key','source'],'settings_links'=>['panel','state'],default=>null};
    if($required===null || ($view==='settings_profile' && count($rows)!==1))return false;sort($required);
    if($view==='settings_links' && array_map(fn($r)=>is_array($r)?($r['panel']??null):null,$rows)!==['second_life','wow'])return false;
    foreach($rows as $row){
        if(!is_array($row))return false;$keys=array_keys($row);sort($keys);if($keys!==$required)return false;
        foreach($row as $key=>$value){
            if($key==='id'){if(!is_int($value)||$value<1)return false;continue;}
            if($key==='username'&&$value===null)continue;
            if($key==='source'&&!in_array($value,['cache','db'],true))return false;
            if($key==='state'&&!in_array($value,$row['panel']==='second_life'?['linked','pending','unlinked']:['linked','unlinked'],true))return false;
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
function ap_admin_native_post(string $url,array $request): ?string
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
        return ap_admin_native_reply($reply,$request);
    }catch(Throwable $e){return null;}
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
/** One Settings section for the signed-in account: settings_lists, settings_follow_requests, settings_featured, settings_mutes, settings_profile or settings_links. */
function ap_settings_native_body(string $view,bool $viewerAdmin): ?string
{
    $flag=match($view){'settings_lists'=>'VAAK_SETTINGS_LISTS_NATIVE','settings_follow_requests','settings_featured','settings_mutes'=>'VAAK_SETTINGS_ACCOUNTS_NATIVE','settings_profile'=>'VAAK_SETTINGS_PROFILE_NATIVE','settings_links'=>'VAAK_SETTINGS_LINKS_NATIVE',default=>null};
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
function ap_admin_native_read_body(string $view,int $offset=0,?string $filter=null): ?string
{
    if(!ap_admin_native_enabled() || !in_array($view,['invites','approvals','users','relays','policies','blocks','moderation'],true) || $offset<0 || $offset>PHP_INT_MAX-100)return null;
    if($view==='users' && getenv('VAAK_ADMIN_USERS_NATIVE')!=='1')return null;
    if($view==='relays' && (getenv('VAAK_ADMIN_RELAYS_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='policies' && (getenv('VAAK_ADMIN_POLICIES_NATIVE')!=='1' || $offset!==0))return null;
    if($view==='blocks' && (getenv('VAAK_ADMIN_BLOCKS_NATIVE')!=='1' || $offset!==0))return null;
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
    $html=ap_admin_native_post($url,$request);if($html===null)return null;
    try{
        $current=ap_auth_current_user();
        if((int)($current['id']??0)!==$owner || !ap_registration_current_admin(ap_db()))return null;
    }catch(Throwable $e){return null;}
    return $html;
}
