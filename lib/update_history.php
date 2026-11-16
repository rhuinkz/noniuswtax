<?php
declare(strict_types=1);

/** Owner-visible, database-backed record of confirmed updater attempts. */
function update_history_begin(array $actor, string $from, string $to, string $notes, string $sha256): string {
    $id=uuid();
    $stmt=db()->prepare('INSERT INTO update_history(id,from_version,to_version,actor_id,actor_name,status,release_details,package_sha256,error_summary,started_at,finished_at) VALUES(?,?,?,?,?,\'installing\',?,?,NULL,?,NULL)');
    $stmt->execute([$id,$from,$to,(string)($actor['id']??''),(string)($actor['username']??$actor['user_id']??'Project Owner'),substr($notes,0,4000),$sha256,now_iso()]);
    return $id;
}

function update_history_finish(string $id, string $status, string $message=''): void {
    if(!in_array($status,['successful','failed'],true))throw new RuntimeException('Invalid update outcome.');
    $stmt=db()->prepare('UPDATE update_history SET status=?,error_summary=?,finished_at=? WHERE id=? AND status=\'installing\'');
    $stmt->execute([$status,$status==='failed'?substr($message,0,350):null,now_iso(),$id]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Update History entry was not completed.');
}

/** Older audit events occur BEFORE file replacement and do not establish success. */
function update_history_for_owner(int $limit=60): array {
    $limit=max(1,min(100,$limit));
    $rows=db()->query('SELECT from_version,to_version,actor_id,actor_name,status,release_details,package_sha256,error_summary,started_at,finished_at FROM update_history ORDER BY started_at DESC LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
    $out=[];$modern=[];
    foreach($rows as $r){
        $modern[(string)$r['from_version'].'/'.(string)$r['to_version']]=true;
        $status=(string)$r['status'];
        if($status==='installing' && (strtotime((string)$r['started_at'])?:0)<time()-3600)$status='interrupted (unverified)';
        $out[]=[
            'from_version'=>$r['from_version'],'to_version'=>$r['to_version'],
            'actor'=>$r['actor_name'],'status'=>$status,
            'at'=>$r['finished_at']?:$r['started_at'],'release_details'=>$r['release_details'],
            'note'=>$r['error_summary'],'legacy'=>false
        ];
    }
    $past=db()->query("SELECT actor,details,at FROM audit WHERE action='system-update' ORDER BY at DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
    foreach($past as $r){
        try{$d=json_decode_assoc((string)$r['details']);}catch(Throwable){continue;}
        $from=(string)($d['from']??'');$to=(string)($d['to']??'');
        if($from===''||$to===''||isset($modern[$from.'/'.$to]))continue;
        $out[]=['from_version'=>$from,'to_version'=>$to,'actor'=>(string)$r['actor'],
            'status'=>'unverified (legacy)','at'=>$r['at'],'release_details'=>'',
            'note'=>'Older updater recorded an installation attempt, not its final outcome.',
            'legacy'=>true];
    }
    usort($out,fn($a,$b)=>strcmp((string)$b['at'],(string)$a['at']));
    return array_slice($out,0,$limit);
}
