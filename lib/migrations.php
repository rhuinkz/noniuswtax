<?php
declare(strict_types=1);

function migration_definitions(): array {
    return [
        [
            'id' => '001_php_hosting_core',
            'description' => 'Create shared-hosting MySQL schema, authentication and monitoring tables.',
            'optional' => false,
            'statements' => [
                "CREATE TABLE IF NOT EXISTS schools (id VARCHAR(64) PRIMARY KEY,name VARCHAR(160) NOT NULL,data LONGTEXT NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,INDEX idx_schools_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS members (id VARCHAR(64) PRIMARY KEY,email VARCHAR(180) NOT NULL,user_id VARCHAR(64) NOT NULL,password_hash VARCHAR(255) NULL,role VARCHAR(32) NOT NULL,school_id VARCHAR(64) NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at VARCHAR(35) NOT NULL,updated_at VARCHAR(35) NOT NULL,UNIQUE KEY member_email(email),UNIQUE KEY member_user(user_id),INDEX idx_member_school(school_id),CONSTRAINT fk_member_school FOREIGN KEY(school_id) REFERENCES schools(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS division (id VARCHAR(64) PRIMARY KEY,name VARCHAR(160) NOT NULL,data LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS atcs (id VARCHAR(64) PRIMARY KEY,name VARCHAR(32) NOT NULL,data LONGTEXT NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,INDEX idx_atc_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS suppliers (id VARCHAR(64) PRIMARY KEY,name VARCHAR(180) NOT NULL,tin VARCHAR(14) NOT NULL,data LONGTEXT NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,UNIQUE KEY supplier_tin(tin),INDEX idx_supplier_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS transactions (id VARCHAR(64) PRIMARY KEY,school_id VARCHAR(64) NOT NULL,supplier_id VARCHAR(64) NOT NULL,created_by VARCHAR(64) NOT NULL,date DATE NOT NULL,gross BIGINT UNSIGNED NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'submitted',snapshot LONGTEXT NULL,finalized_at VARCHAR(35) NULL,finalized_by VARCHAR(64) NULL,void_reason VARCHAR(500) NULL,voided_at VARCHAR(35) NULL,voided_by VARCHAR(64) NULL,created_at VARCHAR(35) NOT NULL,version INT NOT NULL DEFAULT 1,INDEX tx_school_date(school_id,date),INDEX tx_date_status(date,status),INDEX tx_supplier_date(supplier_id,date),CONSTRAINT fk_tx_school FOREIGN KEY(school_id) REFERENCES schools(id),CONSTRAINT fk_tx_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS audit (id VARCHAR(64) PRIMARY KEY,actor VARCHAR(64) NOT NULL,action VARCHAR(80) NOT NULL,entity VARCHAR(128) NOT NULL,details LONGTEXT NOT NULL,at VARCHAR(35) NOT NULL,INDEX audit_time(at),INDEX audit_actor(actor)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(100) PRIMARY KEY,setting_value LONGTEXT NOT NULL,updated_at VARCHAR(35) NOT NULL,updated_by VARCHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '002_integrity_triggers',
            'description' => 'Protect finalized/void tax records and append-only audit history at database level when triggers are permitted.',
            'optional' => true,
            'statements' => [
                "CREATE TRIGGER protect_finalized_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN IF OLD.status IN ('finalized','void') THEN IF NOT (OLD.status='finalized' AND NEW.status='void' AND LENGTH(TRIM(COALESCE(NEW.void_reason,'')))>0 AND NEW.voided_by IS NOT NULL AND NEW.voided_at IS NOT NULL AND NEW.id=OLD.id AND NEW.school_id=OLD.school_id AND NEW.supplier_id=OLD.supplier_id AND NEW.created_by=OLD.created_by AND NEW.date=OLD.date AND NEW.gross=OLD.gross AND (NEW.snapshot <=> OLD.snapshot) AND (NEW.finalized_at <=> OLD.finalized_at) AND (NEW.finalized_by <=> OLD.finalized_by) AND NEW.created_at=OLD.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized tax records are immutable'; END IF; END IF; END",
                "CREATE TRIGGER protect_transaction_delete BEFORE DELETE ON transactions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tax records cannot be deleted'; END",
                "CREATE TRIGGER protect_audit_update BEFORE UPDATE ON audit FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit events are append only'; END",
                "CREATE TRIGGER protect_audit_delete BEFORE DELETE ON audit FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit events are append only'; END",
            ],
        ],
        [
            'id' => '003_vat_tax_type_label',
            'description' => 'Restore the LoyTaxCalc ATC tax type name from Business to VAT for new transactions.',
            'optional' => false,
            'statements' => [
                "UPDATE atcs SET data=JSON_SET(data,'$.tax_type','VAT'), version=version+1 WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'$.tax_type'))='Business'",
            ],
        ],
        [
            'id' => '004_project_owner_role',
            'description' => 'Separate the Project Owner from Division Administrators and School Users.',
            'optional' => false,
            'statements' => [
                "UPDATE members SET role='project_owner' WHERE role='division_admin' AND active=1 ORDER BY created_at ASC LIMIT 1",
            ],
        ],
        [
            'id' => '005_project_owner_protection',
            'description' => 'Protect the Project Owner account from direct role removal, deactivation or deletion when database triggers are permitted.',
            'optional' => true,
            'statements' => [
                "CREATE TRIGGER protect_project_owner_update BEFORE UPDATE ON members FOR EACH ROW BEGIN IF OLD.role='project_owner' AND (NEW.role<>'project_owner' OR NEW.active<>1 OR NEW.id<>OLD.id OR NEW.email<>OLD.email) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Project Owner role and active status are protected'; END IF; END",
                "CREATE TRIGGER protect_project_owner_delete BEFORE DELETE ON members FOR EACH ROW BEGIN IF OLD.role='project_owner' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Project Owner account cannot be deleted'; END IF; END",
            ],
        ],
        [
            'id' => '006_source_of_fund',
            'description' => 'Add configurable source-of-fund master data and mandatory transaction attribution for new and edited drafts.',
            'optional' => false,
            'statements' => [
                "CREATE TABLE IF NOT EXISTS source_funds (id VARCHAR(64) PRIMARY KEY,name VARCHAR(80) NOT NULL,data LONGTEXT NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,UNIQUE KEY source_fund_name(name),INDEX idx_source_fund_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "INSERT INTO source_funds(id,name,data,active,version) VALUES ('fund-mooe','MOOE','{}',1,1),('fund-sned','SNED','{}',1,1),('fund-sbfp','SBFP','{}',1,1),('fund-siif','SIIF','{}',1,1) ON DUPLICATE KEY UPDATE name=VALUES(name)",
                "ALTER TABLE transactions ADD COLUMN source_fund_id VARCHAR(64) NULL AFTER supplier_id, ADD INDEX tx_source_fund_date(source_fund_id,date), ADD CONSTRAINT fk_tx_source_fund FOREIGN KEY(source_fund_id) REFERENCES source_funds(id)",
            ],
        ],
        [
            'id' => '007_source_of_fund_integrity',
            'description' => 'Protect Source of Fund attribution on finalized and void tax records when database triggers are permitted.',
            'optional' => true,
            'statements' => [
                "CREATE TRIGGER protect_finalized_source_fund_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN IF OLD.status IN ('finalized','void') AND NOT (NEW.source_fund_id <=> OLD.source_fund_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized Source of Fund attribution is immutable'; END IF; END",
            ],
        ],
        [
            'id' => '008_legacy_finalized_trigger_transition',
            'description' => 'Retire the legacy finalized-record trigger before enabling the Submitted/Checked review workflow.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_finalized_update",
            ],
        ],
        [
            'id' => '009_transaction_review_workflow',
            'description' => 'Introduce Submitted and Checked workflow, pending approval for edits to checked records, Division remarks, and permanent transaction history.',
            'optional' => false,
            'statements' => [
                "CREATE TABLE IF NOT EXISTS transaction_history (id VARCHAR(64) PRIMARY KEY,transaction_id VARCHAR(64) NOT NULL,revision INT NOT NULL,event_type VARCHAR(40) NOT NULL,actor VARCHAR(64) NOT NULL,actor_role VARCHAR(32) NOT NULL,before_data LONGTEXT NULL,after_data LONGTEXT NULL,remarks VARCHAR(1000) NULL,at VARCHAR(35) NOT NULL,UNIQUE KEY tx_history_revision(transaction_id,revision),INDEX tx_history_time(transaction_id,at),CONSTRAINT fk_tx_history_transaction FOREIGN KEY(transaction_id) REFERENCES transactions(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS transaction_pending_edits (transaction_id VARCHAR(64) PRIMARY KEY,supplier_id VARCHAR(64) NOT NULL,source_fund_id VARCHAR(64) NOT NULL,date DATE NOT NULL,gross BIGINT UNSIGNED NOT NULL,submitted_by VARCHAR(64) NOT NULL,submitted_at VARCHAR(35) NOT NULL,version INT NOT NULL DEFAULT 1,INDEX pending_submitted_at(submitted_at),CONSTRAINT fk_pending_transaction FOREIGN KEY(transaction_id) REFERENCES transactions(id),CONSTRAINT fk_pending_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),CONSTRAINT fk_pending_source_fund FOREIGN KEY(source_fund_id) REFERENCES source_funds(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "UPDATE transactions SET status='submitted' WHERE status='draft'",
                "UPDATE transactions SET status='checked' WHERE status='finalized'",
                "INSERT IGNORE INTO transaction_history(id,transaction_id,revision,event_type,actor,actor_role,before_data,after_data,remarks,at) SELECT UUID(),id,1,'baseline',created_by,'system',NULL,NULL,'Baseline carried forward when the Submitted/Checked review workflow was enabled.',created_at FROM transactions",
            ],
        ],
        [
            'id' => '010_transaction_review_integrity',
            'description' => 'Protect void transactions and append-only transaction history when database triggers are permitted.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_void_transaction_update",
                "CREATE TRIGGER protect_void_transaction_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN IF OLD.status='void' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Void tax records are immutable'; END IF; END",
                "DROP TRIGGER IF EXISTS protect_transaction_history_update",
                "CREATE TRIGGER protect_transaction_history_update BEFORE UPDATE ON transaction_history FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction history is append only'; END",
                "DROP TRIGGER IF EXISTS protect_transaction_history_delete",
                "CREATE TRIGGER protect_transaction_history_delete BEFORE DELETE ON transaction_history FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction history is append only'; END",
            ],
        ],
        [
            'id' => '011_draft_calculate_submit_flow',
            'description' => 'Make Draft the first transaction stage so users can generate and review calculations before explicit submission to the Division.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions MODIFY status VARCHAR(16) NOT NULL DEFAULT 'draft'",
            ],
        ],
        [
            'id' => '012_username_login',
            'description' => 'Add permanent usernames, make recovery email optional for new accounts, and allow sign-in by username or email.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE members ADD COLUMN username VARCHAR(80) NULL AFTER id",
                "UPDATE members SET username=LOWER(CONCAT('u_',LEFT(REGEXP_REPLACE(SUBSTRING_INDEX(COALESCE(email,'user'),'@',1),'[^A-Za-z0-9._-]','_'),25),'_',LEFT(REPLACE(id,'-',''),4))) WHERE username IS NULL OR username=''",
                "ALTER TABLE members MODIFY username VARCHAR(80) NOT NULL, MODIFY email VARCHAR(180) NULL",
                "ALTER TABLE members ADD UNIQUE KEY member_username(username)",
            ],
        ],
        [
            'id' => '013_project_owner_contact_update',
            'description' => 'Keep Project Owner role protection while allowing the owner to maintain a recovery email and password.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_project_owner_update",
                "CREATE TRIGGER protect_project_owner_update BEFORE UPDATE ON members FOR EACH ROW BEGIN IF OLD.role='project_owner' AND (NEW.role<>'project_owner' OR NEW.active<>1 OR NEW.id<>OLD.id OR NEW.username<>OLD.username) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Project Owner role, active status and username are protected'; END IF; END",
            ],
        ],
        [
            'id' => '014_one_time_username_change',
            'description' => 'Allow every account, including the Project Owner, one self-service username change from My Account.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE members ADD COLUMN username_changed_once TINYINT(1) NOT NULL DEFAULT 0 AFTER username",
            ],
        ],
        [
            'id' => '015_project_owner_username_change',
            'description' => 'Keep Project Owner role and active-status protection while allowing the application-controlled one-time username change.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_project_owner_update",
                "CREATE TRIGGER protect_project_owner_update BEFORE UPDATE ON members FOR EACH ROW BEGIN IF OLD.role='project_owner' AND (NEW.role<>'project_owner' OR NEW.active<>1 OR NEW.id<>OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Project Owner role and active status are protected'; END IF; END",
            ],
        ],
        [
            'id' => '016_school_default_account',
            'description' => 'Support automatically created School User accounts with temporary passwords that must be changed on first sign-in.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE members ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash",
            ],
        ],
        [
            'id' => '017_password_reset_requests',
            'description' => 'Add administrator-approved password reset requests and remember whether an account was auto-created with a school.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE members ADD COLUMN account_origin VARCHAR(24) NOT NULL DEFAULT 'manual' AFTER must_change_password",
                "UPDATE members m JOIN audit a ON a.entity=m.id AND a.action='auto-create-school-user' SET m.account_origin='auto_school' WHERE m.role='school_user'",
                "UPDATE members m JOIN schools s ON s.id=m.school_id SET m.account_origin='auto_school' WHERE m.role='school_user' AND LOWER(m.username)=LOWER(JSON_UNQUOTE(JSON_EXTRACT(s.data,'$.code')))",
                "CREATE TABLE IF NOT EXISTS password_reset_requests (id VARCHAR(64) PRIMARY KEY,member_id VARCHAR(64) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',requested_at VARCHAR(35) NOT NULL,reviewed_by VARCHAR(64) NULL,reviewed_at VARCHAR(35) NULL,INDEX reset_status_time(status,requested_at),INDEX reset_member_time(member_id,requested_at),CONSTRAINT fk_reset_member FOREIGN KEY(member_id) REFERENCES members(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '018_return_correction_and_submitted_draft_backup',
            'description' => 'Add Returned for Correction workflow and preserve the last Submitted values while a school revises a submitted transaction.',
            'optional' => false,
            'statements' => [
                "CREATE TABLE IF NOT EXISTS transaction_submitted_backups (transaction_id VARCHAR(64) PRIMARY KEY,supplier_id VARCHAR(64) NOT NULL,source_fund_id VARCHAR(64) NOT NULL,date DATE NOT NULL,gross BIGINT UNSIGNED NOT NULL,version INT NOT NULL,backed_up_at VARCHAR(35) NOT NULL,CONSTRAINT fk_submitted_backup_transaction FOREIGN KEY(transaction_id) REFERENCES transactions(id),CONSTRAINT fk_submitted_backup_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),CONSTRAINT fk_submitted_backup_source_fund FOREIGN KEY(source_fund_id) REFERENCES source_funds(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '019_security_session_and_throttle',
            'description' => 'Add session-version invalidation, sign-in throttling and expiring password-reset requests.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE members ADD COLUMN session_version INT NOT NULL DEFAULT 1 AFTER account_origin",
                "CREATE TABLE IF NOT EXISTS login_throttle (throttle_key CHAR(64) PRIMARY KEY,attempts INT NOT NULL DEFAULT 0,window_started VARCHAR(35) NOT NULL,locked_until VARCHAR(35) NULL,updated_at VARCHAR(35) NOT NULL,INDEX login_throttle_updated(updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "ALTER TABLE password_reset_requests ADD COLUMN expires_at VARCHAR(35) NULL AFTER requested_at",
            ],
        ],
        [
            'id' => '020_atc_code_uniqueness',
            'description' => 'Enforce unique ATC codes at database level when existing data contains no duplicates.',
            'optional' => true,
            'statements' => [
                "ALTER TABLE atcs ADD UNIQUE KEY atc_code_unique(name)",
            ],
        ],
        [
            'id' => '021_transaction_submission_snapshots',
            'description' => 'Freeze the exact calculation/master-data snapshot submitted by a school and the exact snapshot of checked-record edits awaiting Division approval.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN submission_hash CHAR(64) NULL AFTER snapshot",
                "ALTER TABLE transactions ADD COLUMN submitted_at VARCHAR(35) NULL AFTER submission_hash",
                "ALTER TABLE transactions ADD COLUMN submitted_by VARCHAR(64) NULL AFTER submitted_at",
                "ALTER TABLE transaction_pending_edits ADD COLUMN snapshot LONGTEXT NULL AFTER gross",
                "ALTER TABLE transaction_pending_edits ADD COLUMN snapshot_hash CHAR(64) NULL AFTER snapshot",
                "ALTER TABLE transaction_submitted_backups ADD COLUMN snapshot LONGTEXT NULL AFTER gross",
                "ALTER TABLE transaction_submitted_backups ADD COLUMN submission_hash CHAR(64) NULL AFTER snapshot",
                "ALTER TABLE transaction_submitted_backups ADD COLUMN submitted_at VARCHAR(35) NULL AFTER submission_hash",
                "ALTER TABLE transaction_submitted_backups ADD COLUMN submitted_by VARCHAR(64) NULL AFTER submitted_at",
            ],
        ],

        [
            'id' => '022_performance_indexes',
            'description' => 'Add transaction indexes used by paginated registers, dashboard summaries and lightweight pending-notification polling.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD INDEX tx_status_created(status,created_at)",
                "ALTER TABLE transactions ADD INDEX tx_school_status_created(school_id,status,created_at)",
                "ALTER TABLE transactions ADD INDEX tx_status_finalized(status,finalized_at)",
            ],
        ],
        [
            'id' => '023_atc_both_business_classification',
            'description' => 'Use an explicit Both business classification for ATCs that apply to Individual and Corporate suppliers.',
            'optional' => false,
            'statements' => [
                "UPDATE atcs SET data=JSON_SET(data,'$.business_type','Both'),version=version+1 WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'$.business_type'))='All'",
            ],
        ],
        [
            'id' => '024_supplier_multi_transaction_profiles',
            'description' => 'Store the selected supplier transaction type on Draft, pending-edit and Submitted-backup records so suppliers can safely use multiple Goods/Services tax profiles.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN transaction_type VARCHAR(80) NULL AFTER supplier_id",
                "ALTER TABLE transaction_pending_edits ADD COLUMN transaction_type VARCHAR(80) NULL AFTER supplier_id",
                "ALTER TABLE transaction_submitted_backups ADD COLUMN transaction_type VARCHAR(80) NULL AFTER supplier_id",
                "UPDATE transactions SET transaction_type=COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.transaction_type')),JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.supplier.transaction_type'))) WHERE (transaction_type IS NULL OR transaction_type='') AND snapshot IS NOT NULL",
                "UPDATE transaction_pending_edits SET transaction_type=COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.transaction_type')),JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.supplier.transaction_type'))) WHERE (transaction_type IS NULL OR transaction_type='') AND snapshot IS NOT NULL",
                "UPDATE transaction_submitted_backups SET transaction_type=COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.transaction_type')),JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.supplier.transaction_type'))) WHERE (transaction_type IS NULL OR transaction_type='') AND snapshot IS NOT NULL",
            ],
        ],
        [
            'id' => '025_duplicate_transaction_lookup',
            'description' => 'Add an index for fast possible-duplicate transaction checks by school, supplier, date and gross amount.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD INDEX tx_duplicate_lookup(school_id,supplier_id,date,gross,status)",
            ],
        ],
        [
            'id' => '026_human_transaction_reference',
            'description' => 'Add permanent human-readable transaction references and per-school annual sequence counters.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN reference VARCHAR(64) NULL AFTER id",
                "ALTER TABLE transactions ADD UNIQUE KEY transaction_reference_unique(reference)",
                "CREATE TABLE IF NOT EXISTS transaction_reference_counters (reference_year SMALLINT UNSIGNED NOT NULL,school_id VARCHAR(64) NOT NULL,last_value INT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(reference_year,school_id),CONSTRAINT fk_tx_reference_counter_school FOREIGN KEY(school_id) REFERENCES schools(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '027_division_level_transactions',
            'description' => 'Support Division Office-originated transactions separately from school transactions and allocate Division annual references.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN transaction_scope VARCHAR(16) NOT NULL DEFAULT 'school' AFTER reference",
                "ALTER TABLE transactions ADD INDEX tx_scope_date(transaction_scope,date)",
                "CREATE TABLE IF NOT EXISTS division_transaction_reference_counters (reference_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,last_value INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '028_bir_encoding_status',
            'description' => 'Track whether checked transactions have been encoded and reconciled in the BIR system, including encoder, timestamps and optional reference.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN bir_status VARCHAR(20) NOT NULL DEFAULT 'not_encoded' AFTER transaction_scope",
                "ALTER TABLE transactions ADD COLUMN bir_reference VARCHAR(120) NULL AFTER bir_status",
                "ALTER TABLE transactions ADD COLUMN bir_encoded_at VARCHAR(35) NULL AFTER bir_reference",
                "ALTER TABLE transactions ADD COLUMN bir_encoded_by VARCHAR(64) NULL AFTER bir_encoded_at",
                "ALTER TABLE transactions ADD COLUMN bir_reconciled_at VARCHAR(35) NULL AFTER bir_encoded_by",
                "ALTER TABLE transactions ADD COLUMN bir_reconciled_by VARCHAR(64) NULL AFTER bir_reconciled_at",
                "ALTER TABLE transactions ADD INDEX tx_bir_status_checked(bir_status,status,finalized_at)",
            ],
        ],
        [
            'id' => '029_transaction_deletion_workflow',
            'description' => 'Allow originators to delete unsubmitted Drafts and let School Users request Division approval before a Checked transaction is removed from active records.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN deletion_reason VARCHAR(500) NULL AFTER voided_by",
                "ALTER TABLE transactions ADD COLUMN deleted_at VARCHAR(35) NULL AFTER deletion_reason",
                "ALTER TABLE transactions ADD COLUMN deleted_by VARCHAR(64) NULL AFTER deleted_at",
                "CREATE TABLE IF NOT EXISTS transaction_deletion_requests (id VARCHAR(64) PRIMARY KEY,transaction_id VARCHAR(64) NOT NULL,requested_by VARCHAR(64) NOT NULL,reason VARCHAR(500) NOT NULL,requested_at VARCHAR(35) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',reviewed_by VARCHAR(64) NULL,reviewed_at VARCHAR(35) NULL,review_remarks VARCHAR(500) NULL,INDEX deletion_status_time(status,requested_at),INDEX deletion_transaction_time(transaction_id,requested_at),CONSTRAINT fk_deletion_request_transaction FOREIGN KEY(transaction_id) REFERENCES transactions(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '030_deleted_transaction_protection',
            'description' => 'Protect approved deleted transactions from later modification when database triggers are permitted.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_deleted_transaction_update",
                "CREATE TRIGGER protect_deleted_transaction_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN IF OLD.status='deleted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Deleted transaction records are immutable'; END IF; END",
            ],
        ],
        [
            'id' => '031_monthly_transaction_period_control',
            'description' => 'Add selective monthly transaction-period opening, closing and reopening for schools and Division Office with permanent period history.',
            'optional' => false,
            'statements' => [
                "CREATE TABLE IF NOT EXISTS transaction_periods (period_month CHAR(7) NOT NULL,target_key VARCHAR(96) NOT NULL,transaction_scope VARCHAR(16) NOT NULL,school_id VARCHAR(64) NULL,state VARCHAR(16) NOT NULL,opened_at VARCHAR(35) NULL,opened_by VARCHAR(64) NULL,closed_at VARCHAR(35) NULL,closed_by VARCHAR(64) NULL,last_action_at VARCHAR(35) NOT NULL,last_action_by VARCHAR(64) NOT NULL,version INT NOT NULL DEFAULT 1,PRIMARY KEY(period_month,target_key),INDEX period_scope_state(period_month,transaction_scope,state),INDEX period_school_month(school_id,period_month),CONSTRAINT fk_period_school FOREIGN KEY(school_id) REFERENCES schools(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS transaction_period_history (id VARCHAR(64) PRIMARY KEY,period_month CHAR(7) NOT NULL,target_key VARCHAR(96) NOT NULL,transaction_scope VARCHAR(16) NOT NULL,school_id VARCHAR(64) NULL,action VARCHAR(16) NOT NULL,actor VARCHAR(64) NOT NULL,reason VARCHAR(500) NULL,at VARCHAR(35) NOT NULL,INDEX period_history_month_time(period_month,at),INDEX period_history_target_time(target_key,at),CONSTRAINT fk_period_history_school FOREIGN KEY(school_id) REFERENCES schools(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],
        [
            'id' => '032_transaction_period_history_protection',
            'description' => 'Protect monthly transaction-period history from update or deletion when database triggers are permitted.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_transaction_period_history_update",
                "DROP TRIGGER IF EXISTS protect_transaction_period_history_delete",
                "CREATE TRIGGER protect_transaction_period_history_update BEFORE UPDATE ON transaction_period_history FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction period history is append only'; END",
                "CREATE TRIGGER protect_transaction_period_history_delete BEFORE DELETE ON transaction_period_history FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction period history is append only'; END",
            ],
        ],
        [
            'id' => '033_transaction_period_database_guard',
            'description' => 'Enforce open monthly transaction periods at database level for transaction inserts and updates when triggers are permitted.',
            'optional' => true,
            'statements' => [
                "DROP TRIGGER IF EXISTS protect_transaction_period_insert",
                "DROP TRIGGER IF EXISTS protect_transaction_period_update",
                "CREATE TRIGGER protect_transaction_period_insert BEFORE INSERT ON transactions FOR EACH ROW BEGIN DECLARE pstate VARCHAR(16); SET pstate=(SELECT state FROM transaction_periods WHERE period_month=DATE_FORMAT(NEW.date,'%Y-%m') AND target_key=IF(NEW.transaction_scope='division','division',CONCAT('school:',NEW.school_id)) LIMIT 1); IF COALESCE(pstate,'') NOT IN ('open','reopened') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction period is not open'; END IF; END",
                "CREATE TRIGGER protect_transaction_period_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN DECLARE oldstate VARCHAR(16); DECLARE newstate VARCHAR(16); IF NOT (NEW.id=OLD.id AND (NEW.transaction_scope <=> OLD.transaction_scope) AND (NEW.bir_status <=> OLD.bir_status) AND (NEW.bir_reference <=> OLD.bir_reference) AND (NEW.bir_encoded_at <=> OLD.bir_encoded_at) AND (NEW.bir_encoded_by <=> OLD.bir_encoded_by) AND (NEW.bir_reconciled_at <=> OLD.bir_reconciled_at) AND (NEW.bir_reconciled_by <=> OLD.bir_reconciled_by) AND (NEW.school_id <=> OLD.school_id) AND (NEW.supplier_id <=> OLD.supplier_id) AND (NEW.transaction_type <=> OLD.transaction_type) AND (NEW.source_fund_id <=> OLD.source_fund_id) AND (NEW.created_by <=> OLD.created_by) AND (NEW.date <=> OLD.date) AND (NEW.gross <=> OLD.gross) AND (NEW.status <=> OLD.status) AND (NEW.snapshot <=> OLD.snapshot) AND (NEW.submission_hash <=> OLD.submission_hash) AND (NEW.submitted_at <=> OLD.submitted_at) AND (NEW.submitted_by <=> OLD.submitted_by) AND (NEW.finalized_at <=> OLD.finalized_at) AND (NEW.finalized_by <=> OLD.finalized_by) AND (NEW.void_reason <=> OLD.void_reason) AND (NEW.voided_at <=> OLD.voided_at) AND (NEW.voided_by <=> OLD.voided_by) AND (NEW.deletion_reason <=> OLD.deletion_reason) AND (NEW.deleted_at <=> OLD.deleted_at) AND (NEW.deleted_by <=> OLD.deleted_by) AND (NEW.created_at <=> OLD.created_at) AND (NEW.version <=> OLD.version)) THEN SET oldstate=(SELECT state FROM transaction_periods WHERE period_month=DATE_FORMAT(OLD.date,'%Y-%m') AND target_key=IF(OLD.transaction_scope='division','division',CONCAT('school:',OLD.school_id)) LIMIT 1); SET newstate=(SELECT state FROM transaction_periods WHERE period_month=DATE_FORMAT(NEW.date,'%Y-%m') AND target_key=IF(NEW.transaction_scope='division','division',CONCAT('school:',NEW.school_id)) LIMIT 1); IF COALESCE(oldstate,'') NOT IN ('open','reopened') OR COALESCE(newstate,'') NOT IN ('open','reopened') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction period is not open'; END IF; END IF; END",
            ],
        ],
        [
            'id' => '034_checked_certificate_integrity',
            'description' => 'Add independent SHA-256 integrity seal of the full checked Form 2307 snapshot, leaving the original submission hash unchanged.',
            'optional' => false,
            'statements' => [
                "ALTER TABLE transactions ADD COLUMN checked_snapshot_hash CHAR(64) NULL AFTER submission_hash",
                // Backfill only active Checked records. Void/deleted rows are protected as immutable by existing triggers.
                "UPDATE transactions SET checked_snapshot_hash=SHA2(snapshot,256) WHERE status='checked' AND snapshot IS NOT NULL AND checked_snapshot_hash IS NULL",
            ],
        ],
        [
            'id' => '035_application_update_history',
            'description' => 'Record reviewed application-installation attempts, outcome and release details without affecting transaction data.',
            'optional' => false,
            'statements' => [
                "CREATE TABLE IF NOT EXISTS update_history (id VARCHAR(64) PRIMARY KEY,from_version VARCHAR(32) NOT NULL,to_version VARCHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,actor_name VARCHAR(180) NOT NULL,status VARCHAR(24) NOT NULL,release_details LONGTEXT NULL,package_sha256 CHAR(64) NULL,error_summary VARCHAR(350) NULL,started_at VARCHAR(35) NOT NULL,finished_at VARCHAR(35) NULL,INDEX idx_update_history_started(started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ],

        [
            'id'=>'036_transaction_volume_indexes',
            'description'=>'Add targeted chronological and scoped transaction indexes for Division-wide register paging and month filtering.',
            'optional'=>false,
            'statements'=>[
                "ALTER TABLE transactions ADD INDEX tx_created_id(created_at,id)",
                "ALTER TABLE transactions ADD INDEX tx_scope_date_created(transaction_scope,date,created_at,id)",
                "ALTER TABLE transactions ADD INDEX tx_school_created_id(school_id,created_at,id)",
            ],
        ],
        [
            'id'=>'037_checked_hash_period_guard',
            'description'=>'Block checked certificate seal changes when the related transaction month is closed, including hash-only database updates.',
            'optional'=>false,
            'statements'=>[
                "CREATE TRIGGER protect_checked_hash_period_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN DECLARE period_state VARCHAR(16); IF NOT (NEW.checked_snapshot_hash <=> OLD.checked_snapshot_hash) THEN SET period_state=(SELECT state FROM transaction_periods WHERE period_month=DATE_FORMAT(OLD.date,'%Y-%m') AND target_key=IF(OLD.transaction_scope='division','division',CONCAT('school:',OLD.school_id)) LIMIT 1); IF COALESCE(period_state,'') NOT IN ('open','reopened') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Checked certificate hash cannot change in a closed transaction period'; END IF; END IF; END",
            ],
        ],
    ];
}

function normalize_transaction_reference_school_code(string $value, string $schoolId=''): string {
    $code = strtoupper(trim($value));
    $code = preg_replace('/[^A-Z0-9]+/', '-', $code) ?? '';
    $code = trim($code, '-');
    if ($code === '') {
        $fallback = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $schoolId) ?? '');
        $code = 'SCH' . substr($fallback, 0, 8);
    }
    return substr($code, 0, 24);
}

