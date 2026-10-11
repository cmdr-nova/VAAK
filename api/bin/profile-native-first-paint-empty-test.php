<?php
declare(strict_types=1);
/** Actual current first-paint placeholder/loader fragment, no card or header fixture. */
$source=(string)file_get_contents(dirname(__DIR__).'/ap-admin.php');
$start=strpos($source,'            <?php if ($rpTabItems[$rpTab] === [] && ($rpAxumHtml');
$end=strpos($source,'            <?php if ($rpAxumHtml !== null): ?>',$start);
if($start===false||$end===false)throw new RuntimeException('actual first-paint fragment boundary');
$fragment=substr($source,$start,$end-$start);
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$checks=[];
foreach([['native_empty','',0,true],['native_selected_omitted','',2,false],['legacy_empty',null,null,true]] as [$case,$rpAxumHtml,$rpCompleteSourceCount,$expected]){
 $rpTab='posts';$rpTabItems=['posts'=>[]];$rpLocalPageItems=[];$rpIsLocal=true;$rpActor='https://mkultra.monster/users/fixture';$rpLocalNextOffset=$rpCompleteSourceCount??0;$rpLocalPageLimit=12;$rpLocalHasMore=false;$rpAxumCache='';
 ob_start();eval('?>'.$fragment);$html=ob_get_clean();
 if(str_contains($html,'No posts available.')!==$expected||!str_contains($html,'data-offset="'.$rpLocalNextOffset.'"'))throw new RuntimeException('actual firstpaint source-empty distinction');
 $checks[]=['case'=>$case,'html'=>$html,'empty_placeholder'=>$expected];
}
echo json_encode(['checks'=>$checks,'production_fragment_sha256'=>hash('sha256',$fragment),'scope'=>'actual first-paint empty placeholder/loader fragment only, not full header/card HTTP'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
