<?php
declare(strict_types=1);
/** Actual Search alias/self/actor predicates with disposable SQL policy rows. */
ob_start();require __DIR__.'/search-render-reference-capture.php';ob_end_clean();
$accounts=[
 'remote'=>['uri'=>'https://remote.test/users/alice','url'=>'https://remote.test/@alice','acct'=>'alice@remote.test'],
 'web'=>['uri'=>'https://remote.test/@alice/','acct'=>'alice@remote.test'],
 'encoded'=>['uri'=>'https://remote.test/@%61lice','acct'=>'alice@remote.test'],
 'different_case'=>['uri'=>'https://remote.test/users/Alice','acct'=>'Alice@remote.test'],
 'own'=>['uri'=>'https://mkultra.monster/users/fixture','acct'=>'fixture'],
 'own_alias'=>['uri'=>'https://vaak.monster/@fixture','acct'=>'fixture@vaak.monster'],
 'other'=>['uri'=>'https://mkultra.monster/users/other','acct'=>'other'],
 'acct_only'=>['id'=>'123','acct'=>'alice@remote.test'],
 'acct_self'=>['uri'=>'https://remote.test/users/alice','acct'=>'fixture@mkultra.monster'],
 'subdomain'=>['uri'=>'https://sub.remote.test/users/alice'],
 'numeric'=>['uri'=>'https://remote.test/users/123'],
 'did'=>['uri'=>'did:plc:hidden'],
];
$rules=[
 'none'=>[],
 'personal_actor'=>['table'=>'fixture_blocks','row'=>['owner_user_id'=>1,'scope'=>'actor','value'=>'https://remote.test/users/alice']],
 'personal_domain'=>['table'=>'fixture_blocks','row'=>['owner_user_id'=>1,'scope'=>'domain','value'=>'remote.test']],
 'personal_self'=>['table'=>'fixture_blocks','row'=>['owner_user_id'=>1,'scope'=>'actor','value'=>'https://mkultra.monster/users/fixture']],
 'personal_mute'=>['table'=>'fixture_mutes','row'=>['owner_user_id'=>1,'actor_id'=>'https://remote.test/users/alice']],
 'global_domain'=>['table'=>'fixture_server_blocks','row'=>['scope'=>'domain','value'=>'remote.test','kind'=>'block']],
 'global_self'=>['table'=>'fixture_server_blocks','row'=>['scope'=>'actor','value'=>'https://mkultra.monster/users/fixture','kind'=>'block']],
 'global_mute'=>['table'=>'fixture_server_blocks','row'=>['scope'=>'actor','value'=>'https://remote.test/users/alice','kind'=>'mute']],
 'personal_did'=>['table'=>'fixture_blocks','row'=>['owner_user_id'=>1,'scope'=>'actor','value'=>'did:plc:hidden']],
];$cases=[];
foreach($rules as $ruleName=>$rule){
 foreach(['fixture_mutes','fixture_blocks','fixture_server_blocks','fixture_words'] as $table)ap_db()->exec('DELETE FROM '.$table);
 if($rule!==[]){$row=$rule['row'];ap_db()->prepare('INSERT INTO '.$rule['table'].'('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));}
 foreach([0,1,2] as $owner)foreach($accounts as $name=>$account){
  $identity=ap_search_owner_identity($owner);
  $status=['account'=>$account,'content'=>'word-mute must stay visible','quote'=>['author_did'=>'']];
  $cases[$ruleName.'_'.$owner.'_'.$name]=['owner'=>$owner,'identity'=>$identity,'rule'=>$rule,'account'=>$account,'status'=>$status,'account_hidden'=>ap_search_account_hidden($account,$owner),'status_hidden'=>ap_search_status_hidden($status,$owner)];
  $variants=['reblog'=>['account'=>['uri'=>'https://neutral.test/users/n'],'reblog'=>['account'=>$account]],'quote_account'=>['account'=>['uri'=>'https://neutral.test/users/n'],'quote'=>['account'=>$account]],'quote_did'=>['account'=>['uri'=>'https://neutral.test/users/n'],'quote'=>['author_did'=>'did:plc:hidden']],'full_quote_child'=>['account'=>['uri'=>'https://neutral.test/users/n'],'quote'=>['quoted_status'=>['account'=>$account]]]];
  foreach($variants as $variant=>$item)$cases[$ruleName.'_'.$owner.'_'.$name]['variants'][$variant]=['status'=>$item,'hidden'=>ap_search_status_hidden($item,$owner)];
 }
}
echo json_encode(['cases'=>$cases,'case_count'=>count($cases),'permission_facts_from_html'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
