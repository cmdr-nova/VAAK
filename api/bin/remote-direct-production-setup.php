<?php
declare(strict_types=1);
/** Reusable fixture setup; actual helpers, declared offline dependencies only. */
putenv('DIRECT_PRODUCTION_HELPERS=1');
$setup=(string)file_get_contents(__DIR__.'/remote-direct-policy-independent.php');$end=strpos($setup,'$captures=[];$checks=0;');if($end===false)throw new RuntimeException('production setup boundary');$resolved='';foreach(token_get_all(substr($setup,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
