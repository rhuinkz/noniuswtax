<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/login_slideshow.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * Project Owner-managed login photos. No Drive credentials or external requests.
 * The public login page reads the manifest, not this authenticated API.
 */
function gallery_write(string $root,array $gallery): void {
    $dir=$root.'/storage/login-slideshow';
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Cannot create private gallery storage.');
    $path=login_gallery_path($root);
    $tmp=tempnam($dir,'.gallery-');
    if($tmp===false)throw new RuntimeException('Cannot write gallery settings.');
    try{
        $gallery['updated_at']=now_iso();
        $json=json_encode_safe($gallery);
        if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Cannot write gallery settings.');
        @chmod($tmp,0640);
        if(!rename($tmp,$path))throw new RuntimeException('Cannot save gallery settings.');
    }finally{if(is_file($tmp))@unlink($tmp);}
}

function gallery_with_lock(string $root,callable $action): mixed {
    $dir=$root.'/storage/login-slideshow';
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Cannot create private gallery storage.');
    $lock=fopen($dir.'/owner-gallery.lock','c');
    if($lock===false)throw new RuntimeException('Cannot lock login gallery.');
    try{
        if(!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock login gallery.');
        return $action();
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function gallery_public_summary(string $root): array {
    $g=login_gallery_read($root);
    return ['enabled'=>$g['enabled'],'updated_at'=>$g['updated_at'],'max_images'=>40,
        'images'=>array_map(static fn($r)=>[
            'id'=>$r['id'],'name'=>$r['name'],'added_at'=>$r['added_at'],
            'url'=>'assets/login-slides/'.$r['file']
        ],$g['images'])];
}

try{
    $actor=require_member();require_owner($actor);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        echo json_encode_safe(gallery_public_summary(ROOT_PATH));exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Unsupported request method.');
    verify_csrf();
    $op=(string)($_POST['action']??'');
    $result=gallery_with_lock(ROOT_PATH,static function()use($op): array {
        $root=ROOT_PATH;
        $g=login_gallery_read($root);
        if($op==='toggle'){
            if(!in_array($_POST['enabled']??null,['0','1'],true))throw new RuntimeException('Select a valid gallery state.');
            $g['enabled']=$_POST['enabled']==='1';
            gallery_write($root,$g);
            return gallery_public_summary($root);
        }
        if($op==='remove'){
            $id=(string)($_POST['id']??'');
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid photo selection.');
            $old=count($g['images']);
            $g['images']=array_values(array_filter($g['images'],static fn($i)=>$i['id']!==$id));
            if(count($g['images'])===$old)throw new RuntimeException('Photo was not found.');
            gallery_write($root,$g);
            // Delete only an application-generated JPEG, after the manifest no longer lists it.
            @unlink($root.'/assets/login-slides/'.$id.'.jpg');
            return gallery_public_summary($root);
        }
        if($op!=='upload')throw new RuntimeException('Unknown gallery action.');
        $uploads=$_FILES['photos']??null;
        if(!is_array($uploads)||!is_array($uploads['name']??null))throw new RuntimeException('Choose one or more photos to upload.');
        $count=count($uploads['name']);
        if($count<1||$count>8)throw new RuntimeException('Upload between 1 and 8 photos at a time.');
        if(count($g['images'])+$count>40)throw new RuntimeException('Maximum 40 gallery photos. Remove older photos first.');
        if(!extension_loaded('gd'))throw new RuntimeException('PHP GD is required to optimize photos. Contact your hosting administrator.');
        $dir=$root.'/assets/login-slides';
        if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Cannot create photo cache.');
        $created=[];
        try{
            for($i=0;$i<$count;$i++){
                if(($uploads['error'][$i]??-1)!==UPLOAD_ERR_OK)throw new RuntimeException('A photo upload failed or exceeded the server upload limit.');
                $size=(int)($uploads['size'][$i]??0);
                if($size<1||$size>8*1024*1024)throw new RuntimeException('Each photo must be 8 MB or smaller.');
                $tmp=(string)($uploads['tmp_name'][$i]??'');
                if(!is_uploaded_file($tmp))throw new RuntimeException('Invalid uploaded photo.');
                $bytes=file_get_contents($tmp);
                if($bytes===false||strlen($bytes)!==$size)throw new RuntimeException('Photo could not be read.');
                $id=bin2hex(random_bytes(16));
                $path=$dir.'/'.$id.'.jpg';
                $staging=tempnam($dir,'.upload-');
                if($staging===false)throw new RuntimeException('Cannot prepare photo.');
                try{
                    slideshow_encode_photo($bytes,$staging); // validates raster data, size and pixels; strips EXIF/GPS.
                    @chmod($staging,0644);
                    if(!rename($staging,$path))throw new RuntimeException('Could not save photo.');
                }finally{if(is_file($staging))@unlink($staging);}
                $created[]=$path;
                $name=basename(str_replace('\\','/',(string)($uploads['name'][$i]??'Photo')));
                $g['images'][]=['id'=>$id,'file'=>$id.'.jpg','name'=>substr($name,0,130),'added_at'=>now_iso()];
            }
            gallery_write($root,$g);
        }catch(Throwable $e){foreach($created as $file)@unlink($file);throw $e;}
        return gallery_public_summary($root);
    });
    echo json_encode_safe($result);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode_safe(['error'=>$e->getMessage()]);
}
