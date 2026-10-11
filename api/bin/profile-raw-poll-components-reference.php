<?php
declare(strict_types=1);
// Actual display-only outbox poll helper under isolated SQLite SQL. No fake
// Mastodon status, synthetic local poll ID, or interaction authority is added.
ob_start(); require __DIR__ . '/migration-library-component-capture.php'; ob_end_clean();
$cases=[
 'single'=>['oneOf'=>[['name'=>'First','votes_count'=>2],['name'=>'Second','votes_count'=>1]]],
 'multiple'=>['anyOf'=>[['title'=>'First','votes_count'=>0],['title'=>'Second','votes_count'=>0]],'endTime'=>'2099-01-01T00:00:00Z'],
 'expired'=>['oneOf'=>[['name'=>'Finished','votes_count'=>1]],'endTime'=>'2000-01-01T00:00:00Z'],
 'empty_one_precedes_any'=>['oneOf'=>[],'anyOf'=>[['name'=>'Not selected','votes_count'=>1]]],
 'falsey_end'=>['oneOf'=>[['name'=>'0','votes_count'=>0]],'endTime'=>'0'],
];
$rows=[];
foreach($cases as $name=>$obj){
 $id='https://mkultra.monster/users/fixture/notes/raw-poll-'.$name;
 ap_db()->prepare('INSERT INTO outbox_notes(id,raw_create_json,content,visibility) VALUES(?,?,?,?)')->execute([$id,json_encode(['type'=>'Create','object'=>$obj],JSON_THROW_ON_ERROR),'(poll)','public']);
 $rows[]=['case'=>$name,'note'=>$id,'object'=>$obj,'html'=>admin_poll_block_html($id,true,'remote_profile')];
}
echo json_encode(['captures'=>$rows,'scope'=>'Actual pure helper + isolated current SQLite outbox lookup; no native PG source admission or auth/controller effects claim'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
