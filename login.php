<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/login_slideshow.php';
if(!is_installed()){header('Location: install.php');exit;}
send_no_cache_headers();
if($existing=current_member()){header('Location: '.((int)($existing['must_change_password']??0)===1?'first-login.php':'index.php'));exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $member=login_member((string)($_POST['identifier']??''),(string)($_POST['password']??''));
        header('Location: '.((int)($member['must_change_password']??0)===1?'first-login.php':'index.php'));
        exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$division=master_row('division','division');
$slideshow=login_slideshow_urls(ROOT_PATH);
$logoPath='';
if(!empty($division['logo_path'])){
    $logoPath='logo.php';
    if(!empty($division['logo_updated_at'])) $logoPath.='?v='.rawurlencode((string)$division['logo_updated_at']);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Non-IUS Withholding Tax Monitoring</title><?php if($logoPath): ?><link rel="icon" href="<?=htmlspecialchars($logoPath)?>"><link rel="shortcut icon" href="<?=htmlspecialchars($logoPath)?>"><link rel="apple-touch-icon" href="<?=htmlspecialchars($logoPath)?>"><?php endif; ?><link rel="stylesheet" href="assets/style.css?v=<?=urlencode(APP_VERSION)?>"></head><body class="auth-bg<?= $slideshow ? ' slideshow-enabled' : '' ?>"><div class="login-backdrop" aria-hidden="true"><div class="login-photo login-photo-a"></div><div class="login-photo login-photo-b"></div></div><main class="auth-card"><div class="auth-brand"><?php if($logoPath): ?><img class="auth-logo" src="<?=htmlspecialchars($logoPath)?>" alt="Division logo"><?php else: ?><div class="brand-mark auth-mark">WT</div><?php endif; ?><h1>Non-IUS sign in</h1><p class="muted"><?=htmlspecialchars($division['name'] ?? 'Withholding Tax Monitoring')?></p></div><form method="post" class="stack"><label>Username or email<input name="identifier" type="text" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><div class="auth-links"><a href="forgot-password.php">Forgot password?</a></div><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><button class="primary" type="submit">Sign in</button></form></main><?php if($slideshow): ?><script type="application/json" id="loginSlideSources"><?=json_encode($slideshow,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)?></script><script defer src="assets/login-slideshow.js?v=<?=urlencode(APP_VERSION)?>"></script><?php endif; ?></body></html>
