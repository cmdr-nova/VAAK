<?php
declare(strict_types=1);
/** Actual local SQL context and full refresh reassignment/dispatch reference. */
$path=__DIR__.'/remote-direct-moderation-reference.php';$code=(string)file_get_contents($path);$end=strpos($code,"\n\$rows=[];\$checks=0;");if($end===false)throw new RuntimeException('setup boundary');$resolved='';foreach(token_get_all(substr($code,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
$start=strpos($admin,'              $ctx = ap_masto_status_context',strpos($admin,'// Explicit retry from failed AJAX'));
$end=strpos($admin,'              $stMaybeMoreReplies = false;',$start)+strlen('              $stMaybeMoreReplies = false;');$refresh=substr($admin,$start,$end-$start);
if($start===false||!str_contains($refresh,'$stAncestors ='))throw new RuntimeException('actual refresh assignments');
$dispatch=[];foreach(['ancestor'=>'anc','descendant'=>'desc'] as $slot=>$variable){$start=strpos($admin,'<div id="status-thread-'.($slot==='ancestor'?'ancestors':'descendants').'"',strpos($admin,'// Explicit retry from failed AJAX'));$region=substr($admin,$start,3500);if(!preg_match('/admin_render_(?:masto_status_card|direct_status_card)\(\$'.$variable.'[^;]+;/',$region,$match))throw new RuntimeException('actual full '.$slot.' dispatch');$dispatch[$slot]=$match[0];}
$repaired=in_array('--expect-repaired-refresh',$argv,true);$rows=[];$checks=0;
foreach(['INTEGER','BIGINT'] as $width){$schema='remote_direct_refresh_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $pg->exec('ALTER TABLE masto_statuses ADD COLUMN in_reply_to_local_id '.$width.';ALTER TABLE mentions ADD COLUMN in_reply_to TEXT');$GLOBALS['fixture_db']=$pg;
  foreach(['none','personal_block','server_block','personal_domain_block','server_domain_block','personal_mute','server_mute','server_domain_mute','word'] as $rule){
   direct_source($corpus['status_plain']['context']);direct_bind(1);$GLOBALS['view']='status';$_GET=['refresh_thread'=>'1'];
   $pg->exec("UPDATE masto_statuses SET content_text='Blocked parent refresh marker' WHERE local_id=700");
   $pg->exec("UPDATE outbox_notes SET content='Blocked parent refresh marker' WHERE id='https://mkultra.monster/users/other/notes/700'");
   foreach([[710,'fixture','Unblocked focus refresh marker',700],[720,'other','Blocked reply refresh marker',710]] as [$id,$key,$body,$parent]){$uri='https://mkultra.monster/users/'.$key.'/notes/'.$id;$pg->prepare('INSERT INTO masto_statuses(note_id,local_id,content_text,spoiler_text,sensitive,visibility,published,in_reply_to_local_id) VALUES(?,?,?,?,?,?,?,?)')->execute([$uri,$id,$body,'',0,'public','2026-10-10T12:00:00Z',$parent]);$pg->prepare('INSERT INTO outbox_notes(id,raw_create_json,content,visibility,to_json,cc_json,published,in_reply_to) VALUES(?,?,?,?,?,?,?,?)')->execute([$uri,'{}',$body,'public','[]','[]','2026-10-10T12:00:00Z','']);}
   $actor='https://mkultra.monster/users/other';
   if($rule==='personal_block'||$rule==='personal_domain_block')$pg->prepare('INSERT INTO fixture_blocks VALUES(?,?,?)')->execute([1,str_contains($rule,'domain')?'domain':'actor',str_contains($rule,'domain')?'mkultra.monster':$actor]);
   if($rule==='personal_mute')$pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$actor]);
   if(str_starts_with($rule,'server_'))$pg->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute([str_contains($rule,'domain')?'domain':'actor',str_contains($rule,'domain')?'mkultra.monster':$actor,str_ends_with($rule,'block')?'block':'mute']);
   if($rule==='word')$pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,'refresh marker']);
   $stStatusId='710';$stObject='https://mkultra.monster/users/fixture/notes/710';$stFrom='home';$followingIds=[];
   $initial=ap_masto_status_context(710,['allow_fetch'=>false,'max_ancestor_depth'=>20]);
   $stAncestors=admin_direct_filter_blocked_statuses($initial['ancestors']);$stDescendants=admin_direct_filter_blocked_statuses($initial['descendants']);$filtered=['ancestors'=>$stAncestors,'descendants'=>$stDescendants];
   eval($refresh);$after=['ancestors'=>$stAncestors,'descendants'=>$stDescendants];$html=[];
   foreach(['ancestor'=>'stAncestors','descendant'=>'stDescendants'] as $slot=>$variable){ob_start();foreach(${$variable} as $card){if($slot==='ancestor')$anc=$card;else $desc=$card;eval($dispatch[$slot]);}$html[$slot]=(string)ob_get_clean();}
   $blocked=str_ends_with($rule,'block');if(count($initial['ancestors'])!==1||count($initial['descendants'])!==1)throw new RuntimeException('actual collector shape');$checks++;
   if(count($filtered['ancestors'])!==($blocked?0:1)||count($filtered['descendants'])!==($blocked?0:1))throw new RuntimeException('initial actual full filter');$checks++;
   foreach(['ancestor'=>'Blocked parent refresh marker','descendant'=>'Blocked reply refresh marker'] as $slot=>$marker){if(str_contains($html[$slot],$marker)!==(!$repaired||!$blocked))throw new RuntimeException('refresh body expectation '.$width.'/'.$rule.'/'.$slot);$checks++;}
   $rows[]=['width'=>$width,'rule'=>$rule,'initial_actual_context'=>$initial,'filtered_before_refresh'=>$filtered,'actual_refresh_context'=>$after,'full_dispatch_html'=>$html,'refresh_source_sha256'=>hash('sha256',$refresh),'dispatch_source'=>$dispatch,'actual_context_provider'=>'ap_masto_status_context real local parent/reply SQL','full_sapi'=>false];
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-direct-refresh-reference.json';file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$rows,'expect_repaired_refresh'=>$repaired],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo 'remote-direct-refresh-reference PASS '.$checks.' checks; '.count($rows).' actual captures; '.$path."\n";
