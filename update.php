<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/update_history.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function update_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode_safe($data);
    exit;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new FilesystemIterator($dir) as $f) {
        $p = $f->getPathname();
        $f->isDir() ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function backup_code(string $dest): void {
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create update backup.');
    }
    $root = realpath(ROOT_PATH);
    if ($root === false) throw new RuntimeException('Application root could not be resolved.');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        $rel = str_replace('\\', '/', substr($p, strlen($root) + 1));
        if ($rel === 'config.local.php' || str_starts_with($rel, 'storage/') || str_starts_with($rel, 'assets/login-slides/')) continue;
        $zip->addFile($p, $rel);
    }
    if(!$zip->close())throw new RuntimeException('Could not finalize code backup ZIP.');
    // Site backup archives should not be readable by other hosting accounts.
    @chmod($dest, 0600);
    $check=new ZipArchive();if($check->open($dest)!==true)throw new RuntimeException('Code backup did not pass ZIP validation.');
    $check->close();
}

function sql_literal(PDO $pdo, mixed $value): string {
    if ($value === null) return 'NULL';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $pdo->quote((string)$value);
}
// A partial fwrite must never result in a backup reported as complete.
function write_backup_sql($stream, string $content): void {
    $offset = 0;
    $size = strlen($content);
    while ($offset < $size) {
        $written = fwrite($stream, substr($content, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Could not write the complete database backup.');
        $offset += $written;
    }
}
function backup_database_sql(string $dest): array {
    // A table-only SQL dump silently loses integrity triggers. Dump a transaction-consistent
    // set of table rows and the explicit trigger definitions, or fail the update closed.
    $pdo=db();$tmp=$dest.'.partial';$fh=fopen($tmp,'wb');
    if(!$fh)throw new RuntimeException('Could not create database backup.');
    @chmod($tmp, 0600);
    $tablesSaved=0;$triggersSaved=0;
    try{
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        write_backup_sql($fh,"-- Non-IUS full database backup generated ".now_iso()."\n-- Transaction-consistent InnoDB data; include triggers; verify on a recovery clone.\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        if(!$tables)throw new RuntimeException('Database backup found no application tables.');
        foreach($tables as $row){
            $table=(string)$row[0];$safe='`'.str_replace('`','``',$table).'`';
            $create=$pdo->query("SHOW CREATE TABLE {$safe}")->fetch(PDO::FETCH_NUM);
            if(!$create||empty($create[1]))throw new RuntimeException('Could not inspect table '.$table);
            write_backup_sql($fh,"DROP TABLE IF EXISTS {$safe};\n".$create[1].";\n\n");
            $q=$pdo->query("SELECT * FROM {$safe}");
            while($data=$q->fetch(PDO::FETCH_ASSOC)){
                $cols=array_map(fn($c)=>'`'.str_replace('`','``',(string)$c).'`',array_keys($data));
                $vals=array_map(fn($v)=>sql_literal($pdo,$v),array_values($data));
                write_backup_sql($fh,"INSERT INTO {$safe} (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n");
            }
            write_backup_sql($fh,"\n");$tablesSaved++;
        }
        $triggerRows=$pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
        $requiredTriggerState=$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE id IN ('002_integrity_triggers','033_transaction_period_database_guard') AND status='applied'")->fetchColumn();
        if((int)$requiredTriggerState>0&&!$triggerRows)throw new RuntimeException('Database triggers expected but invisible in this backup. Check database privileges.');
        foreach($triggerRows as $tr){
            $name=(string)($tr['Trigger']??'');if($name==='')throw new RuntimeException('Could not identify a database trigger.');
            $safe='`'.str_replace('`','``',$name).'`';
            $definition=$pdo->query("SHOW CREATE TRIGGER {$safe}")->fetch(PDO::FETCH_ASSOC);
            $sql=(string)($definition['SQL Original Statement']??'');
            if($sql===''||!str_contains(strtoupper($sql),'TRIGGER'))throw new RuntimeException('Could not export trigger '.$name.'.');
            // Omit the source hosting account's DEFINER, so the SQL can be
            // imported under a different authorized account on a recovery clone.
            $portable = preg_replace('/^CREATE\s+DEFINER\s*=\s*.*?(?=\s+TRIGGER\b)/is', 'CREATE', trim($sql), 1);
            if (!is_string($portable) || !preg_match('/^CREATE\s+TRIGGER\b/i', $portable)) {
                throw new RuntimeException('Cannot make trigger '.$name.' portable. Backup aborted for safe review.');
            }
            write_backup_sql($fh,"DROP TRIGGER IF EXISTS {$safe};\nDELIMITER $$\n{$portable}$$\nDELIMITER ;\n\n");
            $triggersSaved++;
        }
        $visible=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn();
        if($visible!==$triggersSaved)throw new RuntimeException('Trigger count changed during backup. Retry when the database is quiet.');
        write_backup_sql($fh,"SET FOREIGN_KEY_CHECKS=1;\n-- BACKUP COMPLETE tables={$tablesSaved} triggers={$triggersSaved}\n");
        $pdo->commit();
        if(!fflush($fh))throw new RuntimeException('Could not flush database backup.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fclose($fh);@unlink($tmp);throw $e;}
    fclose($fh);
    if(!rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('Could not finalize database backup.');}
    @chmod($dest, 0600);
    return ['tables'=>$tablesSaved,'triggers'=>$triggersSaved,'sha256'=>hash_file('sha256',$dest)];
}

function prune_update_backups(string $dir, int $keepFiles=20): void {
    $files=array_values(array_filter(glob($dir.'/before-*')?:[], 'is_file'));usort($files,fn($a,$b)=>(filemtime($b)?:0)<=>(filemtime($a)?:0));foreach(array_slice($files,$keepFiles) as $old)@unlink($old);
}

function find_manifest_root(string $dir): ?string {
    if (is_file($dir.'/nonius-update.json')) return $dir;
    foreach (new FilesystemIterator($dir) as $f) {
        if ($f->isDir() && is_file($f->getPathname().'/nonius-update.json')) return $f->getPathname();
    }
    return null;
}

function verify_manifest_files(string $root, array $manifest): void {
    $files=$manifest['files']??null;
    if(!is_array($files)||!$files)throw new RuntimeException('Update manifest must contain SHA-256 checksums for every payload file.');
    $actual=[];
    $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($walk as $entry){
        if($entry->isLink())throw new RuntimeException('Symlinks are not allowed in update packages.');
        $rel=str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));
        if($rel==='nonius-update.json')continue;
        if(str_contains($rel,'../')||str_starts_with($rel,'/')||str_starts_with($rel,'storage/')||$rel==='config.local.php'||$rel==='INSTALL_KEY.txt')throw new RuntimeException('Unsafe payload path: '.$rel);
        $actual[]=$rel;
    }
    sort($actual,SORT_STRING);$expectedPaths=array_keys($files);sort($expectedPaths,SORT_STRING);
    if($actual!==$expectedPaths)throw new RuntimeException('Update manifest must list exactly the files supplied in the ZIP.');
    foreach($files as $rel=>$expected){
        if(!is_string($rel)||!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/i',$expected))throw new RuntimeException('Invalid SHA-256 entry in update manifest.');
        if(!hash_equals(strtolower($expected),hash_file('sha256',$root.'/'.$rel)))throw new RuntimeException('Update file integrity check failed: '.$rel);
        if(str_ends_with(strtolower($rel),'.php')){
            // Syntax precheck without requiring a server command-line PHP executable.
            try{token_get_all((string)file_get_contents($root.'/'.$rel),TOKEN_PARSE);}
            catch(ParseError $e){throw new RuntimeException('PHP syntax check failed in '.$rel.': '.$e->getMessage());}
        }
    }
    if(!isset($files['lib/bootstrap.php'])||!isset($files['CHANGELOG.txt']))throw new RuntimeException('Update must include matching version constant and changelog.');
    $boot=(string)file_get_contents($root.'/lib/bootstrap.php');
    if(!preg_match("/const APP_VERSION\\s*=\\s*'([^']+)'/",$boot,$m)||$m[1]!==($manifest['version']??null))throw new RuntimeException('Update version does not match lib/bootstrap.php.');
    $changelog=(string)file_get_contents($root.'/CHANGELOG.txt');
    if(!preg_match('/^Non-IUS Withholding Tax Monitoring v'.preg_quote((string)$manifest['version'],'/').'\\b/m',$changelog))throw new RuntimeException('Update version does not match CHANGELOG.txt.');
}

function restore_code_backup(string $backup, array $manifest=[]): void {
    $zip=new ZipArchive();if($zip->open($backup)!==true)throw new RuntimeException('Rollback backup could not be opened.');
    try {
        // Remove only files introduced by this update; do not touch unrelated site assets.
        foreach(array_keys($manifest['files']??[]) as $rel){
            if(!is_string($rel)||$rel==='nonius-update.json'||str_starts_with($rel,'storage/')||$rel==='config.local.php')continue;
            if($zip->locateName($rel)===false && is_file(ROOT_PATH.'/'.$rel))@unlink(ROOT_PATH.'/'.$rel);
        }
        if(!$zip->extractTo(ROOT_PATH))throw new RuntimeException('Automatic rollback could not restore the code backup.');
    }finally{$zip->close();}
}

function apply_delete_paths(array $manifest): void {
    $paths=$manifest['delete_paths']??[];if(!is_array($paths))throw new RuntimeException('Update delete list is invalid.');
    foreach($paths as $rel){if(!is_string($rel)||$rel===''||str_contains($rel,'../')||str_starts_with($rel,'/')||$rel==='config.local.php'||str_starts_with($rel,'storage/')||str_starts_with($rel,'lib/'))throw new RuntimeException('Unsafe delete path in update manifest.');$target=ROOT_PATH.'/'.$rel;if(is_file($target)||is_link($target))@unlink($target);elseif(is_dir($target))rrmdir($target);}
}

function copy_update_tree(string $src, string $dst): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        if ($rel === 'nonius-update.json' || $rel === 'config.local.php' || $rel === 'INSTALL_KEY.txt' || str_starts_with($rel, 'storage/')) continue;
        if (str_contains($rel, '../') || str_starts_with($rel, '/')) throw new RuntimeException('Unsafe path in update package.');
        $target = $dst.'/'.$rel;
        if ($f->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) throw new RuntimeException('Could not create update directory.');
        } else {
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) throw new RuntimeException('Could not create update directory.');
            $temp=$target.'.incoming-'.bin2hex(random_bytes(6));
            if(!copy($f->getPathname(), $temp))throw new RuntimeException('Could not stage update file: '.$rel);
            try{
                if(hash_file('sha256',$temp)!==hash_file('sha256',$f->getPathname()))throw new RuntimeException('Staged update content failed checksum verification: '.$rel);
                if(!rename($temp,$target))throw new RuntimeException('Could not atomically replace update file: '.$rel);
            }finally{if(is_file($temp))@unlink($temp);}
        }
    }
}

