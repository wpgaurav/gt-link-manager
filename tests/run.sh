#!/usr/bin/env bash
set -euo pipefail
: "${GTLM_TEST_WP_ROOT:?Set GTLM_TEST_WP_ROOT to an isolated, installed WordPress fixture}"
GTLM_TEST_REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export GTLM_TEST_REPO_ROOT
for gtlm_suite in integration timezones failures regressions pages drilldown chart pagination; do
 export GTLM_TEST_SUITE="$gtlm_suite"
 php -r 'require rtrim(getenv("GTLM_TEST_WP_ROOT"), "/")."/wp-load.php"; require getenv("GTLM_TEST_REPO_ROOT")."/tests/".getenv("GTLM_TEST_SUITE").".php";'
done
