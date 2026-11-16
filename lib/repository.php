<?php
declare(strict_types=1);

function unpack_row(?array $r): ?array {
    if (!$r) return null;
    $data = json_decode_assoc($r['data'] ?? '{}');
    $data['id'] = $r['id'];
    $data['name'] = $r['name'];
    if (array_key_exists('active', $r)) $data['active'] = (int)$r['active'];
    $data['version'] = (int)($r['version'] ?? 1);
    return $data;
}
function master_row(string $table, string $id): ?array {
    if (!in_array($table, ['schools','suppliers','atcs','division','source_funds'], true)) throw new RuntimeException('Unknown record type.');
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE id=?"); $stmt->execute([$id]);
    return unpack_row($stmt->fetch() ?: null);
}
function master_all(string $table): array {
    if (!in_array($table, ['schools','suppliers','atcs','source_funds'], true)) throw new RuntimeException('Unknown record type.');
    $rows = db()->query("SELECT * FROM {$table} ORDER BY name")->fetchAll();
    return array_map(fn($r)=>unpack_row($r), $rows);
}
function audit_event(array $actor, string $action, string $entity, mixed $details, ?PDO $pdo=null): void {
    $pdo ??= db();
    $stmt = $pdo->prepare('INSERT INTO audit(id,actor,action,entity,details,at) VALUES(?,?,?,?,?,?)');
    $stmt->execute([uuid(),$actor['user_id'] ?? $actor['id'],$action,$entity,json_encode_safe($details),now_iso()]);
}

function transaction_history_state(array $t): array {
    return [
        'reference' => (string)($t['reference'] ?? ''),
        'transaction_scope' => transaction_scope_from_record($t),
        'school_id' => (string)($t['school_id'] ?? ''),
        'supplier_id' => (string)($t['supplier_id'] ?? ''),
        'transaction_type' => transaction_type_from_record($t),
        'source_fund_id' => (string)($t['source_fund_id'] ?? ''),
        'date' => (string)($t['date'] ?? ''),
        'gross' => (int)($t['gross'] ?? 0),
        'status' => (string)($t['status'] ?? ''),
        'snapshot' => is_array($t['snapshot'] ?? null) ? $t['snapshot'] : (($t['snapshot'] ?? null) ? json_decode_assoc((string)$t['snapshot']) : null),
        'submission_hash' => $t['submission_hash'] ?? null,
        'submitted_at' => $t['submitted_at'] ?? null,
        'submitted_by' => $t['submitted_by'] ?? null,
        'checked_at' => $t['finalized_at'] ?? null,
        'checked_by' => $t['finalized_by'] ?? null,
        'check_remarks' => is_array($t['snapshot'] ?? null) ? ($t['snapshot']['check_remarks'] ?? null) : null,
        'deletion_reason' => $t['deletion_reason'] ?? null,
        'deleted_at' => $t['deleted_at'] ?? null,
        'deleted_by' => $t['deleted_by'] ?? null,
    ];
}

function frozen_snapshot_result(?array $snapshot, ?string $expectedHash=null, string $label='Transaction'): ?array {
    if (!$snapshot) return null;
    $json=json_encode_safe($snapshot);
    $hash=hash('sha256',$json);
    if ($expectedHash && !hash_equals(strtolower($expectedHash), strtolower($hash))) {
        throw new RuntimeException($label.' calculation snapshot failed its integrity check. Contact the Project Owner before continuing.');
    }
    return ['snapshot'=>$snapshot,'hash'=>$hash];
}

function transaction_scope_from_record(array $t): string {
    $scope=trim((string)($t['transaction_scope']??''));
    if(in_array($scope,['school','division'],true)) return $scope;
    return empty($t['school_id'])?'division':'school';
}

function assert_snapshot_matches_transaction(array $snapshot, array $t, string $label='Transaction'): void {
    $scope=transaction_scope_from_record($t);
    $snapshotScope=trim((string)($snapshot['transaction_scope']??''));
    if($snapshotScope==='') $snapshotScope=!empty($snapshot['school']['id'])?'school':'division';
    if($snapshotScope!==$scope) throw new RuntimeException($label.' snapshot does not match the transaction origin. Contact the Project Owner before continuing.');
    $checks=[
        [(string)($snapshot['transaction_id']??''),(string)($t['id']??'')],
        [(string)($snapshot['supplier']['id']??''),(string)($t['supplier_id']??'')],
        [(string)($snapshot['source_fund']['id']??''),(string)($t['source_fund_id']??'')],
        [(string)($snapshot['date']??''),(string)($t['date']??'')],
        [(string)(int)($snapshot['amounts']['gross']??-1),(string)(int)($t['gross']??-2)],
    ];
    if($scope==='school') $checks[]=[(string)($snapshot['school']['id']??''),(string)($t['school_id']??'')];
    $snapshotReference=trim((string)($snapshot['transaction_reference']??''));
    $savedReference=trim((string)($t['reference']??''));
    if($snapshotReference!==''&&$savedReference!==''&&$snapshotReference!==$savedReference) throw new RuntimeException($label.' snapshot does not match the permanent transaction reference. Contact the Project Owner before continuing.');
    $savedType=trim((string)($t['transaction_type']??''));
    if($savedType!==''){
        $snapshotType=trim((string)($snapshot['transaction_type']??($snapshot['supplier']['transaction_type']??'')));
        if($snapshotType!==$savedType) throw new RuntimeException($label.' snapshot does not match the saved transaction type. Contact the Project Owner before continuing.');
    }
    foreach($checks as [$a,$b]) if($a!==$b) throw new RuntimeException($label.' snapshot does not match the saved transaction values. Contact the Project Owner before continuing.');
}

