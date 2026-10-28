<?php
declare(strict_types=1);
/** Actual PHP component references. No application bootstrap or live network. */
if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "Component references require actual mbstring; use PHPRC=/tmp/vaak-policy-php.ini\n");
    exit(2);
}
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
ob_end_clean();
reference_load(dirname(__DIR__).'/ap-db.php',['ap_moderation_path_segment_forms']);
reference_load(dirname(__DIR__).'/ap-rss.php',['ap_rss_image_host_unusable']);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_remote_emoji_map_for_host','ap_remote_emoji_ensure_host','ap_remote_emoji_ensure_for_display','ap_emoji_html']);
ap_db()->exec('CREATE TABLE IF NOT EXISTS remote_custom_emojis(host TEXT,shortcode TEXT,url TEXT)');
ap_db()->exec('CREATE TABLE IF NOT EXISTS remote_emoji_host_meta(host TEXT,fetched_at TEXT,source TEXT)');
ap_db()->exec("INSERT INTO remote_custom_emojis VALUES('emoji.test','wave','https://emoji.test/wave.png?x=1&y=2')");
$emojis=[];
foreach (['plain'=>'Hello :wave: & <tag>','adjacent'=>'a:wave:b /:wave: -:wave: :wave:- :wave:_','unknown'=>':missing: :wave:','unicode'=>'é:wave:日本 :wave:','html'=>'<a href="https://a.test/">:wave:</a>'] as $name=>$text) foreach ([false,true] as $display) {
    $emojis[$name.($display?'_display':'_body')]=['text'=>$text,'display'=>$display,'map'=>ap_remote_emoji_map_for_host('emoji.test'),'html'=>$display?ap_emoji_html($text,'https://emoji.test/users/alice'):admin_apply_custom_emojis_html($text,'https://emoji.test/users/alice')];
}
$facets=[];
foreach (['short'=>['label'=>'example.test/abc...','end'=>19,'input'=>'Visit example.test/abc...'],'unicode'=>['label'=>'example.test/é...','end'=>18,'input'=>'example.test/é...'],'overshoot'=>['label'=>'example.test/abc...','end'=>25,'input'=>'example.test/abc...'],'miss'=>['label'=>'example.test/abc...','end'=>80,'input'=>'example.test/abc...'],'anchor'=>['label'=>'example.test/abc...','end'=>19,'input'=>'<a href="https://already.test">example.test/abc...</a> example.test/abc...']] as $name=>$v) {
    $st=['vaak_bsky_text'=>$v['label'],'vaak_bsky_facets'=>[['index'=>['byteStart'=>0,'byteEnd'=>$v['end']],'features'=>[['$type'=>'app.bsky.richtext.facet#link','uri'=>'https://example.test/real?x=1&y=2']]]]];
    $facets[$name]=['input'=>$v['input'],'status'=>$st,'html'=>admin_apply_bsky_link_facets($v['input'],$st)];
}
reference_load(dirname(__DIR__).'/ap-asks.php',['ap_ask_actor_identity']);
$asks=[];
foreach (['actor'=>['asker_actor'=>'https://ask.test/users/a'],'no_avatar'=>['asker_actor'=>'https://ask.test/users/a','show_avatar'=>false],'answer'=>['asker_actor'=>'https://ask.test/users/a','answer'=>"Answer & text\nsecond line"],'anonymous'=>['asker_label'=>'Tester']] as $name=>$changes) {
    $row=array_replace(['question'=>"Question & text\nsecond line"],$changes);
    $identity=ap_ask_actor_identity($row['asker_actor']??'');
    $trusted=$row;
    if(!empty($row['asker_actor'])){$trusted['asker_display']=$identity['display_name'];$trusted['asker_handle']=$identity['handle'];$trusted['asker_avatar']=$identity['avatar'];}
    $asks[$name]=['row'=>$trusted,'html'=>ap_ask_card_html($row)];
}
$replies=[];
foreach (['handle'=>['handle'=>'alice.test','text'=>'Parent text','url'=>'https://bsky.app/profile/alice.test/post/p'],'display'=>['display'=>'Alice','text'=>str_repeat('日',140),'uri'=>'at://did:plc:alice/app.bsky.feed.post/p'],'unavailable'=>['handle'=>'did:plc:alice','display'=>'Unavailable'],'ask'=>['handle'=>'alice.test']] as $name=>$parent) {
    $st=['vaak_reply_parent'=>$parent];if($name==='ask')$st['vaak_ask']=['question'=>'Question'];
    $replies[$name]=['status'=>$st,'html'=>admin_status_reply_to_meta_html($st,'bookmarks')];
}
$mentions=[];
foreach (['full'=>['Hello @Alice@remote.test',[['acct'=>'Alice@remote.test','username'=>'Alice','url'=>'https://remote.test/users/Alice']]],'bare'=>['Hello @Alice and #Rust',[['acct'=>'Alice@remote.test','username'=>'Alice','url'=>'https://remote.test/users/Alice']]],'local'=>['Hello @bob@mkultra.monster',[['acct'=>'bob@mkultra.monster','username'=>'bob','url'=>'https://mkultra.monster/users/bob']]],'self'=>['Hello @fixture@mkultra.monster',[['acct'=>'fixture@mkultra.monster','username'=>'fixture','url'=>'https://mkultra.monster/users/fixture']]],'bsky'=>['Hello @alice.bsky.test',[['acct'=>'alice.bsky.test','username'=>'alice.bsky.test','url'=>'https://bsky.app/profile/did:plc:alice']]],'protected'=>['Code `@Alice` https://example.test/@Alice @Alice',[['acct'=>'Alice@remote.test','username'=>'Alice','url'=>'https://remote.test/users/Alice']]]] as $name=>[$text,$rows]) {
    $mentions[$name]=['text'=>$text,'mentions'=>$rows,'html'=>admin_linkify_body_html($text,'bookmarks',$rows)];
}
function reference_render_card(array $st): array
{
    // created_at is two hours behind the captured clock, in the middle of the
    // hour bucket, so a one-second tick during render cannot change "2h".
    $clock = time();
    $stable = gmdate('c', $clock - 7200);
    $st['created_at'] = $stable;
    if (isset($st['quote']['quoted_status']) && is_array($st['quote']['quoted_status'])) {
        $st['quote']['quoted_status']['created_at'] = $stable;
    }
    $GLOBALS['fixture_render_clock'] = $clock;
    ob_start();
    admin_render_masto_status_card($st, [], 'bookmarks');
    $html = (string) ob_get_clean();
    unset($GLOBALS['fixture_render_clock']);
    return ['status' => $st, 'rendered_at' => $clock, 'html' => $html, 'avatar' => admin_actor_avatar_url($st['account']['uri'])];
}
$degradedcards=[];
foreach (['flag_body'=>['vaak_degraded'=>true,'vaak_degraded_reason'=>'half_parsed'],'create'=>['content'=>'','vaak_degraded'=>true,'vaak_degraded_reason'=>'fetch_failed'],'boost'=>['content'=>'','vaak_degraded'=>true,'vaak_degraded_reason'=>'announce_only','vaak_announce_event_id'=>7]] as $name=>$changes) {
    $degradedcards[$name]=reference_render_card(array_replace($captures['plain']['status'],$changes));
}
$accountrefs=[];
foreach (['canonical'=>['uri'=>'https://actor.test/users/alice','url'=>'https://actor.test/@alice'],'web'=>['uri'=>'https://actor.test/@alice','url'=>'https://actor.test/@alice'],'url'=>['url'=>'https://actor.test/@caf%C3%A9'],'reserved'=>['url'=>'https://actor.test/@alice%2F'],'double'=>['url'=>'https://actor.test/@alice%252F'],'invalid'=>['url'=>'https://actor.test/@alice%FF'],'opaque'=>['uri'=>'did:plc:alice'],'empty'=>[]] as $name=>$account)$accountrefs[$name]=['account'=>$account,'actor'=>admin_account_actor_ref($account)];
$times = [];
$now = 0;
for ($attempt = 0; $attempt < 8; $attempt++) {
    $now = time();
    $built = [];
    foreach (['seconds'=>25,'minute'=>80,'hour'=>3900,'day'=>90000,'week'=>604800,'month'=>2678400,'year'=>31622400,'future'=>-90] as $name=>$offset) {
        $iso = gmdate('c', $now - $offset);
        $built[$name] = ['input'=>$iso,'html'=>relative_time($iso)];
    }
    foreach (['empty'=>'','malformed'=>'not a timestamp','sql_date'=>gmdate('Y-m-d H:i:s',$now-7200),'rfc2822'=>gmdate(DATE_RFC2822,$now-7200)] as $name=>$iso) $built[$name]=['input'=>$iso,'html'=>relative_time($iso)];
    if (time() === $now) {
        $times = $built;
        break;
    }
}
if ($times === []) {
    throw new RuntimeException('relative time fixture crossed a second boundary');
}
$mediaInputs = [
    'image'=>[['url'=>'https://cdn.test/photo.jpg','preview_url'=>'https://cdn.test/thumb.jpg','description'=>'a <bright> photo']],
    'video'=>[['url'=>'https://cdn.test/movie.mp4','preview_url'=>'https://cdn.test/poster.png','type'=>'video','mediaType'=>'video/mp4']],
    'hls'=>[['url'=>'https://cdn.test/movie.m3u8?token=x&y=2','preview_url'=>'https://cdn.test/poster.png','type'=>'video','mediaType'=>'application/vnd.apple.mpegurl']],
    'audio'=>[['url'=>'https://cdn.test/sound.mp3','type'=>'audio','mediaType'=>'audio/mpeg']],
    'mime_video'=>[['url'=>'https://cdn.test/media/opaque','mediaType'=>'video/mp4']],
    'poster_guess'=>[['url'=>'https://cdn.test/original/key.webm']],
    'mixed_limit'=>array_map(fn($n)=>['url'=>'https://cdn.test/'.$n.'.png'],range(1,6)),
    'invalid'=>[['url'=>'http://cdn.test/photo.jpg'],['url'=>'javascript:bad']],
];
$replyCw=[];foreach(["\u{00A0}","\u{2009}","\u{00A0} CW \u{2009}",' CW '," \t\n"] as $cw)foreach([false,true] as $sensitive)$replyCw[]=['cw'=>$cw,'sensitive'=>$sensitive,'query'=>admin_reply_cw_query($cw,$sensitive)];
$cwWhitespace=[];
foreach(['empty'=>'','ascii'=>" \t\n\r\0\x0B",'nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'label'=>' CW '] as $name=>$cw)foreach([false,true] as $auto){
    $GLOBALS['vaak_actor_key']='fixture';ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive='.(int)$auto);
    $sensitive=trim($cw)!=='';
    $cwWhitespace[$name.($auto?'_auto':'')]=['cw'=>$cw,'auto'=>$auto,'sensitive'=>$sensitive,'html'=>admin_cw_gate_html($cw,$sensitive,'<div>Body</div>')];
}
$media=[];
foreach ($mediaInputs as $name=>$items) foreach ([false,true] as $lazy) {
    $GLOBALS['admin_bookmark_lazy_media']=$lazy;
    $media[$name.($lazy?'_lazy':'')]=['items'=>$items,'lazy'=>$lazy,'html'=>admin_media_row_html($items)];
}
$links=[];
foreach (['plain'=>'hello & goodbye','url'=>'Read https://example.test/path?q=1&x=2.','status'=>'https://bsky.app/profile/a.test/post/xyz','www'=>'See www.example.test/path','bare_host'=>'rust-lang.org/tools','hashtag'=>'A #Rust and #Été post','code'=>'Code `<tag>` then #Rust','mention'=>'Hello @alice@example.test','bare_mention'=>'Hello @uncachedperson','unicode'=>'Été 日本語 😀 https://example.test/a','ellipsis_scheme'=>'Read https://www.example.test/news/2026-10-27/anibiz-… now','dots_scheme'=>'See https://example.test/a...b.','ellipsis_www'=>'Try www.example.test/news/a…','ellipsis_bare'=>'Try example.test/news/a… and example.test/b..c'] as $name=>$plain) {
    $links[$name]=['plain'=>$plain,'view'=>'bookmarks','html'=>admin_linkify_body_html($plain,'bookmarks')];
}
$previews=[];
foreach (['article'=>'https://article.test/read','watch'=>'https://www.youtube.com/watch?v=abcdEF12','short'=>'https://youtu.be/abcdEF12','shorts'=>'https://youtube.com/shorts/abcdEF12','params'=>'https://youtube.com/watch?x=1&v=abcdEF12'] as $name=>$url) foreach ([false,true] as $interactive) {
    $card=['url'=>$url,'title'=>'Preview title','description'=>'Desc & info','provider_name'=>'Provider','status'=>'ok'];
    $previews[$name.($interactive?'_interactive':'_static')]=['card'=>$card,'interactive'=>$interactive,'html'=>ap_link_preview_html($card,$interactive)];
}
$quotes=[];
foreach (['image'=>['media_attachments'=>[['url'=>'https://cdn.test/photo.jpg','type'=>'image']]],'video'=>['media_attachments'=>[['url'=>'https://cdn.test/movie.mp4','type'=>'video','preview_url'=>'https://cdn.test/poster.png']]],'cw'=>['media_attachments'=>[['url'=>'https://cdn.test/photo.jpg','type'=>'image']],'sensitive'=>true,'spoiler_text'=>'Quoted CW'],'link'=>['card'=>['url'=>'https://article.test/read','title'=>'Title','description'=>'Desc','image'=>'https://cdn.test/card.jpg','provider_name'=>'Publisher','status'=>'ok']]] as $name=>$changes) {
    $status=array_replace(['id'=>'https://quote.test/posts/q','uri'=>'https://quote.test/posts/q','url'=>'https://quote.test/posts/q','visibility'=>'public','account'=>['uri'=>'https://quote.test/users/a','acct'=>'a@quote.test'],'content'=>'quoted body','media_attachments'=>[]],$changes);
    foreach ([false,true] as $auto) {
        $GLOBALS['admin_bookmark_lazy_media']=false;
        ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive='.(int)$auto);
        $quotes[$name.($auto?'_auto':'')]=['status'=>$status,'auto'=>$auto,'html'=>admin_quote_card_html(admin_quote_opts_from_status($status),'bookmarks')];
    }
}
$actors=[];
foreach (['https://actor.test/users/alice','https://actor.test/@alice','https://actor.test/users/123','https://actor.test/@123','https://actor.test/users/%31','https://actor.test/@%31','https://actor.test/users/alice%2F','https://actor.test/users/alice%3F','https://actor.test/users/alice%23','https://actor.test/users/alice%25','https://actor.test/users/%FF','https://actor.test/users/%C3%A9x%FF','https://actor.test/users/a%','https://actor.test/users/%61lice','https://actor.test/@caf%C3%A9','https://actor.test/users/a+b','did:plc:alice','https://bsky.app/profile/did%3Aplc%3Aalice'] as $actor) $actors[$actor]=ap_moderation_syntactic_refs($actor);
$reply=[];
foreach ([['https://bsky.app/profile/ALICE.test',''],['https://bsky.app/profile/did:plc:alice',''],['https://bsky.app/profile/did%3Aplc%3Aalice',''],['https://remote.test/users/alice',''],['https://mkultra.monster/users/bob','bob@mkultra.monster'],['https://remote.test/users/a','Alice.test@bsky.app']] as [$actor,$hint]) $reply[]=['actor'=>$actor,'hint'=>$hint,'handle'=>admin_reply_mention_handle($actor,$hint)];
$rssTitles=[];$rssSummaries=[];
$invalidTitle="\xFF Feed";if(ap_rss_display_title($invalidTitle)!==$invalidTitle)throw new RuntimeException('Invalid UTF-8 title must retain original fallback');

foreach(["\u{00A0}Feed name\u{00A0}","\u{2009}Feed name\u{2009}","日本語 Feed 😀",' -–—|: Feed name :|—–- ',"\tFeed name\n",'Feed name RSS','Feed name blog','--Feed name--','\\Feed name\\',"\u{2009}Feed name –","–Feed name\u{2009}"] as $input)$rssTitles[]=['input'=>$input,'title'=>ap_rss_display_title($input)];
foreach(["\u{00A0}Article summary\u{00A0}","\u{2009}Article summary\u{2009}","日本語\u{00A0}😀",'text submitted by /u/name [link] [comments]'] as $input)$rssSummaries[]=['input'=>$input,'summary'=>ap_rss_display_summary($input)];
$rss=[];
$rssRow=ap_rss_item_by_id(11,1);
foreach (['base'=>[],'summary'=>['title'=>'Article','summary_text'=>'Real text submitted by /u/person [link] [comments]'],'short_image'=>['image_url'=>'https://cdn.test/pic.png','summary_text'=>'short caption'],'long_image'=>['image_url'=>'https://cdn.test/pic.png','summary_text'=>str_repeat('Long article body. ',10)],'reddit'=>['url'=>'https://reddit.com/r/example/comments/id','image_url'=>'https://preview.redd.it/abc.jpg?width=300&crop=smart','summary_text'=>'submitted by /u/person [link] [comments]'],'unicode'=>['summary_text'=>str_repeat('日本語 😀 café ',75)]] as $name=>$changes) {
    $row=array_replace($rssRow,$changes);$rss[$name]=['row'=>$row,'status'=>ap_rss_item_to_masto_status($row)];
}
reference_load(dirname(__DIR__).'/ap-rss.php',['ap_rss_item_by_mirror_note_id']);
$GLOBALS['vaak_owner_id']=1;
$rssQuotes=[];
foreach(['base'=>[], 'unicode'=>['title'=>'日本 & café','summary_text'=>str_repeat('日本語 😀 café ',40)], 'empty_title'=>['title'=>'','summary_text'=>str_repeat('Body ',80)], 'image'=>['image_url'=>'https://cdn.test/photo.png'], 'http_article'=>['url'=>'http://article.test/read']] as $name=>$changes) {
    $row=array_replace($rssRow,$changes,['mirror_note_id'=>'https://mkultra.monster/users/fixture/statuses/rss-mirror']);
    foreach(['title','summary_text','url','image_url','mirror_note_id'] as $column)ap_db()->prepare("UPDATE rss_items SET $column=? WHERE id=11")->execute([$row[$column]]);
    $status=['id'=>$row['mirror_note_id'],'uri'=>$row['mirror_note_id'],'url'=>$row['mirror_note_id'],'visibility'=>'public','content'=>'Mirror snapshot','account'=>['uri'=>'https://mkultra.monster/users/fixture','acct'=>'fixture@mkultra.monster']];
    $rssQuotes[$name]=['status'=>$status,'row'=>$row,'html'=>admin_quote_card_html(admin_quote_opts_from_status($status),'bookmarks')];
}
ap_db()->exec('UPDATE rss_items SET mirror_note_id=NULL WHERE id=11');
$widths=[];foreach ([str_repeat('x',501),str_repeat('日',251),str_repeat('😀',251),str_repeat("e\u{0301}",251),str_repeat('a',500),'日本語 😀 café'] as $text) $widths[]=['text'=>$text,'width'=>mb_strwidth($text,'UTF-8'),'trim'=>mb_strimwidth($text,0,500,'…','UTF-8')];
reference_load(dirname(__DIR__).'/ap-db.php',['ap_normalize_bridgy_plain_mentions','ap_plain_unglue_mentions','ap_plain_unglue_hashtags','ap_plain_expand_bare_mentions_from_urls']);
reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_repair_at_hash_tags','ap_masto_unglue_host_mentions','ap_masto_url_looks_like_status']);
$dialects=[];
foreach(['url_unique'=>'Hello @alice https://EXAMPLE.test/@alice/1','url_ambiguous'=>'@alice https://a.test/users/alice/1 https://b.test/@alice/2','url_encoded'=>'@Alice https://a.test/users/%61lice/1','url_numeric'=>'@123 https://a.test/users/123/1','url_protected'=>'https://a.test/@alice/1 @alice','host_local'=>'@bob@mkultra.monsterHello and @bob@mkultra.monster中文!','host_extra'=>'@bob@example.testHello','host_lower'=>'@bob@mkultra.monsterexchange'] as $name=>$text) {$kind=str_starts_with($name,'url_')?'expand':'host';$extra=['example.test'];$dialects[$name]=['kind'=>$kind,'text'=>$text,'extra'=>$extra,'output'=>$kind==='expand'?ap_plain_expand_bare_mentions_from_urls($text):ap_masto_unglue_host_mentions($text,$extra)];}
foreach (['bridgy'=>['@alice.test@bsky.brid.gy hi','@ALICE.TEST@bsky.brid.gy/x','@alice.test@bsky.brid.gyMore'], 'tags'=>['@#Rust@mastodon.social and @#Été','@#tag@EXAMPLE.TEST-more'], 'mentions'=>['@alice@example.testHello @bob@example.test2','@CactuarJoe today @ElizafoxIf','@élise@example.testOui'], 'hashtags'=>['loaf#catsofmastodon 123#m page#section','é#Été )#tag "]#yes','a# 日本#日']] as $kind=>$inputs) foreach($inputs as $index=>$text) {
    $known=['Elizafox'];
    $output=match($kind){'bridgy'=>ap_normalize_bridgy_plain_mentions($text),'tags'=>ap_masto_repair_at_hash_tags($text),'mentions'=>ap_plain_unglue_mentions($text,$known),'hashtags'=>ap_plain_unglue_hashtags($text)};
    $dialects[$kind.'_'.$index]=['kind'=>$kind,'text'=>$text,'known'=>$known,'output'=>$output];
}
$dialectHtml=[];
foreach(['tag_glue'=>'loaf#Été @#Rust@mastodon.social','protected'=>'`loaf#tag @#Rust` then loaf#tag','full_glue'=>'@alice@example.testHello','url_expand'=>'@alice https://example.test/@alice/1','bridgy'=>'@alice.test@bsky.brid.gy hi','local_glue'=>'@fixture@mkultra.monsterHello'] as $name=>$text){
    $rows=[['acct'=>'alice@example.test','username'=>'alice','url'=>'https://example.test/users/alice'],['acct'=>'alice.test','username'=>'alice.test','url'=>'https://bsky.app/profile/alice.test'],['acct'=>'fixture@mkultra.monster','username'=>'fixture','url'=>'https://mkultra.monster/users/fixture']];
    $dialectHtml[$name]=['text'=>$text,'mentions'=>$rows,'html'=>admin_linkify_body_html($text,'bookmarks',$rows)];
}
$statusLinks=[];
foreach(['https://example.test/@alice/abc_123','https://example.test/users/alice/statuses/xyz','https://example.test/objects/uuid','https://example.test/notes/uuid','https://example.test/notice/abc','https://example.test/p/uuid','https://example.test/@alice','https://example.test/users/alice','http://example.test/@alice/123','https://bsky.app/profile/alice.test/post/abc'] as $url)$statusLinks[]=['url'=>$url,'html'=>admin_linkify_body_html($url,'bookmarks')];
reference_load(dirname(__DIR__).'/ap-db.php',['ap_fix_utf8','ap_acct_from_actor_href','ap_is_bridgy_url','ap_html_to_plain_text']);
$htmlPlain=[];
foreach(['<p>First</p><p>Second<br>Third</p>','<div>Hello <b>world</b></div><blockquote>Quoted</blockquote>','<a class="mention" href="https://example.test/@alice">@alice</a>Hello','<a class="mention hashtag" href="https://example.test/tags/Rust">#Rust</a>','<a class="u-url" href="https://example.test/users/%61lice">Alice</a>','<a href="https://bsky.brid.gy/r/alice.test">@alice.test</a>','<a href="https://example.test/p/1">Visible link</a> <a href="https://example.test/empty"></a>','before<span class="quote-inline">RE: https://example.test/posts/q</span>','&Eacute;t&eacute; &amp; tea','cafÃ© werenâ€™t','<ul><li>One</li><li>Two</li></ul>','<!-- removed -->A	 B


C','@alice@example.testHello loaf#cat','<a>label</a>next'] as $index=>$input)$htmlPlain[]=['input'=>$input,'plain'=>admin_html_to_plain($input)];
reference_load(dirname(__DIR__).'/ap-inbox.php',['ap_text_looks_like_as2_json','ap_text_scrub_as2_dump','ap_plain_strip_raw_quote_lines']);
$rawQuotes=[];
foreach([['hello',null],["Commentary\n\n↪ QTCreate Note\n{\"@context\":true}",null],['{ "type":"Note","attributedTo":"x" }',null],['QuoteAuthorization',null],["Comment\n↪ QT: quoted",null],["Comment\nQTNote quote",null],["RE: https://example.test/posts/1\nAfter",null],['same','SAME'],['quoted quoted','quoted'],['comment quoted','quoted'],['QTComment remains',null],['hello interactionPolicy',null]] as [$input,$quoted])$rawQuotes[]=['input'=>$input,'quoted'=>$quoted,'looks'=>ap_text_looks_like_as2_json($input),'scrub'=>ap_text_scrub_as2_dump($input),'plain'=>ap_plain_strip_raw_quote_lines($input,$quoted)];
$rawQuoteCards=[];
ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive=0');
foreach(['qt_comment'=>"Comment\n↪ QT: quoted body",'dump'=>'{ "type":"Note","attributedTo":"x" }','placeholder'=>'(media)','re_url'=>"RE: https://example.test/posts/q\nComment",'html_blocks'=>'<p>First</p><p>Second<br>Third</p>','same_quote'=>'Quoted body'] as $name=>$content){
    $st=$captures['plain']['status'];$st['content']=$content;
    if($name==='same_quote')$st['quote']=['state'=>'accepted','quoted_status'=>array_replace($st,['id'=>'https://quoted.test/posts/q','uri'=>'https://quoted.test/posts/q','url'=>'https://quoted.test/posts/q','content'=>'Quoted body','account'=>['uri'=>'https://quoted.test/users/quoted','acct'=>'quoted@quoted.test']])];
    $rawQuoteCards[$name]=reference_render_card($st);
}
foreach(['fallback','pending','accepted'] as $state){
    $st=$captures['plain']['status'];$st['content']="Comment\n↪ QT: quoted body";
    $st['quote']=['state'=>$state,'quoted_status'=>array_replace($st,['id'=>'https://quoted.test/posts/q','uri'=>'https://quoted.test/posts/q','url'=>'https://quoted.test/posts/q','content'=>'Quoted body','quote'=>null,'account'=>['uri'=>'https://quoted.test/users/quoted','acct'=>'quoted@quoted.test']])];
    $rawQuoteCards['assembled_'.$state]=reference_render_card($st);
}
reference_load(dirname(__DIR__).'/ap-inbox.php',['ap_quote_post_parent_url','ap_object_url_lookup_candidates']);
$urlAliases=[];
foreach([['https://a.test/@alice/123','https://a.test/ap/users/42/statuses/123'],['https://a.test/@alice/opaque','https://a.test/users/alice/posts/opaque'],['https://a.test/@alice/opaque','https://a.test/notes/opaque'],['https://a.test/statuses/q/QuotePost','https://a.test/statuses/q'],['https://a.test/notes/q/activity','https://a.test/notes/q'],['https://a.test/@Alice/123/','https://A.test/@alice/123'],['https://a.test/notes/123','https://b.test/notes/123'],['https://a.test/notes/123','https://a.test/notes/124'],['http://a.test/notes/q','http://a.test/notes/q']] as [$a,$b])$urlAliases[]=['a'=>$a,'b'=>$b,'equivalent'=>admin_status_urls_equivalent($a,$b)];
foreach(['same'=>'https://quoted.test/users/quoted/statuses/123','snowflake'=>'https://quoted.test/@quoted/123','different'=>'https://article.test/read'] as $name=>$cardUrl){
    $st=$captures['plain']['status'];$st['content']='Comment';$st['quote']=['state'=>'accepted','quoted_status'=>array_replace($st,['uri'=>'https://quoted.test/users/quoted/statuses/123','url'=>'https://quoted.test/@quoted/123','content'=>'Quoted body','account'=>['uri'=>'https://quoted.test/users/quoted','acct'=>'quoted@quoted.test']])];
    $st['card']=['url'=>$cardUrl,'title'=>'Link','description'=>'Description'];
    $rawQuoteCards['og_'.$name]=reference_render_card($st);
}
foreach (["\u{00A0}", "\u{2009}", "\u{00A0} Answer \u{00A0}", "\u{2009} Answer \u{2009}"] as $i => $answer) {
    $row = ['question'=>'Current question', 'answer'=>$answer, 'asker_label'=>'Tester'];
    $qrow=['question'=>$answer,'asker_label'=>$answer];
    $asks['unicode_question_'.$i]=['row'=>$qrow,'html'=>ap_ask_card_html($qrow)];
    $asks['unicode_answer_'.$i] = ['row'=>$row, 'html'=>ap_ask_card_html($row)];
    $st=$captures['plain']['status']; $st['content']='Old body'; $st['vaak_ask']=$row;
    $rawQuoteCards['unicode_ask_'.$i]=reference_render_card($st);
    $htmlPlain[]=['input'=>$answer,'plain'=>ap_html_to_plain_text($answer)];
    $rawQuotes[]=['input'=>$answer,'quoted'=>null,'looks'=>ap_text_looks_like_as2_json($answer),'scrub'=>ap_text_scrub_as2_dump($answer),'plain'=>ap_plain_strip_raw_quote_lines($answer)];
}
echo json_encode(['reply_cw'=>$replyCw,'rss_titles'=>$rssTitles,'rss_summaries'=>$rssSummaries,'cw_whitespace'=>$cwWhitespace,'rss_quotes'=>$rssQuotes,'url_aliases'=>$urlAliases,'raw_quote_cards'=>$rawQuoteCards,'raw_quotes'=>$rawQuotes,'html_plain'=>$htmlPlain,'status_links'=>$statusLinks,'dialect_html'=>$dialectHtml,'dialects'=>$dialects,'accountrefs'=>$accountrefs,'degradedcards'=>$degradedcards,'mentions'=>$mentions,'replies'=>$replies,'asks'=>$asks,'facets'=>$facets,'emojis'=>$emojis,'previews'=>$previews,'quotes'=>$quotes,'rss'=>$rss,'widths'=>$widths,'actors'=>$actors,'reply'=>$reply,'mbstring'=>true,'now'=>$now,'times'=>$times,'media'=>$media,'links'=>$links],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