function transaction_reference_year(?string $createdAt, ?string $date=null): int {
    foreach ([$date, $createdAt] as $candidate) {
        if (is_string($candidate) && preg_match('/^(19|20)\\d{2}/', $candidate, $m)) return (int)$m[0];
    }
    return (int)gmdate('Y');
}

function reserve_transaction_reference(PDO $pdo, string $schoolId, string $schoolCode, ?string $createdAt=null, ?string $date=null): string {
    $year = transaction_reference_year($createdAt, $date);
    $code = normalize_transaction_reference_school_code($schoolCode, $schoolId);
    $stmt = $pdo->prepare("INSERT INTO transaction_reference_counters(reference_year,school_id,last_value) VALUES(?,?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_value=LAST_INSERT_ID(last_value+1)");
    $stmt->execute([$year, $schoolId]);
    $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($seq < 1) throw new RuntimeException('Could not allocate a transaction reference.');
    return sprintf('%04d-%s-%06d', $year, $code, $seq);
}

function reserve_unique_transaction_reference(PDO $pdo, string $schoolId, string $schoolCode, ?string $createdAt=null, ?string $date=null): string {
    $check = $pdo->prepare('SELECT id FROM transactions WHERE reference=? LIMIT 1');
    for ($attempt=0; $attempt<20; $attempt++) {
        $reference = reserve_transaction_reference($pdo, $schoolId, $schoolCode, $createdAt, $date);
        $check->execute([$reference]);
        if (!$check->fetchColumn()) return $reference;
    }
    throw new RuntimeException('Could not allocate a unique transaction reference.');
}

