<?php
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';reference_load(dirname(__DIR__).'/ap-db.php',['ap_profile_badge_catalog','ap_profile_normalize_badges']);
$cases=[null,'invalid-json',[],['supporter'],[' supporter '],['supporter','supporter','cat'],['fake','supporter'],['cat','dog','coffee','tea','bookworm','gamer','space'],['cat','dog','coffee','tea','bookworm','gamer','supporter'],['supporter','cat','dog','coffee','tea','bookworm','gamer','space'],['cat','dog','coffee','tea','bookworm','gamer',' supporter '],['supporter',null,3,true,[],['supporter'],'cat'],json_encode(['supporter','cat'])];
$out=[];foreach($cases as$input)$out[]=['input'=>$input,'expected'=>ap_profile_normalize_badges($input)];
echo json_encode(['cases'=>$out,'supporter'=>ap_profile_badge_catalog()['supporter']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
