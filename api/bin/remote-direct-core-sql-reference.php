<?php
declare(strict_types=1);
/** Genuine production SQL helper outputs. No Rust expected values or runtime edits. */
require __DIR__.'/migration-reference-functions.php';
require __DIR__.'/remote-direct-core-sql-schema.php';
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
$socket = getenv('LIBRARY_FIXTURE_SOCKET');
if (!$socket || !str_starts_with($socket, '/tmp/')) throw new RuntimeException('disposable local PostgreSQL socket required');
$pg = new PDO('pgsql:host='.$socket.';dbname=postgres', 'postgres', null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['fixture_db'] = $pg;
$captures = []; $checks = 0; $clock = 1791673200;
function core_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
function core_insert(string $table, array $row): void {
    $st = ap_db()->prepare('INSERT INTO '.$table.'('.implode(',', array_keys($row)).') VALUES('.implode(',', array_fill(0,count($row),'?')).')');
    $st->execute(array_values($row));
}
function core_snapshot(): array {
    $out = [];
    foreach (ap_db()->query('SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN) as $table)
        $out[$table] = ap_db()->query('SELECT * FROM '.$table)->fetchAll();
    return $out;
}
function core_schema_snapshot(): array {
    $out=[];
    foreach (ap_db()->query("SELECT table_name,column_name,data_type,ordinal_position,is_nullable FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position")->fetchAll() as $column) {
        $out[$column['table_name']][]=['name'=>$column['column_name'],'type'=>strtoupper($column['data_type']),'ordinal_position'=>(int)$column['ordinal_position'],'nullable'=>$column['is_nullable']==='YES'];
    }
    return $out;
}
function core_capture(string $case, array $input, mixed $output): void {
    $GLOBALS['captures'][] = ['width'=>$GLOBALS['core_width'],'case'=>$case,'source'=>$input,'clock'=>$GLOBALS['clock'],
        'session'=>['owner'=>$GLOBALS['vaak_owner_id'],'actor'=>$GLOBALS['vaak_actor_id'],'actor_key'=>$GLOBALS['vaak_actor_key']],
        'current_tables'=>core_snapshot(),'current_schema'=>core_schema_snapshot(),'php'=>$output];
}
foreach (['INTEGER','BIGINT'] as $width) {
    $GLOBALS['core_width'] = $width;
    $schema = 'remote_direct_core_sql_'.strtolower($width);
    $pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
    try {
        direct_core_sql_schema($pg, $width);
        $pg->exec('CREATE TABLE ap_notification_projection(status_id TEXT)');
        if (!function_exists('ap_local_note_engagement_counts')) require __DIR__.'/remote-direct-production-helper-bootstrap.php';
        $me = 'https://mkultra.monster/users/coreowner';
        $peer = 'https://mkultra.monster/users/corepeer';
        $note = $me.'/notes/nonzero'; $remote = 'https://core-remote.test/notes/source';
        $published = '2026-10-10T15:00:00+00:00';
        foreach ([1=>['coreowner',$me],2=>['corepeer',$peer]] as $id=>[$key,$actor]) {
            core_insert('ap_users',['id'=>$id,'actor_id'=>$actor,'actor_key'=>$key,'username'=>$key,'is_admin'=>0]);
            core_insert('actor_profile',['actor_key'=>$key,'name'=>'Current '.$key.' Ω','summary'=>'<p>Current bio</p>','attachment_json'=>'[]','icon_url'=>'https://media.test/'.$key.'.webp','image_url'=>null,'discoverable'=>1,'indexable'=>1,'manually_approves'=>0]);
        }
        core_insert('masto_statuses',['local_id'=>701,'note_id'=>$note,'published'=>$published,'content_text'=>'Current local answer','spoiler_text'=>'','sensitive'=>0,'visibility'=>'public']);
        $sid = ap_masto_snowflake_id($published,701,0);
        foreach ([['Like','https://likes.test/users/a',$note],['Like','https://likes.test/users/a/',$note.'/'],['Like','https://likes.test/users/b',$note.'/'],['Announce','https://boosts.test/users/a',$note],['Announce','https://boosts.test/users/a/',$note.'/'],['Announce','https://boosts.test/users/b',$note]] as $index=>[$type,$actor,$object])
            core_insert('events',['id'=>101+$index,'object_id'=>$object,'actor_id'=>$actor,'type'=>$type,'action_taken'=>'log','visibility'=>'public','created_at'=>$published]);
        foreach (['masto_favourites','masto_bookmarks','masto_reblogs'] as $table) {
            core_insert($table,['owner_user_id'=>1,'status_id'=>$sid,'object_id'=>$note,'created_at'=>$published]);
            core_insert($table,['owner_user_id'=>2,'status_id'=>'peer-saved','object_id'=>$note.'/','created_at'=>$published]);
            core_insert($table,['owner_user_id'=>2,'status_id'=>$sid,'object_id'=>'https://other.test/notes/irrelevant','created_at'=>$published]);
        }
        for ($i=0;$i<2;$i++) core_insert('events',['id'=>201+$i,'type'=>'Create','object_id'=>'https://quotes.test/notes/'.$i,'actor_id'=>'https://quotes.test/users/q','action_taken'=>'log','quote_object'=>$note.($i?'/':''),'created_at'=>$published]);
        for ($i=0;$i<4;$i++) core_insert('quote_authorizations',['id'=>'auth-'.$i,'quoted_note_id'=>$note.($i%2?'/':''),'deleted_at'=>$i===3?$published:null]);
        core_insert('bsky_crossposts',['note_id'=>$note.'/','bsky_uri'=>'at://did:plc:core/app.bsky.feed.post/old','created_at'=>'2026-10-09T00:00:00Z','owner_user_id'=>1]);
        core_insert('bsky_crossposts',['note_id'=>$note,'bsky_uri'=>'at://did:plc:core/app.bsky.feed.post/current','created_at'=>$published,'owner_user_id'=>1]);
        core_insert('bsky_posts',['bsky_uri'=>'at://did:plc:core/app.bsky.feed.post/old','like_count'=>99,'repost_count'=>99,'quote_count'=>99]);
        core_insert('bsky_posts',['bsky_uri'=>'at://did:plc:core/app.bsky.feed.post/current','like_count'=>7,'repost_count'=>5,'quote_count'=>4]);
        core_insert('events',['id'=>501,'type'=>'Create','object_id'=>$remote,'actor_id'=>'https://core-remote.test/users/author','action_taken'=>'log','visibility'=>'public','created_at'=>$published]);
        core_insert('events',['id'=>502,'type'=>'Announce','object_id'=>$remote.'/','actor_id'=>'https://boost.test/users/undo','action_taken'=>'log','visibility'=>'public','created_at'=>$published]);
        core_insert('events',['id'=>503,'type'=>'Quote','object_id'=>$remote,'actor_id'=>'https://quote.test/users/undo','action_taken'=>'log','visibility'=>'public','created_at'=>$published]);
        foreach ([1,2] as $owner) {
            $key = $owner===1?'coreowner':'corepeer';
            $GLOBALS['vaak_owner_id']=$owner; $GLOBALS['vaak_actor_id']=$owner===1?$me:$peer; $GLOBALS['vaak_actor_key']=$key;
            $GLOBALS['vaak_user']=$pg->query('SELECT * FROM ap_users WHERE id='.$owner)->fetch();
            $counts=ap_local_note_engagement_counts($note.'/',(string)$sid);
            core_check($counts===['favourites'=>12,'reblogs'=>10,'quotes'=>7,'bookmarks'=>3], 'nonzero additive counts/max quote auth/all-owner saved counts');
            core_capture('nonzero_counts_owner_'.$owner,['note_id'=>$note.'/','status_id'=>$sid],$counts);
            $localKeys=ap_masto_canonical_interaction_keys('incoming-local-id',$note.'/');
            core_check($localKeys['status_id']===($owner===1?$sid:'incoming-local-id') && $localKeys['object_id']===$note,'viewer-specific own local canonical keys');
            core_capture('canonical_local_owner_'.$owner,['status_id'=>'incoming-local-id','object_id'=>$note.'/'],$localKeys);
            $eventKeys=ap_masto_canonical_interaction_keys('incoming-announce-id',$remote.'/');
            core_check($eventKeys===['status_id'=>ap_masto_event_status_id(501,$published),'object_id'=>$remote],'prefer original Create over later interactions');
            core_capture('canonical_remote_owner_'.$owner,['status_id'=>'incoming-announce-id','object_id'=>$remote.'/'],$eventKeys);
            $mirrorKeys=ap_masto_canonical_interaction_keys('incoming-bsky-id','at://did:plc:core/app.bsky.feed.post/current');
            core_check($mirrorKeys===['status_id'=>$sid,'object_id'=>$note],'indexed Bsky mirror canonical local status');
            core_capture('canonical_bsky_mirror_owner_'.$owner,['status_id'=>'incoming-bsky-id','object_id'=>'at://did:plc:core/app.bsky.feed.post/current'],$mirrorKeys);
        }
        $trimmedCounts=ap_local_note_engagement_counts(' '.$note.'/ ', ' '.$sid.' ');
        core_check($trimmedCounts===['favourites'=>12,'reblogs'=>10,'quotes'=>7,'bookmarks'=>3],'count helper trims note and supplied status ID');
        core_capture('counts_trimmed_inputs',['note_id'=>' '.$note.'/ ','status_id'=>' '.$sid.' '],$trimmedCounts);
        $blankCounts=ap_local_note_engagement_counts($note, ' ');
        core_check($blankCounts===['favourites'=>11,'reblogs'=>9,'quotes'=>7,'bookmarks'=>2],'blank status ID only matches saved object root/slash');
        core_capture('counts_blank_status_id',['note_id'=>$note,'status_id'=>' '],$blankCounts);
        core_insert('masto_favourites',['owner_user_id'=>1,'status_id'=>$note,'object_id'=>'https://other.test/notes/status-fallback','created_at'=>$published]);
        $blankFallbackCounts=ap_local_note_engagement_counts($note, '');
        core_check($blankFallbackCounts===['favourites'=>12,'reblogs'=>9,'quotes'=>7,'bookmarks'=>2],'blank status ID uses note as saved status key');
        core_capture('counts_blank_saved_note_status_fallback',['note_id'=>$note,'status_id'=>''],$blankFallbackCounts);
        $pg->prepare('DELETE FROM masto_favourites WHERE object_id=?')->execute(['https://other.test/notes/status-fallback']);
        foreach (['','https://core-remote.test/articles/non-note'] as $invalidNote) {
            $emptyCounts=ap_local_note_engagement_counts($invalidNote,$sid);
            core_check($emptyCounts===['favourites'=>0,'reblogs'=>0,'quotes'=>0,'bookmarks'=>0],'blank/non-note key rejected before SQL count');
            core_capture($invalidNote===''?'counts_blank_note':'counts_non_note_key',['note_id'=>$invalidNote,'status_id'=>$sid],$emptyCounts);
        }
        $publicIds=['local'=>ap_masto_parse_public_status_id((int)$sid),'event'=>ap_masto_parse_public_status_id((int)ap_masto_event_status_id(501,$published)),'legacy_event'=>ap_masto_event_status_id(501,null)];
        core_check($publicIds['local']['type']==='local'&&$publicIds['event']['type']==='event'&&$publicIds['legacy_event']==='3000501','canonical public status ID namespaces');
        core_capture('public_status_id_namespaces',['local_id'=>701,'event_id'=>501,'published'=>$published],$publicIds);
        $unknownKeys=ap_masto_canonical_interaction_keys('caller-preserved','https://missing.test/notes/unknown/');
        core_check($unknownKeys===['status_id'=>'caller-preserved','object_id'=>'https://missing.test/notes/unknown'],'unknown source retains caller key');
        core_capture('canonical_unknown',['status_id'=>'caller-preserved','object_id'=>'https://missing.test/notes/unknown/'],$unknownKeys);
        $undoneAnnounce=ap_events_mark_interaction_undone('Announce','https://boost.test/users/undo/',$remote.'/');
        $undoneQuote=ap_events_mark_interaction_undone('Quote','https://quote.test/users/undo',$remote);
        $original=ap_event_by_object_id($remote.'/');
        core_check($undoneAnnounce===1&&$undoneQuote===1&&$original['id']==501&&$original['action_taken']==='log','normal Undo preserves original source');
        core_capture('normal_undo_source',['object_id'=>$remote,'announce_actor'=>'https://boost.test/users/undo/','quote_actor'=>'https://quote.test/users/undo'],['announce_rows'=>$undoneAnnounce,'quote_rows'=>$undoneQuote,'source'=>$original,'keys'=>ap_masto_canonical_interaction_keys('incoming',$remote)]);
        // Actual count helper currently includes marked-deleted interaction rows.
        ap_events_mark_interaction_undone('Announce','https://boosts.test/users/a',$note);
        $afterUndo=ap_local_note_engagement_counts($note,$sid);
        core_check($afterUndo===['favourites'=>12,'reblogs'=>10,'quotes'=>7,'bookmarks'=>3],'actual count helper retains deleted interaction records');
        core_capture('normal_undo_count_behavior',['note_id'=>$note,'status_id'=>$sid],$afterUndo);
        $pg->exec('DELETE FROM quote_authorizations');
        $eventQuoteCounts=ap_local_note_engagement_counts($note,$sid);
        core_check($eventQuoteCounts===['favourites'=>12,'reblogs'=>10,'quotes'=>6,'bookmarks'=>3],'event quote count survives absent active authorization plus mirror');
        core_capture('quote_event_count',['note_id'=>$note,'status_id'=>$sid],$eventQuoteCounts);
        $pg->exec('DELETE FROM quote_authorizations;UPDATE events SET quote_object=NULL;DELETE FROM bsky_crossposts');
        core_insert('events',['id'=>601,'type'=>'Create','object_id'=>'https://summary.test/notes/quote','summary'=>'QT: '.$note,'created_at'=>$published]);
        core_insert('events',['id'=>602,'type'=>'Create','object_id'=>$note,'summary'=>'Original refers '.$note,'created_at'=>$published]);
        core_insert('events',['id'=>603,'type'=>'Create','object_id'=>$note.'/','summary'=>'Original slash refers '.$note,'created_at'=>$published]);
        if (!function_exists('vaak_migrate_quote_target_backfill')) {
            require dirname(__DIR__, 2).'/deploy/remote/migrate.php';
        }
        $backfill = vaak_migrate_quote_target_backfill($pg, 2);
        core_check($backfill['stored'] >= 1 && $backfill['done'] === true, 'summary quote targets backfill into the indexed table');
        $again = vaak_migrate_quote_target_backfill($pg, 2);
        core_check($again['stored'] === 0 && $again['done'] === true, 'quote target backfill is a no-op once the cursor is caught up');
        $summaryCounts=ap_local_note_engagement_counts($note,$sid);
        core_check($summaryCounts===['favourites'=>5,'reblogs'=>5,'quotes'=>1,'bookmarks'=>3],'summary fallback excludes original root/slash and no mirror');
        core_capture('quote_summary_fallback',['note_id'=>$note,'status_id'=>$sid],$summaryCounts);
        core_insert('remote_actors',['actor_id'=>'https://ask-cached.test/users/asker','username'=>'asker','display_name'=>'Cached asker Ψ','host'=>'ask-cached.test','icon_source_url'=>'https://media.test/cached-ask.webp','updated_at'=>$published]);
        core_insert('remote_actors',['actor_id'=>'https://ask-media.test/users/asker','username'=>'mediaasker','display_name'=>'Cached media asker','host'=>'ask-media.test','icon_source_url'=>'','updated_at'=>$published]);
        core_insert('remote_media_cache',['id'=>1,'actor_id'=>'https://ask-media.test/users/asker','kind'=>'avatar','public_url'=>'https://media.test/ask-cache.webp','fetched_at'=>$published]);
        foreach (['local'=>$peer.'/','remote'=>'https://ask-cached.test/users/asker/','remote_media'=>'https://ask-media.test/users/asker','anonymous'=>''] as $label=>$actor) {
            $identity=ap_ask_actor_identity($actor);
            core_check($identity['display_name']!==''&&str_starts_with($identity['avatar'],'https://'),'current Ask identity nonempty');
            core_check($identity['display_name']===match($label){'local'=>'Current corepeer Ω','remote'=>'Cached asker Ψ','remote_media'=>'Cached media asker','anonymous'=>'Anonymous'},'Ask genuine local/remote identity');
            core_check($identity['avatar']===match($label){'local'=>'https://media.test/corepeer.webp','remote'=>'https://media.test/cached-ask.webp','remote_media'=>'https://media.test/ask-cache.webp','anonymous'=>'https://mkultra.monster/img/avatar/default.webp'},'Ask current icon/cache/fallback precedence');
            core_capture('ask_identity_'.$label,['actor'=>$actor],$identity);
        }
        core_insert('ap_asks',['id'=>801,'ask_id'=>'https://asks.test/old','question'=>'Old question','asker_actor'=>$peer,'asked_actor'=>$me,'owner_user_id'=>1,'answered'=>1,'answer_note_id'=>$note,'created_at'=>$published,'updated_at'=>$published]);
        core_insert('ap_asks',['id'=>802,'ask_id'=>'https://asks.test/new','question'=>'Current question Ω?','asker_actor'=>'https://ask-cached.test/users/asker','asked_actor'=>$me,'owner_user_id'=>1,'answered'=>1,'answer_note_id'=>$note.'/','created_at'=>$published,'updated_at'=>$published]);
        $answer=ap_ask_answer_for_note(' '.$note.'/ ');
        core_check($answer['id']==802&&$answer['question']==='Current question Ω?','Ask current highest-ID root/slash answer');
        core_capture('ask_current_answer',['note_id'=>' '.$note.'/ '],['answer'=>$answer,'identity'=>ap_ask_actor_identity($answer['asker_actor'])]);
        core_check(ap_ask_answer_for_note('https://missing.test/notes/no-answer')===null,'missing Ask stays null');
        core_capture('ask_missing_answer',['note_id'=>'https://missing.test/notes/no-answer'],null);
    } finally { $pg->exec('DROP SCHEMA '.$schema.' CASCADE'); }
}
$path=$argv[1]??'/tmp/vaak-remote-direct-core-sql-reference.json';
$helperSources=[];
foreach (['ap_local_note_engagement_counts','ap_masto_canonical_interaction_keys','ap_ask_answer_for_note','ap_ask_actor_identity','ap_event_by_object_id','ap_events_mark_interaction_undone','ap_masto_snowflake_id','ap_masto_event_status_id','ap_local_user_by_actor_id','ap_profile_get','ap_remote_actor_label','ap_remote_actor_get','ap_remote_actor_ensure','ap_bsky_local_note_id_for_at_uri'] as $helper) {
    $file=$GLOBALS['fixture_production_helper_declarations'][$helper];
    $helperSources[$helper]=['file'=>'api/'.$file,'file_sha256'=>hash_file('sha256',dirname(__DIR__).'/'.$file)];
}
file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$captures,'helper_declarations'=>$helperSources,
    'external_transports'=>array_merge(['ap_db (disposable PostgreSQL PDO)'],$GLOBALS['fixture_production_external_transports']),
    'fixture_session'=>'declared current owner/actor globals, actual production session readers',
    'clock_note'=>'recorded fixture clock; supplied published timestamps determine captured status IDs; no production clock helper substituted',
    'native_parity'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'remote-direct-core-sql-reference PASS '.$checks.' checks; '.count($captures).' captures; '.$path."\n";
