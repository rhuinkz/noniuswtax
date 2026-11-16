<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/sql_guard.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control:no-store');
function out_sql($d,$s=200):never{http_response_code($s);echo json_encode_safe($d);exit;}
try{
    $a=require_member();require_owner($a);verify_csrf();
    $b=json_decode(file_get_contents('php://input')?:'{}',true);
    $trim=validated_diagnostic_sql((string)($b['sql']??''));
    $pdo=db();
    // Protect against writes even inside otherwise valid SELECT expressions.
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare($trim);$stmt->execute();$rows=[];$truncated=false;
        while($row=$stmt->fetch()){if(count($rows)===200){$truncated=true;break;}$rows[]=$row;}
        $stmt->closeCursor();$pdo->commit();
    } catch(Throwable $error) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    audit_event($a,'sql-read','sql-console',['sql'=>substr($trim,0,2000)]);
    out_sql(['ok'=>true,'read'=>true,'rows'=>$rows,'count'=>count($rows),'truncated'=>$truncated]);
}catch(Throwable $e){out_sql(['error'=>$e->getMessage()],400);}