/**
 * Accept either the original multipart upload or a base64 ZIP sent as JSON.
 * JSON mode avoids shared-host/WAF rules that sometimes replace multipart
 * ZIP responses with an HTML error page.
 */
function receive_update_zip(?array $request = null): array {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $body = $request ?? [];
        $filename = basename((string)($body['filename'] ?? 'update.zip'));
        if (!preg_match('/\.zip$/i', $filename)) throw new RuntimeException('Choose a ZIP update package.');
        $encoded = (string)($body['data_base64'] ?? '');
        if ($encoded === '') throw new RuntimeException('The update package is empty.');
        // Preserve the existing 30 MB in-app ZIP limit on shared hosting.
        if (strlen($encoded) > 42 * 1024 * 1024) throw new RuntimeException('Update package is too large for the in-app updater.');
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) throw new RuntimeException('The update package could not be decoded.');
        if (strlen($bytes) > 30 * 1024 * 1024) throw new RuntimeException('Update package is too large for the in-app updater.');
        $uploadDir = ROOT_PATH.'/storage/update-staging';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create private update staging directory.');
        $path = $uploadDir.'/staged-'.bin2hex(random_bytes(16)).'.zip';
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($path);
            throw new RuntimeException('Could not save the uploaded update package.');
        }
        @chmod($path, 0600);
        return [$path, strlen($bytes), true];
    }

    if (empty($_FILES['update'])) throw new RuntimeException('Choose a valid update ZIP file.');
    $err = (int)($_FILES['update']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The update exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'The update exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The update was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No update file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        ];
        throw new RuntimeException($messages[$err] ?? 'The update upload failed.');
    }
    $size = (int)($_FILES['update']['size'] ?? 0);
    if ($size > 30 * 1024 * 1024) throw new RuntimeException('Update package is too large for the in-app updater.');
    $uploadDir = ROOT_PATH.'/storage/update-staging';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create private update staging directory.');
    $path = $uploadDir.'/staged-'.bin2hex(random_bytes(16)).'.zip';
    if (!move_uploaded_file((string)$_FILES['update']['tmp_name'], $path)) throw new RuntimeException('Could not stage the uploaded update package.');
    @chmod($path, 0600);
    return [$path, $size, true];
}

