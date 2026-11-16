<?php
declare(strict_types=1);
// Run from the repository root in CI to catch accidental publication of
// private configuration, SQL dumps, backups and local credentials.
$root=dirname(__DIR__);
$process=proc_open(['git','-C',$root,'ls-files','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process)){fwrite(STDERR,"Cannot inspect tracked repository files\n");exit(1);}
$paths=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
if($code!==0 || $paths===false){fwrite(STDERR,"Git file inventory failed: $stderr\n");exit(1);}
$blocked=[];
foreach(array_filter(explode("\0",$paths),'strlen') as $name){
    if(preg_match('~(^|/)(?:config\.local\.php|\.env(?:\.[^/]*)?|INSTALL_KEY\.txt)$~i',$name)
        && !str_ends_with($name,'.env.example'))$blocked[]=$name;
    if(preg_match('~(^|/)(?:storage/backups/|assets/login-slides/|[^/]+\.(?:sql|dump|db|sqlite|bak|zip|pem|key|p12))~i',$name))$blocked[]=$name;
}
if($blocked){fwrite(STDERR,"Sensitive or backup files tracked: ".implode(', ',array_unique($blocked))."\n");exit(1);}
$api=file_get_contents($root.'/api.php');$js=file_get_contents($root.'/assets/app.js');
if(!is_string($api)||!is_string($js)||str_contains($api,'resetTestData')||str_contains($js,'resetTestData')){
    fwrite(STDERR,"Destructive reset feature or missing source detected\n");exit(1);
}
$migrations=file_get_contents($root.'/lib/migrations.php');
if(!is_string($migrations)||!str_contains($migrations,'037_checked_hash_period_guard')||!str_contains($migrations,'protect_checked_hash_period_update')){
    fwrite(STDERR,"Checked seal month-lock migration missing\n");exit(1);
}
echo "PASS tracked file and migration policy\n";
