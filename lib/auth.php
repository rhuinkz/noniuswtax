<?php
declare(strict_types=1);

function login_throttle_key(string $identifier): string {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    return hash('sha256', strtolower(trim($identifier)).'|'.$ip);
}
function login_throttle_check(string $identifier): void {
    $key=login_throttle_key($identifier);$now=now_iso();
    $stmt=db()->prepare('SELECT attempts,locked_until FROM login_throttle WHERE throttle_key=?');$stmt->execute([$key]);$r=$stmt->fetch();
    if($r && !empty($r['locked_until']) && strcmp((string)$r['locked_until'],$now)>0) throw new RuntimeException('Too many sign-in attempts. Please wait 15 minutes and try again.');
}
function login_throttle_fail(string $identifier): void {
    $key=login_throttle_key($identifier);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$window=$now->modify('-15 minutes')->format('Y-m-d\TH:i:s.000\Z');$nowIso=now_iso();
    $pdo=db();$stmt=$pdo->prepare('SELECT attempts,window_started FROM login_throttle WHERE throttle_key=?');$stmt->execute([$key]);$r=$stmt->fetch();
    if(!$r || strcmp((string)$r['window_started'],$window)<0){$attempts=1;$started=$nowIso;}else{$attempts=(int)$r['attempts']+1;$started=(string)$r['window_started'];}
    $locked=$attempts>=5?$now->modify('+15 minutes')->format('Y-m-d\TH:i:s.000\Z'):null;
    $up=$pdo->prepare('INSERT INTO login_throttle(throttle_key,attempts,window_started,locked_until,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),window_started=VALUES(window_started),locked_until=VALUES(locked_until),updated_at=VALUES(updated_at)');
    $up->execute([$key,$attempts,$started,$locked,$nowIso]);
}
function login_throttle_clear(string $identifier): void {
    $stmt=db()->prepare('DELETE FROM login_throttle WHERE throttle_key=?');$stmt->execute([login_throttle_key($identifier)]);
}

function login_member(string $identifier, string $password): array {
    $identifier = strtolower(trim($identifier));
    if ($identifier === '') throw new RuntimeException('Enter your username or email.');
    login_throttle_check($identifier);
    $stmt = db()->prepare('SELECT * FROM members WHERE active=1 AND (LOWER(username)=? OR LOWER(email)=?) LIMIT 1');
    $stmt->execute([$identifier,$identifier]);
    $member = $stmt->fetch();
    if (!$member || !$member['password_hash'] || !password_verify($password, $member['password_hash'])) {
        login_throttle_fail($identifier);
        throw new RuntimeException('Invalid username/email or password.');
    }
    if ($member['role'] === 'school_user') {
        $s = db()->prepare('SELECT active FROM schools WHERE id=?');
        $s->execute([$member['school_id']]);
        $school = $s->fetch();
        if (!$school || !(int)$school['active']) throw new RuntimeException('Your school is inactive. Contact the Division administrator.');
    }
    login_throttle_clear($identifier);
    session_regenerate_id(true);
    $_SESSION['member_id'] = $member['id'];
    $_SESSION['session_version'] = (int)($member['session_version'] ?? 1);
    $_SESSION['session_started_at'] = time();
    $_SESSION['session_last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $member;
}

function logout_member(): void {
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function current_member(): ?array {
    start_secure_session();
    $id = $_SESSION['member_id'] ?? null;
    if (!$id) return null;
    $stmt = db()->prepare('SELECT id,username,username_changed_once,email,user_id,role,school_id,active,must_change_password,session_version FROM members WHERE id=? AND active=1');
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if(!$m){$_SESSION=[];return null;}
    if((int)($_SESSION['session_version']??0)!==(int)($m['session_version']??1)){$_SESSION=[];session_regenerate_id(true);return null;}
    if(($m['role']??'')==='school_user'){
        $s=db()->prepare('SELECT active FROM schools WHERE id=?');$s->execute([$m['school_id']]);$school=$s->fetch();
        if(!$school||!(int)$school['active']){$_SESSION=[];session_regenerate_id(true);return null;}
    }
    return $m;
}

function require_member(): array {
    $m = current_member();
    if (!$m) throw new RuntimeException('Please sign in to continue.');
    if ((int)($m['must_change_password'] ?? 0) === 1) throw new RuntimeException('Complete your first sign-in setup before continuing.');
    return $m;
}

function is_project_owner(array $member): bool { return ($member['role'] ?? '') === 'project_owner'; }
function is_division_admin(array $member): bool { return ($member['role'] ?? '') === 'division_admin'; }
function is_admin_or_owner(array $member): bool { return is_project_owner($member) || is_division_admin($member); }
function require_admin(array $member): void { if (!is_admin_or_owner($member)) throw new RuntimeException('Division administrator or Project Owner access is required.'); }
function require_owner(array $member): void { if (!is_project_owner($member)) throw new RuntimeException('Project Owner access is required.'); }

function require_page_login(): array {
    $m = current_member();
    if (!$m) { header('Location: ' . app_url('login.php')); exit; }
    if ((int)($m['must_change_password'] ?? 0) === 1) { header('Location: ' . app_url('first-login.php')); exit; }
    return $m;
}
