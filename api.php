<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(mixed $data, int $status=200): never { http_response_code($status); echo json_encode_safe($data); exit; }
function body_json(): array { $raw=file_get_contents('php://input'); $v=json_decode($raw?:'{}',true); if(!is_array($v)) throw new RuntimeException('Invalid request.'); return $v; }
function fail(Throwable $e): never { error_log('NonIUS API: '.$e->getMessage()); $m=$e->getMessage(); if($e instanceof PDOException) $m='Unable to save. Check for duplicate records or reload and try again.'; $status=$m==='Please sign in to continue.'?401:400; respond(['error'=>$m],$status); }

function division_logo_storage_dir(): string { return ROOT_PATH . '/storage'; }
function remove_division_logo_files(?string $keep=null): void {
    $dir=division_logo_storage_dir();
    foreach (glob($dir.'/division-logo-*.*') ?: [] as $file) {
        if(!is_file($file)) continue;
        if($keep!==null && basename($file)===$keep) continue;
        @unlink($file);
    }
    foreach (glob($dir.'/division-logo.*') ?: [] as $legacy) if(is_file($legacy)) @unlink($legacy);
}
function store_division_logo(string $dataUrl): array {
    if(!preg_match('#^data:(image/(png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)$#',$dataUrl,$m)) throw new RuntimeException('Division logo must be a PNG, JPG or WEBP image.');
    $bytes=base64_decode($m[3],true);
    if($bytes===false) throw new RuntimeException('Division logo upload is invalid.');
    if(strlen($bytes)>800*1024) throw new RuntimeException('Division logo must be 800 KB or smaller.');
    $info=@getimagesizefromstring($bytes);
    if(!$info || empty($info['mime']) || $info['mime']!==$m[1]) throw new RuntimeException('Division logo content does not match the selected image type.');
    if(($info[0]??0)<16 || ($info[1]??0)<16 || ($info[0]??0)>4096 || ($info[1]??0)>4096) throw new RuntimeException('Division logo dimensions must be between 16 and 4096 pixels.');
    $dir=division_logo_storage_dir();
    if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Storage folder could not be created for the division logo.');
    $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$m[1]] ?? null;
    if(!$ext) throw new RuntimeException('Division logo type is not supported.');
    $basename='division-logo-'.bin2hex(random_bytes(8)).'.'.$ext;
    $path=$dir.'/'.$basename;
    if(file_put_contents($path,$bytes,LOCK_EX)===false) throw new RuntimeException('Division logo could not be saved.');
    return ['logo_path'=>'storage/'.$basename,'logo_updated_at'=>now_iso(),'stored_file'=>$path,'stored_basename'=>$basename];
}

function can_approve_password_reset(array $actor, array $target): bool {
    if (is_project_owner($actor)) return true;
    return is_division_admin($actor) && ($target['role'] ?? '') === 'school_user';
}
function new_temporary_password(): string { return bin2hex(random_bytes(16)); }
function reset_temporary_password(array $member): array {
    // Generate a fresh, unpredictable temporary secret for each approved request.
    return ['password'=>new_temporary_password(),'basis'=>'random_one_time'];
}

function pending_password_resets_for_actor(array $actor): array {
    if (!is_admin_or_owner($actor)) return [];
    $now=now_iso();
    $expire=db()->prepare("UPDATE password_reset_requests SET status='expired' WHERE status='pending' AND expires_at IS NOT NULL AND expires_at<?");
    $expire->execute([$now]);
    if (is_project_owner($actor)) {
        $stmt=db()->prepare("SELECT r.id,r.member_id,r.status,r.requested_at,r.expires_at,m.username,m.email,m.role,m.school_id,m.account_origin FROM password_reset_requests r JOIN members m ON m.id=r.member_id WHERE r.status='pending' AND (r.expires_at IS NULL OR r.expires_at>=?) AND m.active=1 ORDER BY r.requested_at ASC");
        $stmt->execute([$now]);
    } else {
        $stmt=db()->prepare("SELECT r.id,r.member_id,r.status,r.requested_at,r.expires_at,m.username,m.email,m.role,m.school_id,m.account_origin FROM password_reset_requests r JOIN members m ON m.id=r.member_id WHERE r.status='pending' AND (r.expires_at IS NULL OR r.expires_at>=?) AND m.active=1 AND m.role='school_user' ORDER BY r.requested_at ASC");
        $stmt->execute([$now]);
    }
    return $stmt->fetchAll();
}

function current_manila_month(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m');
}
function valid_period_month(mixed $value): string {
    $month=trim((string)$value);
    if(!preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/',$month)) throw new RuntimeException('Select a valid transaction month.');
    return $month;
}
function period_month_label(string $month): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m',$month,new DateTimeZone('Asia/Manila'));
    return $d?$d->format('F Y'):$month;
}
function period_month_bounds(string $month): array {
    $start=$month.'-01';
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$start,new DateTimeZone('Asia/Manila'));
    if(!$d) throw new RuntimeException('Invalid transaction month.');
    return [$start,$d->modify('+1 month')->format('Y-m-d')];
}
function period_target_key(string $scope, ?string $schoolId): string {
    return $scope==='division'?'division':'school:'.(string)$schoolId;
}
function period_target_label(string $scope, ?string $schoolId): string {
    if($scope==='division') return 'Division Office';
    $school=$schoolId?master_row('schools',$schoolId):null;
    return (string)($school['name']??'School');
}
function transaction_period_row(string $month, string $scope, ?string $schoolId): ?array {
    $stmt=db()->prepare('SELECT * FROM transaction_periods WHERE period_month=? AND target_key=? LIMIT 1');
    $stmt->execute([$month,period_target_key($scope,$schoolId)]);
    $row=$stmt->fetch();
    if(!$row)return null;
    $row['version']=(int)$row['version'];
    return $row;
}
function transaction_period_state(string $month, string $scope, ?string $schoolId): string {
    return (string)(transaction_period_row($month,$scope,$schoolId)['state']??'not_opened');
}
function period_is_writable_state(string $state): bool { return in_array($state,['open','reopened'],true); }
function assert_transaction_period_writable(string $scope, ?string $schoolId, string $day): void {
    $month=substr($day,0,7);$state=transaction_period_state($month,$scope,$schoolId);
    if(period_is_writable_state($state))return;
    $label=period_target_label($scope,$schoolId);$monthLabel=period_month_label($month);
    if($state==='closed') throw new RuntimeException($monthLabel.' is closed and locked for '.$label.'. The Division must reopen this transaction period before records dated in this month can be changed.');
    throw new RuntimeException($monthLabel.' has not been opened for '.$label.'. The Division must open this transaction period before records dated in this month can be created or changed.');
}
function assert_transaction_periods_for_change(?array $existing, string $scope, ?string $schoolId, string $newDay): void {
    if($existing){$oldScope=transaction_scope_from_record($existing);$oldSchool=$oldScope==='school'?(string)$existing['school_id']:null;$oldDay=(string)$existing['date'];assert_transaction_period_writable($oldScope,$oldSchool,$oldDay);}
    assert_transaction_period_writable($scope,$schoolId,$newDay);
}
/**
 * Serialize month open/close actions with every transaction mutation that affects
 * the same month+origin. This supplements the existing transaction-period DB triggers.
 * Advisory locks live for one PHP request; they protect auxiliary pending-edit and
 * cancellation rows, which the transaction INSERT/UPDATE triggers cannot see.
 */
function period_mutation_locks(array $actor, array $body, string $action): void {
    $periodAction=$action==='setTransactionPeriods';
    $transactions=['saveTransaction','saveDraft','submitTransaction','discardSubmittedDraft',
        'cancelPendingEdit','returnForCorrection','check','cancelDraft','deleteDraft',
        'requestCancellation','requestDeletion','approveCancellation','approveDeletion',
        'rejectCancellation','rejectDeletion','void'];
    if(!$periodAction&&!in_array($action,$transactions,true))return;
    $specs=[];$existing=null;
    if($periodAction){
        require_admin($actor);
        $month=valid_period_month($body['month']??null);
        $targets=$body['targets']??[];
        if(!is_array($targets)||count($targets)>1000)throw new RuntimeException('Invalid transaction period selection.');
        foreach($targets as $key){
            if(!is_string($key))throw new RuntimeException('Invalid transaction period target.');
            $specs[$month.'|'.$key]=true;
        }
    }else{
        $id=trim((string)($body['id']??''));
        if($id!=='')$existing=own_transaction($actor,$id); // Reuse the existing school/role access check.
        $scope=$existing?transaction_scope_from_record($existing):($actor['role']==='school_user'?'school':'division');
        $schoolId=$scope==='school'?(string)($existing['school_id']??$actor['school_id']??''):null;
        $key=period_target_key($scope,$schoolId);
        $dates=[];
        if($existing)$dates[]=(string)$existing['date'];
        if(isset($body['date']))$dates[]=(string)$body['date'];
        if($existing){
            $pending=pending_edit_row((string)$existing['id']);
            if($pending)$dates[]=(string)$pending['date'];
            if($action==='discardSubmittedDraft'){
                $st=db()->prepare('SELECT date FROM transaction_submitted_backups WHERE transaction_id=?');
                $st->execute([$existing['id']]);$prior=$st->fetchColumn();if($prior)$dates[]=(string)$prior;
            }
        }
        foreach($dates as $date){
            if(preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])-[0-3]\d$/',$date))$specs[substr($date,0,7).'|'.$key]=true;
        }
    }
    if(!$specs)return; // Missing dates are rejected by existing route validation.
    $names=array_keys($specs);sort($names,SORT_STRING);$acquired=[];$pdo=db();
    try{
        $q=$pdo->prepare('SELECT GET_LOCK(?,15)');
        foreach($names as $spec){
            $name='nonius_period_'.substr(hash('sha256',$spec),0,40);
            $q->execute([$name]);
            if((int)$q->fetchColumn()!==1)throw new RuntimeException('The selected transaction month is busy. Retry after the current change finishes.');
            $acquired[]=$name;
        }
        if($existing){
            $latest=own_transaction($actor,(string)$existing['id']);
            if((int)$latest['version']!==(int)$existing['version']||
                (string)$latest['date']!==(string)$existing['date']||
                transaction_scope_from_record($latest)!==transaction_scope_from_record($existing))
                throw new RuntimeException('Transaction changed while awaiting the monthly period lock. Reload and try again.');
        }
    }catch(Throwable $e){
        $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');
        foreach(array_reverse($acquired) as $name){try{$release->execute([$name]);$release->fetchColumn();}catch(Throwable){}}
        throw $e;
    }
    // The current database connection is nonpersistent. Explicit release also
    // covers code paths that terminate via respond(...)->exit.
    register_shutdown_function(static function() use($pdo,$acquired): void {
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');
            foreach(array_reverse($acquired) as $name){$release->execute([$name]);$release->fetchColumn();}
        }catch(Throwable $e){error_log('NonIUS: could not release transaction-period advisory lock: '.$e->getMessage());}
    });
}

function period_target_from_key(string $key): array {
    if($key==='division') return ['target_key'=>'division','transaction_scope'=>'division','school_id'=>null,'name'=>'Division Office'];
    if(!str_starts_with($key,'school:'))throw new RuntimeException('Invalid transaction-period target.');
    $schoolId=substr($key,7);$school=master_row('schools',$schoolId);
    if(!$school||!(int)($school['active']??0))throw new RuntimeException('One selected school is no longer active. Reload the period list and try again.');
    return ['target_key'=>$key,'transaction_scope'=>'school','school_id'=>$schoolId,'name'=>(string)$school['name']];
}
function period_blockers(string $month, string $scope, ?string $schoolId): array {
    [$start,$next]=period_month_bounds($month);
    $target=$scope==='school' ? "t.transaction_scope='school' AND t.school_id=?" : "t.transaction_scope='division' AND t.school_id IS NULL";
    $origin=$scope==='school'?[$schoolId]:[];
    $categories=[
        'transactions'=>["SELECT t.reference FROM transactions t WHERE {$target} AND t.date>=? AND t.date<? AND t.status IN ('draft','submitted','returned')",[$start,$next]],
        'pending_cancellations'=>["SELECT t.reference FROM transaction_deletion_requests d JOIN transactions t ON t.id=d.transaction_id WHERE d.status='pending' AND {$target} AND t.date>=? AND t.date<?",[$start,$next]],
    ];
    if($scope==='school'){
        $categories['pending_edits']=["SELECT t.reference FROM transaction_pending_edits p JOIN transactions t ON t.id=p.transaction_id WHERE t.transaction_scope='school' AND t.school_id=? AND ((t.date>=? AND t.date<?) OR (p.date>=? AND p.date<?))",[$start,$next,$start,$next]];
        $categories['revision_backups']=["SELECT t.reference FROM transaction_submitted_backups b JOIN transactions t ON t.id=b.transaction_id WHERE t.transaction_scope='school' AND t.school_id=? AND b.date>=? AND b.date<?",[$start,$next]];
    }
    $out=[];
    foreach($categories as $key=>[$sql,$rangeArgs]){
        $args=array_merge($origin,$rangeArgs);
        $cnt=db()->prepare('SELECT COUNT(*) FROM ('.$sql.') blockers');$cnt->execute($args);
        $sample=db()->prepare($sql.' LIMIT 6');$sample->execute($args);
        $out[$key]=['count'=>(int)$cnt->fetchColumn(),'samples'=>$sample->fetchAll()];
    }
    return $out;
}
function assert_period_can_close(string $month, string $scope, ?string $schoolId): void {
    $b=period_blockers($month,$scope,$schoolId);$parts=[];$refs=[];
    $labels=['transactions'=>'unfinished transaction(s)','pending_edits'=>'pending Checked edit(s)','revision_backups'=>'unresolved Submitted revision Draft(s)','pending_cancellations'=>'pending cancellation request(s)'];
    foreach($labels as $key=>$label){
        if(($b[$key]['count']??0)>0){
            $parts[]=$b[$key]['count'].' '.$label;
            foreach($b[$key]['samples'] as $row)$refs[]=(string)($row['reference']??'');
        }
    }
    if(!$parts)return;
    $refs=array_values(array_unique(array_filter($refs)));
    $refText=$refs?' Example references: '.implode(', ',array_slice($refs,0,6)).'.':'';
    throw new RuntimeException(period_month_label($month).' cannot be closed for '.period_target_label($scope,$schoolId).' because it still has '.implode(', ',$parts).'. Resolve them first.'.$refText);
}

function period_overview_response(array $actor, ?string $month=null): array {
    $month=valid_period_month($month??current_manila_month());
    if(!is_admin_or_owner($actor)){$schoolId=(string)$actor['school_id'];$row=transaction_period_row($month,'school',$schoolId);return ['month'=>$month,'label'=>period_month_label($month),'target_key'=>period_target_key('school',$schoolId),'target_name'=>period_target_label('school',$schoolId),'state'=>(string)($row['state']??'not_opened'),'last_action_at'=>$row['last_action_at']??null];}
    $schools=array_values(array_filter(master_all('schools'),fn($s)=>(int)($s['active']??0)===1));$total=count($schools)+1;$activeSchoolIds=array_fill_keys(array_map(fn($s)=>(string)$s['id'],$schools),true);$stmt=db()->prepare("SELECT target_key,school_id,state FROM transaction_periods WHERE period_month=?");$stmt->execute([$month]);$counts=['open'=>0,'reopened'=>0,'closed'=>0];foreach($stmt->fetchAll() as $r){$key=(string)$r['target_key'];$sid=(string)($r['school_id']??'');if($key!=='division'&&!isset($activeSchoolIds[$sid]))continue;$state=(string)$r['state'];if(isset($counts[$state]))$counts[$state]++;}$configured=array_sum($counts);$divisionState=transaction_period_state($month,'division',null);return ['month'=>$month,'label'=>period_month_label($month),'total_targets'=>$total,'open_targets'=>$counts['open']+$counts['reopened'],'closed_targets'=>$counts['closed'],'not_opened_targets'=>max(0,$total-$configured),'division_state'=>$divisionState];
}
function transaction_periods_response(array $actor, string $month): array {
    require_admin($actor);$month=valid_period_month($month);$schools=array_values(array_filter(master_all('schools'),fn($s)=>(int)($s['active']??0)===1));
    $stmt=db()->prepare("SELECT p.*,COALESCE(NULLIF(m.username,''),NULLIF(m.email,''),p.last_action_by) last_action_by_name FROM transaction_periods p LEFT JOIN members m ON m.id=p.last_action_by WHERE p.period_month=?");$stmt->execute([$month]);$rows=[];foreach($stmt->fetchAll() as $r){$r['version']=(int)$r['version'];$rows[(string)$r['target_key']]=$r;}
    $targets=[];$division=$rows['division']??null;$targets[]=['target_key'=>'division','transaction_scope'=>'division','school_id'=>null,'name'=>'Division Office','state'=>(string)($division['state']??'not_opened'),'last_action_at'=>$division['last_action_at']??null,'last_action_by_name'=>$division['last_action_by_name']??null,'version'=>(int)($division['version']??0)];
    foreach($schools as $school){$key='school:'.$school['id'];$r=$rows[$key]??null;$targets[]=['target_key'=>$key,'transaction_scope'=>'school','school_id'=>$school['id'],'name'=>$school['name'],'state'=>(string)($r['state']??'not_opened'),'last_action_at'=>$r['last_action_at']??null,'last_action_by_name'=>$r['last_action_by_name']??null,'version'=>(int)($r['version']??0)];}
    $hist=db()->prepare("SELECT h.*,COALESCE(NULLIF(m.username,''),NULLIF(m.email,''),h.actor) actor_name FROM transaction_period_history h LEFT JOIN members m ON m.id=h.actor WHERE h.period_month=? ORDER BY h.at DESC LIMIT 150");$hist->execute([$month]);
    return ['month'=>$month,'label'=>period_month_label($month),'targets'=>$targets,'history'=>$hist->fetchAll(),'overview'=>period_overview_response($actor,$month)];
}


