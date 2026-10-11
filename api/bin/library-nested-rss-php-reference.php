<?php
declare(strict_types=1);
ob_start();require __DIR__ . '/migration-card-reference-capture.php';$b=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$base=$b['captures']['plain']['status'];$rss=['id'=>'rss:11','source'=>'rss','uri'=>'https://article.test/post','content'=>'PRIVATE SUBSCRIPTION SNAPSHOT','account'=>['acct'=>'Stale Private Feed','uri'=>'https://feed.test'],'vaak_rss_item_id'=>11];
function render_nested(array $base,array $rss,int $owner,string $kind):string{
 $GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_user']=['id'=>$owner];$GLOBALS['fixture_render_clock']=1700000000;
 if($kind==='quote')$base['quote']=['state'=>'accepted','quoted_status'=>$rss];
 elseif($kind==='reblog'){$base['content']='';$base['reblog']=$rss;}
 else{$base['quote']=['state'=>'pending'];$base['vaak_quote_preview']=$rss;}
 ob_start();admin_render_masto_status_card($base,[],'bookmarks');return ob_get_clean();
}
$checks=0;
foreach(['quote','reblog','compact'] as $kind){
 $html=render_nested($base,$rss,2,$kind);if($html!==''||str_contains($html,'PRIVATE SUBSCRIPTION SNAPSHOT'))throw new RuntimeException("Foreign $kind leaked");$checks++;
 foreach(['rss:bad','rss:0','rss:+11','rss:9999'] as $id){$bad=$rss;$bad['id']=$id;if(render_nested($base,$bad,1,$kind)!=='')throw new RuntimeException("Malformed/deleted $kind rendered");$checks++;}
}
ap_db()->exec("UPDATE rss_items SET title='Current owner title',summary_text='Current owner summary' WHERE id=11");
foreach(['quote','reblog','compact'] as $kind){$html=render_nested($base,$rss,1,$kind);if(str_contains($html,'PRIVATE SUBSCRIPTION SNAPSHOT')||!str_contains($html,'Current owner title')||!str_contains($html,'Current owner summary'))throw new RuntimeException("Current $kind stale");$checks++;}
ap_db()->exec("INSERT INTO fixture_words VALUES(1,'current owner title')");if(render_nested($base,$rss,1,'quote')!=='')throw new RuntimeException('Fresh muted title leaked');$checks++;ap_db()->exec('DELETE FROM fixture_words');
ap_db()->exec('UPDATE rss_feeds SET owner_user_id=2 WHERE id=1');if(render_nested($base,$rss,1,'quote')!=='')throw new RuntimeException('Changed subscription owner leaked');$checks++;
$public=array_replace($base,['id'=>'foreign-public','uri'=>'https://remote.test/public-mirror','url'=>'https://remote.test/public-mirror','content'=>'Ordinary public AS2 mirror body']);
$html=render_nested($base,$public,1,'quote');if(!str_contains($html,'Ordinary public AS2 mirror body')||str_contains($html,'Current owner title'))throw new RuntimeException('Foreign ordinary public AS2 was changed');$checks++;
echo "library-nested-rss-php: PASS ($checks actual PHP complete painter controls: recursive ownership, malformed/deleted, fresh title/summary, late muted title/owner change, foreign public AS2 unchanged)\n";
