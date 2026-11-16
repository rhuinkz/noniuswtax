<?php
declare(strict_types=1);

/** Owner-managed gallery; public login uses only sanitized JPEGs listed here. */
function login_gallery_path(string $root): string {
    return $root.'/storage/login-slideshow/owner-gallery.json';
}

function login_gallery_read(string $root): array {
    $path=login_gallery_path($root);
    if(!is_file($path)) return ['enabled'=>true,'images'=>[],'updated_at'=>null];
    try {
        $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!is_array($data['images']??null))throw new RuntimeException('Invalid gallery manifest.');
        $images=[];
        foreach($data['images'] as $row){
            if(!is_array($row))continue;
            $id=$row['id']??null;
            $file=$row['file']??null;
            if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||$file!==$id.'.jpg')continue;
            if(!is_file($root.'/assets/login-slides/'.$file))continue;
            $images[]=['id'=>$id,'file'=>$file,'name'=>substr((string)($row['name']??'Photo'),0,130),
                'added_at'=>(string)($row['added_at']??'')];
        }
        return ['enabled'=>($data['enabled']??true)===true,'images'=>$images,
            'updated_at'=>is_string($data['updated_at']??null)?$data['updated_at']:null];
    }catch(Throwable $e){throw new RuntimeException('Login image gallery could not be read.',0,$e);}
}

function login_slideshow_urls(string $root): array {
    try{$data=login_gallery_read($root);}catch(Throwable){return [];}
    if(!$data['enabled'])return [];
    return array_map(static fn($row)=>'assets/login-slides/'.$row['file'],$data['images']);
}

/** Formats accepted by browsers and PHP-GD; excludes SVG and HTML. */
function supported_slideshow_mime(string $mime): bool {
    return in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true);
}

function slideshow_cache_name(string $googleFileId,string $modifiedTime): string {
    if(!preg_match('/^[A-Za-z0-9_-]{10,128}$/D',$googleFileId))throw new RuntimeException('Drive file identifier invalid.');
    return hash('sha256',$googleFileId.'|'.$modifiedTime).'.jpg';
}

function slideshow_encode_photo(string $bytes,string $destination): void {
    if(strlen($bytes)>12*1024*1024)throw new RuntimeException('Login photo exceeds 12 MB.');
    $info=@getimagesizefromstring($bytes);
    if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP,IMAGETYPE_GIF],true))throw new RuntimeException('Login photo is not a supported raster image.');
    [$w,$h]=$info;
    if($w<1||$h<1||($w*$h)>12000000)throw new RuntimeException('Login photo dimensions are outside supported limits.');
    if(!extension_loaded('gd')||!function_exists('imagecreatefromstring'))throw new RuntimeException('PHP GD extension is required to strip image metadata and optimize photos.');
    $source=@imagecreatefromstring($bytes);
    if(!$source)throw new RuntimeException('Login photo could not be decoded.');
    try{
        $scale=min(1.0,1920/$w,1280/$h);
        $dw=max(1,(int)round($w*$scale));$dh=max(1,(int)round($h*$scale));
        $target=imagecreatetruecolor($dw,$dh);
        if(!$target)throw new RuntimeException('Could not allocate the optimized photo.');
        try{
            $white=imagecolorallocate($target,255,255,255);
            imagefill($target,0,0,$white);
            if(!imagecopyresampled($target,$source,0,0,0,0,$dw,$dh,$w,$h))throw new RuntimeException('Could not scale a login photo.');
            // New JPEG bytes discard EXIF/GPS and avoid serving the original Drive file.
            if(!imagejpeg($target,$destination,78))throw new RuntimeException('Could not write an optimized photo.');
        }finally{imagedestroy($target);}
    }finally{imagedestroy($source);}
}

/** Owner-visible gallery status, independent of Google Cloud/API credentials. */
function login_slideshow_status(string $root,array $config=[]): array {
    try{$gallery=login_gallery_read($root);return [
        'configured'=>true,'enabled'=>$gallery['enabled'],
        'photos'=>count($gallery['images']),'last_sync'=>$gallery['updated_at']
    ];}catch(Throwable){return ['configured'=>false,'enabled'=>false,'photos'=>0,'last_sync'=>null];}
}
