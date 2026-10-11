<?php
declare(strict_types=1);
/** Actual canonical quote opts/card helper; only preview network transport is isolated. */
putenv('VAAK_SEARCH_NATIVE_RENDER=0');
putenv('VAAK_LIBRARY_NATIVE_RENDER=0');
require_once dirname(__DIR__).'/ap-library-native.php';
ob_start();require __DIR__.'/search-render-reference-capture.php';
$corpus=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR)['captures'];
$base=$corpus['status_plain']['status'];
$base['uri']=$base['url']='https://quote.test/preview-boundary';$base['account']['acct']='quote-author@quote.test';
$base['content']='<p>Plain quoted body</p>';$base['media_attachments']=[];$base['card']=null;
$checks=0;
function preview_boundary_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$cases=['plain'=>$base];
$cases['card']=array_replace($base,['card'=>['url'=>'https://article.test/card','title'=>'Supplied card','description'=>'Card text','image'=>null,'provider_name'=>'article.test']]);
$cases['media']=array_replace($base,['media_attachments'=>[['type'=>'image','url'=>'https://quote.test/image.png','preview_url'=>'https://quote.test/image.png','description'=>'Quoted image']]]);
foreach($cases as $name=>$quoted){
 $GLOBALS['admin_quote_link_preview_budget']=3;$GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];
 $before=json_encode($quoted,JSON_THROW_ON_ERROR);
 $requires=ap_search_native_quote_requires_php($quoted);
 preview_boundary_check($requires===($name==='plain'),'actual opts classification '.$name);
 preview_boundary_check($GLOBALS['admin_quote_link_preview_budget']===3&&$GLOBALS['preview_http_requests']===[]&&$GLOBALS['preview_warm_requests']===[],'classifier performs no preview IO or budget consumption '.$name);
 preview_boundary_check(json_encode($quoted,JSON_THROW_ON_ERROR)===$before,'classifier preserves exact source descriptor '.$name);
 $opts=admin_quote_opts_from_status($quoted,$quoted['uri']);
 $html=admin_quote_card_html($opts,'search');
 preview_boundary_check(str_contains($html,'Plain quoted body'),'actual quoted body retained '.$name);
 preview_boundary_check($GLOBALS['admin_quote_link_preview_budget']===($name==='plain'?2:3),'actual PHP preview budget branch '.$name);
 $parent=$base;$parent['quote']=['state'=>'accepted','quoted_status'=>$quoted];
 $parentBefore=json_encode($parent,JSON_THROW_ON_ERROR);$fallback=false;
 try{$assembled=ap_search_native_assemble_status($parent);}catch(RuntimeException $error){$fallback=true;}
 preview_boundary_check($fallback===($name==='plain'),'precise whole batch source boundary '.$name);
 preview_boundary_check(json_encode($parent,JSON_THROW_ON_ERROR)===$parentBefore,'assembler keeps original source '.$name);
}
foreach(['fixture_mutes','fixture_blocks','fixture_words','fixture_server_blocks','fixture_status_cache'] as $table)ap_db()->exec('DELETE FROM '.$table);
foreach(['bookmarks','favourites'] as $view){
 $GLOBALS['view']=$view;$GLOBALS['admin_quote_link_preview_budget']=3;$GLOBALS['admin_library_fetch_budget']=3;$GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];
 $parent=$base;$parent['uri']=$parent['url']='https://public.test/quote-boundary-parent';$parent['content']='Outer quote boundary marker';$parent['quote']=['state'=>'accepted','quoted_status'=>$base];
 ob_start();admin_render_library_entries([['kind'=>'masto','item'=>$parent]],$view);$html=(string)ob_get_clean();
 preview_boundary_check(str_contains($html,'Plain quoted body'),'actual Library '.$view.' supplied plain quote body');
 preview_boundary_check($GLOBALS['admin_quote_link_preview_budget']===2,'actual Library '.$view.' supplied plain quote consumes preview budget');
}
echo 'search-quote-preview-boundary PASS '.$checks." actual opts/helper controls\n";
