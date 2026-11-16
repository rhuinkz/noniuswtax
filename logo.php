<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if(!is_installed()){http_response_code(404);exit;}
try{
    $division=master_row('division','division');
    $relative=(string)($division['logo_path']??'');
    if($relative==='' || !preg_match('#^storage/division-logo(?:-[a-f0-9]{16})?\.(png|jpg|webp)$#',$relative,$m)){http_response_code(404);exit;}
    $storage=realpath(ROOT_PATH.'/storage');
    $file=realpath(ROOT_PATH.'/'.$relative);
    if(!$storage || !$file || !str_starts_with($file,$storage.DIRECTORY_SEPARATOR) || !is_file($file)){http_response_code(404);exit;}
    $mime=['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'][$m[1]]??'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($file));
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
}catch(Throwable $e){
    error_log('NonIUS logo: '.$e->getMessage());
    http_response_code(404);
}