function transaction_history_event(PDO $pdo, array $actor, string $transactionId, string $eventType, ?array $before, ?array $after, ?string $remarks=null): void {
    $q=$pdo->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM transaction_history WHERE transaction_id=?');
    $q->execute([$transactionId]);
    $revision=(int)$q->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO transaction_history(id,transaction_id,revision,event_type,actor,actor_role,before_data,after_data,remarks,at) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        uuid(), $transactionId, $revision, $eventType,
        $actor['user_id'] ?? $actor['id'], $actor['role'] ?? 'unknown',
        $before === null ? null : json_encode_safe($before),
        $after === null ? null : json_encode_safe($after),
        $remarks, now_iso(),
    ]);
}

function pending_edit_row(string $transactionId): ?array {
    $stmt=db()->prepare('SELECT * FROM transaction_pending_edits WHERE transaction_id=?');
    $stmt->execute([$transactionId]);$r=$stmt->fetch();
    if(!$r)return null;
    $r['gross']=(int)$r['gross'];$r['version']=(int)$r['version'];
    if(array_key_exists('snapshot',$r)) $r['snapshot']=$r['snapshot']?json_decode_assoc((string)$r['snapshot']):null;
    return $r;
}

function pending_deletion_request_row(string $transactionId): ?array {
    $stmt=db()->prepare("SELECT * FROM transaction_deletion_requests WHERE transaction_id=? AND status='pending' ORDER BY requested_at DESC LIMIT 1");
    $stmt->execute([$transactionId]);
    $r=$stmt->fetch();
    return $r ?: null;
}

function pending_history_state(array $t, array $p): array {
    return [
        'reference'=>(string)($t['reference']??''),
        'transaction_scope'=>transaction_scope_from_record($t),
        'school_id'=>(string)($t['school_id']??''),
        'supplier_id'=>(string)$p['supplier_id'],
        'transaction_type'=>transaction_type_from_record($p),
        'source_fund_id'=>(string)$p['source_fund_id'],
        'date'=>(string)$p['date'],
        'gross'=>(int)$p['gross'],
        'status'=>'pending_approval',
        'snapshot'=>is_array($p['snapshot']??null)?$p['snapshot']:null,
        'submission_hash'=>$p['snapshot_hash']??null,
        'submitted_at'=>$p['submitted_at']??null,
        'submitted_by'=>$p['submitted_by']??null,
        'checked_at'=>$t['finalized_at']??null,
        'checked_by'=>$t['finalized_by']??null,
        'check_remarks'=>null,
    ];
}

function transaction_history_for_actor(array $actor): array {
    if (is_admin_or_owner($actor)) {
        $stmt = db()->query("SELECT h.*,m.email AS actor_email FROM transaction_history h LEFT JOIN members m ON m.user_id=h.actor ORDER BY h.at DESC");
    } else {
        $stmt = db()->prepare("SELECT h.*,m.email AS actor_email FROM transaction_history h JOIN transactions t ON t.id=h.transaction_id LEFT JOIN members m ON m.user_id=h.actor WHERE t.transaction_scope='school' AND t.school_id=? ORDER BY h.at DESC");
        $stmt->execute([$actor['school_id']]);
    }
    $rows=[];
    foreach($stmt->fetchAll() as $r){
        $r['revision']=(int)$r['revision'];
        $r['before_data']=$r['before_data']?json_decode_assoc($r['before_data']):null;
        $r['after_data']=$r['after_data']?json_decode_assoc($r['after_data']):null;
        $rows[]=$r;
    }
    return $rows;
}
function transaction_history_for_id_actor(array $actor, string $transactionId): array {
    own_transaction($actor,$transactionId);
    $stmt=db()->prepare("SELECT h.*,m.email AS actor_email FROM transaction_history h LEFT JOIN members m ON m.user_id=h.actor WHERE h.transaction_id=? ORDER BY h.revision DESC");
    $stmt->execute([$transactionId]);
    $rows=[];
    foreach($stmt->fetchAll() as $r){
        $r['revision']=(int)$r['revision'];
        $r['before_data']=$r['before_data']?json_decode_assoc($r['before_data']):null;
        $r['after_data']=$r['after_data']?json_decode_assoc($r['after_data']):null;
        $rows[]=$r;
    }
    return $rows;
}