function map_by_id(array $rows): array {
    $out=[]; foreach($rows as $r) if(isset($r['id'])) $out[(string)$r['id']]=$r; return $out;
}
function transaction_master_maps(): array {
    return [
        'schools'=>map_by_id(master_all('schools')),
        'suppliers'=>map_by_id(master_all('suppliers')),
        'atcs'=>map_by_id(master_all('atcs')),
        'source_funds'=>map_by_id(master_all('source_funds')),
        'division'=>master_row('division','division'),
    ];
}
function transaction_origin_name(array $t, array $maps): string {
    $scope=transaction_scope_from_record($t);
    $snapshot=is_array($t['snapshot']??null)?$t['snapshot']:[];
    if($scope==='division') return (string)($snapshot['division']['name']??$maps['division']['name']??'Division Office');
    return (string)($snapshot['school']['name']??($maps['schools'][(string)($t['school_id']??'')]['name']??'School'));
}
/** Build the same role/status/period predicate for full scans and SQL-bounded pages. */
function accessible_transaction_query_parts(array $actor, ?string $statusSql=null, ?string $month=null, ?string $origin=null): array {
    $where=[];$args=[];
    if(!is_admin_or_owner($actor)){$where[]="transaction_scope='school'";$where[]='school_id=?';$args[]=$actor['school_id'];}
    elseif($origin==='division'){$where[]="transaction_scope='division'";}
    elseif($origin==='__all_schools__'){$where[]="transaction_scope='school'";}
    elseif($origin!==null&&$origin!=='all'){$where[]="transaction_scope='school'";$where[]='school_id=?';$args[]=$origin;}
    if($statusSql)$where[]=$statusSql; // The only callers construct this from fixed server constants.
    if($month!==null&&preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/',$month)){
        [$from,$to]=period_month_bounds($month);$where[]='date>=?';$where[]='date<?';$args[]=$from;$args[]=$to;
    }
    return [$where?' WHERE '.implode(' AND ',$where):'', $args];
}
function accessible_transaction_statement(array $actor, ?string $statusSql=null, ?string $month=null, ?string $origin=null): PDOStatement {
    [$where,$args]=accessible_transaction_query_parts($actor,$statusSql,$month,$origin);
    $stmt=db()->prepare('SELECT * FROM transactions'.$where.' ORDER BY created_at DESC,id DESC');
    $stmt->execute($args);return $stmt;
}
function accessible_transaction_rows(array $actor, ?string $statusSql=null, ?string $month=null, ?string $origin=null): array {
    return accessible_transaction_statement($actor,$statusSql,$month,$origin)->fetchAll();
}
/** SQL-side register search; preserve the original ordered search blob (including
 * snapshot names, current-master fallbacks, pending-edit names, reference and ID).
 * A substring search may still scan indexes; it no longer hydrates the full
 * matching register or all approval records in PHP. */
function register_search_condition(string $search): array {
    $safeSnapshot="IF(JSON_VALID(transactions.snapshot),transactions.snapshot,'{}')";
    $sname="COALESCE(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'$.supplier.name')),(SELECT s.name FROM suppliers s WHERE s.id=transactions.supplier_id),'')";
    $oname="CASE WHEN transactions.transaction_scope='division' THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'$.division.name')),(SELECT d.name FROM division d WHERE d.id='division'),'Division Office') ELSE COALESCE(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'$.school.name')),(SELECT sc.name FROM schools sc WHERE sc.id=transactions.school_id),'School') END";
    $fname="COALESCE(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'$.source_fund.name')),(SELECT f.name FROM source_funds f WHERE f.id=transactions.source_fund_id),'')";
    $psname="COALESCE((SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(p.snapshot),p.snapshot,'{}'),'$.supplier.name')),(SELECT s.name FROM suppliers s WHERE s.id=p.supplier_id),'') FROM transaction_pending_edits p WHERE p.transaction_id=transactions.id),'')";
    $pfname="COALESCE((SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(p.snapshot),p.snapshot,'{}'),'$.source_fund.name')),(SELECT f.name FROM source_funds f WHERE f.id=p.source_fund_id),'') FROM transaction_pending_edits p WHERE p.transaction_id=transactions.id),'')";
    $expression="LOWER(TRIM(CONCAT_WS(' ',$sname,$oname,$fname,$psname,$pfname,COALESCE(transactions.reference,''),transactions.id))) COLLATE utf8mb4_bin";
    // Escape SQL wildcards to preserve literal substring search semantics.
    $escaped=str_replace(['!','%','_'],['!!','!%','!_'],$search);
    return [$expression." LIKE ? ESCAPE '!'",['%'.$escaped.'%']];
}
function transaction_register_query_parts(array $actor, ?string $statusSql, ?string $month, string $search=''): array {
    [$where,$args]=accessible_transaction_query_parts($actor,$statusSql,$month);
    if($search!==''){
        [$condition,$values]=register_search_condition($search);
        $where.=($where?' AND ':' WHERE ').$condition;
        array_push($args,...$values);
    }
    return [$where,$args];
}
/** Load only the requested visible register page; never hydrate off-page rows. */
function accessible_transaction_page(array $actor, ?string $statusSql, ?string $month, int $perPage, int $offset, string $search=''): array {
    [$where,$args]=transaction_register_query_parts($actor,$statusSql,$month,$search);
    $stmt=db()->prepare('SELECT * FROM transactions'.$where.' ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?');
    $i=1;foreach($args as $arg)$stmt->bindValue($i++,$arg,PDO::PARAM_STR);
    $stmt->bindValue($i++,$perPage,PDO::PARAM_INT);
    $stmt->bindValue($i,$offset,PDO::PARAM_INT);
    $stmt->execute();return $stmt->fetchAll();
}
/** Count and sum exactly as the existing register, without building every client row. */
/** SQL-aggregate persisted amount snapshots. Only unsnapshotted editable rows need
 * the original master-data calculation path. Preserves gross/count for ALL rows
 * matching the established role/status/month predicates, including void rows. */
function transaction_register_summary(array $actor, ?string $statusSql, ?string $month, array $maps, string $search=''): array {
    [$where,$args]=transaction_register_query_parts($actor,$statusSql,$month,$search);
    $safeSnapshot="IF(JSON_VALID(snapshot),snapshot,'{}')";
    $amountType="JSON_TYPE(JSON_EXTRACT($safeSnapshot,'$.amounts'))";
    $columns=[];
    foreach(['business','ewt','total'] as $key){
        $path='$.amounts.'.$key;
        $columns[]="COALESCE(SUM(CASE WHEN $amountType='OBJECT' THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'$path')) AS DECIMAL(30,0)),0) ELSE 0 END),0) AS $key";
    }
    $sql='SELECT COUNT(*) AS row_count,COALESCE(SUM(gross),0) AS gross,'.implode(',',$columns).' FROM transactions'.$where;
    $stmt=db()->prepare($sql);$stmt->execute($args);$saved=$stmt->fetch()?:[];
    $summary=['gross'=>(int)($saved['gross']??0),'business'=>(int)($saved['business']??0),'ewt'=>(int)($saved['ewt']??0),'total'=>(int)($saved['total']??0)];
    // Preserve the approved preview calculation for Draft/Submitted/Returned
    // records without a usable stored amount snapshot. The normal Checked and
    // Submitted paths are handled completely by the aggregate query above.
    $fallbackWhere=$where.($where?' AND ':' WHERE ')."status IN ('draft','submitted','returned') AND ($amountType IS NULL OR $amountType<>'OBJECT')";
    $preview=db()->prepare('SELECT transaction_scope,school_id,supplier_id,transaction_type,source_fund_id,date,gross,status,snapshot FROM transactions'.$fallbackWhere);
    $preview->execute($args);
    while($raw=$preview->fetch()){
        $amounts=fast_display_amounts($raw,$maps);
        if($amounts)foreach(['business','ewt','total'] as $key)$summary[$key]+=(int)($amounts[$key]??0);
    }
    return [(int)($saved['row_count']??0),$summary];
}
function accessible_transaction_aux_maps(array $actor, bool $includeBackups=true, ?array $transactionIds=null): array {
    if($transactionIds===[])return [[],[],[]];
    if($transactionIds!==null){
        // IDs always originate from rows already authorized by the transaction query.
        $ids=array_values(array_unique(array_map('strval',$transactionIds)));
        if(count($ids)>100)throw new RuntimeException('Invalid transaction page size.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        if(is_admin_or_owner($actor)){
            $pstmt=db()->prepare("SELECT * FROM transaction_pending_edits WHERE transaction_id IN ($marks)");$pstmt->execute($ids);
            $bstmt=null;if($includeBackups){$bstmt=db()->prepare("SELECT * FROM transaction_submitted_backups WHERE transaction_id IN ($marks)");$bstmt->execute($ids);}
            $dstmt=db()->prepare("SELECT * FROM transaction_deletion_requests WHERE status='pending' AND transaction_id IN ($marks)");$dstmt->execute($ids);
        }else{
            $school=(string)$actor['school_id'];
            $pstmt=db()->prepare("SELECT p.* FROM transaction_pending_edits p JOIN transactions t ON t.id=p.transaction_id WHERE t.transaction_scope='school' AND t.school_id=? AND p.transaction_id IN ($marks)");$pstmt->execute(array_merge([$school],$ids));
            $bstmt=null;if($includeBackups){$bstmt=db()->prepare("SELECT b.* FROM transaction_submitted_backups b JOIN transactions t ON t.id=b.transaction_id WHERE t.transaction_scope='school' AND t.school_id=? AND b.transaction_id IN ($marks)");$bstmt->execute(array_merge([$school],$ids));}
            $dstmt=db()->prepare("SELECT d.* FROM transaction_deletion_requests d JOIN transactions t ON t.id=d.transaction_id WHERE d.status='pending' AND t.transaction_scope='school' AND t.school_id=? AND d.transaction_id IN ($marks)");$dstmt->execute(array_merge([$school],$ids));
        }
        return decode_transaction_aux_maps($pstmt,$bstmt,$dstmt);
    }
    if(is_admin_or_owner($actor)){
        $pstmt=db()->query('SELECT p.* FROM transaction_pending_edits p');
        $bstmt=$includeBackups?db()->query('SELECT b.* FROM transaction_submitted_backups b'):null;
        $dstmt=db()->query("SELECT d.* FROM transaction_deletion_requests d WHERE d.status='pending'");
    }else{
        $pstmt=db()->prepare("SELECT p.* FROM transaction_pending_edits p JOIN transactions t ON t.id=p.transaction_id WHERE t.transaction_scope='school' AND t.school_id=?");$pstmt->execute([$actor['school_id']]);
        $bstmt=null;if($includeBackups){$bstmt=db()->prepare("SELECT b.* FROM transaction_submitted_backups b JOIN transactions t ON t.id=b.transaction_id WHERE t.transaction_scope='school' AND t.school_id=?");$bstmt->execute([$actor['school_id']]);}
        $dstmt=db()->prepare("SELECT d.* FROM transaction_deletion_requests d JOIN transactions t ON t.id=d.transaction_id WHERE d.status='pending' AND t.transaction_scope='school' AND t.school_id=?");$dstmt->execute([$actor['school_id']]);
    }
    return decode_transaction_aux_maps($pstmt,$bstmt,$dstmt);
}
function decode_transaction_aux_maps(PDOStatement $pstmt, ?PDOStatement $bstmt, PDOStatement $dstmt): array {
    $pending=[];foreach($pstmt->fetchAll() as $r){$r['gross']=(int)$r['gross'];$r['version']=(int)$r['version'];if(array_key_exists('snapshot',$r))$r['snapshot']=$r['snapshot']?json_decode_assoc((string)$r['snapshot']):null;$pending[$r['transaction_id']]=$r;}
    $backups=[];if($bstmt)foreach($bstmt->fetchAll() as $r){$r['gross']=(int)$r['gross'];$r['version']=(int)$r['version'];if(array_key_exists('snapshot',$r))$r['snapshot']=$r['snapshot']?json_decode_assoc((string)$r['snapshot']):null;$backups[$r['transaction_id']]=$r;}
    $deletions=[];foreach($dstmt->fetchAll() as $r)$deletions[(string)$r['transaction_id']]=$r;
    return [$pending,$backups,$deletions];
}
function fast_display_amounts(array $r, array $maps): ?array {
    $snapshot=is_array($r['snapshot']??null)?$r['snapshot']:(($r['snapshot']??null)?json_decode_assoc((string)$r['snapshot']):null);
    if(is_array($snapshot['amounts']??null)) return $snapshot['amounts'];
    if(!in_array((string)($r['status']??''),['draft','submitted','returned'],true)) return null;
    $scope=transaction_scope_from_record($r);
    $supplier=$maps['suppliers'][(string)($r['supplier_id']??'')]??null;
    $fund=$maps['source_funds'][(string)($r['source_fund_id']??'')]??null;
    $division=$maps['division']??null;
    if(!$supplier || !(int)($supplier['active']??0) || !$fund || !(int)($fund['active']??0) || !$division) return null;
    if($scope==='school'){
        $school=$maps['schools'][(string)($r['school_id']??'')]??null;
        if(!$school || !(int)($school['active']??0) || empty($school['representative'])||empty($school['position'])) return null;
    }elseif(empty($division['representative'])||empty($division['designation'])||empty($division['representative_tin'])) return null;
    try{$profile=resolve_supplier_transaction_profile($supplier,transaction_type_from_record($r,$supplier),false);}catch(Throwable){return null;}
    $supplierForTax=$supplier;$supplierForTax['transaction_type']=$profile['transaction_type'];$supplierForTax['business_atc']=$profile['business_atc'];$supplierForTax['ewt_atc']=$profile['ewt_atc'];
    $business=$maps['atcs'][(string)$profile['business_atc']]??null;
    $ewt=$maps['atcs'][(string)$profile['ewt_atc']]??null;
    foreach([[$business,'VAT'],[$ewt,'EWT']] as [$atc,$kind]){
        if(!$atc || !(int)($atc['active']??0) || ($atc['tax_type']??'')!==$kind) return null;
        if(!atc_applies_to_supplier($atc,$supplierForTax)) return null;
        $date=(string)($r['date']??'');
        if(($atc['effective_from']??'9999-12-31')>$date || (!empty($atc['effective_to']) && $atc['effective_to']<$date)) return null;
    }
    return calculate_tax((int)($r['gross']??0),($supplier['vat_status']??'')==='VAT Registered',(float)$business['rate'],(float)$ewt['rate']);
}

function client_transaction_row(array $r, array $pending, array $backups, array $maps, array $deletions=[]): array {
    $r['gross']=(int)$r['gross'];$r['version']=(int)$r['version'];
    $r['snapshot']=$r['snapshot']?json_decode_assoc((string)$r['snapshot']):null;
    $r['pending_edit']=$pending[$r['id']]??null;$r['submitted_backup']=$backups[$r['id']]??null;$r['deletion_request']=$deletions[$r['id']]??null;
    $r['display_amounts']=fast_display_amounts($r,$maps);
    return $r;
}
function transaction_search_blob(array $t, array $maps): string {
    $snapshot=$t['snapshot']??null;$pending=$t['pending_edit']??null;
    $supplier=$snapshot['supplier']['name']??($maps['suppliers'][$t['supplier_id']]['name']??'');
    $origin=transaction_origin_name($t,$maps);
    $fund=$snapshot['source_fund']['name']??($maps['source_funds'][$t['source_fund_id']]['name']??'');
    $ps='';$pf='';
    if($pending){$ps=$pending['snapshot']['supplier']['name']??($maps['suppliers'][$pending['supplier_id']]['name']??'');$pf=$pending['snapshot']['source_fund']['name']??($maps['source_funds'][$pending['source_fund_id']]['name']??'');}
    return mb_strtolower(trim($supplier.' '.$origin.' '.$fund.' '.$ps.' '.$pf.' '.($t['reference']??'').' '.($t['id']??'')));
}

function duplicate_transaction_candidates(string $scope, ?string $schoolId, string $supplierId, string $day, int $gross, ?string $excludeId=null): array {
    if($scope==='division'){
        $sql="SELECT id,reference,transaction_scope,school_id,supplier_id,transaction_type,source_fund_id,date,gross,status,snapshot,created_at,submitted_at,finalized_at FROM transactions WHERE transaction_scope='division' AND school_id IS NULL AND supplier_id=? AND date=? AND gross=? AND status NOT IN ('void','deleted')";
        $args=[$supplierId,$day,$gross];
    }else{
        $sql="SELECT id,reference,transaction_scope,school_id,supplier_id,transaction_type,source_fund_id,date,gross,status,snapshot,created_at,submitted_at,finalized_at FROM transactions WHERE transaction_scope='school' AND school_id=? AND supplier_id=? AND date=? AND gross=? AND status NOT IN ('void','deleted')";
        $args=[$schoolId,$supplierId,$day,$gross];
    }
    if($excludeId!==null && $excludeId!==''){$sql.=' AND id<>?';$args[]=$excludeId;}
    $sql.=' ORDER BY COALESCE(submitted_at,finalized_at,created_at) DESC LIMIT 8';
    $stmt=db()->prepare($sql);$stmt->execute($args);
    $funds=map_by_id(master_all('source_funds'));$rows=[];
    foreach($stmt->fetchAll() as $r){
        $snapshot=$r['snapshot']?json_decode_assoc((string)$r['snapshot']):null;
        $fundName=$snapshot['source_fund']['name']??($funds[(string)$r['source_fund_id']]['name']??(string)$r['source_fund_id']);
        $rows[]=[
            'id'=>(string)$r['id'],
            'reference'=>(string)($r['reference']??substr((string)$r['id'],0,12).'…'),
            'status'=>(string)$r['status'],
            'date'=>(string)$r['date'],
            'gross'=>(int)$r['gross'],
            'source_fund'=>(string)$fundName,
            'transaction_type'=>transaction_type_from_record($r),
        ];
    }
    return $rows;
}
function duplicate_warning_payload(array $matches): array {
    return [
        'ok'=>true,
        'duplicate_warning'=>true,
        'duplicates'=>$matches,
        'message'=>'A transaction with the same origin, supplier, date and gross amount already exists. This may still be a valid separate transaction; review the matching records before continuing.',
    ];
}

function transaction_list_response(array $actor): array {
    $maps=transaction_master_maps();
    $q=mb_strtolower(trim((string)($_GET['q']??'')));$status=(string)($_GET['status']??'all');$month=trim((string)($_GET['month']??''));
    $page=max(1,(int)($_GET['page']??1));$perPage=(int)($_GET['per_page']??50);if(!in_array($perPage,[25,50,100],true))$perPage=50;
    $sqlStatus=$status==='all'?"status<>'deleted'":($status==='pending'?"id IN (SELECT transaction_id FROM transaction_pending_edits)":(in_array($status,['draft','submitted','returned','checked','void','deleted'],true)?"status='".$status."'":"status='!invalid!'"));
    // Both ordinary register browsing and free-text search are paginated in SQL.
    // Never build the complete matching transaction array in PHP.
    if($month!==''&&!preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/',$month)) {
        return ['rows'=>[],'summary'=>['gross'=>0,'business'=>0,'ewt'=>0,'total'=>0], 'pagination'=>['page'=>1,'per_page'=>$perPage,'total'=>0,'pages'=>1]];
    }
    [$total,$summary]=transaction_register_summary($actor,$sqlStatus,$month,$maps,$q);
    $pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;
    $rawRows=accessible_transaction_page($actor,$sqlStatus,$month,$perPage,($page-1)*$perPage,$q);
    [$pending,$backups,$deletions]=accessible_transaction_aux_maps($actor,true,array_column($rawRows,'id'));
    $rows=[];foreach($rawRows as $raw)$rows[]=client_transaction_row($raw,$pending,$backups,$maps,$deletions);
    return ['rows'=>$rows,'summary'=>$summary,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages]];
}
/** ATC labels shown in Reports use the frozen tax codes for submitted/checked
 * transactions; only editable Draft/Returned records use today's supplier profile. */
