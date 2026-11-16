<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if(!is_installed()){header('Location: install.php');exit;}
send_no_cache_headers();
if(current_member()){header('Location: index.php');exit;}
$error='';$submitted=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $identifier=strtolower(trim((string)($_POST['identifier']??'')));
        if($identifier===''||strlen($identifier)>180) throw new RuntimeException('Enter your username or recovery email.');
        $stmt=db()->prepare('SELECT id,role FROM members WHERE active=1 AND (LOWER(username)=? OR LOWER(email)=?) LIMIT 1');
        $stmt->execute([$identifier,$identifier]);
        if(($member=$stmt->fetch()) && ($member['role']??'')!=='project_owner'){
            $now=now_iso();$expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d\TH:i:s.000\Z');
            $expire=db()->prepare("UPDATE password_reset_requests SET status='expired' WHERE member_id=? AND status='pending' AND expires_at IS NOT NULL AND expires_at<?");$expire->execute([$member['id'],$now]);
            $pending=db()->prepare("SELECT id FROM password_reset_requests WHERE member_id=? AND status='pending' AND (expires_at IS NULL OR expires_at>=?) LIMIT 1");
            $pending->execute([$member['id'],$now]);
            if(!$pending->fetch()){
                $insert=db()->prepare("INSERT INTO password_reset_requests(id,member_id,status,requested_at,expires_at) VALUES(?,?,'pending',?,?)");
                $insert->execute([uuid(),$member['id'],$now,$expires]);
            }
        }
        $submitted=true;
    }catch(Throwable $e){error_log('NonIUS forgot password: '.$e->getMessage());$error='Unable to submit the request right now. Please try again later.';}
}
$division=master_row('division','division');
$logoPath='';
if(!empty($division['logo_path'])){
    $logoPath='logo.php';
    if(!empty($division['logo_updated_at'])) $logoPath.='?v='.rawurlencode((string)$division['logo_updated_at']);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot password · Non-IUS Withholding Tax Monitoring</title><?php if($logoPath): ?><link rel="icon" href="<?=htmlspecialchars($logoPath)?>"><link rel="shortcut icon" href="<?=htmlspecialchars($logoPath)?>"><link rel="apple-touch-icon" href="<?=htmlspecialchars($logoPath)?>"><?php endif; ?><link rel="stylesheet" href="assets/style.css?v=<?=urlencode(APP_VERSION)?>"></head><body class="auth-bg"><main class="auth-card"><div class="auth-brand"><?php if($logoPath): ?><img class="auth-logo" src="<?=htmlspecialchars($logoPath)?>" alt="Division logo"><?php else: ?><div class="brand-mark auth-mark">WT</div><?php endif; ?><h1>Forgot password</h1><p class="muted">Request an administrator-approved password reset.</p></div><?php if($submitted): ?><div class="notice success">If the username or email matches an eligible active account, the reset request is now pending administrator approval. After approval, use the temporary password provided by your administrator and you will be required to change it on sign-in.</div><div class="auth-links centered"><a href="login.php">Back to sign in</a></div><?php else: ?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf_token())?>"><label>Username or recovery email<input name="identifier" type="text" autocomplete="username" required autofocus></label><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><button class="primary" type="submit">Request password reset</button><div class="auth-links centered"><a href="login.php">Back to sign in</a></div></form><?php endif; ?></main></body></html>