function clear_reviewed_update(): void {
    $old = $_SESSION['reviewed_update'] ?? null;
    unset($_SESSION['reviewed_update']);
    if (is_array($old)) {
        $path = (string)($old['path'] ?? '');
        $prefix = ROOT_PATH.'/storage/update-staging/staged-';
        if (str_starts_with($path, $prefix) && is_file($path)) @unlink($path);
    }
}

function prune_reviewed_updates(): void {
    $dir = ROOT_PATH.'/storage/update-staging';
    foreach (glob($dir.'/staged-*.zip') ?: [] as $path) {
        if (is_file($path) && (time() - (int)filemtime($path)) > 3600) @unlink($path);
    }
}

/** Identical ZIP/manifest/code checks on both Review and Install. */
function inspect_update_package(string $zipPath): array {
    $tmp = ROOT_PATH.'/storage/uploads/update-'.bin2hex(random_bytes(8));
    if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) throw new RuntimeException('Could not create update workspace.');
    try {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('Unable to open the update ZIP.');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 1000) throw new RuntimeException('Update package contains too many files.');
            $totalUncompressed = 0;
            $seenPaths = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $stat = $zip->statIndex($i);
                $totalUncompressed += (int)($stat['size'] ?? 0);
                $key = strtolower((string)$name);
                if (isset($seenPaths[$key]) || $totalUncompressed > 120*1024*1024) throw new RuntimeException('Unsafe or oversized ZIP contents.');
                $seenPaths[$key] = true;
                if (!is_string($name) || preg_match('~(^|[\\/])\.\.([\\/]|$)~', $name) || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) throw new RuntimeException('Unsafe path in update package.');
            }
            if (!$zip->extractTo($tmp)) throw new RuntimeException('Could not extract the update package.');
        } finally { $zip->close(); }

        $root = find_manifest_root($tmp);
        if (!$root) throw new RuntimeException('This is not a Non-IUS update package (nonius-update.json is missing).');
        $manifest = json_decode((string)file_get_contents($root.'/nonius-update.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) throw new RuntimeException('Invalid update manifest.');
        $product = (string)($manifest['product'] ?? '');
        $version = (string)($manifest['version'] ?? '');
        $minimum = (string)($manifest['minimum_version'] ?? '');
        $minimumPhp = (string)($manifest['minimum_php'] ?? '');
        if ($product !== APP_NAME) throw new RuntimeException('This update package is for a different product.');
        if (!$version || !version_compare($version, APP_VERSION, '>')) throw new RuntimeException('Update version must be newer than '.APP_VERSION.'.');
        if ($minimum !== '' && version_compare(APP_VERSION, $minimum, '<')) throw new RuntimeException('This update requires application version '.$minimum.' or newer. Install the missing intermediate update first.');
        if ($minimumPhp !== '' && version_compare(PHP_VERSION, $minimumPhp, '<')) throw new RuntimeException('This update requires PHP '.$minimumPhp.' or newer.');
        verify_manifest_files($root, $manifest);
        return ['tmp'=>$tmp, 'root'=>$root, 'manifest'=>$manifest];
    } catch (Throwable $e) {
        rrmdir($tmp);
        throw $e;
    }
}

