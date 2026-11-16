'use strict';
const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const fs = require('fs');

// ALL credentials, entities and payments in this file are deliberately fictional.
// Docker Compose exposes no MariaDB TCP port to the host or the Internet.
const BASE = process.env.BASE_URL || 'http://web';
const OWNER_USER = 'release_owner';
const OWNER_PASSWORD = 'LocalOwnerPassword!12345';
const SCHOOL_PASSWORD = 'LocalOnlyPass!1234';
// PHP password_hash('LocalOnlyPass!1234', PASSWORD_DEFAULT); local test fixture only.
const SCHOOL_PASSWORD_HASH = '$2y$12$5VVgewYadXk2s04XAhejyOnQB2lQoygUpm/gCFlLEt1jMzzx0DnMK';
const SCHOOL_A = 'synthetic-school-a';
const SCHOOL_B = 'synthetic-school-b';
const SUPPLIER = 'synthetic-supplier';
const TX_A = 'synthetic-transaction-a';
const TX_B = 'synthetic-transaction-b';
const TEST_MONTH = '2026-01';
const AT = '2026-01-12T10:00:00.000Z';

async function database() {
  return mysql.createConnection({
    host: process.env.DB_HOST || 'db',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'nonius_test',
    password: process.env.DB_PASSWORD || 'local-release-password-only',
    database: process.env.DB_NAME || 'nonius_release_test',
    connectTimeout: 10000,
    multipleStatements: false
  });
}