function reserve_division_transaction_reference(PDO $pdo, ?string $createdAt=null, ?string $date=null): string {
    $year = transaction_reference_year($createdAt, $date);
    $stmt = $pdo->prepare("INSERT INTO division_transaction_reference_counters(reference_year,last_value) VALUES(?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_value=LAST_INSERT_ID(last_value+1)");
    $stmt->execute([$year]);
    $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($seq < 1) throw new RuntimeException('Could not allocate a Division transaction reference.');
    return sprintf('%04d-DIVISION-%06d', $year, $seq);
}

function reserve_unique_division_transaction_reference(PDO $pdo, ?string $createdAt=null, ?string $date=null): string {
    $check = $pdo->prepare('SELECT id FROM transactions WHERE reference=? LIMIT 1');
    for ($attempt=0; $attempt<20; $attempt++) {
        $reference = reserve_division_transaction_reference($pdo, $createdAt, $date);
        $check->execute([$reference]);
        if (!$check->fetchColumn()) return $reference;
    }
    throw new RuntimeException('Could not allocate a unique Division transaction reference.');
}

function backfill_transaction_references(PDO $pdo): void {
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'reference'")->fetch();
        if (!$exists) return;
        $missing = $pdo->query("SELECT id FROM transactions WHERE reference IS NULL OR reference='' LIMIT 1")->fetchColumn();
        if (!$missing) return;
        $rows = $pdo->query("SELECT t.id,t.school_id,t.created_at,t.date,t.snapshot,s.data AS school_data FROM transactions t LEFT JOIN schools s ON s.id=t.school_id WHERE t.reference IS NULL OR t.reference='' ORDER BY t.created_at ASC,t.id ASC")->fetchAll();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("UPDATE transactions SET reference=? WHERE id=? AND (reference IS NULL OR reference='')");
            foreach ($rows as $row) {
                $schoolCode = '';
                if (!empty($row['snapshot'])) {
                    try {
                        $snapshot = json_decode_assoc((string)$row['snapshot']);
                        $schoolCode = trim((string)($snapshot['school']['code'] ?? ''));
                    } catch (Throwable) {}
                }
                if ($schoolCode === '' && !empty($row['school_data'])) {
                    try {
                        $school = json_decode_assoc((string)$row['school_data']);
                        $schoolCode = trim((string)($school['code'] ?? ''));
                    } catch (Throwable) {}
                }
                $reference = reserve_unique_transaction_reference($pdo, (string)$row['school_id'], $schoolCode, (string)($row['created_at'] ?? ''), (string)($row['date'] ?? ''));
                $update->execute([$reference, $row['id']]);
                if ($update->rowCount() !== 1) {
                    $check = $pdo->prepare('SELECT reference FROM transactions WHERE id=?');
                    $check->execute([$row['id']]);
                    if ((string)$check->fetchColumn() === '') throw new RuntimeException('Could not assign a reference to an existing transaction.');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Transaction reference backfill failed: '.$e->getMessage(), 0, $e);
    }
}

function quote_schema_identifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function ensure_division_transaction_school_nullable(PDO $pdo): void {
    $stmt=$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transactions' AND COLUMN_NAME='school_id' LIMIT 1");
    $nullable=strtoupper((string)($stmt->fetchColumn() ?: ''));
    if($nullable==='' || $nullable==='YES') return;

    try {
        $pdo->exec("ALTER TABLE transactions MODIFY school_id VARCHAR(64) NULL");
        return;
    } catch (PDOException $directError) {
        $fkStmt=$pdo->query("SELECT k.CONSTRAINT_NAME,rc.UPDATE_RULE,rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND rc.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='transactions' AND k.COLUMN_NAME='school_id' AND k.REFERENCED_TABLE_NAME='schools'");
        $fks=$fkStmt->fetchAll();
        if(!$fks) throw $directError;

        $dropped=[];
        try {
            foreach($fks as $fk){
                $name=(string)$fk['CONSTRAINT_NAME'];
                $pdo->exec('ALTER TABLE transactions DROP FOREIGN KEY '.quote_schema_identifier($name));
                $dropped[]=$fk;
            }
            $pdo->exec("ALTER TABLE transactions MODIFY school_id VARCHAR(64) NULL");
        } catch (Throwable $modifyError) {
            foreach($dropped as $fk){
                try {
                    $name=(string)$fk['CONSTRAINT_NAME'];
                    $pdo->exec('ALTER TABLE transactions ADD CONSTRAINT '.quote_schema_identifier($name).' FOREIGN KEY (school_id) REFERENCES schools(id)');
                } catch (Throwable) {}
            }
            throw $modifyError;
        }

        foreach($dropped as $fk){
            $name=(string)$fk['CONSTRAINT_NAME'];
            $update=strtoupper((string)($fk['UPDATE_RULE']??'RESTRICT'));
            $delete=strtoupper((string)($fk['DELETE_RULE']??'RESTRICT'));
            $allowed=['CASCADE','SET NULL','NO ACTION','RESTRICT'];
            if(!in_array($update,$allowed,true))$update='RESTRICT';
            if(!in_array($delete,$allowed,true))$delete='RESTRICT';
            $pdo->exec('ALTER TABLE transactions ADD CONSTRAINT '.quote_schema_identifier($name).' FOREIGN KEY (school_id) REFERENCES schools(id) ON UPDATE '.$update.' ON DELETE '.$delete);
        }
    }
}

function ensure_database(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();
    // Normal requests must not take the schema advisory lock or execute DDL when
    // every known migration already has a recorded outcome. Preserve the legacy
    // reference backfill check so interrupted historical data repairs can resume.
    $definitions = migration_definitions();
    try {
        $statuses = $pdo->query('SELECT id,status FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1146) throw $e;
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(100) PRIMARY KEY,description VARCHAR(500) NOT NULL,applied_at VARCHAR(35) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'applied',note VARCHAR(1000) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $statuses = [];
    }
    $complete = true;
    foreach ($definitions as $definition) {
        if (!in_array($statuses[$definition['id']] ?? null, ['applied','skipped'], true)) {
            $complete = false;
            break;
        }
    }
    if ($complete) {
        backfill_transaction_references($pdo);
        $done = true;
        return;
    }
    $locked = false;
    try { $locked = (int)$pdo->query("SELECT GET_LOCK('nonius_schema_migrations',15)")->fetchColumn() === 1; } catch (Throwable) {}
    if (!$locked) throw new RuntimeException('Database migration lock could not be acquired. Try again in a moment.');
    try {
        foreach ($definitions as $migration) {
            $q = $pdo->prepare('SELECT status FROM schema_migrations WHERE id=?');
            $q->execute([$migration['id']]);
            $existing = $q->fetch();
            if (in_array(($existing['status'] ?? null), ['applied','skipped'], true)) continue;
            try {
                foreach ($migration['statements'] as $sql) {
                    try { $pdo->exec($sql); }
                    catch (PDOException $e) {
                        if (in_array((int)($e->errorInfo[1] ?? 0), [1060,1061,1359,1826], true)) continue;
                        throw $e;
                    }
                }
                if (($migration['id'] ?? '') === '027_division_level_transactions') ensure_division_transaction_school_nullable($pdo);
                if (($migration['id'] ?? '') === '034_checked_certificate_integrity') {
                    // A preceding interrupted ALTER may already have added the column.
                    // Never treat an unrelated same-name column as a completed migration.
                    $field = $pdo->query("SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transactions' AND COLUMN_NAME='checked_snapshot_hash'")->fetch(PDO::FETCH_ASSOC);
                    if (!$field || strtolower((string)$field['DATA_TYPE']) !== 'char' || (int)$field['CHARACTER_MAXIMUM_LENGTH'] !== 64 || strtoupper((string)$field['IS_NULLABLE']) !== 'YES') {
                        throw new RuntimeException('Migration 034 requires a nullable CHAR(64) checked_snapshot_hash column; inspect the existing column before resuming.');
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO schema_migrations(id,description,applied_at,status,note) VALUES(?,?,?,'applied',NULL) ON DUPLICATE KEY UPDATE description=VALUES(description),applied_at=VALUES(applied_at),status='applied',note=NULL");
                $stmt->execute([$migration['id'], $migration['description'], now_iso()]);
            } catch (Throwable $e) {
                if (empty($migration['optional'])) throw $e;
                $stmt = $pdo->prepare("INSERT INTO schema_migrations(id,description,applied_at,status,note) VALUES(?,?,?,'skipped',?) ON DUPLICATE KEY UPDATE description=VALUES(description),applied_at=VALUES(applied_at),status='skipped',note=VALUES(note)");
                $stmt->execute([$migration['id'], $migration['description'], now_iso(), substr($e->getMessage(), 0, 1000)]);
            }
        }
        backfill_transaction_references($pdo);
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('nonius_schema_migrations')")->fetch(); } catch (Throwable) {}
    }
    $done = true;
}

function migration_status(): array {
    ensure_database();
    $rows = db()->query('SELECT id,description,applied_at,status,note FROM schema_migrations ORDER BY id')->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[$r['id']] = $r;
    $out = [];
    foreach (migration_definitions() as $m) {
        $r = $map[$m['id']] ?? null;
        $out[] = [
            'id' => $m['id'], 'description' => $m['description'], 'optional' => (bool)$m['optional'],
            'status' => $r['status'] ?? 'pending', 'applied_at' => $r['applied_at'] ?? null, 'note' => $r['note'] ?? null,
        ];
    }
    return $out;
}

/** Read-only diagnostics for the Project Owner. Never mutate an installed schema here. */
function schema_migration_integrity(PDO $pdo): array {
    $warnings = [];
    $recorded = $pdo->query('SELECT id,status FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $expectedTriggers = [];
    foreach (migration_definitions() as $migration) {
        $id = (string)$migration['id'];
        $status = (string)($recorded[$id] ?? 'pending');
        if ($status === 'skipped') {
            $warnings[] = 'Migration '.$id.' was skipped. Review its database protections and permissions.';
        } elseif ($status !== 'applied') {
            $warnings[] = 'Migration '.$id.' is '.$status.'.';
        }
        if ($status !== 'applied') continue;
        foreach ($migration['statements'] as $statement) {
            if (preg_match('/^\s*DROP\s+TRIGGER\s+IF\s+EXISTS\s+`?([a-z0-9_]+)`?/i', $statement, $match)) {
                unset($expectedTriggers[strtolower($match[1])]);
            }
            if (preg_match('/^\s*CREATE\s+TRIGGER\s+`?([a-z0-9_]+)`?/i', $statement, $match)) {
                $expectedTriggers[strtolower($match[1])] = $id;
            }
        }
    }
    $triggersVerified = false;
    try {
        $found = [];
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            $found[strtolower((string)$trigger['Trigger'])] = true;
        }
        $triggersVerified = true;
        foreach ($expectedTriggers as $name => $id) {
            if (!isset($found[$name])) {
                $warnings[] = 'Expected database trigger '.$name.' is missing (migration '.$id.').';
            }
        }
    } catch (Throwable $e) {
        $warnings[] = 'Database trigger inspection is unavailable; verify SHOW TRIGGERS permissions.';
    }
    if (($recorded['031_monthly_transaction_period_control'] ?? '') === 'applied') {
        try {
            $rows = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transaction_periods','transaction_period_history') AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['transaction_periods','transaction_period_history'] as $name) {
                if (!in_array($name, $rows, true)) $warnings[] = 'Required monthly-period table '.$name.' is missing.';
            }
        } catch (Throwable $e) {
            $warnings[] = 'Monthly-period table inspection is unavailable.';
        }
    }
    if (($recorded['034_checked_certificate_integrity'] ?? '') === 'applied') {
        try {
            $field = $pdo->query("SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transactions' AND COLUMN_NAME='checked_snapshot_hash'")->fetch(PDO::FETCH_ASSOC);
            if (!$field || strtolower((string)$field['DATA_TYPE']) !== 'char' || (int)$field['CHARACTER_MAXIMUM_LENGTH'] !== 64 || strtoupper((string)$field['IS_NULLABLE']) !== 'YES') {
                $warnings[] = 'Checked certificate integrity column has an unexpected or missing definition.';
            } elseif ($pdo->query("SELECT id FROM transactions WHERE status='checked' AND snapshot IS NOT NULL AND checked_snapshot_hash IS NULL LIMIT 1")->fetchColumn() !== false) {
                $warnings[] = 'At least one Checked transaction is missing its certificate integrity hash.';
            }
        } catch (Throwable $e) {
            $warnings[] = 'Checked certificate integrity column inspection is unavailable.';
        }
    }
    return ['warnings' => $warnings, 'expected_trigger_count' => count($expectedTriggers), 'triggers_verified' => $triggersVerified];
}
