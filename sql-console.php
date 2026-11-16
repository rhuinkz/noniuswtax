<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control:no-store');
function out_sql($d,$s=200):never{http_response_code($s);echo json_encode_safe($d);exit;}
try{
    $a=require_member();require_owner($a);verify_csrf();
    $b=json_decode(file_get_contents('php://input')?:'{}',true);$sql=trim((string)($b['sql']??''));if($sql==='')throw new RuntimeException('Enter a SQL statement.');
    $trim=rtrim($sql," \t\r\n;");if(str_contains($trim,';'))throw new RuntimeException('Run one SQL statement at a time.');
    $verb=strtoupper((string)strtok(ltrim($trim)," \t\r\n"));$read=in_array($verb,['SELECT','SHOW','DESCRIBE','DESC','EXPLAIN'],true);
    if(!$read)throw new RuntimeException('Production SQL Console is read-only. Use application workflows or a reviewed database migration for changes.');
    $stmt=db()->prepare($trim);$stmt->execute();$rows=[];$truncated=false;while($row=$stmt->fetch()){if(count($rows)===200){$truncated=true;break;}$rows[]=$row;}$stmt->closeCursor();
    audit_event($a,'sql-read','sql-console',['sql'=>substr($trim,0,2000)]);out_sql(['ok'=>true,'read'=>true,'rows'=>$rows,'count'=>count($rows),'truncated'=>$truncated]);
}catch(Throwable $e){out_sql(['error'=>$e->getMessage()],400);}
