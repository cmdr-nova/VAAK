<?php
/** Render the genuine current PHP Approvals branch; no synthetic expected HTML. */
declare(strict_types=1);
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';require dirname(__DIR__).'/ap-admin-native.php';
function h(string $value): string {return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function ap_auth_csrf_token(): string {return (string)($_SESSION[AP_AUTH_CSRF_KEY]??'');}
$source=file_get_contents(dirname(__DIR__).'/ap-admin.php');
$marker="      <?php elseif (\$view === 'approvals'): ?>";$start=strpos($source,$marker)+strlen($marker);$end=strpos($source,"      <?php elseif (\$view === 'invites'): ?>",$start);$template=substr($source,$start,$end-$start);
$approval_db=new PDO('sqlite::memory:');$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$approval_db->exec('CREATE TABLE ap_users(id INTEGER PRIMARY KEY,username TEXT,is_admin INTEGER,disabled_at TEXT)');$approval_db->exec("INSERT INTO ap_users VALUES(1,'operator',1,NULL),(2,'ordinary',0,NULL)");
ap_registration_approvals_schema($approval_db);
$approval_db->prepare('INSERT INTO ap_registration_applications(username,email,password_hash,created_at)VALUES(?,?,?,?)')->execute(['alice','alice@example.test','never-render-this-hash','now']);
$results=[];
foreach(['enabled','closed','guest','ordinary','disabled','revoked'] as $case){
 $_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'fixture-csrf'];$_GET=[];$approval_db->exec('UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1');putenv('VAAK_REGISTRATION_APPROVALS=1');
 if($case==='closed')putenv('VAAK_REGISTRATION_APPROVALS');if($case==='guest')$_SESSION['vaak_user_id']=0;if($case==='ordinary')$_SESSION['vaak_user_id']=2;
 if($case==='disabled')$approval_db->exec("UPDATE ap_users SET disabled_at='now' WHERE id=1");if($case==='revoked')$approval_db->exec('UPDATE ap_users SET is_admin=0 WHERE id=1');
 ob_start();eval('?>'.$template);$html=ob_get_clean();
 $private=in_array($case,['enabled','closed'],true);
 if(str_contains($html,'never-render-this-hash') || str_contains($html,'alice@example.test')!==$private)throw new RuntimeException('private template '.$case);
 if(str_contains($html,'registration_approve')!==($case==='enabled') || str_contains($html,'registration_reject')!==($case==='enabled'))throw new RuntimeException('decision form '.$case);
 if($case==='enabled'&&!str_contains($html,'value="fixture-csrf"'))throw new RuntimeException('form CSRF');
 $results[$case]=['html'=>$html,'sha256'=>hash('sha256',$html)];
}
foreach(['vaakAdminOnlyViews'=>'approvals','vaakAdminOnlyActions'=>'registration_approve'] as $variable=>$expected){
 $start=strpos($source,'$'.$variable.' = [');$end=strpos($source,'];',$start)+2;eval(substr($source,$start,$end-$start));if(!in_array($expected,$$variable,true))throw new RuntimeException('controller gate '.$variable);
}
if(!in_array('registration_reject',$vaakAdminOnlyActions,true))throw new RuntimeException('reject gate');
file_put_contents($argv[1]??'/tmp/registration-approvals-template.json',json_encode(['template_sha256'=>hash('sha256',$template),'declarations'=>$declarations,'boundary'=>'actual current template and SQL with extracted declarations; fixture h/session bootstrap. Not complete SAPI controller.', 'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['passed'=>9,'templates'=>6,'gate_checks'=>3]).PHP_EOL;