function report_atc_codes(array $t, array $maps): array {
    $snapshot=$t['snapshot']??null;
    if(is_array($snapshot)){
        $vat=trim((string)($snapshot['business']['name']??''));
        $ewt=trim((string)($snapshot['ewt']['name']??''));
        return ['VAT'=>$vat,'EWT'=>$ewt];
    }
    $result=['VAT'=>'','EWT'=>''];
    if(!in_array((string)($t['status']??''),['draft','returned'],true))return $result;
    $supplier=$maps['suppliers'][(string)($t['supplier_id']??'')]??null;
    if(!$supplier)return $result;
    try{$profile=resolve_supplier_transaction_profile($supplier,transaction_type_from_record($t,$supplier),false);}
    catch(Throwable){return $result;}
    foreach(['VAT'=>'business_atc','EWT'=>'ewt_atc'] as $type=>$field){
        $atc=$maps['atcs'][(string)($profile[$field]??'')]??null;
        if($atc)$result[$type]=trim((string)($atc['name']??''));
    }
    return $result;
}

/** Reduce candidate rows for ATC/Fund Reports before fetching snapshots and
 * approval records. These SQL tests are intentionally BROADER than the final
 * PHP rules: source_fund_id covers absent/null frozen IDs, and LOCATE covers
 * snapshot names/IDs whose whitespace PHP trim() would remove. A draft or
 * returned transaction with no snapshot must still reach the current supplier
 * tax-profile resolver. PHP remains authoritative for every exact match.
 * Parameterized clauses preserve school/Division access and literal ATC input. */
function report_candidate_sql_parts(array $actor, string $month, string $school, string $supplier, string $fund, string $status, string $atcType, string $atcCode): array {
    [$where,$args]=report_simple_sql_parts($actor,$month,$school,$supplier,$status);
    $safeSnapshot="IF(JSON_VALID(snapshot),snapshot,'{}')";
    if($fund!=='all'){
        $where.=" AND (source_fund_id=? OR LOCATE(?,COALESCE(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'$.source_fund.id')),''))>0)";
        $args[]=$fund;$args[]=$fund;
    }
    if($atcType!==''){
        // Path is chosen from fixed server-validated VAT/EWT constants.
        $path=$atcType==='VAT'?'$.business.name':'$.ewt.name';
        $where.=" AND (LOCATE(?,COALESCE(JSON_UNQUOTE(JSON_EXTRACT($safeSnapshot,'".$path."')),''))>0 OR (status IN ('draft','returned') AND (snapshot IS NULL OR snapshot='' OR snapshot='0')))";
        $args[]=$atcCode;
    }
    return [$where,$args];
}

/** Iterate report rows in bounded batches, preserving all existing saved-snapshot,
 * origin, ATC and approval filters. Keyset order avoids deep OFFSET scans. */