function update_release_notes(string $root, string $version): string {
    $content = (string)file_get_contents($root.'/CHANGELOG.txt');
    $header = 'Non-IUS Withholding Tax Monitoring v'.$version;
    $start = strpos($content, $header);
    if ($start === false) return '';
    $section = substr($content, $start + strlen($header));
    if (preg_match('/\nNon-IUS Withholding Tax Monitoring v\d+\./', $section, $m, PREG_OFFSET_CAPTURE)) {
        $section = substr($section, 0, $m[0][1]);
    }
    return trim(substr($section, 0, 2400));
}

$tmp = null;
$ownedUpload = null;
$maintenanceLock = null;
$historyId = null;
$installed = false;
try {
    $actor = require_member();
    require_owner($actor);
    verify_csrf();
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZIP support is not enabled on this hosting account.');
    $json = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    $request = $json ? json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR) : $_POST;
    if (!is_array($request)) throw new RuntimeException('Invalid update request.');
    $action = (string)($request['action'] ?? '');
    if (!in_array($action, ['review','install','cancel'], true)) throw new RuntimeException('Use Upload and Review before installing an application update.');
    prune_reviewed_updates();

    if ($action === 'cancel') {
        clear_reviewed_update();
        update_out(['ok'=>true,'message'=>'Update review cancelled. No files were installed.']);
    }

    if ($action === 'review') {
        clear_reviewed_update();
        [$zipPath, $zipSize, $owned] = receive_update_zip($request);
        if ($owned) $ownedUpload = $zipPath;
        $checked = inspect_update_package($zipPath);
        $tmp = $checked['tmp'];
        $manifest = $checked['manifest'];
        $version = (string)$manifest['version'];
        $notes = update_release_notes($checked['root'], $version);
        $token = bin2hex(random_bytes(24));
        $_SESSION['reviewed_update'] = [
            'path'=>$zipPath, 'sha256'=>hash_file('sha256',$zipPath),
            'version'=>$version, 'actor_id'=>(string)$actor['id'],
            'created_at'=>time(), 'token'=>$token,
        ];
        // The staged ZIP is kept securely until Install Update / Cancel / expiry.
        $ownedUpload = null;
        rrmdir($tmp); $tmp = null;
        update_out([
            'ok'=>true, 'review_token'=>$token,
            'details'=>[
                'product'=>APP_NAME, 'current_version'=>APP_VERSION, 'version'=>$version,
                'description'=>(string)($manifest['description'] ?? ''),
                'release_notes'=>$notes,
                'minimum_version'=>(string)($manifest['minimum_version'] ?? ''),
                'minimum_php'=>(string)($manifest['minimum_php'] ?? ''),
                'files'=>array_keys($manifest['files']),
                'file_count'=>count($manifest['files']),
                'zip_size'=>$zipSize, 'sha256'=>$_SESSION['reviewed_update']['sha256'],
            ],
            'message'=>'Update validated for review. Nothing has been installed.'
        ]);
    }

    // Explicit server-side consent is mandatory. A direct POST without the
    // prior same-owner/same-session review cannot trigger installation.
    $review = $_SESSION['reviewed_update'] ?? null;
    if (!is_array($review) || ($request['confirmed'] ?? null) !== true) throw new RuntimeException('Review the update and tick the mandatory confirmation before installation.');
    $token = $request['review_token'] ?? null;
    if (!is_string($token) || !hash_equals((string)$review['token'], $token)) throw new RuntimeException('Update review token is invalid. Upload and review the ZIP again.');
    if (!hash_equals((string)$review['actor_id'], (string)$actor['id'])) throw new RuntimeException('This update was reviewed under another account.');
    if (time()-(int)$review['created_at'] > 900) throw new RuntimeException('Update review expired after 15 minutes. Please review the ZIP again.');
    $zipPath = (string)$review['path'];
    if (!str_starts_with($zipPath, ROOT_PATH.'/storage/update-staging/staged-') || !is_file($zipPath)) throw new RuntimeException('Reviewed ZIP is missing. Upload and review again.');
    if (!hash_equals((string)$review['sha256'], hash_file('sha256',$zipPath))) throw new RuntimeException('Reviewed update ZIP has changed. Upload and review again.');
    $zipSize = (int)filesize($zipPath);
    $checked = inspect_update_package($zipPath);
    $tmp = $checked['tmp'];
    $root = $checked['root'];
    $manifest = $checked['manifest'];
    $version = (string)$manifest['version'];
    if (!hash_equals((string)$review['version'], $version)) throw new RuntimeException('The update version changed after review.');
    // Consume the one-time review before any backups or file operations.
    unset($_SESSION['reviewed_update']);
    $ownedUpload = $zipPath;
    // The owner-visible history begins only after a package passes both reviews.
    $historyId = update_history_begin($actor, APP_VERSION, $version,
        (string)($manifest['description']??'').'\n'.update_release_notes($root,$version), hash_file('sha256',$zipPath));

    $maintenanceLock = ROOT_PATH.'/storage/maintenance.lock';
    if (file_put_contents($maintenanceLock, json_encode_safe(['from'=>APP_VERSION,'to'=>$version,'at'=>now_iso()]), LOCK_EX) === false) throw new RuntimeException('Could not enable maintenance mode for the update.');

    $backupDir = ROOT_PATH.'/storage/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) throw new RuntimeException('Could not create backup directory.');
    @chmod($backupDir, 0700);
    $stamp = gmdate('Ymd-His');
    $backup = $backupDir.'/before-'.$version.'-'.$stamp.'.zip';
    $dbBackup = $backupDir.'/before-'.$version.'-'.$stamp.'-db.sql';
    backup_code($backup);
    $dbBackupStatus = backup_database_sql($dbBackup);
    audit_event($actor, 'system-update', 'application', ['from'=>APP_VERSION, 'to'=>$version, 'backup'=>basename($backup),'database_backup'=>basename($dbBackup), 'bytes'=>$zipSize]);
    try {
        copy_update_tree($root, ROOT_PATH);
        apply_delete_paths($manifest);
        if (!copy($root.'/nonius-update.json',ROOT_PATH.'/nonius-update.json')) throw new RuntimeException('Could not update release metadata.');
    } catch (Throwable $copyError) {
        try { restore_code_backup($backup, $manifest); }
        catch (Throwable $rollbackError) {
            error_log('NonIUS rollback failure: '.$rollbackError->getMessage());
            throw new RuntimeException('Update failed and rollback ALSO failed. Recover from the saved backups immediately: '.$copyError->getMessage());
        }
        throw new RuntimeException('Update installation failed; prior code backup was restored. Database migration recovery may still be required: '.$copyError->getMessage());
    }
    // Code replacement and release metadata have completed; do not misreport the
    // installation as failed if only writing its secondary history row fails.
    $installed = true;
    if($historyId !== null){
        try{update_history_finish($historyId,'successful');}
        catch(Throwable $historyError){error_log('NonIUS update-history success write failed: '.$historyError->getMessage());}
    }
    prune_update_backups($backupDir, 20);
    rrmdir($tmp); $tmp = null;
    @unlink($ownedUpload); $ownedUpload = null;
    @unlink($maintenanceLock); $maintenanceLock = null;
    update_out([
        'ok'=>true, 'from'=>APP_VERSION, 'to'=>$version,
        'backup'=>basename($backup), 'database_backup'=>basename($dbBackup),
        'database_backup_verified'=>$dbBackupStatus,
        'message'=>'Code files installed. On reload, review migration status and perform a recovery-clone test before treating the release as accepted.'
    ]);
} catch (Throwable $e) {
    if($historyId !== null && !$installed){
        try{update_history_finish($historyId,'failed',$e->getMessage());}
        catch(Throwable $historyError){error_log('NonIUS update-history failure write failed: '.$historyError->getMessage());}
    }
    if ($tmp !== null) rrmdir($tmp);
    if ($ownedUpload !== null && is_file($ownedUpload)) @unlink($ownedUpload);
    if ($maintenanceLock !== null && is_file($maintenanceLock)) @unlink($maintenanceLock);
    update_out(['error'=>$e->getMessage()], 400);
}
