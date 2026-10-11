<?php
declare(strict_types=1);
/** Actual staged Clearance selectors/issuance coordinator; Stripe GET and cross-app bootstrap declared. */
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';
function clearance_db(): PDO { return $GLOBALS['clearance_fixture']; }
function clearance_cfg(string $key,$default=null){return $GLOBALS['clearance_config'][$key]??$default;}
function clearance_stripe_request(string $method,string $path,array $query=[],int $timeoutMs=20000): array {
 $GLOBALS['stripe_calls'][]=[$method,$path,$query,$timeoutMs];
 if(!empty($GLOBALS['stripe_failure']))throw new RuntimeException('declared unavailable transport');
 if(!empty($GLOBALS['stripe_delay_us']))usleep($GLOBALS['stripe_delay_us']);
 return array_shift($GLOBALS['stripe_pages'])??['data'=>[],'has_more'=>false];
}
function clearance_request_vaak_invite(string $customer,string $receipt,string $email): array {
 $GLOBALS['issue_calls']++;return ap_supporter_issue($customer,$receipt,$email);
}
$staged=dirname(__DIR__,2).'/rust/target/parity-evidence/supporter-registration-phase/clearance/lib/vaak_invite.php';
foreach(['clearance_now','clearance_member_by_id']as$fn)approval_load('/home/cmdr-nova/Documents/Website/NovaLandia/clearance/lib/db.php',$fn);
foreach(['clearance_db_migrate_vaak','clearance_set_vaak_invite','clearance_set_vaak_invite_status','clearance_ensure_vaak_invite','clearance_vaak_invite_plain','clearance_vaak_paid_receipt']as$fn)approval_load($staged,$fn);
$approval_db=new PDO('sqlite::memory:');$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$clearance_fixture=new PDO('sqlite::memory:');$clearance_fixture->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$clearance_fixture->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$clearance_fixture->exec("CREATE TABLE members(id INTEGER PRIMARY KEY,email TEXT,stripe_customer_id TEXT,subscription_status TEXT,updated_at TEXT)");clearance_db_migrate_vaak();
putenv('VAAK_SUPPORTER_CODES=1');putenv('VAAK_SUPPORTER_CODE_SECRET='.str_repeat('fixture-secret-',4));
$clearance_config=['dev_mode'=>false];$checks=[];$stripe_calls=[];$issue_calls=0;
$charge=['id'=>'ch_paid','customer'=>'cus_paid','invoice'=>'in_paid','livemode'=>true,'status'=>'succeeded','paid'=>true,'captured'=>true,'amount'=>500,'amount_refunded'=>0,'refunded'=>false,'disputed'=>false];
$session=['id'=>'cs_paid','customer'=>'cus_paid','subscription'=>'sub_paid','livemode'=>true,'status'=>'complete','payment_status'=>'paid','mode'=>'subscription','metadata'=>['type'=>'monthly_subscription']];
$invoice=['id'=>'in_paid','customer'=>'cus_paid','subscription'=>'sub_paid','charge'=>'ch_paid','livemode'=>true,'paid'=>true,'status'=>'paid','amount_paid'=>500];
$member=['stripe_customer_id'=>'cus_paid','email'=>'paid@example.test','subscription_status'=>'active'];
foreach(['paid','failed','pending','uncaptured','zero','refunded','disputed','testmode','wrongcustomer','trial','customer_only','canceled_paid','partial_refund','donation','wrong_metadata','checkout_customer','checkout_subscription','checkout_testmode','invoice_customer','invoice_subscription','invoice_charge','invoice_id','invoice_unpaid','invoice_zero','invoice_testmode']as$case){
 $row=$charge;$checkout=$session;$bill=$invoice;
 if($case==='failed')$row['status']='failed';if($case==='pending')$row['paid']=false;if($case==='uncaptured')$row['captured']=false;
 if($case==='zero')$row['amount']=0;if($case==='refunded'){$row['refunded']=true;$row['amount_refunded']=500;}if($case==='disputed')$row['disputed']=true;
 if($case==='testmode')$row['livemode']=false;if($case==='wrongcustomer')$row['customer']='cus_other';if($case==='partial_refund')$row['amount_refunded']=100;
 if($case==='donation'){$checkout['mode']='payment';$checkout['metadata']['type']='one_time_donation';}
 if($case==='wrong_metadata')$checkout['metadata']['type']='unrelated_subscription';
 if($case==='checkout_customer')$checkout['customer']='cus_other';if($case==='checkout_subscription')$checkout['subscription']='sub_other';if($case==='checkout_testmode')$checkout['livemode']=false;
 if($case==='invoice_customer')$bill['customer']='cus_other';if($case==='invoice_subscription')$bill['subscription']='sub_other';
 if($case==='invoice_charge')$bill['charge']='ch_other';if($case==='invoice_id')$bill['id']='in_other';if($case==='invoice_unpaid')$bill['paid']=false;
 if($case==='invoice_zero')$bill['amount_paid']=0;if($case==='invoice_testmode')$bill['livemode']=false;
 $stripe_pages=[['data'=>in_array($case,['trial','customer_only'])?[]:[$checkout],'has_more'=>false],['data'=>[$row],'has_more'=>false],$bill];
 $member['subscription_status']=$case==='canceled_paid'?'canceled':($case==='trial'?'trialing':($case==='customer_only'?'customer_only':'active'));
 $actual=clearance_vaak_paid_receipt($member);$eligible=in_array($case,['paid','canceled_paid','partial_refund']);check(($actual!==null)===$eligible,'actual source/invoice/charge selector '.$case);
}
$clearance_config['dev_mode']=true;$before=count($stripe_calls);check(clearance_vaak_paid_receipt($member)===null&&count($stripe_calls)===$before,'development never paid');$clearance_config['dev_mode']=false;
$stripe_pages=[['data'=>[['id'=>'cs_old']],'has_more'=>true],['data'=>[$session],'has_more'=>false],['data'=>[$charge],'has_more'=>false],$invoice];
$before=count($stripe_calls);check(clearance_vaak_paid_receipt($member)==='ch_paid'&&$stripe_calls[$before+1][2]['starting_after']==='cs_old','paginated source GET');
$stripe_pages=array_fill(0,5,['data'=>[['id'=>'cs_old']],'has_more'=>true]);$before=count($stripe_calls);check(clearance_vaak_paid_receipt($member)===null&&count($stripe_calls)-$before===5,'bounded historical source lookup');
$stripe_failure=true;try{clearance_vaak_paid_receipt($member);throw new RuntimeException('expected failure');}catch(RuntimeException$e){check($e->getMessage()==='declared unavailable transport','receipt transport failure grants none');}$stripe_failure=false;
$stripe_pages=[['data'=>[$session],'has_more'=>false],['data'=>[$charge],'has_more'=>false],$invoice];$stripe_delay_us=2100000;$before=count($stripe_calls);$start=microtime(true);
check(clearance_vaak_paid_receipt($member)===null&&count($stripe_calls)-$before===2&&microtime(true)-$start<4.8,'shared deadline stops further GET after declared slow transport');$stripe_delay_us=0;
$clearance_fixture->exec("INSERT INTO members(id,email,stripe_customer_id,subscription_status,updated_at) VALUES(1,'paid@example.test','cus_paid','customer_only','now')");
$stripe_pages=[['data'=>[$session],'has_more'=>false],['data'=>[$charge],'has_more'=>false],$invoice];$issued=clearance_ensure_vaak_invite(clearance_member_by_id(1));check($issued['issued_now']&&str_starts_with($issued['member']['vaak_invite_code'],'SUP-'),'actual Clearance paid-only issuance');
$before=count($stripe_calls);$again=clearance_ensure_vaak_invite(clearance_member_by_id(1));check(!$again['issued_now']&&count($stripe_calls)===$before&&$issue_calls===1,'issued reward no repeat Stripe navigation');
$clearance_fixture->exec("INSERT INTO members(id,email,stripe_customer_id,subscription_status,updated_at,vaak_invite_code,vaak_invite_status) VALUES(2,'unpaid@example.test','cus_unpaid','active','now','LEGACY-CODE','issued')");
$stripe_pages=[['data'=>[],'has_more'=>false]];$none=clearance_ensure_vaak_invite(clearance_member_by_id(2));check(!$none['issued_now']&&$issue_calls===1&&!str_contains(clearance_vaak_invite_plain($none['member'])['detail'],'on file'),'legacy/status alone never reward');
$before=count($stripe_calls);clearance_ensure_vaak_invite(clearance_member_by_id(2));check(count($stripe_calls)===$before,'unpaid retry cached 15 minutes');
$clearance_fixture->exec("INSERT INTO members(id,email,stripe_customer_id,subscription_status,updated_at) VALUES(3,'failure@example.test','cus_failure','active','now')");
$stripe_failure=true;$before=count($stripe_calls);$failure=clearance_ensure_vaak_invite(clearance_member_by_id(3));clearance_ensure_vaak_invite(clearance_member_by_id(3));$stripe_failure=false;
check(!$failure['issued_now']&&count($stripe_calls)===$before+1&&clearance_member_by_id(3)['vaak_supporter_checked_at']!==null,'failed transport admission persisted and retry bounded');
check(count(array_filter($stripe_calls,fn($call)=>$call[0]!=='GET'||!in_array($call[1],['charges','checkout/sessions','invoices/in_paid'])||$call[3]>4000||$call[3]<1))===0,'only declared read-only source and receipt GET within budget');
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'declarations'=>$declarations,'boundary'=>'Actual source declarations and SQLite; Stripe REST returns declared fixture objects, no live billing calls. Cross-app bootstrap calls actual reward issuer; production bootstrap, magic-link mail and billing webhook not exercised.'],JSON_PRETTY_PRINT)."\n";
