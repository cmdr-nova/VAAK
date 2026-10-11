<?php
declare(strict_types=1);
/** Actual Search caller baseline. Collector transport is supplied; policy/painters are real. */
function admin_search_run(string $q,string $type,bool $resolve,int $limit=20,int $offset=0):array {
    $GLOBALS['search_caller_collector_calls'][]=compact('q','type','resolve','limit','offset');
    return $GLOBALS['search_caller_window'];
}
ob_start();require __DIR__.'/search-render-reference-capture.php';
$corpus=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR)['captures'];
$api=dirname(__DIR__);
reference_load($api.'/ap-db.php',['ap_follow_lists_cache_gen','ap_following_list','ap_actor_id_membership_map','ap_following_id_set']);
reference_load($api.'/ap-masto-entities.php',['ap_masto_snowflake_id']);
// Actual follow helpers require the production row shape, not supplied following badges.
ap_db()->exec('ALTER TABLE following ADD COLUMN host TEXT;ALTER TABLE following ADD COLUMN followed_at TEXT;CREATE TABLE IF NOT EXISTS remote_actors(actor_id TEXT,username TEXT,host TEXT)');
$GLOBALS['vaak_actor_id']=vaak_actor_id();
$GLOBALS['vaak_user']=['id'=>1,'username'=>'fixture'];
$GLOBALS['vaak_actor_key']='fixture';$GLOBALS['vaak_owner_id']=1;
$sqlite=ap_db();$snapshots=[];
foreach($sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $snapshots[$table]=['columns'=>$sqlite->query('PRAGMA table_info('.$table.')')->fetchAll(),'rows'=>$sqlite->query('SELECT * FROM '.$table)->fetchAll()];
}
function caller_restore(array $context):void {
    foreach(['masto_favourites'=>'saved_favourites','masto_bookmarks'=>'saved_bookmarks','masto_reblogs'=>'saved_reblogs','masto_statuses'=>'local_status_rows','masto_polls'=>'poll_rows','fixture_mutes'=>'moderation_mutes','fixture_blocks'=>'moderation_blocks','fixture_words'=>'moderation_words','fixture_server_blocks'=>'server_moderation','events'=>'audience_events','outbox_notes'=>'audience_outbox','following'=>'following','bsky_hide_dids'=>'native_bsky_hide_dids','fixture_status_cache'=>'cached_status_payloads','bsky_posts'=>'search_bsky_rows'] as $table=>$field) {
        ap_db()->exec('DELETE FROM '.$table);
        foreach($context[$field]??[] as $row) {
            $keys=array_keys($row);ap_db()->prepare('INSERT INTO '.$table.'('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($row));
        }
    }
    ap_db()->exec('DELETE FROM link_preview_cards');
    ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive='.(int)!empty($context['profile']['auto_unblur_sensitive']));
    unset($GLOBALS['ap_relset_memo'],$GLOBALS['ap_masto_flag_memo'],$GLOBALS['ap_bsky_hide_did_cache'],$GLOBALS['vaak_quote_policy_statuses'],$GLOBALS['vaak_bsky_quote_policy_posts'],$GLOBALS['vaak_rss_quote_policy_rows']);
    ap_follow_lists_cache_gen(true);
    $GLOBALS['fixture_render_clock']=$context['rendered_at'];
    $GLOBALS['admin_library_fetch_budget']=3;$GLOBALS['admin_quote_link_preview_budget']=3;
    $GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];
}
function caller_capture(callable $paint):array {
    ob_start();$result=$paint();$html=(string)ob_get_clean();
    return ['html'=>$html,'result'=>$result,'effects'=>['preview_http'=>$GLOBALS['preview_http_requests'],'preview_warm'=>$GLOBALS['preview_warm_requests'],'fetch_budget'=>$GLOBALS['admin_library_fetch_budget'],'quote_budget'=>$GLOBALS['admin_quote_link_preview_budget']]];
}
function caller_assert(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
$account=$corpus['account_remote']['input'];$self=$corpus['account_own']['input'];
$tag=['name'=>'café & tea','following'=>false];$followingTag=['name'=>'rust','following'=>true];
$base=$corpus['status_plain'];
$cases=[];
foreach(['','accounts','hashtags','statuses'] as $type) $cases['mixed_type_'.($type?:'all')]=['type'=>$type,'rows'=>['accounts'=>[$account,$self],'hashtags'=>[$tag,$followingTag],'statuses'=>[$base['status']]],'more'=>['accounts'=>true,'hashtags'=>false,'statuses'=>true],'context'=>$base['context']];
$followContext=$base['context'];$followContext['following'][]=['owner_actor_id'=>vaak_actor_id(),'actor_id'=>$account['uri'],'host'=>'remote.test','followed_at'=>'2026-10-10'];
$cases['current_account_following']=['type'=>'accounts','rows'=>['accounts'=>[$account]],'more'=>['accounts'=>false],'context'=>$followContext];
foreach(['own','cw_media','sensitive_auto_unblur','saved_flags','poll_open','ask','quote_accepted','quote_pending','quote_fallback','bsky_normalized'] as $name) {
    $case=$corpus['status_'.$name];$cases['status_'.$name]=['type'=>'statuses','rows'=>['statuses'=>[$case['status']]],'more'=>['statuses'=>true],'context'=>$case['context']];
}
$snowflake=$corpus['status_own'];$published='2026-10-10T12:00:00.000Z';
$snowflake['status']['id']=ap_masto_snowflake_id($published,456,0);
$snowflake['context']['canonical_public_id_provenance']=['helper'=>'ap_masto_snowflake_id','published'=>$published,'local_id'=>456,'type'=>0,'public_id'=>$snowflake['status']['id']];
$cases['status_own_snowflake']=['type'=>'statuses','rows'=>['statuses'=>[$snowflake['status']]],'more'=>['statuses'=>false],'context'=>$snowflake['context']];
$cases['nonarray_rows']=['type'=>'','rows'=>['accounts'=>[null,$account,false],'hashtags'=>['invalid',$tag,null],'statuses'=>[null,$base['status'],false]],'more'=>['accounts'=>true,'hashtags'=>true,'statuses'=>true],'context'=>$base['context']];
$cases['nonarray_sections']=['type'=>'','rows'=>['accounts'=>'invalid','hashtags'=>null,'statuses'=>false],'more'=>[],'context'=>$base['context']];
$cases['empty_tag_raw_slot']=['type'=>'hashtags','rows'=>['hashtags'=>[['name'=>'','following'=>true],null]],'more'=>['hashtags'=>true],'context'=>$base['context']];
$denied=array_replace($base['status'],['uri'=>'https://mkultra.monster/users/other/notes/700','url'=>'https://mkultra.monster/users/other/notes/700','id'=>'700','content'=>'Denied current private marker','account'=>['uri'=>'https://mkultra.monster/users/other','acct'=>'other','display_name'=>'Other']]);
$denyContext=$base['context'];$denyContext['local_status_rows'][]=['note_id'=>$denied['uri'],'local_id'=>700,'content_text'=>'Denied current private marker','spoiler_text'=>'','sensitive'=>0,'visibility'=>'private'];
$denyContext['audience_outbox'][]=['id'=>$denied['uri'],'raw_create_json'=>'','content'=>'Denied current private marker','visibility'=>'private','to_json'=>'[]','cc_json'=>'[]'];
$cases['empty_advanced_denied']=['type'=>'statuses','rows'=>['statuses'=>array_fill(0,10,$denied)],'more'=>['statuses'=>true],'context'=>$denyContext];
$cases['empty_end']=['type'=>'statuses','rows'=>['statuses'=>[]],'more'=>['statuses'=>true],'context'=>$base['context']];
$exports=[];$checks=0;
$socket=getenv('LIBRARY_FIXTURE_SOCKET');
$widths=$socket?['INTEGER','BIGINT']:['SQLITE'];
$pg=$socket?new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]):null;
foreach($widths as $width) {
    $schema='search_native_callers_'.strtolower($width);
    if($pg){
        $pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
        foreach($snapshots as $table=>$snapshot){
            $defs=[];foreach($snapshot['columns'] as $col)$defs[]=$col['name'].' '.(str_contains(strtoupper($col['type']),'INT')||($table==='actor_profile'&&$col['name']==='indexable')?$width:'TEXT');
            $pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');
            $names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');
            foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));
        }
        $GLOBALS['fixture_db']=$pg;
    }
    try {
        foreach($cases as $name=>$case){
            $window=array_replace(['accounts'=>[],'hashtags'=>[],'statuses'=>[]],$case['rows']);$window['has_more']=$case['more'];
            $q="café & tea\u{00A0}";$type=$case['type'];$context=$case['context'];$captures=[];
            // Both production first-page entrances call this exact shared body.
            foreach(['full_shared_body','soft_shared_body'] as $entry){
                caller_restore($context);$following=ap_following_id_set(vaak_actor_id(),1,true);
                $captures[$entry]=caller_capture(static function()use($window,$q,$type,$following){admin_search_render_summary($q,$window,$window['has_more']);admin_search_render_sections($window,$q,$type,true,20,$following);});
            }
            caller_assert($captures['full_shared_body']===$captures['soft_shared_body'],"full/soft shared body differs $width/$name");$checks++;
            foreach(['accounts','hashtags','statuses'] as $section){
                caller_restore($context);$GLOBALS['search_caller_window']=$window;$GLOBALS['search_caller_collector_calls']=[];
                $captures['append_'.$section]=caller_capture(static function()use($q,$section){$fragment=admin_search_page_fragment($q,$section,true,7,20);echo $fragment['html'];return $fragment;});
                $fragment=$captures['append_'.$section]['result'];$rows=is_array($window[$section])?$window[$section]:[];$more=$rows!==[]&&!empty($window['has_more'][$section]);
                $opening='<div data-search-fragment data-section="'.$section.'" data-offset="'.(7+count($rows)).'" data-limit="20" data-has-more="'.($more?'1':'0').'">';
                caller_assert(str_starts_with($fragment['html'],$opening)&&$fragment['has_more']===$more,"raw cursor/hasMore $width/$name/$section");
                caller_assert($GLOBALS['search_caller_collector_calls']===[['q'=>$q,'type'=>$section,'resolve'=>true,'limit'=>20,'offset'=>7]],"one exact collector call $width/$name/$section");
                $captures['append_'.$section]['collector_calls']=$GLOBALS['search_caller_collector_calls'];$checks+=2;
            }
            if($name==='empty_advanced_denied'){caller_assert(!str_contains($captures['append_statuses']['html'],'<article')&&!str_contains($captures['append_statuses']['html'],'Denied current private marker'),"actual current audience denied cards $width");$checks++;}
            if(str_starts_with($name,'status_')){caller_assert(str_contains($captures['append_statuses']['html'],'<article'),"mandatory positive actual status painter $width/$name");$checks++;}
            if($name==='current_account_following'){caller_assert(str_contains($captures['append_accounts']['html'],'You follow them')&&!str_contains($captures['append_accounts']['html'],'value="follow_remote"'),"current SQL follow actions $width");$checks++;}
            $exports[$width][$name]=['input'=>$window,'q'=>$q,'type'=>$type,'resolve'=>true,'context'=>$context,'captures'=>$captures];
        }
        foreach([['q'=>'q','section'=>'invalid','offset'=>4,'limit'=>1],['q'=>'','section'=>'statuses','offset'=>4,'limit'=>80]] as $i=>$args){
            $GLOBALS['search_caller_collector_calls']=[];$fragment=admin_search_page_fragment($args['q'],$args['section'],true,$args['offset'],$args['limit']);
            caller_assert($fragment===['html'=>'<div data-search-fragment data-has-more="0"></div>','has_more'=>false]&&$GLOBALS['search_caller_collector_calls']===[],"invalid input bypasses collector $width");$checks++;
            $exports[$width]['invalid_'.$i]=['input'=>$args,'fragment'=>$fragment];
        }
        foreach([[-5,1,0,10],[200,80,200,40]] as $i=>$args){
            caller_restore($base['context']);$GLOBALS['search_caller_window']=['statuses'=>[$base['status']],'has_more'=>['statuses'=>false]];$GLOBALS['search_caller_collector_calls']=[];
            $fragment=admin_search_page_fragment('q','statuses',false,$args[0],$args[1]);
            caller_assert($GLOBALS['search_caller_collector_calls']===[['q'=>'q','type'=>'statuses','resolve'=>false,'limit'=>$args[3],'offset'=>$args[2]]],"actual fragment input clamp $width");$checks++;
            $exports[$width]['clamp_'.$i]=['input'=>$args,'fragment'=>$fragment,'collector_calls'=>$GLOBALS['search_caller_collector_calls']];
        }
    } finally {if($pg){$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
}
$path=$argv[1]??'/tmp/vaak-search-native-callers-baseline.json';
file_put_contents($path,json_encode(['boundary'=>'actual shared body/page_fragment; supplied collector transport; no SAPI bootstrap','widths'=>$widths,'checks'=>$checks,'cases'=>$exports],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'search-native-callers-capture PASS '.$checks.' controls; '.implode('/',$widths).'; baseline '.$path."\n";
