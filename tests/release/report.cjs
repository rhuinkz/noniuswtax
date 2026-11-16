'use strict';
// Generate a single offline evidence dashboard from the disposable test run.
const fs = require('fs');
const path = require('path');
const OUT = '/suite/output';
function readJson(name) { try { return JSON.parse(fs.readFileSync(path.join(OUT,name), 'utf8')); } catch { return null; } }
function exists(name) { return fs.existsSync(path.join(OUT,name)); }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
const playwright = readJson('results.json');
const stats = playwright?.stats || null;
const total = stats ? (stats.expected || 0) + (stats.unexpected || 0) + (stats.skipped || 0) + (stats.flaky || 0) : 0;
const browserPassed = !!stats && stats.expected > 0 && stats.unexpected === 0 && stats.skipped === 0 && stats.flaky === 0;
const backup = readJson('backup-restore.json');
const performance = readJson('performance.json');
const sourcePassed = exists('source-smoke.status') && fs.readFileSync(path.join(OUT,'source-smoke.status'),'utf8').trim() === 'PASS';
const pdfPassed = exists('bir2307-rendered-synthetic.pdf') && exists('bir2307-visual-review.png');
const gates = [
  {name:'PHP/JavaScript lint and tax mechanical golden cases', status:sourcePassed?'PASS':'FAIL', evidence:'source-smoke.status + console output'},
  {name:'Playwright install, login, roles, CSRF, period locks and functional flows', status:browserPassed?'PASS':'FAIL', evidence:`${stats?.expected||0} passed, ${stats?.unexpected||0} failed, ${stats?.skipped||0} skipped / ${total} results`},
  {name:'BIR Form 2307 local PDF generation and review PNG', status:pdfPassed && browserPassed?'PASS':'FAIL', evidence:'bir2307-rendered-synthetic.pdf, bir2307-visual-review.png (visual approval pending)'},
  {name:'Database backup/restore with triggers and immutable records', status:backup?.status==='passed'?'PASS':'FAIL', evidence:backup?`${backup.restored_transactions} transactions, ${backup.restored_triggers} triggers restored`:'backup-restore.json not generated'},
  {name:'10,000 synthetic records and concurrent HTTP probe', status:performance?.synthetic_transaction_rows===10000 && browserPassed?'PASS':'FAIL', evidence:performance?`${performance.synthetic_transaction_rows} records, ${performance.concurrent_http_requests} requests, p95 ${performance.p95_ms} ms (informational)`:'performance.json missing'}
];
const manual = [
  'Authorized Philippine BIR/VAT/EWT/ATC and rounding approval',
  'Human inspection of the generated 2307 PDF and signature/TIN/amount mapping',
  'Anonymized existing-installation migration, rollback and backup drill',
  'Actual Hostinger HTTPS, secrets, access controls and deployment hardening',
  'Real multiuser target load, measured hardware limits and accepted p95 SLO',
  'Versioned release artifact, recorded review and authorized production sign-off'
];
const allTechnical = gates.every(g => g.status === 'PASS');
const summary = {
  generated_at_utc: new Date().toISOString(),
  automated_verification: allTechnical?'PASS':'FAIL',
  production_release: 'PENDING_MANUAL_APPROVAL',
  playwright: {passed:stats?.expected??0, failed:stats?.unexpected??0, skipped:stats?.skipped??0, total},
  backup, performance, files:{html:'release-report.html', playwright:'html/index.html', rendered_pdf:'bir2307-rendered-synthetic.pdf', pdf_review_png:'bir2307-visual-review.png'},
  gates, manual_approval_pending:manual
};
fs.mkdirSync(OUT,{recursive:true});
fs.writeFileSync(path.join(OUT,'release-summary.json'),JSON.stringify(summary,null,2));
const rows = gates.map(g=>`<tr><td>${esc(g.name)}</td><td class="${g.status.toLowerCase()}">${esc(g.status)}</td><td>${esc(g.evidence)}</td></tr>`).join('');
const manualRows=manual.map(g=>`<li>${esc(g)}</li>`).join('');
const html=`<!doctype html><html lang="en"><meta charset="utf-8"><title>NONIUSWTAX Release Test Report</title><style>body{max-width:980px;margin:40px auto;padding:0 22px;font:16px/1.55 system-ui,Arial,sans-serif;color:#172235}h1{margin-bottom:0}h2{margin-top:35px}table{border-collapse:collapse;width:100%;margin:20px 0}td,th{text-align:left;border-bottom:1px solid #d8dee8;padding:12px;vertical-align:top}th{background:#f5f7fb}.pass{color:#17603d;font-weight:bold}.fail{color:#a12b35;font-weight:bold}.muted{color:#64748b}.notice{background:#f4f6f9;border:1px solid #d4dbe6;padding:16px;border-radius:8px}code{background:#edf1f7;padding:3px 6px}li{margin:7px 0}</style><h1>NONIUSWTAX One-Package Release Verification</h1><p class="muted">Generated ${esc(summary.generated_at_utc)} · Local disposable Docker + MariaDB + PHP + Playwright</p><div class="notice"><strong>Automated technical verification: <span class="${allTechnical?'pass':'fail'}">${esc(summary.automated_verification)}</span></strong><p><strong>Production release: PENDING MANUAL APPROVAL.</strong> A green suite is not a regulatory or deployment authorization.</p><p>Playwright: ${summary.playwright.passed} passed, ${summary.playwright.failed} failed, ${summary.playwright.skipped} skipped.</p></div><h2>All automated categories</h2><table><thead><tr><th>Category</th><th>Result</th><th>Evidence</th></tr></thead><tbody>${rows}</tbody></table><h2>Still requiring external approval</h2><ol>${manualRows}</ol><h2>Files saved beside this report</h2><p><a href="html/index.html">Playwright detailed HTML</a> · <a href="results.json">Raw Playwright JSON</a> · <a href="release-summary.json">Consolidated summary JSON</a> · <a href="bir2307-rendered-synthetic.pdf">Generated 2307</a> · <a href="bir2307-visual-review.png">2307 preview</a> · <a href="backup-restore.json">Restore evidence</a> · <a href="performance.json">Load metrics</a></p><p class="muted">This report uses fictional-only data. No live system or employee/supplier records were accessed. Verify the test source commit and attach approval evidence separately.</p></html>`;
fs.writeFileSync(path.join(OUT,'release-report.html'),html);
console.log('NONIUSWTAX COMBINED REPORT:', summary.automated_verification);
for(const g of gates)console.log(g.status.padEnd(4),g.name, '-',g.evidence);
console.log('Production release: PENDING MANUAL APPROVAL');
console.log('Saved /suite/output/release-report.html and release-summary.json');
if (!allTechnical) process.exitCode = 1;
