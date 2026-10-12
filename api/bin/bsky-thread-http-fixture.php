<?php
declare(strict_types=1);
/** Owned SQLite SAPI adapter. No production bootstrap, authentication middleware, or remote HTTP.
 * Loads genuine declaration fixture helpers and evaluates exact controller/shell source.
 * Only require_once module-loader statements are removed: those declarations are already loaded.
 */
if (PHP_SAPI !== 'cli-server') { fwrite(STDERR, "Run only in an owned PHP cli-server fixture.\n"); exit(2); }
ob_start(); require __DIR__.'/bsky-thread-fragment-smoke.php'; ob_end_clean();
$GLOBALS['thread_reply']=['ok'=>true,'json'=>['thread'=>$tree]];
$GLOBALS['thread_focus']=$focus;
$GLOBALS['thread_mutation']=null;
$GLOBALS['thread_calls']=[];
$owner=(string)($_GET['owner']??'1');
$GLOBALS['vaak_owner_id']=$owner==='guest'?0:1;
if($owner==='guest'){
 $GLOBALS['vaak_user']=['id'=>0,'username'=>'guest','actor_key'=>'','display_name'=>'Guest','is_guest'=>true];
 $GLOBALS['vaak_actor_id']='';$GLOBALS['vaak_actor_key']='';$GLOBALS['vaak_guest_profile']=true;
 if(admin_owner_user_id()!==0)throw new RuntimeException('guest fixture owner mismatch');
}
header('X-Fixture-Owner: '.admin_owner_user_id());
// Real current session binding, not a cloned or unclosed production session.
$sessionDir=getenv('VAAK_THREAD_FIXTURE_SESSION_DIR');
if(!is_string($sessionDir)||!is_dir($sessionDir))throw new RuntimeException('owned session directory required');
session_start(['save_path'=>$sessionDir]);
$_SESSION['vaak_user_id']=$GLOBALS['vaak_owner_id'];
if($owner==='mismatch')$_SESSION['vaak_user_id']=2;
$control=(string)($_GET['control']??'');
if($control==='block')ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','did:plc:fixture')");
if($control==='server-block')ap_db()->exec("INSERT INTO fixture_server_blocks VALUES('actor','did:plc:fixture','block')");
if($control==='source-withdrawal')$GLOBALS['thread_mutation']=static function()use($focus){ap_db()->prepare('DELETE FROM bsky_posts WHERE bsky_uri=?')->execute([$focus['uri']]);$GLOBALS['thread_mutation']=null;};
if($control==='wrong-focus')$GLOBALS['thread_reply']['json']['thread']['post']['uri']=$child['uri'];
$source=(string)file_get_contents(dirname(__DIR__).'/ap-admin.php');
$followingIds=[];$view='status';$isPartial=isset($_GET['thread']);
if($isPartial){
    if(isset($_GET['delay']))usleep(800000);
    $start=strpos($source,"if (\$isPartial && \$view === 'status' && isset(\$_GET['thread'])) {");
    $end=strpos($source,"        echo \$html;exit;",$start);
    if($start===false||$end===false)throw new RuntimeException('actual dispatcher unavailable');
    // Keep exact genuine dispatch through its Bluesky terminal branch. The following AP branch is outside this fixture.
    $block=substr($source,$start,$end+strlen("        echo \$html;exit;")-$start)."\n    }\n}";
    $block=preg_replace("~^[ \t]*require_once __DIR__\.'/ap-(?:bsky|masto-entities)\\.php';\\n~m",'', $block);
    eval($block);
    throw new RuntimeException('actual dispatcher did not terminate');
}
$stObject=(string)($_GET['object']??$focus['uri']);$stFrom=(string)($_GET['from']??'home');$stIsBskyObject=true;
$stBskyFocusHtml=admin_bsky_thread_fragment($stObject,'focus',$stFrom,$followingIds);
$start=strpos($source,'<?php elseif ($stIsBskyObject): ?>');
$end=strpos($source,'<?php elseif (!is_array($stEvent) && !is_array($stLocalStatus)): ?>',$start);
if($start===false||$end===false)throw new RuntimeException('actual Open shell unavailable');
$body=substr($source,$start,$end-$start);
$body=preg_replace('/^<\?php elseif /','<?php if ',$body,1).'<?php endif; ?>';
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
if(isset($_GET['browser'])){
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'");
    echo '<!doctype html><title>Owned Bluesky thread fixture</title><style>body{margin:0;font:16px sans-serif}section.main{max-width:700px;margin:auto}article.tweet{min-height:230px;border:1px solid #aaa;padding:20px}.tweet-av{width:40px;height:40px}.fixture-spacer{height:600px}.fixture-tools{position:fixed;top:0;right:0;background:white;z-index:100;padding:10px}pre{white-space:pre-wrap}</style>';
    echo '<div class="fixture-tools"><button onclick="window.vaakStopStatusThread()">Stop thread</button><pre id="fixture-metrics">Starting real loader</pre></div><div class="fixture-spacer"></div><section class="main"><div class="status-back"><a href="?leave=1">Home fixture</a></div>';
}
eval('?>'.$body);
if(isset($_GET['browser'])){
    echo '</section><div class="fixture-spacer"></div>';
    $jsStart=strpos($source,"<script>\n(function () {\nlet stopThread = null;");
    $jsEnd=strpos($source,'</script>',$jsStart);
    if($jsStart===false||$jsEnd===false)throw new RuntimeException('actual thread JS unavailable');
    // Observer only: pass actual native fetch/signal through, record completion/abort.
    echo '<script>window.fixtureEvents=[];const fixtureFetch=window.fetch.bind(window);window.fetch=(url,opts)=>{let target=new URL(url,location.href);if(new URL(location.href).searchParams.has("delay"))target.searchParams.set("delay","1");window.fixtureEvents.push({event:"request",part:target.searchParams.get("thread")});return fixtureFetch(target,opts).then(res=>{window.fixtureEvents.push({event:"response",status:res.status});return res},err=>{window.fixtureEvents.push({event:"error",name:err.name});throw err})};window.addEventListener("load",()=>scrollTo(0,620));setInterval(()=>{const focus=document.getElementById("status-focus");document.getElementById("fixture-metrics").textContent=JSON.stringify({events:window.fixtureEvents,focusTop:focus&&Math.round(focus.getBoundingClientRect().top),scrollY:Math.round(scrollY),ancestors:document.querySelectorAll("#status-thread-ancestors article").length,replies:document.querySelectorAll("#status-thread-descendants article").length},null,2)},100);</script>';
    echo substr($source,$jsStart,$jsEnd+strlen('</script>')-$jsStart);
}
