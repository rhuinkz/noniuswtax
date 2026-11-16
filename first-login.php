<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if(!is_installed()){header('Location: install.php');exit;}
send_no_cache_headers();
$member=current_member();
if(!$member){header('Location: login.php');exit;}
if((int)($member['must_change_password']??0)!==1){header('Location: index.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $email=strtolower(trim((string)($_POST['email']??'')));
        if($email===''||strlen($email)>180||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid recovery email address.');
        $new=(string)($_POST['new_password']??'');
        $confirm=(string)($_POST['confirm_password']??'');
        if(strlen($new)<10) throw new RuntimeException('New password must contain at least 10 characters.');
        if($new!==$confirm) throw new RuntimeException('New password and confirmation do not match.');
        $passwordStmt=db()->prepare('SELECT password_hash FROM members WHERE id=? AND active=1');
        $passwordStmt->execute([$member['id']]);$currentHash=(string)($passwordStmt->fetchColumn()?:'');
        if($currentHash!==''&&password_verify($new,$currentHash)) throw new RuntimeException('Choose a password different from the temporary password.');
        $pdo=db();
        $collision=$pdo->prepare('SELECT id FROM members WHERE id<>? AND (LOWER(email)=? OR LOWER(username)=?) LIMIT 1');
        $collision->execute([$member['id'],$email,$email]);
        if($collision->fetch()) throw new RuntimeException('That email is already used by another account or matches another username.');
        $now=now_iso();
        $pdo->beginTransaction();
        try{
            $nextSessionVersion=(int)($member['session_version']??1)+1;
            $stmt=$pdo->prepare('UPDATE members SET email=?,password_hash=?,must_change_password=0,session_version=?,updated_at=? WHERE id=? AND active=1');
            $stmt->execute([$email,password_hash($new,PASSWORD_DEFAULT),$nextSessionVersion,$now,$member['id']]);
            if($stmt->rowCount()!==1) throw new RuntimeException('Account could not be updated.');
            audit_event($member,'complete-first-login',$member['id'],['username'=>$member['username'],'recovery_email'=>$email,'password_changed'=>true],$pdo);
            $pdo->commit();
            $_SESSION['session_version']=$nextSessionVersion;
            header('Location: index.php');
            exit;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$division=master_row('division','division');
$logoPath='';
if(!empty($division['logo_path'])){
    $logoPath='logo.php';
    if(!empty($division['logo_updated_at'])) $logoPath.='?v='.rawurlencode((string)$division['logo_updated_at']);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>First sign-in setup · Non-IUS Withholding Tax Monitoring</title><?php if($logoPath): ?><link rel="icon" href="<?=htmlspecialchars($logoPath)?>"><link rel="shortcut icon" href="<?=htmlspecialchars($logoPath)?>"><?php endif; ?><link rel="stylesheet" href="assets/style.css?v=<?=urlencode(APP_VERSION)?>"></head><body class="auth-bg"><main class="auth-card"><div class="auth-brand"><?php if($logoPath): ?><img class="auth-logo" src="<?=htmlspecialchars($logoPath)?>" alt="Division logo"><?php else: ?><div class="brand-mark auth-mark">WT</div><?php endif; ?><h1>Complete first sign-in</h1><p class="muted">Username: <strong><?=htmlspecialchars((string)$member['username'])?></strong></p></div><div class="notice warn">Your account is using a temporary password. Set your own password and recovery email before continuing.</div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf_token())?>"><label>Recovery email<input name="email" type="email" autocomplete="email" required value="<?=htmlspecialchars((string)($member['email']??''))?>" placeholder="name@example.com"></label><label>New password<input name="new_password" type="password" autocomplete="new-password" minlength="10" required></label><label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password" minlength="10" required></label><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><button class="primary" type="submit">Save and continue</button></form></main></body></html>
