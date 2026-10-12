<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/migration-reference-functions.php';
reference_load(dirname(__DIR__).'/ap-inbox.php',['ap_as_id','ap_unwrap_as2_object','ap_quote_is_tombstone','ap_quote_target_pack']);
$url='https://quote.test/users/quoted/notes/source/';
$cases=[];
foreach(['quote','https://w3id.org/fep/044f#quote','https://misskey-hub.net/ns#_misskey_quote','https://other.test/quoted-object'] as $rel){
 foreach([false,true] as $array){$tag=['type'=>'Link','rel'=>$rel,'href'=>$url];$cases['relation_'.$rel.'_'.(int)$array]=['tag'=>$array?[$tag]:$tag];}
}
$cases+= [
 'associative_collection'=>['tag'=>['first'=>['rel'=>'quote','href'=>$url]]],
 'null_type_collection'=>['tag'=>['type'=>null,'first'=>['rel'=>'quote','href'=>$url]]],
 'ordered_targets'=>['tag'=>[['rel'=>'alternate','href'=>'https://not-quote.test/n'],['rel'=>'quote','href'=>$url],['rel'=>'quote','href'=>'https://second.test/n']]],
 'rel_associative'=>['tag'=>[['rel'=>['relation'=>'quote'],'href'=>$url]]],
 'rel_array'=>['tag'=>[['rel'=>[42,null,'alternate','quote'],'href'=>$url]]],
 'plain_activity_link'=>['tag'=>[['type'=>'Link','mediaType'=>'application/activity+json','href'=>$url]]],
 'bad_scheme'=>['tag'=>[['rel'=>'quote','href'=>'http://quote.test/n']]],
 'bad_href_type'=>['tag'=>[['rel'=>'quote','href'=>['id'=>$url]]]],
 'bad_rel_type'=>['tag'=>[['rel'=>42,'href'=>$url]]],
 'missing_href'=>['tag'=>[['rel'=>'quote']]],
 'nonobject_tag'=>['tag'=>[$url,42,null]],
 'null_tag'=>['tag'=>null],
 'empty_tag'=>['tag'=>[]],
 'scalar_precedence'=>['quote'=>'https://scalar.test/n','tag'=>[['rel'=>'quote','href'=>$url]]],
 'scalar_http_then_tag'=>['quote'=>'http://scalar.test/n','tag'=>[['rel'=>'quote','href'=>$url]]],
 'upper_rel'=>['tag'=>[['rel'=>'QUOTE','href'=>$url]]],
 'empty_rel'=>['tag'=>[['rel'=>'','href'=>$url]]],
];
$rows=[];foreach($cases as $case=>$object){$pack=ap_quote_target_pack($object);$rows[]=['case'=>$case,'object'=>$object,'plain'=>'Plain ordinary body','php_pack'=>$pack];}
echo json_encode(['captures'=>$rows,'source_sha256'=>hash_file('sha256',dirname(__DIR__).'/ap-inbox.php'),'scope'=>'actual unchanged PHP target-pack declarations; pure protocol extraction only, canonical child admission and whole caller separate'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
