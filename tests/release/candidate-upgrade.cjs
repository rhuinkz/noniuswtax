'use strict';
// Synthetic-only in-container rehearsal of the actual v0.4.57-rc.1 updater ZIP.
// Never connects to Hostinger; no real data. A second script verifies rollback.
const { chromium } = require('@playwright/test');
const mysql = require('mysql2/promise');
const fs = require('fs');
const crypto = require('crypto');
const BASE = process.env.BASE_URL || 'http://web';
const OUT = '/suite/output';
const VERSION = '0.4.57-rc.1';
const EXPECTED_SHA = process.env.NONIUS_TEST_CI_MODE === 'true' && process.env.GITHUB_ACTIONS === 'true'
  ? process.env.NONIUS_TEST_ZIP_SHA256
  : 'e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a';
if (!/^[a-f0-9]{64}$/.test(EXPECTED_SHA)) throw Error('Invalid test ZIP SHA-256 override.');
const CANDIDATE = '/suite/candidate/noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip';
const EVIDENCE = OUT + '/staging-upgrade.json';
function check(condition, message) { if (!condition) throw Error(message); }
function connect() {
  return mysql.createConnection({
    host: 'db', user: 'nonius_test', password: 'local-release-password-only',
    database: 'nonius_release_test', connectTimeout: 10000
  });
}
async function stats(db) {
  const [[tx]] = await db.query('SELECT COUNT(*) AS n FROM transactions');
  const [[audit]] = await db.query('SELECT COUNT(*) AS n FROM audit');
  const [[migrations]] = await db.query('SELECT COUNT(*) AS n FROM schema_migrations');
  const [triggers] = await db.query('SHOW TRIGGERS');
  const [checked] = await db.query("SELECT id, checked_snapshot_hash FROM transactions WHERE status='checked' ORDER BY created_at ASC LIMIT 1");
  return {
    transactions: Number(tx.n), audit: Number(audit.n), migrations: Number(migrations.n),
    triggers: triggers.length,
    checked_id: checked?.[0]?.id || '',
    checked_hash: checked?.[0]?.checked_snapshot_hash || ''
  };
}
async function main() {
  const report = {
    status:'failed', environment:'disposable-local-docker-only',
    source_commit:'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f',
    version:VERSION, candidate_sha256:EXPECTED_SHA,
    production_approval:'PENDING',
    staged_existing_live_database:false
  };
  fs.mkdirSync(OUT, {recursive:true});
  const zip = fs.readFileSync(CANDIDATE);
  check(crypto.createHash('sha256').update(zip).digest('hex') === EXPECTED_SHA,
    'Candidate ZIP differs from verified local/CI source. Stop.');
  const db = await connect();
  const browser = await chromium.launch({headless:true});
  try {
    report.before = await stats(db);
    check(report.before.transactions >= 10003 && report.before.triggers >= 15,
      'Fresh synthetic installed data not prepared; do not run updater.');
    const page = await browser.newPage({acceptDownloads:true});
    await page.goto(BASE + '/login.php');
    await page.locator('input[name="identifier"]').fill('release_owner');
    await page.locator('input[name="password"]').fill('LocalOwnerPassword!12345');
    await page.getByRole('button', {name:'Sign in'}).click();
    await page.waitForURL(/(?:index|first-login)\.php/, {timeout:20000});
    const boot = await page.request.get(BASE + '/api.php?scope=bootstrap');
    check(boot.status() === 200, 'Owner bootstrap failed before update');
    const state = await boot.json();
    check(state.actor?.role === 'project_owner' && !!state.csrf, 'Owner authentication or CSRF missing');
    const headers = {'X-CSRF-Token':state.csrf};
    const disallowed = await page.request.post(BASE + '/update.php', {
      data:{action:'install',confirmed:true,review_token:'unreviewed'}, headers
    });
    check(disallowed.status() >= 400, 'Update permitted without prior review and consent');
    const review = await page.request.post(BASE + '/update.php', {
      data:{action:'review',filename:'noniuswtax-rc.zip',data_base64:zip.toString('base64')},
      headers, timeout:90000
    });
    const reviewed = await review.json();
    check(review.status() === 200 && reviewed.ok === true,
      'Updater review failed: HTTP '+review.status()+' '+JSON.stringify(reviewed));
    check(reviewed.details.version === VERSION
      && reviewed.details.current_version === '0.4.56'
      && reviewed.details.sha256 === EXPECTED_SHA
      && reviewed.details.file_count === 27,
      'Updater review version, SHA, or inventory did not match');
    report.updater_review = {status:'passed', version:reviewed.details.version,
      file_count:reviewed.details.file_count, sha256:reviewed.details.sha256};
    const install = await page.request.post(BASE + '/update.php', {
      data:{action:'install',review_token:reviewed.review_token,confirmed:true},
      headers, timeout:180000
    });
    const result = await install.json();
    check(install.status() === 200 && result.ok === true,
      'Updater installation failed: HTTP '+install.status()+' '+JSON.stringify(result));
    check(result.from === '0.4.56' && result.to === VERSION,
      'Updater version transition mismatch');
    check(result.database_backup_verified?.triggers >= 15,
      'Updater database snapshot did not include expected triggers');
    check(/^before-0\.4\.57-rc\.1-.+\.zip$/.test(result.backup || ''),
      'Updater code backup missing');
    check(/^before-0\.4\.57-rc\.1-.+-db\.sql$/.test(result.database_backup || ''),
      'Updater database backup missing');
    report.install = {
      status:'passed', from:result.from, to:result.to,
      backup:result.backup, database_backup:result.database_backup,
      database_backup_tables:result.database_backup_verified.tables,
      database_backup_triggers:result.database_backup_verified.triggers
    };
    // The updater responds before a separate Apache request has necessarily
    // refreshed its compiled PHP scripts (e.g., enabled OPcache validation).
    // Poll briefly; never hide a persistent stale-version or failed install.
    // Use a NEW anonymous context and cache-busting URL for each sample.
    const versionedAsset = 'assets/style.css?v=' + VERSION;
    const samples = [];
    let confirmed = false;
    for (let attempt = 0; attempt < 16; attempt++) {
      if (attempt > 0) await new Promise(resolve => setTimeout(resolve, 2000));
      const anonymous = await browser.newContext();
      try {
        const probe = await anonymous.request.get(
          BASE + '/login.php?staging_version_probe=' + attempt + '-' + Date.now(),
          {maxRedirects: 0, timeout: 15000, headers:{'Cache-Control':'no-cache'}}
        );
        const html = await probe.text();
        const cssRefs = html.split('href="').slice(1)
          .map(part => part.split('"')[0])
          .filter(href => href.includes('style.css')).slice(0, 3);
        const match = probe.status() === 200 && html.includes(versionedAsset);
        samples.push({
          attempt:attempt + 1, http_status:probe.status(),
          observed_css:cssRefs, version_matched:match
        });
        if (match) { confirmed = true; break; }
      } finally {
        await anonymous.close();
      }
    }
    report.unauthenticated_runtime_probe = {
      expected_asset:versionedAsset,
      matched:confirmed,
      attempts:samples.length,
      observations:samples
    };
    check(confirmed,
      'Candidate runtime stayed stale after bounded HTTP refresh probes; ' +
      'expected ' + versionedAsset + ', last response ' +
      JSON.stringify(samples[samples.length - 1]) +
      '. Review staging-results/candidate-runtime-diagnostic.json ' +
      'for the installed on-disk file version.');
    // Following a 302 to login returns HTTP 200 too; explicitly check the URL.
    let mainPage = await page.goto(BASE + '/index.php', {waitUntil:'domcontentloaded'});
    report.post_install_navigation = {
      status: mainPage?.status() ?? null, url: page.url(), reauthenticated: false
    };
    if (new URL(page.url()).pathname === '/login.php') {
      report.post_install_navigation.reauthenticated = true;
      await page.locator('input[name="identifier"]').fill('release_owner');
      await page.locator('input[name="password"]').fill('LocalOwnerPassword!12345');
      await page.getByRole('button', {name:'Sign in'}).click();
      await page.waitForURL(url =>
        ['/index.php','/first-login.php'].includes(url.pathname), {timeout:20000});
      mainPage = await page.goto(BASE + '/index.php', {waitUntil:'domcontentloaded'});
      report.post_install_navigation.after_relogin_url = page.url();
    }
    check(mainPage && mainPage.status() === 200 &&
      new URL(page.url()).pathname === '/index.php',
      'Authenticated candidate dashboard is unavailable: ' +
      (mainPage?.status() ?? 'no response') + ' ' + page.url());
    const renderedVersion = await page.evaluate(() => window.NONIUS?.version ?? null);
    report.authenticated_runtime_version = renderedVersion;
    check(renderedVersion === VERSION,
      'Candidate runtime version mismatch: expected ' + VERSION +
      ', observed ' + JSON.stringify(renderedVersion) + ', URL ' + page.url());
    report.after = await stats(db);
    check(report.after.transactions === report.before.transactions,
      'Transaction rows changed during the staging update');
    check(report.after.triggers === report.before.triggers,
      'Triggers changed unexpectedly during the staging update');
    check(report.after.audit >= report.before.audit,
      'Audit history was lost during the update');
    check(report.after.checked_hash === report.before.checked_hash,
      'Checked transaction hash changed during staging update');
    report.status = 'passed';
    console.log('PASS: exact ZIP reviewed, installed via real updater, '+report.before.transactions+
      ' transactions retained and '+report.after.triggers+' database triggers intact.');
  } catch (e) {
    report.error = String(e?.message || e);
    console.error('FAIL candidate staging upgrade: ' + report.error);
    throw e;
  } finally {
    fs.writeFileSync(EVIDENCE, JSON.stringify(report,null,2));
    await db.end();
    await browser.close();
  }
}
main().catch(()=>process.exitCode=1);