async function signIn(page, username, password) {
  await page.goto('/login.php');
  await page.locator('input[name="identifier"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/(?:index|first-login)\.php/);
}

async function bootstrap(page) {
  const response = await page.request.get(BASE + '/api.php?scope=bootstrap');
  expect(response.status()).toBe(200);
  const data = await response.json();
  expect(data.csrf).toBeTruthy();
  return data;
}

async function postApi(page, csrf, action, payload = {}) {
  return page.request.post(BASE + '/api.php', {
    data: { action, ...payload },
    headers: { 'X-CSRF-Token': csrf }
  });
}

async function mustFailSql(connection, sql, params, label) {
  let rejected = false;
  try {
    await connection.execute(sql, params);
  } catch (e) {
    rejected = true;
    expect(String(e.message), label).toMatch(/period|closed|immutable|cannot|append|not open|signal/i);
  }
  expect(rejected, label).toBe(true);
}

test.describe.configure({ mode: 'serial' });
// Keep core browser tests usable offline; BIR PDF rendering still requires its
// separately approved asset/dependency check before production deployment.
test.beforeEach(async ({ page }) => {
  await page.route('https://cdnjs.cloudflare.com/**', route => route.abort());
});

test('01 fresh local installer creates the application with synthetic credentials', async ({ page }) => {
  let loaded = false;
  for (let i = 0; i < 30; i++) {
    try {
      const response = await page.goto('/install.php', { timeout: 10000 });
      if (response && response.ok()) { loaded = true; break; }
    } catch (_) {}
    await page.waitForTimeout(2000);
  }
  expect(loaded).toBe(true);
  await expect(page.getByRole('heading', { name: /Install Non-IUS/i })).toBeVisible();
  await page.locator('input[name="base_url"]').fill(BASE);
  await page.locator('input[name="db_host"]').fill(process.env.DB_HOST || 'db');
  await page.locator('input[name="db_port"]').fill(process.env.DB_PORT || '3306');
  await page.locator('input[name="db_name"]').fill(process.env.DB_NAME || 'nonius_release_test');
  await page.locator('input[name="db_user"]').fill(process.env.DB_USER || 'nonius_test');
  await page.locator('input[name="db_password"]').fill(process.env.DB_PASSWORD || 'local-release-password-only');
  await page.locator('input[name="admin_username"]').fill(OWNER_USER);
  await page.locator('input[name="admin_email"]').fill('owner@example.invalid');
  await page.locator('input[name="admin_password"]').fill(OWNER_PASSWORD);
  await page.getByRole('button', { name: 'Install application' }).click();
  await expect(page.getByText('Installation completed.')).toBeVisible({ timeout: 60000 });
  const locked = await page.request.get(BASE + '/install.php');
  expect(locked.url()).toContain('/login.php');
});

test('02 owner can log in; invalid credentials are denied; API bootstraps', async ({ page }) => {
  await page.goto('/login.php');
  await page.locator('input[name="identifier"]').fill(OWNER_USER);
  await page.locator('input[name="password"]').fill('incorrect-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/login\.php/);
  await expect(page.locator('.notice.error')).toBeVisible();
  await signIn(page, OWNER_USER, OWNER_PASSWORD);
  const data = await bootstrap(page);
  expect(data.actor.role).toBe('project_owner');
  expect(Array.isArray(data.schools)).toBe(true);
  expect(Array.isArray(data.members)).toBe(true);
});

test('03 mandatory schema migrations and database triggers are present', async () => {
  const db = await database();
  try {
    const [migrationRows] = await db.query('SELECT id,status FROM schema_migrations ORDER BY id');
    expect(migrationRows.length).toBeGreaterThanOrEqual(37);
    const status = Object.fromEntries(migrationRows.map(row => [row.id, row.status]));
    for (const id of [
      '001_php_hosting_core',
      '031_monthly_transaction_period_control',
      '034_checked_certificate_integrity',
      '037_checked_hash_period_guard'
    ]) {
      expect(status[id], 'Required migration ' + id).toBe('applied');
    }
    const [triggerRows] = await db.query('SHOW TRIGGERS');
    const names = new Set(triggerRows.map(row => row.Trigger));
    for (const name of [
      'protect_checked_hash_period_update',
      'protect_transaction_period_insert',
      'protect_transaction_period_update',
      'protect_transaction_delete',
      'protect_audit_update',
      'protect_audit_delete'
    ]) {
      expect(names.has(name), 'Required trigger ' + name).toBe(true);
    }
  } finally {
    await db.end();
  }
});

test('04 owner SQL diagnostics, CSRF and retired reset endpoint security', async ({ page }) => {
  await signIn(page, OWNER_USER, OWNER_PASSWORD);
  const data = await bootstrap(page);

  const noCsrf = await page.request.post(BASE + '/api.php', { data: { action: 'saveMaster', kind: 'schools', record: {} } });
  expect(noCsrf.status()).toBeGreaterThanOrEqual(400);

  const reset = await postApi(page, data.csrf, 'resetTestData');
  expect(reset.status()).toBeGreaterThanOrEqual(400);
  expect((await reset.json()).error).toMatch(/Unknown action/i);

  const read = await page.request.post(BASE + '/sql-console.php', {
    data: { sql: 'SELECT 1 AS test_value' },
    headers: { 'X-CSRF-Token': data.csrf }
  });
  expect(read.status()).toBe(200);
  const result = await read.json();
  expect(result.ok).toBe(true);
  expect(Number(result.rows[0].test_value)).toBe(1);

  for (const sql of ['DELETE FROM audit', 'SELECT SLEEP(2)', "SELECT 1 INTO OUTFILE '/tmp/output'", 'SELECT 1; DELETE FROM audit']) {
    const denied = await page.request.post(BASE + '/sql-console.php', {
      data: { sql },
      headers: { 'X-CSRF-Token': data.csrf }
    });
    expect(denied.status(), 'Unsafe diagnostic SQL should be rejected: ' + sql).toBeGreaterThanOrEqual(400);
  }
});

test('05 synthetic transactions are locked by month and protected by DB triggers', async () => {
  const db = await database();
  try {
    for (const [id, name] of [[SCHOOL_A, 'Synthetic School Alpha'], [SCHOOL_B, 'Synthetic School Beta']]) {
      await db.execute(
        'INSERT INTO schools(id,name,data,active,version) VALUES(?,?,?,?,?)',
        [id, name, JSON.stringify({ id, name, code: id, address: 'Fictional test address', representative: 'Test User', position: 'Tester' }), 1, 1]
      );
    }
    await db.execute(
      'INSERT INTO suppliers(id,name,tin,data,active,version) VALUES(?,?,?,?,?)',
      [SUPPLIER, 'Synthetic Supplier', '00000000000000', JSON.stringify({ id: SUPPLIER, name: 'Synthetic Supplier' }), 1, 1]
    );
    for (const school of [SCHOOL_A, SCHOOL_B]) {
      await db.execute(
        'INSERT INTO transaction_periods(period_month,target_key,transaction_scope,school_id,state,last_action_at,last_action_by) VALUES(?,?,?,?,?,?,?)',
        [TEST_MONTH, 'school:' + school, 'school', school, 'open', AT, 'synthetic-actor']
      );
    }
    for (const [id, school] of [[TX_A, SCHOOL_A], [TX_B, SCHOOL_B]]) {
      await db.execute(
        'INSERT INTO transactions(id,school_id,supplier_id,created_by,date,gross,status,snapshot,created_at,version) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [id, school, SUPPLIER, 'synthetic-actor', '2026-01-12', 11200, 'submitted', '{"synthetic":true}', AT, 1]
      );
    }
    await db.execute(
      'UPDATE transactions SET checked_snapshot_hash=SHA2(?,256) WHERE id=?',
      ['open-period-update', TX_A]
    );
    await db.execute(
      'UPDATE transaction_periods SET state=? WHERE period_month=? AND target_key=?',
      ['closed', TEST_MONTH, 'school:' + SCHOOL_A]
    );
    await mustFailSql(db,
      'UPDATE transactions SET checked_snapshot_hash=SHA2(?,256) WHERE id=?',
      ['closed-period-tampering', TX_A],
      'Hash-only tampering in closed month');
    await mustFailSql(db,
      'UPDATE transactions SET gross=gross+1 WHERE id=?',
      [TX_A],
      'Value tampering in closed month');
    await mustFailSql(db,
      'INSERT INTO transactions(id,school_id,supplier_id,created_by,date,gross,status,created_at) VALUES(?,?,?,?,?,?,?,?)',
      ['synthetic-after-close', SCHOOL_A, SUPPLIER, 'synthetic-actor', '2026-01-12', 100, 'draft', AT],
      'Inserting into closed month');

    await db.execute(
      'UPDATE transaction_periods SET state=? WHERE period_month=? AND target_key=?',
      ['reopened', TEST_MONTH, 'school:' + SCHOOL_A]
    );
    await db.execute(
      'UPDATE transactions SET checked_snapshot_hash=SHA2(?,256) WHERE id=?',
      ['reopened-period-update', TX_A]
    );
    await mustFailSql(db, 'DELETE FROM transactions WHERE id=?', [TX_A], 'Tax record deletion');
  } finally {
    await db.end();
  }
});

test('06 append-only audit history resists SQL updates and deletion', async () => {
  const db = await database();
  try {
    await db.execute(
      'INSERT INTO audit(id,actor,action,entity,details,at) VALUES(?,?,?,?,?,?)',
      ['synthetic-audit-1', 'synthetic-actor', 'test', 'synthetic', '{}', AT]
    );
    await mustFailSql(db, 'UPDATE audit SET details=? WHERE id=?', ['{"changed":true}', 'synthetic-audit-1'], 'Audit update');
    await mustFailSql(db, 'DELETE FROM audit WHERE id=?', ['synthetic-audit-1'], 'Audit deletion');
  } finally {
    await db.end();
  }
});

test('07 private files are denied by Apache and PDF template is present', async ({ page }) => {
  for (const path of [
    '/config.local.php',
    '/lib/bootstrap.php',
    '/tests/safety-smoke.php',
    '/storage/backups/example.sql',
    '/.git/config',
    '/compose.release-test.yml',
    '/.dockerignore',
    '/.gitignore',
    '/.github/workflows/lint.yml'
  ]) {
    const response = await page.request.get(BASE + path);
    expect([403, 404], path + ' must be inaccessible').toContain(response.status());
  }
  const pdf = fs.readFileSync('/suite/assets/bir2307-v1.pdf');
  expect(pdf.length).toBeGreaterThan(1000);
  expect(pdf.subarray(0, 5).toString('ascii')).toBe('%PDF-');
  expect(pdf.toString('latin1').includes('%%EOF')).toBe(true);
  const served = await page.request.get(BASE + '/assets/bir2307-v1.pdf');
  expect(served.status()).toBe(200);
  const bytes = await served.body();
  expect(bytes.subarray(0, 5).toString('ascii')).toBe('%PDF-');
});

test('08 school user cannot read School B transactions or use administrator functions', async ({ page }) => {
  const db = await database();
  try {
    await db.execute(
      'INSERT INTO members(id,username,email,user_id,password_hash,role,school_id,active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
      ['synthetic-user-school-a', 'synthetic_school_a', null, 'synthetic-user-school-a', SCHOOL_PASSWORD_HASH, 'school_user', SCHOOL_A, 1, AT, AT]
    );
  } finally {
    await db.end();
  }
  await signIn(page, 'synthetic_school_a', SCHOOL_PASSWORD);
  const data = await bootstrap(page);
  expect(data.actor.role).toBe('school_user');
  expect(data.schools.map(row => row.id)).toEqual([SCHOOL_A]);

  const foreign = await page.request.get(BASE + '/api.php?scope=transaction&id=' + encodeURIComponent(TX_B));
  expect(foreign.status()).toBeGreaterThanOrEqual(400);

  const denied = await postApi(page, data.csrf, 'saveMaster', {
    kind: 'schools',
    record: { id: SCHOOL_B, name: 'Cross-tenant modification' }
  });
  expect(denied.status()).toBeGreaterThanOrEqual(400);

  const sql = await page.request.post(BASE + '/sql-console.php', {
    data: { sql: 'SELECT 1' },
    headers: { 'X-CSRF-Token': data.csrf }
  });
  expect(sql.status()).toBeGreaterThanOrEqual(400);

  const reset = await postApi(page, data.csrf, 'resetTestData');
  expect(reset.status()).toBeGreaterThanOrEqual(400);
});

test('09 real API workflow: School draft, submit, certificate and Division check', async ({ page }) => {
  const db = await database();
  try {
    const vat = {
      id: 'synthetic-vat-atc', name: 'TVAT05', tax_type: 'VAT', rate: 5,
      transaction_type: 'All', business_type: 'Both', effective_from: '2020-01-01'
    };
    const ewt = {
      id: 'synthetic-ewt-atc', name: 'TEWT01', tax_type: 'EWT', rate: 1,
      transaction_type: 'All', business_type: 'Both', effective_from: '2020-01-01'
    };
    for (const row of [vat, ewt]) {
      await db.execute('INSERT INTO atcs(id,name,data,active,version) VALUES(?,?,?,?,?)',
        [row.id, row.name, JSON.stringify(row), 1, 1]);
    }
    const profile = {
      id: SUPPLIER, name: 'Synthetic Supplier', business_type: 'Corporate',
      vat_status: 'VAT Registered',
      transaction_profiles: [{ transaction_type: 'Supplier of Goods',
        business_atc: vat.id, ewt_atc: ewt.id }],
      tin: '00000000000000', address: 'Synthetic supplier address', zip: '6300'
    };
    await db.execute('UPDATE suppliers SET data=? WHERE id=?', [JSON.stringify(profile), SUPPLIER]);
    const division = {
      name: 'Synthetic Division', tin: '00000000000000',
      address: 'Synthetic division address', zip: '6300',
      representative: 'Synthetic Division Reviewer',
      designation: 'Testing Reviewer', representative_tin: '00000000000000'
    };
    await db.execute('INSERT INTO division(id,name,data,version) VALUES(?,?,?,1)',
      ['division', division.name, JSON.stringify(division)]);
  } finally {
    await db.end();
  }

  await signIn(page, 'synthetic_school_a', SCHOOL_PASSWORD);
  const school = await bootstrap(page);
  const draft = await postApi(page, school.csrf, 'saveTransaction', {
    supplier_id: SUPPLIER, transaction_type: 'Supplier of Goods',
    source_fund_id: 'fund-mooe', date: '2026-01-13', amount: '112.00'
  });
  expect(draft.status()).toBe(200);
  const created = await draft.json();
  expect(created.status).toBe('draft');
  expect(created.reference).toMatch(/^2026-/);
  expect(created.id).toBeTruthy();

  const submitted = await postApi(page, school.csrf, 'submitTransaction', {
    id: created.id, version: 1
  });
  expect(submitted.status()).toBe(200);
  const submittedData = await submitted.json();
  expect(submittedData.status).toBe('submitted');
  expect(submittedData.submission_hash).toMatch(/^[a-f0-9]{64}$/);

  const schoolCert = await postApi(page, school.csrf, 'certificate', { id: created.id });
  expect(schoolCert.status()).toBe(200);
  const schoolSnapshot = await schoolCert.json();
  expect(schoolSnapshot.integrity_level).toBe('submitted_verified');
  expect(schoolSnapshot.snapshot.amounts.base).toBe(10000);
  expect(schoolSnapshot.snapshot.amounts.gross).toBe(11200);

  const cannotCheck = await postApi(page, school.csrf, 'check', { id: created.id, version: 2 });
  expect(cannotCheck.status()).toBeGreaterThanOrEqual(400);

  await page.goto('/logout.php');
  await signIn(page, OWNER_USER, OWNER_PASSWORD);
  const owner = await bootstrap(page);
  const check = await postApi(page, owner.csrf, 'check', {
    id: created.id, version: 2, remarks: 'Synthetic review approved'
  });
  expect(check.status()).toBe(200);
  expect((await check.json()).ok).toBe(true);

  const certificate = await postApi(page, owner.csrf, 'certificate', { id: created.id });
  expect(certificate.status()).toBe(200);
  const verified = await certificate.json();
  expect(verified.integrity_level).toBe('checked_verified');
  expect(verified.snapshot.amounts.gross).toBe(11200);

  const databaseAgain = await database();
  try {
    const [rows] = await databaseAgain.execute(
      'SELECT status,submission_hash,checked_snapshot_hash,SHA2(snapshot,256) AS calculated_hash FROM transactions WHERE id=?',
      [created.id]
    );
    expect(rows[0].status).toBe('checked');
    expect(rows[0].submission_hash).toMatch(/^[a-f0-9]{64}$/);
    expect(rows[0].checked_snapshot_hash).toBe(rows[0].calculated_hash);
    const [history] = await databaseAgain.execute(
      'SELECT COUNT(*) AS entries FROM transaction_history WHERE transaction_id=?', [created.id]
    );
    expect(Number(history[0].entries)).toBeGreaterThanOrEqual(3);
  } finally {
    await databaseAgain.end();
  }
});

test('10 simultaneous local dashboard reads complete and report response-time samples', async ({ page }) => {
  await signIn(page, OWNER_USER, OWNER_PASSWORD);
  const timings = await Promise.all(Array.from({ length: 15 }, async () => {
    const start = Date.now();
    const response = await page.request.get(BASE + '/api.php?scope=bootstrap', { timeout: 45000 });
    expect(response.status()).toBe(200);
    return Date.now() - start;
  }));
  timings.sort((a, b) => a - b);
  const p95 = timings[Math.ceil(timings.length * 0.95) - 1];
  console.log('Synthetic 15-request dashboard timing: p95=' + p95 + 'ms; max=' + timings.at(-1) + 'ms. Informational only.');
  // This is a smoke check, NOT an approved capacity/load-test threshold.
  expect(timings.length).toBe(15);
});