function report_filtered_rows(array $actor, array $maps, string $month, string $school, string $supplier, string $fund, string $status, string $atcType, string $atcCode): Generator {
    // Candidate SQL only prunes records proven not to match. The original
    // PHP snapshot/ATC/fund check below remains the final filter for both the
    // on-screen report and streamed CSV, including unsnapshotted Draft/Returned.
    [$where,$args]=report_candidate_sql_parts($actor,$month,$school,$supplier,$fund,$status,$atcType,$atcCode);
    $cursor=null;
    while(true){
        $paging=$cursor!==null?($where?' AND ':' WHERE ').'(created_at<? OR (created_at=? AND id<?))':'';
        $sql='SELECT * FROM transactions'.$where.$paging.' ORDER BY created_at DESC,id DESC LIMIT 100';
        $stmt=db()->prepare($sql);$stmt->execute($cursor!==null?array_merge($args,[$cursor['created_at'],$cursor['created_at'],$cursor['id']]):$args);
        $chunk=$stmt->fetchAll();if(!$chunk)break;
        $last=end($chunk);$cursor=['created_at'=>$last['created_at'],'id'=>$last['id']];
        [$pending,$backups,$deletions]=accessible_transaction_aux_maps($actor,false,array_column($chunk,'id'));
        foreach($chunk as $raw){
            $t=client_transaction_row($raw,$pending,$backups,$maps,$deletions);
            $officialFund=$t['snapshot']['source_fund']['id']??$t['source_fund_id'];
            if(($t['status']??'')==='deleted')continue;
            if($month!==''&&!str_starts_with((string)$t['date'],$month))continue;
            if(is_admin_or_owner($actor)&&$school!=='all'){
                if($school==='division'){if(transaction_scope_from_record($t)!=='division')continue;}
                elseif($school==='__all_schools__'){if(transaction_scope_from_record($t)!=='school')continue;}
                elseif(transaction_scope_from_record($t)!=='school'||(string)$t['school_id']!==$school)continue;
            }
            if($supplier!=='all'&&$t['supplier_id']!==$supplier)continue;
            if($fund!=='all'&&$officialFund!==$fund)continue;
            if($status!=='all'&&!($status==='pending'?!empty($t['pending_edit']):$t['status']===$status))continue;
            $t['report_atcs']=report_atc_codes($t,$maps);
            if($atcType!==''&&$t['report_atcs'][$atcType]!==$atcCode)continue;
            yield $t;
        }
        if(count($chunk)<100)break;
    }
}
function report_filter_values(): array {
    $month=trim((string)($_GET['month']??''));$school=(string)($_GET['school']??'all');$supplier=(string)($_GET['supplier']??'all');$fund=(string)($_GET['fund']??'all');$status=(string)($_GET['status']??'all');
    $atc=trim((string)($_GET['atc']??'all'));$atcType='';$atcCode='';
    if($atc!=='all'){
        $parts=explode('|',$atc,2);
        if(count($parts)!==2||!in_array($parts[0],['VAT','EWT'],true)||trim($parts[1])===''||strlen($parts[1])>100)throw new RuntimeException('Invalid ATC report filter.');
        [$atcType,$atcCode]=$parts;
    }
    if($month!==''&&!preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/',$month))throw new RuntimeException('Invalid report month.');
    return compact('month','school','supplier','fund','status','atcType','atcCode');
}
/** SQL shortcut for the unfiltered-by-ATC/fund report. Its predicates match
 * report_filtered_rows(), including the intentionally excluded cancelled
 * records, and never remove school-role restrictions. The existing generator
 * remains authoritative for snapshot Source-of-Fund and ATC filters. */
function report_simple_sql_parts(array $actor, string $month, string $school, string $supplier, string $status): array {
    $sqlStatus=$status==='all'?"status<>'deleted'":($status==='pending'?"id IN (SELECT transaction_id FROM transaction_pending_edits)":(in_array($status,['draft','submitted','returned','checked','void','deleted'],true)?"status='".$status."'":"status='!invalid!'"));
    [$where,$args]=accessible_transaction_query_parts($actor,$sqlStatus,$month,is_admin_or_owner($actor)?$school:null);
    // Even an explicit 'deleted' filter produces no records in the existing
    // report generator. Cancelled records stay on their separate register view.
    $where.=($where?' AND ':' WHERE ')."status<>'deleted'";
    if($supplier!=='all'){$where.=' AND supplier_id=?';$args[]=$supplier;}
    return [$where,$args];
}
/** Checked-only sums of the frozen amounts (NOT live ATC rates or gross field).
 * Keep the same empty/missing snapshot behavior as report_response(). */
function report_simple_sql_summary(string $where, array $args): array {
    $safe="IF(JSON_VALID(snapshot),snapshot,'{}')";
    $type="JSON_TYPE(JSON_EXTRACT($safe,'$.amounts'))";
    $columns=[];
    foreach(['gross','base','business','ewt','total'] as $key){
        $path='$.amounts.'.$key;
        $columns[]="COALESCE(SUM(CASE WHEN status='checked' AND $type='OBJECT' THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT($safe,'$path')) AS DECIMAL(30,0)),0) ELSE 0 END),0) AS `$key`";
    }
    $stmt=db()->prepare('SELECT COUNT(*) AS row_count,COALESCE(SUM(CASE WHEN status=\'checked\' THEN 1 ELSE 0 END),0) AS checked_count,'.implode(',',$columns).' FROM transactions'.$where);
    $stmt->execute($args);$r=$stmt->fetch()?:[];
    $summary=[];
    foreach(['gross','base','business','ewt','total'] as $key)$summary[$key]=(int)($r[$key]??0);
    return [(int)($r['row_count']??0),(int)($r['checked_count']??0),$summary];
}
/** Fetch only requested rows, and resolve approval metadata for those rows only.
 * An all_rows print request is still capped at 5,000 for existing print safety. */
function report_simple_sql_page(array $actor, array $maps, string $where, array $args, int $limit, int $offset): array {
    if($limit<=0)return [];
    $stmt=db()->prepare('SELECT * FROM transactions'.$where.' ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?');
    $i=1;foreach($args as $arg)$stmt->bindValue($i++,$arg,PDO::PARAM_STR);
    $stmt->bindValue($i++,$limit,PDO::PARAM_INT);
    $stmt->bindValue($i,$offset,PDO::PARAM_INT);
    $stmt->execute();$rawRows=$stmt->fetchAll();
    $result=[];
    foreach(array_chunk($rawRows,100) as $chunk){
        [$pending,$backups,$deletions]=accessible_transaction_aux_maps($actor,false,array_column($chunk,'id'));
        foreach($chunk as $raw){
            $t=client_transaction_row($raw,$pending,$backups,$maps,$deletions);
            $t['report_atcs']=report_atc_codes($t,$maps);
            $result[]=$t;
        }
    }
    return $result;
}
function report_simple_response(array $actor, array $maps, array $f): array {
    [$where,$args]=report_simple_sql_parts($actor,$f['month'],$f['school'],$f['supplier'],$f['status']);
    [$total,$checked,$summary]=report_simple_sql_summary($where,$args);
    $perPage=100;$page=max(1,(int)($_GET['page']??1));
    $allRows=(string)($_GET['all_rows']??'')==='1';
    $offset=$allRows?0:($page-1)*$perPage;
    $limit=$allRows?5000:$perPage;
    $rows=$offset>=$total?[]:report_simple_sql_page($actor,$maps,$where,$args,$limit,$offset);
    return ['rows'=>$rows,'summary'=>$summary,'checked_rows'=>$checked,'total'=>$total,'truncated'=>$total>5000,'pagination'=>['page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage)),'total'=>$total],'selected_atc_withheld'=>null];
}

function report_response(array $actor): array {
    $maps=transaction_master_maps();$filters=report_filter_values();
    ['month'=>$month,'school'=>$school,'supplier'=>$supplier,'fund'=>$fund,'status'=>$status,'atcType'=>$atcType,'atcCode'=>$atcCode]=$filters;
    // Most report visits have no historical Fund/ATC filter. Avoid scanning and
    // hydrating every matching transaction just to show one 100-row page.
    if($fund==='all'&&$atcType==='')return report_simple_response($actor,$maps,$filters);
    $rows=[];$summary=['gross'=>0,'base'=>0,'business'=>0,'ewt'=>0,'total'=>0];$checked=0;$selectedAtcWithheld=0;$total=0;
    $allRows=(string)($_GET['all_rows']??'')==='1';$perPage=100;$page=max(1,(int)($_GET['page']??1));
    $offset=$allRows?0:($page-1)*$perPage;$max=$allRows?5000:$perPage;
    foreach(report_filtered_rows($actor,$maps,$month,$school,$supplier,$fund,$status,$atcType,$atcCode) as $t){
        $total++;
        if($t['status']==='checked'){
            $checked++;$a=$t['snapshot']['amounts']??null;
            if($a){foreach(array_keys($summary) as $k)$summary[$k]+=(int)($a[$k]??0);
                if($atcType!=='')$selectedAtcWithheld+=(int)($a[$atcType==='VAT'?'business':'ewt']??0);}
        }
        if($total>$offset&&count($rows)<$max)$rows[]=$t;
    }
    $pages=max(1,(int)ceil($total/$perPage));
    return ['rows'=>$rows,'summary'=>$summary,'checked_rows'=>$checked,'total'=>$total,'truncated'=>$total>5000,'pagination'=>['page'=>$page,'per_page'=>$perPage,'pages'=>$pages,'total'=>$total],'selected_atc_withheld'=>$atcType!==''?$selectedAtcWithheld:null];
}
/** Stream full CSV on the server; school-role boundaries and frozen ATC filters
 * are the same generator used by Reports. Never buffer the full export. */
function report_csv_response(array $actor): never {
    $maps=transaction_master_maps();$f=report_filter_values();
    // Validate filter values before beginning the CSV response.
    $generator=report_filtered_rows($actor,$maps,$f['month'],$f['school'],$f['supplier'],$f['fund'],$f['status'],$f['atcType'],$f['atcCode']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="withholding-report-'.gmdate('Y-m-d').'.csv"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    $out=fopen('php://output','wb');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Transaction Reference','Date','Origin','Source of Fund','Supplier','Supplier Tax Status','Supplier TIN','Status','VAT ATC','EWT ATC','Selected ATC Withheld','Gross','Tax Base','VAT Withheld','EWT','Total Tax Withheld','Net Amount Paid','Transaction ID'],',','"','');
    $safe=static function(mixed $value): string {
        $s=(string)($value??'');return preg_match('/^[=+\-@]/u',ltrim($s))?"'".$s:$s;
    };
    $decimal=static function(?int $cents): string {
        if($cents===null)return '';
        $negative=$cents<0; $abs=abs($cents);
        return ($negative?'-':'').intdiv($abs,100).'.'.str_pad((string)($abs%100),2,'0',STR_PAD_LEFT);
    };
    $tinFormat=static function(mixed $value): string {
        $raw=trim((string)($value??''));$d=preg_replace('/\D/','',$raw);
        if(strlen($d)===14)return substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6,3).'-'.substr($d,9,5);
        if(strlen($d)===12)return substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6,3).'-'.substr($d,9,3);
        if(strlen($d)===9)return substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6,3);
        return $raw;
    };
    $i=0;
    foreach($generator as $t){
        $snap=$t['snapshot']??[];
        $a=$t['display_amounts']??null;
        $supplier=is_array($snap['supplier']??null)?$snap['supplier']:($maps['suppliers'][(string)$t['supplier_id']]??[]);
        $supplierName=(string)($supplier['name']??($maps['suppliers'][(string)$t['supplier_id']]['name']??''));
        $vatStatus=trim((string)($supplier['vat_status']??''));
        if(preg_match('/^non[-\s]?vat/i',$vatStatus))$vatStatus='Non-VAT';
        elseif(preg_match('/^vat/i',$vatStatus))$vatStatus='VAT';
        else $vatStatus=preg_replace('/\s+Registered$/i','',$vatStatus);
        $rawReference=trim((string)($t['reference']??$snap['transaction_reference']??''));
        $reference=$rawReference!==''?$rawReference:mb_substr((string)$t['id'],0,12).'…';
        $statusLabel=(string)$t['status']==='deleted'?'cancelled':str_replace('_',' ',(string)$t['status']);
        if(!empty($t['deletion_request']))$statusLabel.=' / cancellation pending';
        elseif(!empty($t['pending_edit']))$statusLabel.=' / pending approval';
        $codeVat=(string)($t['report_atcs']['VAT']??'');$codeEwt=(string)($t['report_atcs']['EWT']??'');
        $selected=$f['atcType']!==''&&$a?($f['atcType']==='VAT'?($a['business']??0):($a['ewt']??0)):null;
        $row=[$reference,$t['date'],transaction_origin_name($t,$maps),$snap['source_fund']['name']??($maps['source_funds'][(string)$t['source_fund_id']]['name']??''),$supplierName,$vatStatus,$tinFormat($supplier['tin']??''),$statusLabel,$codeVat,$codeEwt,$decimal($selected!==null?(int)$selected:null),$decimal((int)$t['gross']),$a?$decimal((int)($a['base']??0)):'',$a?$decimal((int)($a['business']??0)):'',$a?$decimal((int)($a['ewt']??0)):'',$a?$decimal((int)($a['total']??0)):'',$a?$decimal((int)$t['gross']-(int)($a['total']??0)):'',(string)$t['id']];
        fputcsv($out,array_map($safe,$row),',','"','');
        if((++$i%100)===0)fflush($out);
    }
    fclose($out);exit;
}

/** Read-only, server-aggregated month-end overview for Division reviewers.
 * Never loads per-transaction snapshots into PHP and never changes period state. */
function reconciliation_response(array $actor): array {
    require_admin($actor);
    $month=valid_period_month($_GET['month']??current_manila_month());
    [$from,$to]=period_month_bounds($month);
    $selection=(string)($_GET['school']??'all');
    $schools=master_all('schools');
    $schoolMap=[];foreach($schools as $school)$schoolMap[(string)$school['id']]=$school;
    if(!in_array($selection,['all','division','__all_schools__'],true)&&!isset($schoolMap[$selection]))throw new RuntimeException('Invalid reconciliation origin.');
    $scopeFilter=match($selection){'division'=>" AND t.transaction_scope='division'",'__all_schools__'=>" AND t.transaction_scope='school'",'all'=>'',default=>" AND t.transaction_scope='school' AND t.school_id=?"};
    $args=[$from,$to];if(!in_array($selection,['all','division','__all_schools__'],true))$args[]=$selection;
    $safe="IF(JSON_VALID(t.snapshot),t.snapshot,'{}')";
    $amountObject="JSON_TYPE(JSON_EXTRACT($safe,'$.amounts'))='OBJECT'";
    $amountSums=[];
    foreach(['gross','base','business','ewt','total'] as $key){
        if($key==='gross'){$amountSums[]="COALESCE(SUM(CASE WHEN t.status='checked' THEN t.gross ELSE 0 END),0) AS checked_gross";continue;}
        $path='$.amounts.'.$key;
        $amountSums[]="COALESCE(SUM(CASE WHEN t.status='checked' AND $amountObject THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT($safe,'$path')) AS DECIMAL(30,0)),0) ELSE 0 END),0) AS checked_$key";
    }
    $sql="SELECT t.transaction_scope,t.school_id,COUNT(*) AS row_count,".
        "SUM(CASE WHEN t.status='checked' THEN 1 ELSE 0 END) AS checked_count,".
        "SUM(CASE WHEN t.status='void' THEN 1 ELSE 0 END) AS void_count,".
        "SUM(CASE WHEN t.status='deleted' THEN 1 ELSE 0 END) AS cancelled_count,".
        "SUM(CASE WHEN t.status='draft' THEN 1 ELSE 0 END) AS draft_count,".
        "SUM(CASE WHEN t.status='submitted' THEN 1 ELSE 0 END) AS submitted_count,".
        "SUM(CASE WHEN t.status='returned' THEN 1 ELSE 0 END) AS returned_count,".
        "SUM(CASE WHEN t.status='checked' AND NOT COALESCE(($amountObject),0) THEN 1 ELSE 0 END) AS missing_checked_amounts,".
        "SUM(CASE WHEN t.status='checked' AND (t.checked_snapshot_hash IS NULL OR LOWER(t.checked_snapshot_hash)<>LOWER(SHA2(t.snapshot,256))) THEN 1 ELSE 0 END) AS checked_hash_mismatches,".
        "SUM(CASE WHEN t.status='checked' AND $amountObject AND CAST(JSON_UNQUOTE(JSON_EXTRACT($safe,'$.amounts.gross')) AS DECIMAL(30,0))<>t.gross THEN 1 ELSE 0 END) AS checked_gross_mismatches,".
        implode(',',$amountSums).
        " FROM transactions t WHERE t.date>=? AND t.date<?".$scopeFilter.
        " GROUP BY t.transaction_scope,t.school_id";
    $stmt=db()->prepare($sql);$stmt->execute($args);
    $values=[];
    $fields=['row_count','checked_count','void_count','cancelled_count','draft_count','submitted_count','returned_count','missing_checked_amounts','checked_hash_mismatches','checked_gross_mismatches','checked_gross','checked_base','checked_business','checked_ewt','checked_total'];
    $keyFor=static fn(string $scope,?string $schoolId)=>$scope==='division'?'division':'school:'.$schoolId;
    while($r=$stmt->fetch()){
        $key=$keyFor((string)$r['transaction_scope'],$r['school_id']);
        $values[$key]=[];foreach($fields as $name)$values[$key][$name]=(int)($r[$name]??0);
    }
    // Batch the period lookup: the number of queries does not grow with schools.
    $periodStmt=db()->prepare('SELECT target_key,state FROM transaction_periods WHERE period_month=?');
    $periodStmt->execute([$month]);$states=[];while($r=$periodStmt->fetch())$states[(string)$r['target_key']]=(string)$r['state'];
    // Counts from pending requests are informational; the established month
    // close blocker function remains the authoritative mutation safeguard.
    $requestSql=[
        'pending_edits'=>"SELECT t.transaction_scope,t.school_id,COUNT(DISTINCT p.transaction_id) n FROM transaction_pending_edits p JOIN transactions t ON t.id=p.transaction_id WHERE ((t.date>=? AND t.date<?) OR (p.date>=? AND p.date<?))",
        'pending_cancellations'=>"SELECT t.transaction_scope,t.school_id,COUNT(DISTINCT d.transaction_id) n FROM transaction_deletion_requests d JOIN transactions t ON t.id=d.transaction_id WHERE d.status='pending' AND t.date>=? AND t.date<?",
        'revision_backups'=>"SELECT t.transaction_scope,t.school_id,COUNT(DISTINCT b.transaction_id) n FROM transaction_submitted_backups b JOIN transactions t ON t.id=b.transaction_id WHERE b.date>=? AND b.date<?",
    ];
    foreach($requestSql as $category=>$base){
        $filters=$scopeFilter;
        // All request queries have transaction alias t.
        $query=$base.$filters.' GROUP BY t.transaction_scope,t.school_id';
        $baseArgs=$category==='pending_edits'?[$from,$to,$from,$to]:[$from,$to];
        if(!in_array($selection,['all','division','__all_schools__'],true))$baseArgs[]=$selection;
        $pendingStmt=db()->prepare($query);$pendingStmt->execute($baseArgs);
        while($r=$pendingStmt->fetch()){
            $key=$keyFor((string)$r['transaction_scope'],$r['school_id']);
            if(!isset($values[$key]))$values[$key]=array_fill_keys($fields,0);
            $values[$key][$category]=(int)$r['n'];
        }
    }
    $targets=[];
    if(in_array($selection,['all','division'],true))$targets[]=['key'=>'division','name'=>master_row('division','division')['name']??'Division Office','scope'=>'division'];
    if($selection!=='division')foreach($schools as $school){
        $id=(string)$school['id'];if(!in_array($selection,['all','__all_schools__'],true)&&$selection!==$id)continue;
        $targets[]=['key'=>'school:'.$id,'name'=>(string)$school['name'],'scope'=>'school'];
    }
    $rows=[];$zeros=array_fill_keys(array_merge($fields,['pending_edits','pending_cancellations','revision_backups']),0);$totals=$zeros;
    foreach($targets as $t){
        $key=$t['key'];$numbers=array_merge($zeros,$values[$key]??[]);
        $out=['key'=>$key,'name'=>$t['name'],'scope'=>$t['scope'],'period_state'=>$states[$key]??'not_opened'];
        foreach($totals as $field=>$unused){$out[$field]=(int)($numbers[$field]??0);$totals[$field]+=$out[$field];}
        $out['exceptions']=$out['draft_count']+$out['submitted_count']+$out['returned_count']+$out['pending_edits']+$out['pending_cancellations']+$out['revision_backups']+$out['missing_checked_amounts']+$out['checked_hash_mismatches']+$out['checked_gross_mismatches'];
        $rows[]=$out;
    }
    // Include orphaned historic transactions belonging to removed schools in
    // an explicit exception row rather than silently losing financial totals.
    $included=array_fill_keys(array_column($rows,'key'),true);
    foreach($values as $key=>$numbers){
        if(isset($included[$key]))continue;
        if($selection==='division'||(!in_array($selection,['all','__all_schools__'],true)))continue;
        $out=['key'=>$key,'name'=>'Historical school (not in current master)','scope'=>'school','period_state'=>$states[$key]??'not_opened'];
        foreach($totals as $field=>$unused){$out[$field]=(int)($numbers[$field]??0);$totals[$field]+=$out[$field];}
        $out['exceptions']=$out['draft_count']+$out['submitted_count']+$out['returned_count']+$out['pending_edits']+$out['pending_cancellations']+$out['revision_backups']+$out['missing_checked_amounts']+$out['checked_hash_mismatches']+$out['checked_gross_mismatches'];
        $rows[]=$out;
    }
    $totals['exceptions']=array_sum(array_column($rows,'exceptions'));
    return ['month'=>$month,'label'=>period_month_label($month),'school'=>$selection,'rows'=>$rows,'totals'=>$totals,'read_only'=>true];
}

function transaction_detail_response(array $actor, string $id): array {
    $t=own_transaction($actor,$id);$pending=[];$backups=[];$deletions=[];
    if($p=pending_edit_row($id))$pending[$id]=$p;
    if($d=pending_deletion_request_row($id))$deletions[$id]=$d;
    $stmt=db()->prepare('SELECT * FROM transaction_submitted_backups WHERE transaction_id=?');$stmt->execute([$id]);$b=$stmt->fetch();if($b){$b['gross']=(int)$b['gross'];$b['version']=(int)$b['version'];if(array_key_exists('snapshot',$b))$b['snapshot']=$b['snapshot']?json_decode_assoc((string)$b['snapshot']):null;$backups[$id]=$b;}
    $maps=transaction_master_maps();
    // own_transaction already decoded the snapshot; convert it back only for the shared row normalizer.
    if(is_array($t['snapshot']??null))$t['snapshot']=json_encode_safe($t['snapshot']);
    return client_transaction_row($t,$pending,$backups,$maps,$deletions);
}
function dashboard_response(array $actor): array {
    $where="status='checked'";$args=[];if(!is_admin_or_owner($actor)){$where.=" AND transaction_scope='school' AND school_id=?";$args[]=$actor['school_id'];}
    // Frozen Checked snapshots allow the database to calculate exact dashboard
    // totals; the browser only needs the ten most recent complete records.
    $amountSum=[];
    foreach(['base','business','ewt','total'] as $key){
        $path='$.amounts.'.$key;
        $amountSum[]="COALESCE(SUM(CASE WHEN JSON_VALID(snapshot)=1 THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot, '".$path."')) AS DECIMAL(30,0)) ELSE 0 END),0) AS ".$key;
    }
    $sql="SELECT COUNT(*) AS count,COALESCE(SUM(gross),0) AS gross,".implode(',',$amountSum)." FROM transactions WHERE {$where}";
    $totals=db()->prepare($sql);$totals->execute($args);$saved=$totals->fetch()?:[];
    $summary=[];foreach(['count','gross','base','business','ewt','total'] as $key)$summary[$key]=(int)($saved[$key]??0);
    $stmt=db()->prepare("SELECT * FROM transactions WHERE {$where} ORDER BY COALESCE(finalized_at,created_at) DESC LIMIT 10");$stmt->execute($args);
    $rawRecent=$stmt->fetchAll();$recent=[];
    if($rawRecent){
        $maps=transaction_master_maps();[$pending,$backups,$deletions]=accessible_transaction_aux_maps($actor,false,array_column($rawRecent,'id'));
        foreach($rawRecent as $raw)$recent[]=client_transaction_row($raw,$pending,$backups,$maps,$deletions);
    }
    return ['summary'=>$summary,'recent'=>$recent];
}
/** A first-opening reminder, not a requirement to open every target.
 * Closed months and intentionally unopened schools cannot be pending work. */
function period_initial_opening_reminder(array $period): ?array {
    $total=(int)($period['total_targets']??0);
    $notOpened=(int)($period['not_opened_targets']??0);
    if($total<=0||$notOpened!==$total)return null;
    return ['id'=>'period:'.$period['month'],'type'=>'period',
        'title'=>$period['label'].' transaction period has not been opened',
        'meta'=>'No school or Division Office target opened yet',
        'detail'=>'Open the month for the schools or Division Office that need to transact.',
        'kind'=>'action','sort'=>$period['month'].'-01T00:00:00Z'];
}

function notification_response(array $actor): array {
    $maps=['schools'=>map_by_id(master_all('schools')),'suppliers'=>map_by_id(master_all('suppliers')),'division'=>master_row('division','division')];[$pending,,$deletions]=accessible_transaction_aux_maps($actor,false);$items=[];
    $pendingCheck="(EXISTS (SELECT 1 FROM transaction_pending_edits p WHERE p.transaction_id=transactions.id) OR EXISTS (SELECT 1 FROM transaction_deletion_requests d WHERE d.transaction_id=transactions.id AND d.status='pending'))";
    $statusFilter=is_admin_or_owner($actor)?"(status='submitted' OR (status='draft' AND transaction_scope='division') OR (status='checked' AND $pendingCheck))":"(status IN ('draft','submitted','returned') OR (status='checked' AND $pendingCheck))";
    foreach(accessible_transaction_rows($actor,$statusFilter) as $raw){
        $status=(string)$raw['status'];$scope=transaction_scope_from_record($raw);$hasPending=isset($pending[$raw['id']]);$hasDeletion=isset($deletions[$raw['id']]);
        if(is_admin_or_owner($actor)){if(!($scope==='division'&&$status==='draft')&&$status!=='submitted'&&!($status==='checked'&&($hasPending||$hasDeletion)))continue;}else{if(!in_array($status,['draft','submitted','returned'],true)&&!($status==='checked'&&($hasPending||$hasDeletion)))continue;}
        $snapshot=$raw['snapshot']?json_decode_assoc((string)$raw['snapshot']):null;$p=$pending[$raw['id']]??null;$d=$deletions[$raw['id']]??null;
        $origin=$scope==='division'?($snapshot['division']['name']??$maps['division']['name']??'Division Office'):($snapshot['school']['name']??($maps['schools'][$raw['school_id']]['name']??$raw['school_id']));$supplier=$snapshot['supplier']['name']??($maps['suppliers'][$raw['supplier_id']]['name']??$raw['supplier_id']);$ref=(string)($raw['reference']??substr((string)$raw['id'],0,12).'…');
        if(is_admin_or_owner($actor)){
            if($scope==='division'&&$status==='draft')$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Division Draft not yet checked','meta'=>$origin.' · '.$supplier,'detail'=>'Transaction '.$ref.' · '.$raw['date'],'kind'=>'action','sort'=>$raw['created_at']??$raw['date']];
            if($scope==='school'&&$status==='submitted')$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Submitted for checking','meta'=>$origin.' · '.$supplier,'detail'=>'Transaction '.$ref.' · '.$raw['date'],'kind'=>'action','sort'=>$raw['submitted_at']??$raw['created_at']??$raw['date']];
            if($status==='checked'&&$hasDeletion){$ownDivisionRequest=$scope==='division'&&(string)($d['requested_by']??'')===(string)$actor['user_id'];$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>$ownDivisionRequest?'Cancellation request awaiting another Division reviewer':'Cancellation request awaiting approval','meta'=>$origin.' · '.$supplier,'detail'=>'Transaction '.$ref.' · '.($d['reason']??'Cancellation requested'),'kind'=>$ownDivisionRequest?'waiting':'action','sort'=>$d['requested_at']??$raw['finalized_at']??$raw['date']];}
            elseif($scope==='school'&&$status==='checked'&&$hasPending){$ps=$p['snapshot']['supplier']['name']??($maps['suppliers'][$p['supplier_id']]['name']??$supplier);$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'School edit awaiting approval','meta'=>$origin.' · '.$ps,'detail'=>'Transaction '.$ref.' · proposed '.($p['date']??$raw['date']),'kind'=>'action','sort'=>$p['submitted_at']??$raw['finalized_at']??$raw['date']];}
        }else{
            if($status==='draft')$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Draft not yet submitted','meta'=>$supplier,'detail'=>'Transaction '.$ref.' · '.$raw['date'],'kind'=>'action','sort'=>$raw['created_at']??$raw['date']];
            if($status==='submitted')$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Awaiting Division checking','meta'=>$supplier,'detail'=>'Transaction '.$ref.' · submitted '.$raw['date'],'kind'=>'waiting','sort'=>$raw['submitted_at']??$raw['created_at']??$raw['date']];
            if($status==='returned')$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Returned for correction','meta'=>$supplier,'detail'=>'Transaction '.$ref.' · correction required','kind'=>'action','sort'=>$raw['created_at']??$raw['date']];
            if($status==='checked'&&$hasDeletion)$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Cancellation request awaiting Division approval','meta'=>$supplier,'detail'=>'Transaction '.$ref,'kind'=>'waiting','sort'=>$d['requested_at']??$raw['finalized_at']??$raw['date']];
            elseif($status==='checked'&&$hasPending)$items[]=['id'=>$raw['id'],'type'=>'transaction','title'=>'Edit awaiting Division approval','meta'=>$supplier,'detail'=>'Transaction '.$ref.' · proposed '.($p['date']??$raw['date']),'kind'=>'waiting','sort'=>$p['submitted_at']??$raw['finalized_at']??$raw['date']];
        }
    }
    $period=period_overview_response($actor);
    if(is_admin_or_owner($actor)){
        // Selective opening is intentional. Closed/Locked targets are completed,
        // not incomplete coverage. Remind the Division only before ANY target
        // has ever been opened for the current month.
        if($reminder=period_initial_opening_reminder($period))$items[]=$reminder;
        foreach(pending_password_resets_for_actor($actor) as $r)$items[]=['id'=>$r['id'],'type'=>'password_reset','title'=>'Password reset awaiting approval','meta'=>$r['username'].' · '.($r['role']==='division_admin'?'Division Admin':'School User'),'detail'=>'Requested '.($r['requested_at']??''),'kind'=>'action','sort'=>$r['requested_at']??''];
    }elseif((string)($period['state']??'not_opened')==='not_opened'){
        // A locked month is a normal final state and is NOT pending school
        // work; retain the waiting reminder only before the school is opened.
        $items[]=['id'=>'period:'.$period['month'],'type'=>'period','title'=>$period['label'].' transaction period is not yet open','meta'=>$period['target_name']??'Assigned school','detail'=>'Wait for the Division to open this month before entering transactions.','kind'=>'waiting','sort'=>$period['month'].'-01T00:00:00Z'];
    }
    usort($items,fn($a,$b)=>strcmp((string)($b['sort']??''),(string)($a['sort']??'')));
    return $items;
}

