#!/usr/bin/env bash
# Single-command FULL release verification for Docker Engine on Linux/WSL2.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
export COMPOSE_PROJECT_NAME=nonius-release-verification
COMPOSE=(docker compose -f compose.release-test.yml)
mkdir -p test-results
rm -f test-results/results.json test-results/backup-restore.json test-results/performance.json test-results/source-smoke.status test-results/release-summary.json test-results/release-report.html test-results/bir2307-rendered-synthetic.pdf test-results/bir2307-visual-review.png
cleanup() {
  echo "Removing ONLY disposable local test containers and test volume..."
  "${COMPOSE[@]}" down --volumes --remove-orphans || true
}
trap cleanup EXIT
echo 'NONIUSWTAX: ONE FULL RELEASE VERIFICATION PACKAGE (synthetic data only)'
docker info --format '{{.ServerVersion}}'
"${COMPOSE[@]}" down --volumes --remove-orphans
"${COMPOSE[@]}" build web browser
"${COMPOSE[@]}" up -d --wait db web
source_ok=0
browser_ok=0
restore_ok=0
report_ok=0
echo 'STAGE 1/4: PHP, golden calculation and source security'
if "${COMPOSE[@]}" run --rm --no-deps web sh -ec 'php tests/release/php-lint.php && php tests/release/tax-golden.php && php tests/calculation-smoke.php && php tests/safety-smoke.php && php tests/sql-guard-smoke.php'; then
  printf 'PASS' > test-results/source-smoke.status
  source_ok=1
else echo 'FAIL: Source and golden-case checks'; fi
echo 'STAGE 2/4: Playwright, actual PDF, roles, SQL, 10k load'
if "${COMPOSE[@]}" run --rm browser; then browser_ok=1; else echo "FAIL: Browser/integration suite"; fi
echo 'STAGE 3/4: SQL backup/restore with triggers and guard validation'
if "${COMPOSE[@]}" exec -T db bash /suite-tests/backup-restore.sh; then restore_ok=1; else echo "FAIL: Backup/restore"; fi
echo 'STAGE 4/4: Write one HTML/JSON release report'
if "${COMPOSE[@]}" run --rm --no-deps browser node /suite/report.cjs; then report_ok=1; else echo "FAIL: One or more automated release gates"; fi
echo 'Consolidated report: test-results/release-report.html'
echo 'Consolidated JSON: test-results/release-summary.json'
if [[ "$source_ok" -eq 1 && "$browser_ok" -eq 1 && "$restore_ok" -eq 1 && "$report_ok" -eq 1 ]]; then
  echo 'PASS: Automated suite. Human BIR, security and deployment approvals remain PENDING.'
  exit 0
fi
echo 'FAIL: Automated suite. Review the consolidated report.'
exit 1
