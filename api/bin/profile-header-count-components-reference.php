<?php
declare(strict_types=1);
$inputs=['9007199254740993','-9007199254740993','9223372036854775807','-9223372036854775808','9223372036854775808','-9223372036854775809','9999999999999999999999','1e30','-1e30','12.75 trailing','1e2 remainder','  +42tail',"\u{00a0}12",'plain','0',0,17,-3,12.75,true,false,null,[],[1],['count'=>1]];
$out=[];foreach($inputs as $input)$out[]=['input'=>$input,'php_int'=>(int)$input];echo json_encode(['cases'=>$out],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
