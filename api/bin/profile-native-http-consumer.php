<?php
declare(strict_types=1);
/** Disposable HTTP acceptance bootstrap; production fetch/validator unchanged. */
require dirname(__DIR__).'/ap-profile-native.php';
$capture=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
function ap_auth_current_user(): array {return ['id'=>2,'is_guest'=>false];}
function ap_auth_csrf_token(): string {return 'fixture-csrf-token';}
$GLOBALS['vaak_guest_profile']=$capture['owner']===0;
$GLOBALS['admin_status_link_preview_budget']=0;
$GLOBALS['admin_quote_link_preview_budget']=0;
$GLOBALS['admin_bookmark_lazy_media']=false;
$reply=ap_profile_complete_fetch($capture['actor'],'posts',10,0,$capture['owner'],'append');
echo json_encode(['reply'=>$reply,'status_budget'=>$GLOBALS['admin_status_link_preview_budget'],'quote_budget'=>$GLOBALS['admin_quote_link_preview_budget']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
