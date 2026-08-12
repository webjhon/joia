<?php
header('Content-Type: application/json; charset=utf-8');
$dir=__DIR__.'/tmp/joia_uploads/';
if(!file_exists($dir))mkdir($dir,0777,true);

if(!isset($_FILES['file'])){echo json_encode(["error"=>"Nenhum arquivo enviado."]);exit;}
$f=$_FILES['file'];
$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
$allow=['png','jpg','jpeg','webm','mp3','wav','m4a'];
if(!in_array($ext,$allow)){echo json_encode(["error"=>"Tipo não permitido."]);exit;}

$name='joia_'.date('Ymd_His').'.'.$ext;
$path=$dir.$name;
if(move_uploaded_file($f['tmp_name'],$path)){
 foreach(glob($dir.'*')as$o)if(time()-filemtime($o)>1800)unlink($o);
 $url='https://'.$_SERVER['HTTP_HOST'].'/joia/tmp/joia_uploads/'.$name;
 echo json_encode(["url"=>$url]);
}else echo json_encode(["error"=>"Falha no upload."]);
?>