try {
    $actor=require_member();
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $scope=(string)($_GET['scope']??'bootstrap');
        if($scope==='notifications') respond(['notifications'=>notification_response($actor),'password_reset_requests'=>is_admin_or_owner($actor)?pending_password_resets_for_actor($actor):[],'csrf'=>csrf_token()]);
        if($scope==='history') respond(['history'=>transaction_history_for_id_actor($actor,valid_text($_GET['id']??null,'Transaction',64)),'csrf'=>csrf_token()]);
        if($scope==='transaction') respond(['transaction'=>transaction_detail_response($actor,valid_text($_GET['id']??null,'Transaction',64)),'csrf'=>csrf_token()]);
        if($scope==='transactions') respond(transaction_list_response($actor)+['csrf'=>csrf_token()]);
        if($scope==='reports') respond(report_response($actor)+['csrf'=>csrf_token()]);
        if($scope==='reports_csv') report_csv_response($actor);
        if($scope==='reconciliation') respond(reconciliation_response($actor)+['csrf'=>csrf_token()]);
        if($scope==='periods') respond(transaction_periods_response($actor,valid_period_month($_GET['month']??current_manila_month()))+['csrf'=>csrf_token()]);
        if($scope!=='bootstrap') respond(['error'=>'Unknown data scope.'],400);
        $admin=is_admin_or_owner($actor);$owner=is_project_owner($actor);
        $schools=$admin?master_all('schools'):[master_row('schools',(string)$actor['school_id'])];
        $suppliers=master_all('suppliers');$atcs=master_all('atcs');$sourceFunds=master_all('source_funds');$division=master_row('division','division');
        $members=[];$audit=[];
        if($admin){
            if($owner){$members=db()->query('SELECT id,username,username_changed_once,email,user_id,role,school_id,active,must_change_password,account_origin,created_at,updated_at FROM members ORDER BY role,username')->fetchAll();}
            else{$stmtMembers=db()->prepare("SELECT id,username,username_changed_once,email,user_id,role,school_id,active,must_change_password,account_origin,created_at,updated_at FROM members WHERE role='school_user' OR id=? ORDER BY role,username");$stmtMembers->execute([$actor['id']]);$members=$stmtMembers->fetchAll();}
            $audit=db()->query('SELECT * FROM audit ORDER BY at DESC LIMIT 100')->fetchAll();
        }
        $passwordResetRequests=$admin?pending_password_resets_for_actor($actor):[];$dashboard=dashboard_response($actor);$notifications=notification_response($actor);$periodOverview=period_overview_response($actor);
        respond(['actor'=>$actor,'schools'=>array_values(array_filter($schools)),'suppliers'=>$suppliers,'atcs'=>$atcs,'source_funds'=>$sourceFunds,'division'=>$division,'transactions'=>$dashboard['recent'],'dashboard'=>$dashboard,'notifications'=>$notifications,'transaction_period'=>$periodOverview,'members'=>$members,'password_reset_requests'=>$passwordResetRequests,'audit'=>$audit,'csrf'=>csrf_token(),'version'=>APP_VERSION]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') respond(['error'=>'Method not allowed.'],405);
    verify_csrf();
    $b=body_json(); $action=$b['action']??''; $pdo=db();
    period_mutation_locks($actor,$b,(string)$action);

    if($action==='saveMaster'){
        $kind=enum_value($b['kind']??null,['schools','suppliers','atcs','division','source_funds'],'record type');
        $record=is_array($b['record']??null)?$b['record']:[]; $id=$kind==='division'?'division':((string)($record['id']??'')?:uuid());
        $previous=master_row($kind,$id); $r=$kind==='division'&&$previous?array_merge($previous,$record):$record;
        if($actor['role']==='school_user'){
            if($kind==='schools'){
                if($id!==$actor['school_id']||!$previous) throw new RuntimeException('You may update only your assigned school profile.');
                $r=$previous; foreach(['contact','representative','position','representative_tin'] as $k)$r[$k]=$record[$k]??($r[$k]??'');
            } elseif(in_array($kind,['suppliers','atcs'],true)) {
                if($previous) throw new RuntimeException('School Users may add new shared Supplier and ATC records, but only the Division may edit existing master records.');
                // School-created master records are shared and start active. Division users retain edit/deactivation control.
                $r['active']=1;
            } else {
                require_admin($actor);
            }
        } else require_admin($actor);
        $r['name']=valid_text($r['name']??null,'Name'); $r['active']=isset($r['active'])&&!$r['active']?0:1;
        if(in_array($kind,['suppliers','division'],true)){
            $r['tin']=normalize_tin($r['tin']??null); $r['address']=valid_text($r['address']??null,'Registered address',240); $r['zip']=valid_text($r['zip']??null,'ZIP code',4); if(!preg_match('/^\d{4}$/',$r['zip']))throw new RuntimeException('ZIP code must be 4 digits.');
        }
        if($kind==='division'){$r['representative']=valid_text($r['representative']??null,'Authorized representative',120);$r['designation']=valid_text($r['designation']??null,'Designation',100);$r['representative_tin']=normalize_tin($r['representative_tin']??null);}
        if($kind==='schools'){$r['address']=valid_text($r['address']??null,'School address',240);$r['code']=valid_text($r['code']??null,'School code',40);$r['representative']=valid_text($r['representative']??null,'Representative',120);$r['position']=valid_text($r['position']??null,'Position',100);foreach(master_all('schools') as $otherSchool){if((string)$otherSchool['id']!==$id&&strcasecmp((string)($otherSchool['code']??''),(string)$r['code'])===0)throw new RuntimeException('School code already exists. Each school code must be unique.');}}
        $autoSchoolAccount=null;
        if($kind==='schools'&&!$previous){
            $autoUsername=normalize_username($r['code']);
            $collision=$pdo->prepare('SELECT id FROM members WHERE LOWER(username)=? OR LOWER(email)=? LIMIT 1');
            $collision->execute([$autoUsername,$autoUsername]);
            if($collision->fetch()) throw new RuntimeException('The school code cannot be used as a username because it is already assigned to another account.');
            $autoSchoolAccount=['id'=>uuid(),'username'=>$autoUsername,'password'=>new_temporary_password()];
        }
        $types=['Supplier of Goods','Supplier of Services','Rental','Contractor','Utilities','Other'];
        if($kind==='atcs'){
            $r['name']=strtoupper(valid_text($r['name']??null,'ATC code',16));$r['description']=valid_text($r['description']??null,'Description',600);$r['rate']=(float)($r['rate']??-1);if(!is_finite($r['rate'])||$r['rate']<0||$r['rate']>100)throw new RuntimeException('Rate must be between 0 and 100 percent.');
            $r['tax_type']=enum_value($r['tax_type']??null,['VAT','EWT'],'tax type');$r['transaction_type']=enum_value($r['transaction_type']??null,array_merge(['All'],$types),'transaction type');$businessClass=(string)($r['business_type']??'');if($businessClass==='All')$businessClass='Both';$r['business_type']=enum_value($businessClass,['Both','Individual','Corporate'],'business type');$r['effective_from']=valid_date($r['effective_from']??null);if(!empty($r['effective_to'])){$r['effective_to']=valid_date($r['effective_to']);if($r['effective_to']<$r['effective_from'])throw new RuntimeException('Effective end precedes start.');}
            $atcCheck=$pdo->prepare('SELECT id FROM atcs WHERE LOWER(name)=LOWER(?) AND id<>? LIMIT 1');$atcCheck->execute([$r['name'],$previous['id']??'']);if($atcCheck->fetch())throw new RuntimeException('ATC code already exists. Open the existing ATC record instead of creating a duplicate.');
        }
        if($kind==='source_funds'){$r['name']=strtoupper(valid_text($r['name']??null,'Source of Fund',80));}
        $newLogoFile=null;$newLogoBasename=null;$cleanupLogoAfterCommit=false;
        if($kind==='division'){
            if(!empty($record['remove_logo'])){unset($r['logo_path'],$r['logo_updated_at']);$cleanupLogoAfterCommit=true;}
            if(!empty($record['logo_data_url']) && is_string($record['logo_data_url'])){
                $logo=store_division_logo($record['logo_data_url']);$newLogoFile=$logo['stored_file'];$newLogoBasename=$logo['stored_basename'];unset($logo['stored_file'],$logo['stored_basename']);$r=array_merge($r,$logo);$cleanupLogoAfterCommit=true;
            }
        }
        if($kind==='suppliers'){
            $r['business_type']=enum_value($r['business_type']??null,['Individual','Corporate'],'business type');$r['vat_status']=enum_value($r['vat_status']??null,['VAT Registered','Non-VAT Registered'],'VAT status');
            $rawProfiles=$r['transaction_profiles']??null;
            if(!is_array($rawProfiles)||count($rawProfiles)===0){
                $rawProfiles=[['transaction_type'=>$r['transaction_type']??null,'business_atc'=>$r['business_atc']??null,'ewt_atc'=>$r['ewt_atc']??null]];
            }
            if(count($rawProfiles)>count($types))throw new RuntimeException('A supplier cannot have more transaction profiles than the supported transaction classifications.');
            $profiles=[];$seenTypes=[];
            foreach($rawProfiles as $profile){
                if(!is_array($profile))throw new RuntimeException('Invalid supplier transaction profile.');
                $transactionType=enum_value($profile['transaction_type']??null,$types,'transaction type');
                if(isset($seenTypes[$transactionType]))throw new RuntimeException('Each supplier transaction type may be configured only once.');
                $seenTypes[$transactionType]=true;
                $validated=['transaction_type'=>$transactionType];
                $supplierForProfile=$r;$supplierForProfile['transaction_type']=$transactionType;
                foreach([['business_atc','VAT'],['ewt_atc','EWT']] as [$key,$tax]){
                    $atcId=valid_text($profile[$key]??null,'ATC');$c=master_row('atcs',$atcId);
                    if(!$c||($c['tax_type']??'')!==$tax)throw new RuntimeException('Select valid VAT and EWT codes for '.$transactionType.'.');
                    if(!(int)($c['active']??0))throw new RuntimeException('Select active VAT and EWT codes for '.$transactionType.'.');
                    if(!atc_applies_to_supplier($c,$supplierForProfile))throw new RuntimeException('The selected '.$tax.' ATC does not apply to '.$transactionType.' for this supplier.');
                    $validated[$key]=$atcId;
                }
                $profiles[]=$validated;
            }
            if(!$profiles)throw new RuntimeException('Configure at least one transaction type for this supplier.');
            $r['transaction_profiles']=$profiles;
            // Keep the first profile mirrored in the legacy fields so older snapshots/tools remain compatible.
            $r['transaction_type']=$profiles[0]['transaction_type'];$r['business_atc']=$profiles[0]['business_atc'];$r['ewt_atc']=$profiles[0]['ewt_atc'];
            // TIN is normalized first, so 9/12/14 digit forms of the same TIN cannot create duplicate suppliers.
            $tinCheck=$pdo->prepare('SELECT id,name FROM suppliers WHERE tin=? AND id<>? LIMIT 1');
            $tinCheck->execute([$r['tin'],$previous['id']??'']);
            if($duplicate=$tinCheck->fetch()) throw new RuntimeException('Supplier TIN already exists for '.$duplicate['name'].'. Open the existing supplier instead of creating a duplicate.');
        }
        unset($r['id'],$r['version'],$r['logo_data_url'],$r['remove_logo']); $payload=json_encode_safe($r); if(strlen($payload)>12000)throw new RuntimeException('Record is too large.');
        $pdo->beginTransaction();
        try{
            if($previous){$expected=(int)($record['version']??0);if($expected!==$previous['version'])throw new RuntimeException('This record changed. Reload before saving.');
                if($kind==='division')$stmt=$pdo->prepare('UPDATE division SET name=?,data=?,version=version+1 WHERE id=? AND version=?');
                elseif($kind==='suppliers')$stmt=$pdo->prepare('UPDATE suppliers SET name=?,data=?,tin=?,active=?,version=version+1 WHERE id=? AND version=?');
                else $stmt=$pdo->prepare("UPDATE {$kind} SET name=?,data=?,active=?,version=version+1 WHERE id=? AND version=?");
                $params=$kind==='division'?[$r['name'],$payload,$id,$previous['version']]:($kind==='suppliers'?[$r['name'],$payload,$r['tin'],$r['active'],$id,$previous['version']]:[$r['name'],$payload,$r['active'],$id,$previous['version']]);$stmt->execute($params);if($stmt->rowCount()!==1)throw new RuntimeException('This record changed. Reload before saving.');
            }else{
                if($kind==='division'){$stmt=$pdo->prepare('INSERT INTO division(id,name,data,version) VALUES(?,?,?,1)');$stmt->execute([$id,$r['name'],$payload]);}
                elseif($kind==='suppliers'){$stmt=$pdo->prepare('INSERT INTO suppliers(id,name,data,tin,active,version) VALUES(?,?,?,?,?,1)');$stmt->execute([$id,$r['name'],$payload,$r['tin'],$r['active']]);}
                else{$stmt=$pdo->prepare("INSERT INTO {$kind}(id,name,data,active,version) VALUES(?,?,?,?,1)");$stmt->execute([$id,$r['name'],$payload,$r['active']]);}
            }
            if($kind==='schools'&&$autoSchoolAccount){
                $now=now_iso();
                $memberStmt=$pdo->prepare('INSERT INTO members(id,username,email,user_id,password_hash,must_change_password,account_origin,role,school_id,active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $memberStmt->execute([$autoSchoolAccount['id'],$autoSchoolAccount['username'],null,$autoSchoolAccount['id'],password_hash($autoSchoolAccount['password'],PASSWORD_DEFAULT),1,'auto_school','school_user',$id,1,$now,$now]);
                audit_event($actor,'auto-create-school-user',$autoSchoolAccount['id'],['school_id'=>$id,'school_code'=>$r['code'],'username'=>$autoSchoolAccount['username'],'must_change_password'=>true],$pdo);
            }
            audit_event($actor,'save-master',$id,['kind'=>$kind,'before'=>$previous,'after'=>$r],$pdo);$pdo->commit();
            if($kind==='division'&&$cleanupLogoAfterCommit)remove_division_logo_files($newLogoBasename);
            respond(['ok'=>true,'id'=>$id,'default_account'=>$autoSchoolAccount?['username'=>$autoSchoolAccount['username'],'temporary_password'=>$autoSchoolAccount['password']]:null]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($newLogoFile&&is_file($newLogoFile))@unlink($newLogoFile);throw $e;}
    }

    if($action==='saveMember'){
        require_admin($actor);
        $r=is_array($b['record']??null)?$b['record']:[];
        $id=(string)($r['id']??'')?:uuid();
        $q=$pdo->prepare('SELECT * FROM members WHERE id=?');$q->execute([$id]);$previous=$q->fetch()?:null;
        $username=$previous?(string)$previous['username']:normalize_username($r['username']??null);
        if($previous && isset($r['username']) && normalize_username($r['username'])!==$username) throw new RuntimeException('Username is permanent after the account is created.');
        $collision=$pdo->prepare('SELECT id FROM members WHERE id<>? AND (LOWER(username)=? OR LOWER(email)=?) LIMIT 1');
        $collision->execute([$id,$username,$username]);
        if($collision->fetch()) throw new RuntimeException('That username is already in use or matches another account email.');

        $requestedRole=(string)($r['role']??'');
        $active=isset($r['active'])&&!$r['active']?0:1;
        $sid=null;

        if(is_project_owner($actor)){
            if($previous&&$previous['role']==='project_owner'){
                if($previous['id']!==$actor['id'])throw new RuntimeException('The Project Owner account is protected.');
                if($requestedRole!=='project_owner'||!$active)throw new RuntimeException('The Project Owner role and active status cannot be changed here.');
                $role='project_owner';$active=1;
            }else{
                $role=enum_value($requestedRole,['school_user','division_admin'],'role');
                $sid=$role==='school_user'?valid_text($r['school_id']??null,'School'):null;
            }
        }else{
            if($previous&&$previous['id']===$actor['id']){
                if($requestedRole!=='division_admin'||!$active)throw new RuntimeException('You cannot change your own Division Administrator role or active status.');
                $role='division_admin';$active=1;
            }else{
                if($previous&&$previous['role']!=='school_user')throw new RuntimeException('Only the Project Owner can manage Division Administrator accounts.');
                $role=enum_value($requestedRole,['school_user'],'role');
                $sid=valid_text($r['school_id']??null,'School');
            }
        }

        if($sid&&!master_row('schools',$sid))throw new RuntimeException('School not found.');
        $password=(string)($r['password']??'');$hash=$previous['password_hash']??null;
        if($previous&&$password!=='')throw new RuntimeException('Existing passwords cannot be changed from Authorized Account. Use the approved password-reset workflow or My Account.');
        if(!$previous){if(strlen($password)<10)throw new RuntimeException('Temporary password must contain at least 10 characters.');$hash=password_hash($password,PASSWORD_DEFAULT);}
        $email=$previous['email']??null;
        $now=now_iso();$pdo->beginTransaction();
        try{
            if($previous){$stmt=$pdo->prepare('UPDATE members SET role=?,school_id=?,active=?,updated_at=? WHERE id=?');$stmt->execute([$role,$sid,$active,$now,$id]);}
            else{$stmt=$pdo->prepare('INSERT INTO members(id,username,email,user_id,password_hash,must_change_password,account_origin,session_version,role,school_id,active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$id,$username,null,$id,$hash,1,'manual',1,$role,$sid,$active,$now,$now]);}
            audit_event($actor,'save-member',$id,['before'=>$previous?['id'=>$previous['id'],'username'=>$previous['username'],'email'=>$previous['email'],'role'=>$previous['role'],'school_id'=>$previous['school_id'],'active'=>$previous['active']]:null,'after'=>['username'=>$username,'email'=>$email,'role'=>$role,'school_id'=>$sid,'active'=>$active,'temporary_password_created'=>!$previous,'must_change_password'=>!$previous?true:(bool)($previous['must_change_password']??0)]],$pdo);
            $pdo->commit();respond(['ok'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='updateOwnAccount'){
        $r=is_array($b['record']??null)?$b['record']:[];
        $q=$pdo->prepare('SELECT * FROM members WHERE id=? AND active=1');$q->execute([$actor['id']]);$current=$q->fetch();
        if(!$current)throw new RuntimeException('Account not found.');
        $currentPassword=(string)($r['current_password']??'');
        if(!$current['password_hash']||!password_verify($currentPassword,$current['password_hash']))throw new RuntimeException('Current password is incorrect.');
        $requestedUsername=normalize_username($r['username']??$current['username']);
        $usernameChanged=strtolower((string)$current['username'])!==$requestedUsername;
        if($usernameChanged && (int)($current['username_changed_once']??0)===1)throw new RuntimeException('Your one-time username change has already been used.');
        if($usernameChanged){
            $usernameCollision=$pdo->prepare('SELECT id FROM members WHERE id<>? AND (LOWER(username)=? OR LOWER(email)=?) LIMIT 1');
            $usernameCollision->execute([$actor['id'],$requestedUsername,$requestedUsername]);
            if($usernameCollision->fetch())throw new RuntimeException('That username is already in use or matches another account email.');
        }
        $email=strtolower(trim((string)($r['email']??'')));
        if($email===''||strlen($email)>180||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid recovery email address.');
        $collision=$pdo->prepare('SELECT id FROM members WHERE id<>? AND (LOWER(email)=? OR LOWER(username)=?) LIMIT 1');
        $collision->execute([$actor['id'],$email,$email]);
        if($collision->fetch())throw new RuntimeException('That email is already used by another account or matches another username.');
        $newPassword=(string)($r['new_password']??'');
        $hash=$current['password_hash'];$passwordChanged=false;
        if($newPassword!==''){if(strlen($newPassword)<10)throw new RuntimeException('New password must contain at least 10 characters.');$hash=password_hash($newPassword,PASSWORD_DEFAULT);$passwordChanged=true;}
        $emailChanged=strtolower((string)($current['email']??''))!==$email;
        if(!$emailChanged&&!$passwordChanged&&!$usernameChanged)respond(['ok'=>true,'unchanged'=>true]);
        $usernameChangedOnce=$usernameChanged?1:(int)($current['username_changed_once']??0);$sessionVersion=(int)($current['session_version']??1)+($passwordChanged?1:0);
        $now=now_iso();$pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare('UPDATE members SET username=?,username_changed_once=?,email=?,password_hash=?,session_version=?,updated_at=? WHERE id=?');$stmt->execute([$requestedUsername,$usernameChangedOnce,$email,$hash,$sessionVersion,$now,$actor['id']]);
            audit_event($actor,'update-own-account',$actor['id'],['username_before'=>$current['username'],'username_after'=>$requestedUsername,'username_changed'=>$usernameChanged,'email_before'=>$current['email'],'email_after'=>$email,'password_changed'=>$passwordChanged],$pdo);
            $pdo->commit();if($passwordChanged)$_SESSION['session_version']=$sessionVersion;respond(['ok'=>true,'username_changed'=>$usernameChanged]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='approvePasswordReset'){
        require_admin($actor);
        $requestId=valid_text($b['id']??null,'Password reset request',64);
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("SELECT r.*,m.username,m.email,m.role,m.school_id,m.account_origin,m.active FROM password_reset_requests r JOIN members m ON m.id=r.member_id WHERE r.id=? FOR UPDATE");
            $stmt->execute([$requestId]);$request=$stmt->fetch();
            if(!$request || $request['status']!=='pending') throw new RuntimeException('This password reset request is no longer pending.');
            if(!empty($request['expires_at']) && strcmp((string)$request['expires_at'],now_iso())<0) throw new RuntimeException('This password reset request has expired. Ask the user to submit a new request.');
            if(!(int)$request['active']) throw new RuntimeException('The account is inactive. Activate it before approving a password reset.');
            if((string)$request['member_id']===(string)$actor['id']) throw new RuntimeException('You cannot approve your own password reset while signed in.');
            if(!can_approve_password_reset($actor,$request)) throw new RuntimeException('You do not have permission to reset this account.');
            $temporary=reset_temporary_password($request);$now=now_iso();
            $update=$pdo->prepare('UPDATE members SET password_hash=?,must_change_password=1,session_version=session_version+1,updated_at=? WHERE id=? AND active=1');
            $update->execute([password_hash($temporary['password'],PASSWORD_DEFAULT),$now,$request['member_id']]);
            if($update->rowCount()!==1) throw new RuntimeException('The account could not be reset.');
            $review=$pdo->prepare("UPDATE password_reset_requests SET status='approved',reviewed_by=?,reviewed_at=? WHERE id=? AND status='pending'");
            $review->execute([$actor['id'],$now,$requestId]);
            if($review->rowCount()!==1) throw new RuntimeException('The request changed. Reload and try again.');
            audit_event($actor,'approve-password-reset',(string)$request['member_id'],['request_id'=>$requestId,'username'=>$request['username'],'role'=>$request['role'],'temporary_password_basis'=>$temporary['basis'],'must_change_password'=>true],$pdo);
            $pdo->commit();
            respond(['ok'=>true,'username'=>$request['username'],'temporary_password'=>$temporary['password'],'must_change_password'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='rejectPasswordReset'){
        require_admin($actor);$requestId=valid_text($b['id']??null,'Password reset request',64);$reason=trim((string)($b['reason']??''));if(mb_strlen($reason)>500)throw new RuntimeException('Rejection note must be 500 characters or fewer.');
        $pdo->beginTransaction();
        try{$stmt=$pdo->prepare("SELECT r.*,m.username,m.role,m.school_id,m.active FROM password_reset_requests r JOIN members m ON m.id=r.member_id WHERE r.id=? FOR UPDATE");$stmt->execute([$requestId]);$request=$stmt->fetch();if(!$request||$request['status']!=='pending')throw new RuntimeException('This password reset request is no longer pending.');if(!can_approve_password_reset($actor,$request))throw new RuntimeException('You do not have permission to review this account.');$now=now_iso();$up=$pdo->prepare("UPDATE password_reset_requests SET status='rejected',reviewed_by=?,reviewed_at=? WHERE id=? AND status='pending'");$up->execute([$actor['id'],$now,$requestId]);if($up->rowCount()!==1)throw new RuntimeException('The request changed. Reload and try again.');audit_event($actor,'reject-password-reset',(string)$request['member_id'],['request_id'=>$requestId,'username'=>$request['username'],'reason'=>$reason?:null],$pdo);$pdo->commit();respond(['ok'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='setTransactionPeriods'){
        require_admin($actor);$month=valid_period_month($b['month']??null);$operation=enum_value($b['operation']??null,['open','close'],'period action');$keys=is_array($b['targets']??null)?array_values(array_unique(array_map('strval',$b['targets']))):[];
        if(!$keys)throw new RuntimeException('Select at least one school or Division Office.');if(count($keys)>1000)throw new RuntimeException('Too many period targets were selected.');
        $reason=trim((string)($b['reason']??''));if(mb_strlen($reason)>500)throw new RuntimeException('Reopening reason may contain at most 500 characters.');
        $targets=[];foreach($keys as $key)$targets[]=period_target_from_key($key);$now=now_iso();$changed=[];$unchanged=[];$pdo->beginTransaction();
        try{
            foreach($targets as $target){
                $sel=$pdo->prepare('SELECT * FROM transaction_periods WHERE period_month=? AND target_key=? FOR UPDATE');$sel->execute([$month,$target['target_key']]);$current=$sel->fetch();$currentState=(string)($current['state']??'not_opened');
                if($operation==='open'){
                    if(period_is_writable_state($currentState)){$unchanged[]=$target['name'];continue;}
                    $event=$currentState==='closed'?'reopen':'open';if($event==='reopen'&&$reason==='')throw new RuntimeException('A reason is required to reopen '.$month.' for '.$target['name'].'.');$newState=$event==='reopen'?'reopened':'open';
                    if($current){$up=$pdo->prepare("UPDATE transaction_periods SET state=?,opened_at=?,opened_by=?,last_action_at=?,last_action_by=?,version=version+1 WHERE period_month=? AND target_key=? AND version=?");$up->execute([$newState,$now,$actor['user_id'],$now,$actor['user_id'],$month,$target['target_key'],(int)$current['version']]);if($up->rowCount()!==1)throw new RuntimeException('Transaction period changed. Reload and try again.');}
                    else{$ins=$pdo->prepare("INSERT INTO transaction_periods(period_month,target_key,transaction_scope,school_id,state,opened_at,opened_by,closed_at,closed_by,last_action_at,last_action_by,version) VALUES(?,?,?,?,?,?,?,NULL,NULL,?,?,1)");$ins->execute([$month,$target['target_key'],$target['transaction_scope'],$target['school_id'],$newState,$now,$actor['user_id'],$now,$actor['user_id']]);}
                    $h=$pdo->prepare('INSERT INTO transaction_period_history(id,period_month,target_key,transaction_scope,school_id,action,actor,reason,at) VALUES(?,?,?,?,?,?,?,?,?)');$h->execute([uuid(),$month,$target['target_key'],$target['transaction_scope'],$target['school_id'],$event,$actor['user_id'],$event==='reopen'?$reason:null,$now]);$changed[]=['target_key'=>$target['target_key'],'name'=>$target['name'],'action'=>$event];
                }else{
                    if($currentState==='not_opened'){$unchanged[]=$target['name'];continue;}if($currentState==='closed'){$unchanged[]=$target['name'];continue;}assert_period_can_close($month,$target['transaction_scope'],$target['school_id']);
                    $up=$pdo->prepare("UPDATE transaction_periods SET state='closed',closed_at=?,closed_by=?,last_action_at=?,last_action_by=?,version=version+1 WHERE period_month=? AND target_key=? AND version=?");$up->execute([$now,$actor['user_id'],$now,$actor['user_id'],$month,$target['target_key'],(int)$current['version']]);if($up->rowCount()!==1)throw new RuntimeException('Transaction period changed. Reload and try again.');
                    $h=$pdo->prepare('INSERT INTO transaction_period_history(id,period_month,target_key,transaction_scope,school_id,action,actor,reason,at) VALUES(?,?,?,?,?,?,?,?,?)');$h->execute([uuid(),$month,$target['target_key'],$target['transaction_scope'],$target['school_id'],'close',$actor['user_id'],null,$now]);$changed[]=['target_key'=>$target['target_key'],'name'=>$target['name'],'action'=>'close'];
                }
            }
            if($changed)audit_event($actor,'transaction-period-'.$operation,$month,['month'=>$month,'targets'=>$changed,'reopen_reason'=>$reason?:null],$pdo);
            $pdo->commit();respond(['ok'=>true,'month'=>$month,'operation'=>$operation,'changed'=>$changed,'unchanged'=>$unchanged,'periods'=>transaction_periods_response($actor,$month)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='saveTransaction'||$action==='saveDraft'){
        $id=(string)($b['id']??'')?:uuid();$existing=!empty($b['id'])?own_transaction($actor,$id):null;
        if($existing&&$existing['status']==='void')throw new RuntimeException('Void transactions cannot be edited.');
        if($existing&&!in_array($existing['status'],['draft','submitted','checked','returned'],true))throw new RuntimeException('This transaction cannot be edited.');
        $scope=$existing?transaction_scope_from_record($existing):($actor['role']==='school_user'?'school':'division');
        if($existing&&in_array($existing['status'],['submitted','returned'],true)){
            if($scope==='school'&&$actor['role']!=='school_user')throw new RuntimeException('Only the originating School User may edit a Submitted or Returned school transaction.');
            if($scope==='division'&&!is_admin_or_owner($actor))throw new RuntimeException('Only a Division user may edit a Division-level transaction.');
        }
        if($existing&&$existing['status']==='draft'&&$scope==='school'&&$actor['role']!=='school_user'){ $q=$pdo->prepare('SELECT transaction_id FROM transaction_submitted_backups WHERE transaction_id=?');$q->execute([$id]);if($q->fetch())throw new RuntimeException('Only the originating School User may edit this revision Draft.'); }
        $schoolId=$scope==='school'?($existing?(string)$existing['school_id']:(string)$actor['school_id']):null;
        $school=null;if($scope==='school'){$school=master_row('schools',(string)$schoolId);if(!$school||!(int)($school['active']??0))throw new RuntimeException('Select an active school.');}
        elseif(!is_admin_or_owner($actor))throw new RuntimeException('Only Division users may create Division-level transactions.');
        $supplier=master_row('suppliers',valid_text($b['supplier_id']??null,'Supplier'));if(!$supplier||!(int)($supplier['active']??0))throw new RuntimeException('Select an active supplier.');
        $sourceFund=master_row('source_funds',valid_text($b['source_fund_id']??null,'Source of Fund'));if(!$sourceFund||!(int)($sourceFund['active']??0))throw new RuntimeException('Select an active Source of Fund.');
        $requestedTransactionType=trim((string)($b['transaction_type']??''));$profiles=supplier_transaction_profiles($supplier);$profile=resolve_supplier_transaction_profile($supplier,$requestedTransactionType,count($profiles)>1);$transactionType=$profile['transaction_type'];
        $existingSupplier=$existing?master_row('suppliers',(string)$existing['supplier_id']):null;$existingType=$existing?transaction_type_from_record($existing,$existingSupplier):'';
        $day=valid_date($b['date']??null);$gross=amount_to_cents((string)($b['amount']??''));
        assert_transaction_periods_for_change($existing,$scope,$schoolId,$day);

        // Checked school records remain official. School edits use a frozen proposed snapshot and require Division approval.
        if($existing&&$existing['status']==='checked'){
            if(pending_deletion_request_row($id))throw new RuntimeException('A cancellation request is already awaiting Division review. Approve or reject that request before editing the checked transaction.');
            if($scope==='division')throw new RuntimeException('Checked Division-level transactions are protected. Void the checked record and create a new Division transaction if a correction is required.');
            if($actor['role']!=='school_user')throw new RuntimeException('Only the assigned school user may submit edits to a checked school transaction.');
            $expected=(int)($b['version']??0);if($expected!==$existing['version'])throw new RuntimeException('The checked transaction changed. Reload before editing.');
            $pending=pending_edit_row($id);$sameApproved=$existing['supplier_id']===$supplier['id']&&$existingType===$transactionType&&(string)($existing['source_fund_id']??'')===$sourceFund['id']&&$existing['date']===$day&&(int)$existing['gross']===$gross;
            if($sameApproved){
                if(!$pending)respond(['ok'=>true,'id'=>$id,'status'=>'checked','unchanged'=>true,'pending'=>false]);
                $pendingExpected=(int)($b['pending_version']??0);if($pendingExpected!==$pending['version'])throw new RuntimeException('Pending changes were updated elsewhere. Reload before saving.');
                $before=pending_history_state($existing,$pending);$after=transaction_history_state($existing);$pdo->beginTransaction();
                try{$del=$pdo->prepare('DELETE FROM transaction_pending_edits WHERE transaction_id=? AND version=?');$del->execute([$id,$pending['version']]);if($del->rowCount()!==1)throw new RuntimeException('Pending changes were updated elsewhere. Reload first.');transaction_history_event($pdo,$actor,$id,'edit_request_cancelled',$before,$after,'Pending changes were reverted to the last checked values.');audit_event($actor,'cancel-edit-request',$id,['before'=>$before],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$id,'status'=>'checked','pending'=>false,'cancelled'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            }
            $samePending=$pending&&$pending['supplier_id']===$supplier['id']&&transaction_type_from_record($pending)===$transactionType&&$pending['source_fund_id']===$sourceFund['id']&&$pending['date']===$day&&(int)$pending['gross']===$gross;
            if($samePending)respond(['ok'=>true,'id'=>$id,'status'=>'checked','unchanged'=>true,'pending'=>true]);
            $duplicates=duplicate_transaction_candidates($scope,$schoolId,(string)$supplier['id'],$day,$gross,$id);$duplicateConfirmed=!empty($b['duplicate_confirmed']);if($duplicates&&!$duplicateConfirmed)respond(duplicate_warning_payload($duplicates));$duplicateRefs=array_map(fn($r)=>(string)$r['reference'],$duplicates);$duplicateNote=$duplicates?' Possible duplicate warning was reviewed and acknowledged before the checked-record edit was submitted for approval. Matching transaction references: '.implode(', ',$duplicateRefs).'.':'';
            $temp=$existing;$temp['supplier_id']=$supplier['id'];$temp['transaction_type']=$transactionType;$temp['source_fund_id']=$sourceFund['id'];$temp['date']=$day;$temp['gross']=$gross;
            $proposal=preview_transaction($temp,$actor);$proposalJson=json_encode_safe($proposal['snapshot']);$proposedAt=now_iso();
            $before=$pending?pending_history_state($existing,$pending):transaction_history_state($existing);$after=['transaction_scope'=>$scope,'school_id'=>$schoolId,'supplier_id'=>$supplier['id'],'transaction_type'=>$transactionType,'source_fund_id'=>$sourceFund['id'],'date'=>$day,'gross'=>$gross,'status'=>'pending_approval','snapshot'=>$proposal['snapshot'],'submission_hash'=>$proposal['hash'],'submitted_at'=>$proposedAt,'submitted_by'=>$actor['user_id'],'checked_at'=>$existing['finalized_at'],'checked_by'=>$existing['finalized_by'],'check_remarks'=>null];$pdo->beginTransaction();
            try{
                if($pending){$pendingExpected=(int)($b['pending_version']??0);if($pendingExpected!==$pending['version'])throw new RuntimeException('Pending changes were updated elsewhere. Reload before saving.');$stmt=$pdo->prepare('UPDATE transaction_pending_edits SET supplier_id=?,transaction_type=?,source_fund_id=?,date=?,gross=?,snapshot=?,snapshot_hash=?,submitted_by=?,submitted_at=?,version=version+1 WHERE transaction_id=? AND version=?');$stmt->execute([$supplier['id'],$transactionType,$sourceFund['id'],$day,$gross,$proposalJson,$proposal['hash'],$actor['user_id'],$proposedAt,$id,$pending['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Pending changes were updated elsewhere. Reload first.');$event='edit_request_updated';}
                else{$stmt=$pdo->prepare('INSERT INTO transaction_pending_edits(transaction_id,supplier_id,transaction_type,source_fund_id,date,gross,snapshot,snapshot_hash,submitted_by,submitted_at,version) VALUES(?,?,?,?,?,?,?,?,?,?,1)');$stmt->execute([$id,$supplier['id'],$transactionType,$sourceFund['id'],$day,$gross,$proposalJson,$proposal['hash'],$actor['user_id'],$proposedAt]);$event='edit_requested';}
                transaction_history_event($pdo,$actor,$id,$event,$before,$after,'Changes require Division approval before replacing the checked version. The proposed calculation is frozen exactly as submitted.'.$duplicateNote);audit_event($actor,$event,$id,['before'=>$before,'after'=>$after,'hash'=>$proposal['hash'],'duplicate_warning_acknowledged'=>(bool)$duplicates,'duplicate_matches'=>$duplicateRefs],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$id,'status'=>'checked','pending'=>true,'requires_review'=>true,'duplicate_warning_acknowledged'=>(bool)$duplicates]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }

        // New transactions and pre-check edits are saved as Drafts. School Submitted revisions preserve the last submitted values so the school can discard the Draft.
        if($existing){
            $expected=(int)($b['version']??0);if($expected!==$existing['version'])throw new RuntimeException('Transaction changed. Reload before saving.');
            $same=$existing['supplier_id']===$supplier['id']&&$existingType===$transactionType&&(string)($existing['source_fund_id']??'')===$sourceFund['id']&&$existing['date']===$day&&(int)$existing['gross']===$gross;
            if($same)respond(['ok'=>true,'id'=>$id,'status'=>$existing['status'],'unchanged'=>true]);
            $before=transaction_history_state($existing);$targetStatus=$existing['status']==='returned'?'returned':'draft';$after=['transaction_scope'=>$scope,'school_id'=>$schoolId,'supplier_id'=>$supplier['id'],'transaction_type'=>$transactionType,'source_fund_id'=>$sourceFund['id'],'date'=>$day,'gross'=>$gross,'status'=>$targetStatus,'snapshot'=>null,'submission_hash'=>null,'submitted_at'=>null,'submitted_by'=>null,'checked_at'=>null,'checked_by'=>null,'check_remarks'=>null];$pdo->beginTransaction();
            try{
                if($existing['status']==='submitted'){
                    if($scope!=='school')throw new RuntimeException('Division-level transactions do not use the school Submitted-revision workflow.');
                    $backup=$pdo->prepare('INSERT INTO transaction_submitted_backups(transaction_id,supplier_id,transaction_type,source_fund_id,date,gross,snapshot,submission_hash,submitted_at,submitted_by,version,backed_up_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE transaction_id=transaction_id');
                    $backup->execute([$id,$existing['supplier_id'],$existingType,$existing['source_fund_id'],$existing['date'],$existing['gross'],$existing['snapshot']?json_encode_safe($existing['snapshot']):null,$existing['submission_hash']??null,$existing['submitted_at']??null,$existing['submitted_by']??null,$existing['version'],now_iso()]);
                }
                $stmt=$pdo->prepare("UPDATE transactions SET supplier_id=?,transaction_type=?,source_fund_id=?,date=?,gross=?,status=?,snapshot=NULL,submission_hash=NULL,submitted_at=NULL,submitted_by=NULL,finalized_at=NULL,finalized_by=NULL,version=version+1 WHERE id=? AND version=? AND status IN ('draft','submitted','returned')");
                $stmt->execute([$supplier['id'],$transactionType,$sourceFund['id'],$day,$gross,$targetStatus,$id,$existing['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload before saving.');
                $event=$existing['status']==='submitted'?'submitted_revision_draft':($existing['status']==='returned'?'returned_correction_edit':'draft_edit');
                $remarks=$existing['status']==='submitted'?'A revision Draft was created. The prior Submitted values were preserved and can be restored by Discard Draft.':($existing['status']==='returned'?'School revised a transaction returned for correction.':($scope==='division'?'Division-level Draft recalculated.':null));
                transaction_history_event($pdo,$actor,$id,$event,$before,$after,$remarks);audit_event($actor,$event,$id,['before'=>$before,'after'=>$after],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$id,'status'=>$targetStatus,'returned_to_draft'=>$existing['status']==='submitted','has_submitted_backup'=>$existing['status']==='submitted']);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }else{
            $created=now_iso();$pdo->beginTransaction();
            try{
                $reference=$scope==='division'?reserve_unique_division_transaction_reference($pdo,$created,$day):reserve_unique_transaction_reference($pdo,(string)$schoolId,(string)($school['code']??''),$created,$day);
                $after=['reference'=>$reference,'transaction_scope'=>$scope,'school_id'=>$schoolId,'supplier_id'=>$supplier['id'],'transaction_type'=>$transactionType,'source_fund_id'=>$sourceFund['id'],'date'=>$day,'gross'=>$gross,'status'=>'draft','snapshot'=>null,'submission_hash'=>null,'submitted_at'=>null,'submitted_by'=>null,'checked_at'=>null,'checked_by'=>null,'check_remarks'=>null];
                $stmt=$pdo->prepare("INSERT INTO transactions(id,reference,transaction_scope,school_id,supplier_id,transaction_type,source_fund_id,created_by,date,gross,created_at,status,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,'draft',1)");
                $stmt->execute([$id,$reference,$scope,$schoolId,$supplier['id'],$transactionType,$sourceFund['id'],$actor['user_id'],$day,$gross,$created]);
                $historyNote=$scope==='division'?'Division-level calculation Draft created with permanent transaction reference '.$reference.'.':'Initial calculation Draft created with permanent transaction reference '.$reference.'.';
                transaction_history_event($pdo,$actor,$id,'draft_created',null,$after,$historyNote);audit_event($actor,'create-draft',$id,$after,$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$id,'reference'=>$reference,'status'=>'draft','transaction_scope'=>$scope]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
    }

    if($action==='submitTransaction'){
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));if(!in_array($t['status'],['draft','returned'],true))throw new RuntimeException('Only Draft or Returned transactions can be submitted for Division checking.');
        if(transaction_scope_from_record($t)!=='school')throw new RuntimeException('Division-level Drafts are checked directly by a Division user and are not submitted to a school-review queue.');
        if($actor['role']!=='school_user')throw new RuntimeException('Only the originating School User may submit or resubmit a school transaction.');
        $expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Transaction changed. Reload before submitting.');assert_transaction_period_writable('school',(string)$t['school_id'],(string)$t['date']);
        $duplicates=duplicate_transaction_candidates('school',(string)$t['school_id'],(string)$t['supplier_id'],(string)$t['date'],(int)$t['gross'],(string)$t['id']);
        $duplicateConfirmed=!empty($b['duplicate_confirmed']);
        if($duplicates&&!$duplicateConfirmed)respond(duplicate_warning_payload($duplicates));
        $duplicateRefs=array_map(fn($r)=>(string)$r['reference'],$duplicates);$duplicateNote=$duplicates?' Possible duplicate warning was reviewed and acknowledged before submission. Matching transaction references: '.implode(', ',$duplicateRefs).'.':'';
        $p=preview_transaction($t,$actor);$submittedAt=now_iso();$snapshotJson=json_encode_safe($p['snapshot']);$before=transaction_history_state($t);$after=$before;$after['status']='submitted';$after['snapshot']=$p['snapshot'];$after['submission_hash']=$p['hash'];$after['submitted_at']=$submittedAt;$after['submitted_by']=$actor['user_id'];$pdo->beginTransaction();
        try{$stmt=$pdo->prepare("UPDATE transactions SET status='submitted',snapshot=?,checked_snapshot_hash=NULL,submission_hash=?,submitted_at=?,submitted_by=?,finalized_at=NULL,finalized_by=NULL,version=version+1 WHERE id=? AND status IN ('draft','returned') AND version=?");$stmt->execute([$snapshotJson,$p['hash'],$submittedAt,$actor['user_id'],$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload before submitting.');$del=$pdo->prepare('DELETE FROM transaction_submitted_backups WHERE transaction_id=?');$del->execute([$t['id']]);$event=$t['status']==='returned'?'correction_resubmitted':'submitted';$historyRemarks=($t['status']==='returned'?'Corrected transaction resubmitted to the Division with a frozen calculation snapshot.':'Submitted to the Division with the exact calculation and master-data snapshot frozen for review.').$duplicateNote;transaction_history_event($pdo,$actor,$t['id'],$event,$before,$after,$historyRemarks);audit_event($actor,'submit-transaction',$t['id'],['hash'=>$p['hash'],'from_status'=>$t['status'],'duplicate_warning_acknowledged'=>(bool)$duplicates,'duplicate_matches'=>$duplicateRefs],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'submitted','submission_hash'=>$p['hash'],'duplicate_warning_acknowledged'=>(bool)$duplicates]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='discardSubmittedDraft'){
        if($actor['role']!=='school_user')throw new RuntimeException('Only the originating School User may discard this revision Draft.');
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));if($t['status']!=='draft')throw new RuntimeException('Only a revision Draft can be discarded.');$expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Draft changed. Reload before discarding.');
        $q=$pdo->prepare('SELECT * FROM transaction_submitted_backups WHERE transaction_id=?');$q->execute([$t['id']]);$backup=$q->fetch();if(!$backup)throw new RuntimeException('This Draft has no prior Submitted version to restore.');assert_transaction_period_writable('school',(string)$t['school_id'],(string)$t['date']);assert_transaction_period_writable('school',(string)$t['school_id'],(string)$backup['date']);$backupSnapshot=!empty($backup['snapshot'])?json_decode_assoc((string)$backup['snapshot']):null;if($backupSnapshot){$frozen=frozen_snapshot_result($backupSnapshot,$backup['submission_hash']??null,'Previous Submitted version');$checkRow=['id'=>$t['id'],'school_id'=>$t['school_id'],'supplier_id'=>$backup['supplier_id'],'transaction_type'=>$backup['transaction_type']??'','source_fund_id'=>$backup['source_fund_id'],'date'=>$backup['date'],'gross'=>(int)$backup['gross']];assert_snapshot_matches_transaction($frozen['snapshot'],$checkRow,'Previous Submitted version');}
        $backupSupplier=master_row('suppliers',(string)$backup['supplier_id']);$restoreType=transaction_type_from_record(['transaction_type'=>$backup['transaction_type']??'','snapshot'=>$backupSnapshot],$backupSupplier);$before=transaction_history_state($t);$after=$before;$after['supplier_id']=$backup['supplier_id'];$after['transaction_type']=$restoreType;$after['source_fund_id']=$backup['source_fund_id'];$after['date']=$backup['date'];$after['gross']=(int)$backup['gross'];$after['status']='submitted';$after['snapshot']=$backupSnapshot;$after['submission_hash']=$backup['submission_hash']??null;$after['submitted_at']=$backup['submitted_at']??null;$after['submitted_by']=$backup['submitted_by']??null;$pdo->beginTransaction();
        try{$stmt=$pdo->prepare("UPDATE transactions SET supplier_id=?,transaction_type=?,source_fund_id=?,date=?,gross=?,status='submitted',snapshot=?,checked_snapshot_hash=NULL,submission_hash=?,submitted_at=?,submitted_by=?,finalized_at=NULL,finalized_by=NULL,version=version+1 WHERE id=? AND status='draft' AND version=?");$stmt->execute([$backup['supplier_id'],$restoreType,$backup['source_fund_id'],$backup['date'],$backup['gross'],$backup['snapshot']??null,$backup['submission_hash']??null,$backup['submitted_at']??null,$backup['submitted_by']??null,$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Draft changed. Reload before discarding.');$del=$pdo->prepare('DELETE FROM transaction_submitted_backups WHERE transaction_id=?');$del->execute([$t['id']]);transaction_history_event($pdo,$actor,$t['id'],'submitted_revision_discarded',$before,$after,'Revision Draft discarded; the exact prior Submitted values and calculation snapshot were restored.');audit_event($actor,'discard-submitted-draft',$t['id'],['restored'=>$after],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'submitted']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='cancelPendingEdit'){
        if($actor['role']!=='school_user')throw new RuntimeException('Only the originating School User may cancel pending changes.');
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));
        if($t['status']!=='checked')throw new RuntimeException('Only a Checked transaction can have pending changes cancelled.');
        $expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('The checked transaction changed. Reload before cancelling the pending changes.');
        $pending=pending_edit_row($t['id']);if(!$pending)throw new RuntimeException('There are no pending changes to cancel.');assert_transaction_period_writable('school',(string)$t['school_id'],(string)$t['date']);assert_transaction_period_writable('school',(string)$t['school_id'],(string)$pending['date']);
        $pendingExpected=(int)($b['pending_version']??0);if($pendingExpected!==$pending['version'])throw new RuntimeException('Pending changes were updated elsewhere. Reload before cancelling.');
        $before=pending_history_state($t,$pending);$after=transaction_history_state($t);$pdo->beginTransaction();
        try{
            $del=$pdo->prepare('DELETE FROM transaction_pending_edits WHERE transaction_id=? AND version=?');$del->execute([$t['id'],$pending['version']]);if($del->rowCount()!==1)throw new RuntimeException('Pending changes were updated elsewhere. Reload first.');
            transaction_history_event($pdo,$actor,$t['id'],'edit_request_cancelled',$before,$after,'Pending changes cancelled by School User. The last Checked version remains official.');
            audit_event($actor,'cancel-edit-request',$t['id'],['before'=>$before,'reason'=>'school_user_cancelled_pending_changes'],$pdo);
            $pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'checked','pending'=>false,'cancelled'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='returnForCorrection'){
        require_admin($actor);$t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));if(transaction_scope_from_record($t)!=='school')throw new RuntimeException('Return for Correction applies only to school transactions. Division-level checked records may be voided and recreated if correction is required.');if(!in_array($t['status'],['submitted','checked'],true))throw new RuntimeException('Only Submitted or Checked transactions may be returned for correction.');if($t['status']==='checked'&&pending_deletion_request_row($t['id']))throw new RuntimeException('This checked transaction has a pending cancellation request. Approve or reject the cancellation request before returning it for correction.');$returnType=transaction_type_from_record($t,master_row('suppliers',(string)$t['supplier_id']));assert_transaction_period_writable('school',(string)$t['school_id'],(string)$t['date']);$expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Transaction changed. Reload before returning it.');$remarks=valid_text($b['remarks']??null,'Correction remarks',1000);$before=transaction_history_state($t);$pending=pending_edit_row($t['id']);$after=$before;$after['status']='returned';$after['snapshot']=null;$after['submission_hash']=null;$after['submitted_at']=null;$after['submitted_by']=null;$after['checked_at']=null;$after['checked_by']=null;$after['check_remarks']=$remarks;$pdo->beginTransaction();
        try{if($pending){$pendingState=pending_history_state($t,$pending);$del=$pdo->prepare('DELETE FROM transaction_pending_edits WHERE transaction_id=? AND version=?');$del->execute([$t['id'],$pending['version']]);if($del->rowCount()!==1)throw new RuntimeException('Pending changes were updated. Reload first.');transaction_history_event($pdo,$actor,$t['id'],'edit_request_discarded',$pendingState,$before,'Pending school edit was superseded by the Division Return for Correction action.');} $clear=$pdo->prepare('DELETE FROM transaction_submitted_backups WHERE transaction_id=?');$clear->execute([$t['id']]);$stmt=$pdo->prepare("UPDATE transactions SET transaction_type=?,status='returned',snapshot=NULL,checked_snapshot_hash=NULL,submission_hash=NULL,submitted_at=NULL,submitted_by=NULL,finalized_at=NULL,finalized_by=NULL,version=version+1 WHERE id=? AND status IN ('submitted','checked') AND version=?");$stmt->execute([$returnType,$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload first.');transaction_history_event($pdo,$actor,$t['id'],'returned_for_correction',$before,$after,$remarks);audit_event($actor,'return-for-correction',$t['id'],['from_status'=>$t['status'],'remarks'=>$remarks,'prior_submission_hash'=>$t['submission_hash']??null],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'returned']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='certificate'){
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction',64));
        if(!in_array((string)$t['status'],['submitted','checked','void'],true))throw new RuntimeException('Form 2307 is available only for submitted or reviewed records.');
        $snapshot=is_array($t['snapshot']??null)?$t['snapshot']:null;
        if(!$snapshot){
            if($t['status']!=='submitted')throw new RuntimeException('Checked transaction has no saved certificate snapshot. Contact the Division.');
            // Preserve the pre-snapshot legacy Submitted preview pathway while clearly identifying it.
            $legacy=preview_transaction($t,$actor);
            respond(['snapshot'=>$legacy['snapshot'],'integrity_level'=>'legacy_recalculated','status'=>$t['status']]);
        }
        if($t['status']==='submitted'){
            frozen_snapshot_result($snapshot,$t['submission_hash']??null,'Submitted certificate');
            assert_snapshot_matches_transaction($snapshot,$t,'Submitted certificate');
            respond(['snapshot'=>$snapshot,'integrity_level'=>'submitted_verified','status'=>$t['status']]);
        }
        $checkedHash=trim((string)($t['checked_snapshot_hash']??''));
        if($checkedHash!==''){
            // Verify exact saved bytes, including historical JSON serialization variations.
            $sealStmt=db()->prepare('SELECT snapshot,checked_snapshot_hash,status,version FROM transactions WHERE id=?');
            $sealStmt->execute([$t['id']]);$sealed=$sealStmt->fetch();
            if(!$sealed||(int)$sealed['version']!==(int)$t['version']||(string)$sealed['status']!==(string)$t['status'])throw new RuntimeException('Transaction changed during certificate verification. Reload first.');
            $raw=(string)($sealed['snapshot']??'');
            if($raw===''||!hash_equals(strtolower($checkedHash),hash('sha256',$raw)))throw new RuntimeException('Checked certificate integrity verification failed. Contact the Project Owner before printing.');
            $snapshot=json_decode_assoc($raw);
            assert_snapshot_matches_transaction($snapshot,$t,'Checked certificate');
            respond(['snapshot'=>$snapshot,'integrity_level'=>'checked_verified','status'=>$t['status']]);
        }
        // Legacy Void rows cannot be backfilled without violating their existing immutability triggers.
        if($t['status']==='void'){
            assert_snapshot_matches_transaction($snapshot,$t,'Legacy void certificate');
            respond(['snapshot'=>$snapshot,'integrity_level'=>'legacy_void_immutable','status'=>$t['status']]);
        }
        throw new RuntimeException('Checked certificate integrity seal is missing. Ask the Project Owner to review migration 034 before printing.');
    }

    if($action==='preview'){
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));if(!in_array($t['status'],['draft','submitted','returned'],true))throw new RuntimeException('Only Draft, Submitted or Returned transactions can be calculated before checking.');
        if($t['status']==='submitted'&&is_array($t['snapshot']??null)){$frozen=frozen_snapshot_result($t['snapshot'],$t['submission_hash']??null,'Submitted transaction');assert_snapshot_matches_transaction($frozen['snapshot'],$t,'Submitted transaction');$frozen['frozen']=true;respond($frozen);}
        $live=preview_transaction($t,$actor);if($t['status']==='submitted')$live['legacy_unfrozen']=true;else{$scope=transaction_scope_from_record($t);$live['duplicate_candidates']=duplicate_transaction_candidates($scope,$scope==='school'?(string)$t['school_id']:null,(string)$t['supplier_id'],(string)$t['date'],(int)$t['gross'],(string)$t['id']);}respond($live);
    }

    if($action==='previewPending'){
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));if($t['status']!=='checked')throw new RuntimeException('Only checked transactions can have pending edits.');$pending=pending_edit_row($t['id']);if(!$pending)throw new RuntimeException('No pending edit is waiting for approval.');$temp=$t;$temp['supplier_id']=$pending['supplier_id'];$temp['transaction_type']=transaction_type_from_record($pending);$temp['source_fund_id']=$pending['source_fund_id'];$temp['date']=$pending['date'];$temp['gross']=$pending['gross'];
        if(is_array($pending['snapshot']??null)){$frozen=frozen_snapshot_result($pending['snapshot'],$pending['snapshot_hash']??null,'Pending edit');assert_snapshot_matches_transaction($frozen['snapshot'],$temp,'Pending edit');$frozen['frozen']=true;respond($frozen);}
        $live=preview_transaction($temp,$actor);$live['legacy_unfrozen']=true;respond($live);
    }

    if($action==='check'){
        require_admin($actor);$t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));$expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Transaction changed. Reload before checking.');$remarks=trim((string)($b['remarks']??''));if(mb_strlen($remarks)>1000)throw new RuntimeException('Remarks may contain at most 1000 characters.');$now=now_iso();$checkScope=transaction_scope_from_record($t);assert_transaction_period_writable($checkScope,$checkScope==='school'?(string)$t['school_id']:null,(string)$t['date']);
        if($t['status']==='draft'&&transaction_scope_from_record($t)==='division'){
            $duplicates=duplicate_transaction_candidates('division',null,(string)$t['supplier_id'],(string)$t['date'],(int)$t['gross'],(string)$t['id']);$duplicateConfirmed=!empty($b['duplicate_confirmed']);if($duplicates&&!$duplicateConfirmed)respond(duplicate_warning_payload($duplicates));$duplicateRefs=array_map(fn($r)=>(string)$r['reference'],$duplicates);$duplicateNote=$duplicates?' Possible duplicate warning was reviewed and acknowledged before the Division transaction was checked. Matching transaction references: '.implode(', ',$duplicateRefs).'.':'';
            $p=preview_transaction($t,$actor);$submissionHash=$p['hash'];$snap=json_encode_safe(array_merge($p['snapshot'],['checked_at'=>$now,'checked_by'=>$actor['user_id'],'check_remarks'=>$remarks]));$before=transaction_history_state($t);$after=$before;$after['status']='checked';$after['snapshot']=json_decode_assoc($snap);$after['submission_hash']=$submissionHash;$after['submitted_at']=$now;$after['submitted_by']=$actor['user_id'];$after['checked_at']=$now;$after['checked_by']=$actor['user_id'];$after['check_remarks']=$remarks;$pdo->beginTransaction();
            try{$stmt=$pdo->prepare("UPDATE transactions SET status='checked',snapshot=?,checked_snapshot_hash=?,submission_hash=?,submitted_at=?,submitted_by=?,finalized_at=?,finalized_by=?,version=version+1 WHERE id=? AND transaction_scope='division' AND status='draft' AND version=?");$stmt->execute([$snap,hash('sha256',$snap),$submissionHash,$now,$actor['user_id'],$now,$actor['user_id'],$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Division transaction changed. Reload and review again.');$historyRemarks=trim(($remarks?$remarks.' ':'').'Division-level Draft checked directly by the Division using the Division representative profile.'.$duplicateNote);transaction_history_event($pdo,$actor,$t['id'],'division_checked',$before,$after,$historyRemarks);audit_event($actor,'check-division-transaction',$t['id'],['remarks'=>$remarks,'hash'=>$submissionHash,'duplicate_warning_acknowledged'=>(bool)$duplicates,'duplicate_matches'=>$duplicateRefs],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'mode'=>'division','duplicate_warning_acknowledged'=>(bool)$duplicates]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        if($t['status']==='submitted'){
            $legacy=!is_array($t['snapshot']??null);$p=$legacy?preview_transaction($t,$actor):frozen_snapshot_result($t['snapshot'],$t['submission_hash']??null,'Submitted transaction');assert_snapshot_matches_transaction($p['snapshot'],$t,'Submitted transaction');
            $submissionHash=$t['submission_hash']??$p['hash'];$submittedAt=$t['submitted_at']??$now;$submittedBy=$t['submitted_by']??$t['created_by'];$snap=json_encode_safe(array_merge($p['snapshot'],['checked_at'=>$now,'checked_by'=>$actor['user_id'],'check_remarks'=>$remarks]));$before=transaction_history_state($t);if($legacy){$before['snapshot']=$p['snapshot'];$before['submission_hash']=$submissionHash;$before['submitted_at']=$submittedAt;$before['submitted_by']=$submittedBy;}$after=$before;$after['status']='checked';$after['snapshot']=json_decode_assoc($snap);$after['submission_hash']=$submissionHash;$after['submitted_at']=$submittedAt;$after['submitted_by']=$submittedBy;$after['checked_at']=$now;$after['checked_by']=$actor['user_id'];$after['check_remarks']=$remarks;$pdo->beginTransaction();
            try{$stmt=$pdo->prepare("UPDATE transactions SET status='checked',snapshot=?,checked_snapshot_hash=?,submission_hash=?,submitted_at=?,submitted_by=?,finalized_at=?,finalized_by=?,version=version+1 WHERE id=? AND status='submitted' AND version=?");$stmt->execute([$snap,hash('sha256',$snap),$submissionHash,$submittedAt,$submittedBy,$now,$actor['user_id'],$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload and review again.');$historyRemarks=$remarks?:null;if($legacy)$historyRemarks=trim(($historyRemarks?$historyRemarks.' ':'').'Legacy pre-v0.4.22 submission had no frozen snapshot; its current calculation was frozen at this Division review.');transaction_history_event($pdo,$actor,$t['id'],'checked',$before,$after,$historyRemarks);audit_event($actor,'check-transaction',$t['id'],['remarks'=>$remarks,'hash'=>$submissionHash,'legacy_snapshot_frozen'=>$legacy],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'mode'=>'initial']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        if($t['status']==='checked'){
            if(pending_deletion_request_row($t['id']))throw new RuntimeException('This checked transaction has a pending cancellation request. Approve or reject that request before approving any edit.');
            $pending=pending_edit_row($t['id']);if(!$pending)throw new RuntimeException('This transaction is already checked and has no pending school edit.');assert_transaction_period_writable('school',(string)$t['school_id'],(string)$pending['date']);$pendingExpected=(int)($b['pending_version']??0);if($pendingExpected!==$pending['version'])throw new RuntimeException('Pending changes were updated. Reload before approving.');$temp=$t;$temp['supplier_id']=$pending['supplier_id'];$temp['transaction_type']=transaction_type_from_record($pending);$temp['source_fund_id']=$pending['source_fund_id'];$temp['date']=$pending['date'];$temp['gross']=$pending['gross'];$legacy=!is_array($pending['snapshot']??null);$p=$legacy?preview_transaction($temp,$actor):frozen_snapshot_result($pending['snapshot'],$pending['snapshot_hash']??null,'Pending edit');assert_snapshot_matches_transaction($p['snapshot'],$temp,'Pending edit');$submissionHash=$pending['snapshot_hash']??$p['hash'];$submittedAt=$pending['submitted_at']??$now;$submittedBy=$pending['submitted_by']??$t['created_by'];$snap=json_encode_safe(array_merge($p['snapshot'],['checked_at'=>$now,'checked_by'=>$actor['user_id'],'check_remarks'=>$remarks]));$before=transaction_history_state($t);$after=['transaction_scope'=>'school','school_id'=>$t['school_id'],'supplier_id'=>$pending['supplier_id'],'transaction_type'=>transaction_type_from_record($pending),'source_fund_id'=>$pending['source_fund_id'],'date'=>$pending['date'],'gross'=>$pending['gross'],'status'=>'checked','snapshot'=>json_decode_assoc($snap),'submission_hash'=>$submissionHash,'submitted_at'=>$submittedAt,'submitted_by'=>$submittedBy,'checked_at'=>$now,'checked_by'=>$actor['user_id'],'check_remarks'=>$remarks];$pdo->beginTransaction();
            try{$approvedType=transaction_type_from_record($pending);$stmt=$pdo->prepare("UPDATE transactions SET supplier_id=?,transaction_type=?,source_fund_id=?,date=?,gross=?,snapshot=?,checked_snapshot_hash=?,submission_hash=?,submitted_at=?,submitted_by=?,finalized_at=?,finalized_by=?,version=version+1 WHERE id=? AND status='checked' AND version=?");$stmt->execute([$pending['supplier_id'],$approvedType,$pending['source_fund_id'],$pending['date'],$pending['gross'],$snap,hash('sha256',$snap),$submissionHash,$submittedAt,$submittedBy,$now,$actor['user_id'],$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload before approving.');$del=$pdo->prepare('DELETE FROM transaction_pending_edits WHERE transaction_id=? AND version=?');$del->execute([$t['id'],$pending['version']]);if($del->rowCount()!==1)throw new RuntimeException('Pending changes were updated. Reload before approving.');$historyRemarks=$remarks?:null;if($legacy)$historyRemarks=trim(($historyRemarks?$historyRemarks.' ':'').'Legacy pre-v0.4.22 pending edit had no frozen snapshot; its current calculation was frozen at approval.');transaction_history_event($pdo,$actor,$t['id'],'checked_edit_approved',$before,$after,$historyRemarks);audit_event($actor,'approve-checked-edit',$t['id'],['remarks'=>$remarks,'hash'=>$submissionHash,'legacy_snapshot_frozen'=>$legacy],$pdo);$pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'mode'=>'edit_approval']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        throw new RuntimeException('Only a Division-level Draft, a submitted school transaction, or a checked school transaction with pending edits may be checked.');
    }

    if(in_array($action,['cancelDraft','deleteDraft'],true)){
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));
        if($t['status']!=='draft')throw new RuntimeException('Only an unsubmitted Draft can be cancelled directly.');
        $expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Draft changed. Reload before cancelling it.');
        $scope=transaction_scope_from_record($t);assert_transaction_period_writable($scope,$scope==='school'?(string)$t['school_id']:null,(string)$t['date']);
        if($scope==='school'){
            if($actor['role']!=='school_user'||(string)$t['created_by']!==(string)$actor['user_id'])throw new RuntimeException('Only the School User who originated this Draft may cancel it.');
            $q=$pdo->prepare('SELECT transaction_id FROM transaction_submitted_backups WHERE transaction_id=?');$q->execute([$t['id']]);
            if($q->fetch())throw new RuntimeException('This Draft is a revision of an already Submitted transaction. Use Discard Draft to restore the previous Submitted version instead of cancelling it.');
        }else{
            require_admin($actor);
        }
        $now=now_iso();$reason=$scope==='division'?'Division-level Draft cancelled before checking.':'School Draft cancelled before submission.';
        $before=transaction_history_state($t);$after=$before;$after['status']='deleted';$after['deletion_reason']=$reason;$after['deleted_at']=$now;$after['deleted_by']=$actor['user_id'];
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("UPDATE transactions SET status='deleted',deletion_reason=?,deleted_at=?,deleted_by=?,version=version+1 WHERE id=? AND status='draft' AND version=?");
            $stmt->execute([$reason,$now,$actor['user_id'],$t['id'],$t['version']]);
            if($stmt->rowCount()!==1)throw new RuntimeException('Draft changed. Reload before cancelling it.');
            transaction_history_event($pdo,$actor,$t['id'],'draft_cancelled',$before,$after,$reason.' The soft-cancelled record remains available through the Cancelled filter for audit purposes.');
            audit_event($actor,'cancel-draft',$t['id'],['transaction_scope'=>$scope,'reason'=>$reason],$pdo);
            $pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'deleted','cancelled'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if(in_array($action,['requestCancellation','requestDeletion'],true)){
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));
        $scope=transaction_scope_from_record($t);
        if($t['status']!=='checked')throw new RuntimeException('Only a Checked transaction can be submitted for cancellation approval.');assert_transaction_period_writable($scope,$scope==='school'?(string)$t['school_id']:null,(string)$t['date']);
        $expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Transaction changed. Reload before requesting cancellation.');
        if($scope==='school'){
            if($actor['role']!=='school_user'||(string)$t['created_by']!==(string)$actor['user_id'])throw new RuntimeException('Only the School User who originated this Checked transaction may request cancellation.');
            if(pending_edit_row($t['id']))throw new RuntimeException('Cancel the pending edit before requesting cancellation of this Checked transaction.');
        }else{
            require_admin($actor);
        }
        if($existingRequest=pending_deletion_request_row($t['id']))respond(['ok'=>true,'id'=>$t['id'],'pending'=>true,'request_id'=>$existingRequest['id']]);
        $reason=valid_text($b['reason']??null,'Cancellation reason',500);$now=now_iso();$requestId=uuid();$state=transaction_history_state($t);
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("INSERT INTO transaction_deletion_requests(id,transaction_id,requested_by,reason,requested_at,status) VALUES(?,?,?,?,?,'pending')");
            $stmt->execute([$requestId,$t['id'],$actor['user_id'],$reason,$now]);
            $originLabel=$scope==='division'?'Division':'School';
            transaction_history_event($pdo,$actor,$t['id'],'cancellation_requested',$state,$state,$originLabel.' cancellation reason: '.$reason);
            audit_event($actor,'request-transaction-cancellation',$t['id'],['request_id'=>$requestId,'transaction_scope'=>$scope,'reason'=>$reason],$pdo);
            $pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'pending'=>true,'request_id'=>$requestId]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if(in_array($action,['approveCancellation','approveDeletion'],true)){
        require_admin($actor);
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));
        $scope=transaction_scope_from_record($t);
        if($t['status']!=='checked')throw new RuntimeException('Only a Checked transaction with a pending cancellation request can be approved.');assert_transaction_period_writable($scope,$scope==='school'?(string)$t['school_id']:null,(string)$t['date']);
        $expected=(int)($b['version']??0);if($expected!==$t['version'])throw new RuntimeException('Transaction changed. Reload before approving cancellation.');
        if(pending_edit_row($t['id']))throw new RuntimeException('This transaction has a pending edit. Resolve the edit before approving cancellation.');
        $request=pending_deletion_request_row($t['id']);if(!$request)throw new RuntimeException('No pending cancellation request was found.');
        if($scope==='division'&&(string)$request['requested_by']===(string)$actor['user_id'])throw new RuntimeException('A Division-level cancellation request must be approved or rejected by a different Division user.');
        $reviewRemarks=trim((string)($b['remarks']??''));if(mb_strlen($reviewRemarks)>500)throw new RuntimeException('Review remarks may contain at most 500 characters.');
        $now=now_iso();$reason=(string)$request['reason'];$before=transaction_history_state($t);$after=$before;$after['status']='deleted';$after['deletion_reason']=$reason;$after['deleted_at']=$now;$after['deleted_by']=$actor['user_id'];
        $originLabel=$scope==='division'?'Division':'School';$historyRemarks='Cancellation approved by Division. '.$originLabel.' reason: '.$reason.($reviewRemarks!==''?' Division remarks: '.$reviewRemarks:'');
        $pdo->beginTransaction();
        try{
            $up=$pdo->prepare("UPDATE transactions SET status='deleted',deletion_reason=?,deleted_at=?,deleted_by=?,version=version+1 WHERE id=? AND status='checked' AND version=?");
            $up->execute([$reason,$now,$actor['user_id'],$t['id'],$t['version']]);if($up->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload before approving cancellation.');
            $review=$pdo->prepare("UPDATE transaction_deletion_requests SET status='approved',reviewed_by=?,reviewed_at=?,review_remarks=? WHERE id=? AND status='pending'");
            $review->execute([$actor['user_id'],$now,$reviewRemarks?:null,$request['id']]);if($review->rowCount()!==1)throw new RuntimeException('Cancellation request changed. Reload before approving it.');
            transaction_history_event($pdo,$actor,$t['id'],'cancellation_approved',$before,$after,$historyRemarks);
            audit_event($actor,'approve-transaction-cancellation',$t['id'],['request_id'=>$request['id'],'transaction_scope'=>$scope,'requested_by'=>$request['requested_by'],'reason'=>$reason,'review_remarks'=>$reviewRemarks?:null],$pdo);
            $pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'deleted','cancelled'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if(in_array($action,['rejectCancellation','rejectDeletion'],true)){
        require_admin($actor);
        $t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));
        $scope=transaction_scope_from_record($t);
        if($t['status']!=='checked')throw new RuntimeException('Only a Checked transaction with a pending cancellation request can be reviewed.');assert_transaction_period_writable($scope,$scope==='school'?(string)$t['school_id']:null,(string)$t['date']);
        $request=pending_deletion_request_row($t['id']);if(!$request)throw new RuntimeException('No pending cancellation request was found.');
        if($scope==='division'&&(string)$request['requested_by']===(string)$actor['user_id'])throw new RuntimeException('A Division-level cancellation request must be approved or rejected by a different Division user.');
        $remarks=valid_text($b['remarks']??null,'Rejection remarks',500);$now=now_iso();$state=transaction_history_state($t);$originLabel=$scope==='division'?'Division':'School';
        $pdo->beginTransaction();
        try{
            $review=$pdo->prepare("UPDATE transaction_deletion_requests SET status='rejected',reviewed_by=?,reviewed_at=?,review_remarks=? WHERE id=? AND status='pending'");
            $review->execute([$actor['user_id'],$now,$remarks,$request['id']]);if($review->rowCount()!==1)throw new RuntimeException('Cancellation request changed. Reload before rejecting it.');
            transaction_history_event($pdo,$actor,$t['id'],'cancellation_rejected',$state,$state,'Cancellation request rejected by Division. '.$originLabel.' reason: '.$request['reason'].' Division remarks: '.$remarks);
            audit_event($actor,'reject-transaction-cancellation',$t['id'],['request_id'=>$request['id'],'transaction_scope'=>$scope,'requested_by'=>$request['requested_by'],'reason'=>$request['reason'],'review_remarks'=>$remarks],$pdo);
            $pdo->commit();respond(['ok'=>true,'id'=>$t['id'],'status'=>'checked']);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if($action==='deleteDivisionChecked'){
        throw new RuntimeException('Direct deletion of a Checked Division-level transaction is no longer allowed. Submit a cancellation request for review by another Division user.');
    }

    if($action==='finalize'){throw new RuntimeException('Transactions now require Division checking. Use the Check transaction action.');}

    if($action==='void'){
        require_admin($actor);$t=own_transaction($actor,valid_text($b['id']??null,'Transaction'));if($t['status']==='void')respond(['ok'=>true]);if($t['status']!=='checked')throw new RuntimeException('Only checked transactions may be voided.');$voidScope=transaction_scope_from_record($t);assert_transaction_period_writable($voidScope,$voidScope==='school'?(string)$t['school_id']:null,(string)$t['date']);if(pending_deletion_request_row($t['id']))throw new RuntimeException('This transaction has a pending cancellation request. Approve or reject that request before voiding the transaction.');$reason=valid_text($b['reason']??null,'Void reason',500);$now=now_iso();$before=transaction_history_state($t);$pending=pending_edit_row($t['id']);$pdo->beginTransaction();
        try{if($pending){$pendingState=pending_history_state($t,$pending);$del=$pdo->prepare('DELETE FROM transaction_pending_edits WHERE transaction_id=? AND version=?');$del->execute([$t['id'],$pending['version']]);if($del->rowCount()!==1)throw new RuntimeException('Pending changes were updated. Reload first.');transaction_history_event($pdo,$actor,$t['id'],'edit_request_discarded',$pendingState,$before,'Pending school edit discarded because the checked transaction was voided.');}$stmt=$pdo->prepare("UPDATE transactions SET status='void',void_reason=?,voided_at=?,voided_by=?,version=version+1 WHERE id=? AND status='checked' AND version=?");$stmt->execute([$reason,$now,$actor['user_id'],$t['id'],$t['version']]);if($stmt->rowCount()!==1)throw new RuntimeException('Transaction changed. Reload first.');$after=$before;$after['status']='void';transaction_history_event($pdo,$actor,$t['id'],'void',$before,$after,$reason);audit_event($actor,'void',$t['id'],['reason'=>$reason],$pdo);$pdo->commit();respond(['ok'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    respond(['error'=>'Unknown action.'],400);
} catch(Throwable $e){fail($e);} 
