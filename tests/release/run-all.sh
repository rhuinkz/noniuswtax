#!/usr/bin/env bash
# Single-command isolated release suite for Linux/WSL2 + Docker Engine.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
export COMPOSE_PROJECT_NAME=nonius-release-verification
COMPOSE=(docker compose -f compose.release-test.yml)
cleanup() {
  echo "Removing disposable test containers and test database volume..."
  "${COMPOSE[@]}" down --volumes --remove-orphans || true
}
trap cleanup EXIT
echo "NONIUSWTAX local release verification (synthetic data ONLY)"
docker info --format '{{.ServerVersion}}'
"${COMPOSE[@]}" down --volumes --remove-orphans
"${COMPOSE[@]}" build web browser
"${COMPOSE[@]}" up -d --wait db web
echo "Running PHP syntax, calculation and source-security smoke checks..."
"${COMPOSE[@]}" run --rm --no-deps web sh -ec 'php tests/release/php-lint.php && php tests/calculation-smoke.php && php tests/safety-smoke.php && php tests/sql-guard-smoke.php'
echo "Running combined browser, identity, migration, SQL and HTTP suite..."
"${COMPOSE[@]}" run --rm browser
echo "PASS: Automated local verification completed."
echo "HTML: test-results/html/index.html"
echo "JSON: test-results/results.json"
echo "Tax/BIR, visual PDF, restore and capacity approvals remain separate gates."
