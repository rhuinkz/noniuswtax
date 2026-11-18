<?php
declare(strict_types=1);

// ONLY in the disposable, CLI-only nonius-private-historical-drill Compose project.
// All source row values and hashes remain inside the temporary Docker volumes.
// The ONLY externally emitted result is aggregate counts and boolean test evidence.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
$mode = (string)($argv[1] ?? '');
$root = realpath(__DIR__ . '/../..');
$evidenceFile = '/private-evidence/before-snapshot.json';
$allowed = ['baseline', 'migrate', 'after', 'restored'];
try {
    if ($root !== '/var/www/html' || PHP_SAPI !== 'cli'
        || getenv('NONIUS_LOCAL_PRIVATE_DRILL') !== 'true'
        || getenv('DB_HOST') !== 'db'
        || getenv('DB_USER') !== 'nonius_historical_local'
        || getenv('DB_PASS') !== 'disposable-historical-user-only'
        || !in_array($mode, $allowed, true)) {
        throw new LogicException('NOT_PRIVATE_TEST_ENVIRONMENT');
    }
    $dbname = getenv('DB_NAME');
    $expected = $mode === 'restored' ? 'nonius_historical_restored' : 'nonius_historical_test';
    if ($dbname !== $expected) throw new LogicException('UNEXPECTED_TEST_DATABASE');
    if ($mode !== 'migrate' && is_file($root . '/config.local.php')) {
        throw new LogicException('UNEXPECTED_CONFIG_FILE');
    }
    $db = new PDO(
        'mysql:host=db;dbname=' . $dbname . ';charset=utf8mb4',
        'nonius_historical_local', 'disposable-historical-user-only',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,
         PDO::ATTR_STRINGIFY_FETCHES=>true]
    );
    $db->exec("SET time_zone = '+00:00'");

    function num(PDO $db, string $sql): int {
        return (int)$db->query($sql)->fetchColumn();
    }
    function rows(PDO $db, string $sql): array {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    function digestSnapshot(PDO $db): array {
        $tables = rows($db, "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $snapshot = [];
        foreach ($tables as $item) {
            $name = $item['name'];
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) throw new LogicException('UNEXPECTED_TABLE_NAME');
            $primary = $db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
            $primary->execute([$name]);
            $columns = $primary->fetchAll(PDO::FETCH_COLUMN);
            if (!$columns) throw new LogicException('MISSING_PRIMARY_KEY');
            foreach ($columns as $column) {
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $column)) throw new LogicException('UNEXPECTED_PK_COLUMN');
            }
            $orderBy = implode(',', array_map(static fn($v) => '`' . $v . '`', $columns));
            $stmt = $db->query('SELECT * FROM `' . $name . '` ORDER BY ' . $orderBy);
            $digest = hash_init('sha256');
            $count = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                hash_update($digest, "\n");
                $count++;
            }
            $stmt->closeCursor();
            $snapshot[$name] = ['count'=>$count, 'sha256'=>hash_final($digest)];
        }
        return $snapshot;
    }
    function guardStatus(PDO $db): array {
        $q=$db->prepare('SELECT status FROM schema_migrations WHERE id=?');
        $q->execute(['037_checked_hash_period_guard']);
        $state = $q->fetchColumn();
        $triggers = rows($db, "SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()");
        $found = false;
        foreach ($triggers as $row) {
            if ($row['name'] === 'protect_checked_hash_period_update') $found = true;
        }
        return [
            'migration037_status' => $state === false ? 'NO_ROW' : $state,
            'triggers' => count($triggers),
            'guard_present' => $found,
            'columns' => num($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"),
            'tables' => num($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'"),
            'foreign_keys' => num($db, "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"),
            'checked_with_incorrect_hash' => num($db, "SELECT COUNT(*) FROM transactions WHERE status='checked' AND checked_snapshot_hash IS NOT NULL AND (snapshot IS NULL OR LOWER(checked_snapshot_hash)<>SHA2(snapshot,256))"),
            'checked_missing_hash' => num($db, "SELECT COUNT(*) FROM transactions WHERE status='checked' AND (checked_snapshot_hash IS NULL OR snapshot IS NULL)")
        ];
    }
    function compareSnapshots(array $a, array $b, bool $ignoreMigrationTable=false): void {
        if (count($a) !== count($b)) throw new LogicException('TABLE_COUNT_CHANGED');
        foreach ($a as $table => $content) {
            if ($ignoreMigrationTable && $table === 'schema_migrations') continue;
            if (!isset($b[$table]) || $content !== $b[$table]) throw new LogicException('BUSINESS_DATA_CHANGED');
        }
    }
    function getBaseline(string $path): array {
        if (!is_file($path)) throw new LogicException('MISSING_INTERNAL_BASELINE');
        $x = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($x['purpose'] ?? '') !== 'PRIVATE_DOCKER_DATABASE_BASELINE_V1') throw new LogicException('INVALID_BASELINE');
        return $x;
    }

    if ($mode === 'baseline') {
        $status = guardStatus($db);
        if ($status['tables'] !== 21 || $status['columns'] !== 177 ||
            $status['triggers'] !== 14 || $status['guard_present'] ||
            $status['migration037_status'] !== 'NO_ROW') {
            throw new LogicException('SOURCE_SCHEMA_NOT_EXPECTED_21_177_14_PENDING_037');
        }
        $migrationRows=rows($db,'SELECT id,status FROM schema_migrations ORDER BY id');
        $migrationMap=[];
        foreach ($migrationRows as $r) $migrationMap[(string)$r['id']] = (string)$r['status'];
        require_once $root . '/lib/migrations.php'; // Definitions only; no schema DDL.
        $definitions=migration_definitions();
        $skipped=0;
        foreach ($definitions as $definition) {
            $id=(string)$definition['id'];
            if ($id === '037_checked_hash_period_guard') continue;
            $state=$migrationMap[$id]??'MISSING';
            if ($state === 'skipped' && !empty($definition['optional'])) { $skipped++; continue; }
            if ($state !== 'applied') throw new LogicException('OTHER_MIGRATION_NOT_READY');
        }
        if ($status['checked_with_incorrect_hash'] > 0 || $status['checked_missing_hash'] > 0) {
            throw new LogicException('PRE_EXISTING_CHECKED_HASH_INTEGRITY_FAILURE');
        }
        $snapshot=digestSnapshot($db);
        $baseline=[
            'purpose'=>'PRIVATE_DOCKER_DATABASE_BASELINE_V1',
            'schema'=>$status,
            'table_digest_map'=>$snapshot,
            'optional_skipped_migrations'=>$skipped,
            'database_version'=>(string)$db->query('SELECT VERSION()')->fetchColumn()
        ];
        if (file_put_contents($evidenceFile,json_encode($baseline,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX) === false) {
            throw new LogicException('INTERNAL_BASELINE_WRITE_FAILED');
        }
        echo "PASS: 21 tables, 177 columns, 14 triggers, migration 037 absent. Confidential fingerprints retained only in a private Docker volume.\n";
    } elseif ($mode === 'migrate') {
        $baseline=getBaseline($evidenceFile);
        if (($baseline['schema']['migration037_status'] ?? '') !== 'NO_ROW') throw new LogicException('MIGRATION_NOT_PENDING');
        $cfg=$root.'/config.local.php';
        if (is_file($cfg)) throw new LogicException('UNEXPECTED_CONFIG_FILE');
        $settings=[
            'base_url'=>'http://localhost.invalid',
            'db'=>[
                'host'=>'db','name'=>'nonius_historical_test',
                'user'=>'nonius_historical_local','password'=>'disposable-historical-user-only',
                'port'=>3306
            ]
        ];
        $contents='<?php return '.var_export($settings,true).';';
        if (file_put_contents($cfg,$contents,LOCK_EX)===false) throw new LogicException('TEMP_CONFIG_FAILED');
        try {
            // Normal application bootstrap performs migration 037 in this one
            // disposable database; no web listener or live connection exists.
            require $root . '/lib/bootstrap.php';
        } finally {
            @unlink($cfg);
        }
        echo "PASS: PHP bootstrap completed in private isolated Docker CLI.\n";
    } elseif ($mode === 'after') {
        $baseline=getBaseline($evidenceFile);
        $status=guardStatus($db);
        if ($status['tables']!==21 || $status['columns']!==177 ||
            $status['triggers']!==15 || !$status['guard_present'] ||
            $status['migration037_status']!=='applied' ||
            $status['checked_with_incorrect_hash']!==0 || $status['checked_missing_hash']!==0) {
            throw new LogicException('MIGRATION_037_GUARD_VERIFICATION_FAILED');
        }
        $after=digestSnapshot($db);
        compareSnapshots($baseline['table_digest_map'],$after,true);
        if ($after['schema_migrations']['count'] !== $baseline['table_digest_map']['schema_migrations']['count']+1) {
            throw new LogicException('MIGRATION_HISTORY_COUNT_UNEXPECTED');
        }
        echo "PASS: 14->15 triggers; migration 037 applied; all original business-table content digests unchanged.\n";
    } elseif ($mode === 'restored') {
        $baseline=getBaseline($evidenceFile);
        $status=guardStatus($db);
        if ($status !== $baseline['schema']) throw new LogicException('ORIGINAL_SCHEMA_NOT_RESTORED');
        $restored=digestSnapshot($db);
        compareSnapshots($baseline['table_digest_map'],$restored);
        $summary=[
            'format'=>'NONIUS_PRIVATE_HISTORICAL_REHEARSAL_V1',
            'status'=>'PASS',
            'environment'=>'DISPOSABLE_OFFLINE_CLI_DOCKER',
            'original_database_rows_imported'=>true,
            'original_database_values_exported_in_report'=>false,
            'production_site_modified'=>false,
            'original_tables'=>$status['tables'],
            'original_columns'=>$status['columns'],
            'original_triggers'=>$status['triggers'],
            'migration037_initially'=>'NO_ROW',
            'migration037_in_isolated_clone'=>'applied',
            'trigger_count_after_clone_upgrade'=>15,
            'all_business_table_content_digests_unchanged'=>true,
            'restored_original_schema_and_data_digest_match'=>true,
            'checked_hash_mismatch_count'=>0,
            'optional_skipped_previous_migrations'=>$baseline['optional_skipped_migrations'],
            'source_db_engine_version'=>$baseline['database_version'],
            'real_data_sanitized'=>false,
            'web_staging_uat_performed'=>false,
            'full_candidate_updater_on_historical_clone_tested'=>false,
            'production_approval'=>'PENDING',
            'warning'=>'Real historical backup was processed ONLY in offline local test DB, not anonymized or exposed as a website. Final production approval requires separate sanitized UI test and other sign-offs.'
        ];
        echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    }
} catch (Throwable $e) {
    $error = $e instanceof LogicException ? $e->getMessage() : 'DATABASE_OPERATION_OR_RUNTIME_ERROR';
    // Never print SQL exception details, original values, names or source rows.
    fwrite(STDERR,"FAIL private historical rehearsal phase=".$mode." reason=".$error."\n");
    exit(1);
}
