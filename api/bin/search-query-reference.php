<?php
require __DIR__.'/migration-reference-functions.php';
reference_load(dirname(__DIR__).'/ap-db.php',['ap_fix_utf8']);
reference_load(dirname(__DIR__).'/ap-search-fts.php',['ap_search_fts_match_query','ap_search_fts_postgres_query']);
$out=[];
$queries=['İX','İx alpha','ΟΣ','ΟΣΑ','ΣΟΣ','ΟΣ́','Straße ẞ','ÉTÉ','１２','漢字','a','ab','a_b','!!!',"\u{00A0}foo\u{2009}bar","a\u{001C}bc","a\u{001F}bc","a\u{000B}bc",'"foo" AND bar:*','one two three four five six seven eight nine',"i\u{0307}x",'foo%bar_baz'];
foreach($queries as $query)foreach(['','Rust','İX','ΟΣ','rust_tag','!!!'] as $tag){$match=ap_search_fts_match_query($query,$tag);$out[]=['query'=>$query,'tag'=>$tag,'match'=>$match,'postgres'=>$match===null?null:ap_search_fts_postgres_query($match)];}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