function own_transaction(array $actor, string $id): array {
    $stmt = db()->prepare('SELECT * FROM transactions WHERE id=?'); $stmt->execute([$id]); $t=$stmt->fetch();
    if (!$t || (!is_admin_or_owner($actor) && (transaction_scope_from_record($t)!=='school' || (string)$t['school_id'] !== (string)$actor['school_id']))) throw new RuntimeException('Record not found or access denied.');
    if ($t['snapshot']) $t['snapshot'] = json_decode_assoc($t['snapshot']);
    $t['gross'] = (int)$t['gross']; $t['version']=(int)$t['version'];
    return $t;
}
function valid_text(mixed $v, string $label, int $max=160): string {
    if (!is_string($v)) throw new RuntimeException("{$label} is required.");
    $v=trim($v); if ($v==='' || mb_strlen($v)>$max) throw new RuntimeException("{$label} is required (maximum {$max} characters).");
    return $v;
}
function valid_date(mixed $v): string {
    $s=valid_text($v,'Transaction date',10); $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,new DateTimeZone('UTC'));
    if(!$d||$d->format('Y-m-d')!==$s) throw new RuntimeException('Enter a valid date.'); return $s;
}
function enum_value(mixed $v, array $allowed, string $label): string {
    if(!is_string($v)||!in_array($v,$allowed,true)) throw new RuntimeException("Invalid {$label}."); return $v;
}
function normalize_username(mixed $v): string {
    $u=strtolower(trim(valid_text($v,'Username',40)));
    if(!preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/',$u)) throw new RuntimeException('Username must be 3-40 characters using letters, numbers, dot, underscore or hyphen.');
    return $u;
}
function normalize_tin(mixed $v): string {
    $digits=preg_replace('/\D+/','',valid_text($v,'TIN',30));
    if(!preg_match('/^(\d{9}|\d{12}|\d{14})$/',$digits)) throw new RuntimeException('TIN must contain 9, 12 or 14 digits.');
    return str_pad($digits,14,'0');
}
function supplier_transaction_profiles(array $supplier): array {
    $profiles=[];
    $raw=$supplier['transaction_profiles']??null;
    if(is_array($raw)){
        foreach($raw as $profile){
            if(!is_array($profile)) continue;
            $type=trim((string)($profile['transaction_type']??''));
            $businessAtc=trim((string)($profile['business_atc']??''));
            $ewtAtc=trim((string)($profile['ewt_atc']??''));
            if($type===''||$businessAtc===''||$ewtAtc==='') continue;
            $profiles[]=['transaction_type'=>$type,'business_atc'=>$businessAtc,'ewt_atc'=>$ewtAtc];
        }
    }
    if(!$profiles){
        $type=trim((string)($supplier['transaction_type']??''));
        $businessAtc=trim((string)($supplier['business_atc']??''));
        $ewtAtc=trim((string)($supplier['ewt_atc']??''));
        if($type!==''&&$businessAtc!==''&&$ewtAtc!=='') $profiles[]=['transaction_type'=>$type,'business_atc'=>$businessAtc,'ewt_atc'=>$ewtAtc];
    }
    return $profiles;
}
function resolve_supplier_transaction_profile(array $supplier, ?string $transactionType=null, bool $requireExplicit=false): array {
    $profiles=supplier_transaction_profiles($supplier);
    if(!$profiles) throw new RuntimeException('This supplier has no configured transaction tax profile. Update the Supplier master first.');
    $requested=trim((string)$transactionType);
    if($requested===''){
        if(count($profiles)===1) return $profiles[0];
        if($requireExplicit) throw new RuntimeException('Select whether this supplier transaction is Goods, Services, or another configured transaction type.');
        $legacy=trim((string)($supplier['transaction_type']??''));
        foreach($profiles as $profile) if($profile['transaction_type']===$legacy) return $profile;
        return $profiles[0];
    }
    foreach($profiles as $profile) if($profile['transaction_type']===$requested) return $profile;
    throw new RuntimeException('The selected transaction type is not configured for this supplier. Reload the Supplier master and try again.');
}
function transaction_type_from_record(array $t, ?array $supplier=null): string {
    $saved=trim((string)($t['transaction_type']??''));
    if($saved!=='') return $saved;
    $snapshot=is_array($t['snapshot']??null)?$t['snapshot']:(($t['snapshot']??null)?json_decode_assoc((string)$t['snapshot']):null);
    $frozen=trim((string)($snapshot['transaction_type']??($snapshot['supplier']['transaction_type']??'')));
    if($frozen!=='') return $frozen;
    if($supplier){
        try{return (string)resolve_supplier_transaction_profile($supplier,null,false)['transaction_type'];}catch(Throwable){}
    }
    return '';
}

function atc_business_applies_to_supplier(array $atc, array $supplier): bool {
    $classification=trim((string)($atc['business_type']??'Both'));
    if($classification==='' || in_array($classification,['Both','All'],true)) return true;
    return $classification===(string)($supplier['business_type']??'');
}
function atc_transaction_applies_to_supplier(array $atc, array $supplier): bool {
    $classification=trim((string)($atc['transaction_type']??'All'));
    return $classification==='' || $classification==='All' || $classification===(string)($supplier['transaction_type']??'');
}
function atc_applies_to_supplier(array $atc, array $supplier): bool {
    return atc_business_applies_to_supplier($atc,$supplier) && atc_transaction_applies_to_supplier($atc,$supplier);
}

function preview_transaction(array $t, array $actor): array {
    $scope=transaction_scope_from_record($t);
    $supplier=master_row('suppliers',$t['supplier_id']);
    $division=master_row('division','division');
    $school=null;
    $sourceFund=master_row('source_funds',(string)($t['source_fund_id']??''));
    if(!$supplier || !(int)($supplier['active']??0)) throw new RuntimeException('Select an active supplier.');
    if(!$sourceFund || !(int)($sourceFund['active']??0)) throw new RuntimeException('Select an active Source of Fund.');
    if(!$division) throw new RuntimeException('Complete the Division profile first.');
    if($scope==='school'){
        $school=master_row('schools',(string)($t['school_id']??''));
        if(!$school || !(int)($school['active']??0)) throw new RuntimeException('Select an active school.');
        if(empty($school['representative'])||empty($school['position'])) throw new RuntimeException('Complete the school representative and position first.');
        $representative=['name'=>(string)$school['representative'],'position'=>(string)$school['position'],'tin'=>(string)($school['representative_tin']??''),'source'=>'school'];
    }else{
        if(!is_admin_or_owner($actor)) throw new RuntimeException('Only Division users may prepare Division-level transactions.');
        if(empty($division['representative'])||empty($division['designation'])||empty($division['representative_tin'])) throw new RuntimeException('Complete the Division representative, designation and representative TIN in Administration → Division Profile first.');
        $representative=['name'=>(string)$division['representative'],'position'=>(string)$division['designation'],'tin'=>(string)$division['representative_tin'],'source'=>'division'];
    }
    $profile=resolve_supplier_transaction_profile($supplier,transaction_type_from_record($t,$supplier),false);
    $supplierForTax=$supplier;$supplierForTax['transaction_type']=$profile['transaction_type'];$supplierForTax['business_atc']=$profile['business_atc'];$supplierForTax['ewt_atc']=$profile['ewt_atc'];
    $business=master_row('atcs',(string)$profile['business_atc']); $ewt=master_row('atcs',(string)$profile['ewt_atc']);
    foreach ([[$business,'VAT'],[$ewt,'EWT']] as [$r,$kind]) {
        if(!$r || !(int)($r['active']??0) || ($r['tax_type']??'')!==$kind) throw new RuntimeException('Supplier tax codes must be active and match their tax types.');
        if(!atc_applies_to_supplier($r,$supplierForTax)) throw new RuntimeException('ATC classification does not match this supplier transaction type.');
        if(($r['effective_from']??'9999-12-31')>$t['date'] || (!empty($r['effective_to']) && $r['effective_to']<$t['date'])) throw new RuntimeException('A tax code is outside its effective dates.');
    }
    $amounts=calculate_tax((int)$t['gross'],($supplier['vat_status']??'')==='VAT Registered',(float)$business['rate'],(float)$ewt['rate']);
    $snapshot=['transaction_scope'=>$scope,'supplier'=>$supplierForTax,'school'=>$school,'division'=>$division,'representative'=>$representative,'source_fund'=>$sourceFund,'transaction_type'=>$profile['transaction_type'],'business'=>$business,'ewt'=>$ewt,'amounts'=>$amounts,'date'=>$t['date'],'period'=>reporting_period($t['date']),'calculation_version'=>'loytaxcalc-v1','template_version'=>'bir2307-v1','transaction_id'=>$t['id'],'transaction_reference'=>$t['reference']??null];
    $json=json_encode_safe($snapshot); return ['snapshot'=>$snapshot,'hash'=>hash('sha256',$json)];
}
