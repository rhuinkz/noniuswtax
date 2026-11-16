'use strict';
// Confirm code and database were restored together in disposable Docker.
const { chromium } = require('@playwright/test');
const mysql = require('mysql2/promise');
const fs = require('fs');
const OUT = '/suite/output';
function ensure(ok, message) { if (!ok) throw Error(message); }
async function snapshot(db) {
  const [[tx]] = await db.query('SELECT COUNT(*) AS n FROM transactions');
  const [[audit]] = await db.query('SELECT COUNT(*) AS n FROM audit');
  const [[migrations]] = await db.query('SELECT COUNT(*) AS n FROM schema_migrations');
  const [triggers] = await db.query('SHOW TRIGGERS');
  const [checked] = await db.query("SELECT id, checked_snapshot_hash FROM transactions WHERE status='checked' ORDER BY created_at ASC LIMIT 1");
  return {
    transactions:Number(tx.n),audit:Number(audit.n),migrations:Number(migrations.n),
    triggers:triggers.length,checked_id:checked?.[0]?.id || '',
    checked_hash:checked?.[0]?.checked_snapshot_hash || ''
  };
}
async function run() {
  const report = {
    status:'failed', production_approval:'PENDING',
    source_commit:'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f',
    warning:'Rehearses disposal-only synthetic installed data; existing production database upgrade and rollback still require independently approved staging review.'
  };
  const upgrade = JSON.parse(fs.readFileSync(OUT+'/staging-upgrade.json','utf8'));
  ensure(upgrade.status==='passed', 'Staging upgrade did not pass; cannot verify rollback');
  const db=await mysql.createConnection({host:'db',user:'nonius_test',
    password:'local-release-password-only',database:'nonius_release_test'});
  const browser=await chromium.launch({headless:true});
  try {
    report.after = await snapshot(db);
    for (const field of ['transactions','audit','migrations','triggers','checked_id','checked_hash']) {
      ensure(report.after[field] === upgrade.before[field],
        'Rollback mismatch for '+field+': before '+upgrade.before[field]+', restored '+report.after[field]);
    }
    const page=await browser.newPage();
    await page.goto('http://web/login.php');
    await page.locator('input[name="identifier"]').fill('release_owner');
    await page.locator('input[name="password"]').fill('LocalOwnerPassword!12345');
    await page.getByRole('button',{name:'Sign in'}).click();
    await page.waitForURL(/(?:index|first-login)\.php/,{timeout:20000});
    const response = await page.request.get('http://web/api.php?scope=bootstrap');
    ensure(response.status()===200 && (await response.json()).actor?.role==='project_owner',
      'Restored application authentication failed');
    const index = await page.goto('http://web/index.php');
    ensure(index.status()===200, 'Restored dashboard unavailable');
    ensure(await page.locator('link[href*="style.css?v=0.4.56"]').count()>=1,
      'Application did not revert to original v0.4.56 code');
    const reset = await page.request.post('http://web/api.php', {
      data:{action:'resetTestData'},
      headers:{'X-CSRF-Token':(await (await page.request.get('http://web/api.php?scope=bootstrap')).json()).csrf}
    });
    ensure(reset.status()>=400, 'Restored source unexpectedly permits destructive reset');
    report.status = 'passed';
    console.log('PASS: synthetic application source and MariaDB restored together: '
      +report.after.transactions+' records, '+report.after.triggers+' triggers, intact audit/history.');
  } catch (e) {
    report.error=String(e?.message||e);
    console.error('FAIL staging rollback: '+report.error);
    throw e;
  } finally {
    fs.writeFileSync(OUT+'/staging-rollback.json', JSON.stringify(report,null,2));
    await db.end();
    await browser.close();
  }
}
run().catch(()=>process.exitCode=1);
