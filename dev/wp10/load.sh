#!/usr/bin/env bash
# WP10 bounded staging load test (test tooling only; disposable databases of the isolated nuxbill-reseller-test
# compose project; never a production service, never a connection- or pool-exhaustion test).
#   dev/wp9/t1a_run.sh up ; dev/wp10/load.sh <mariadb|mysql> ; dev/wp9/t1a_run.sh down
set -euo pipefail
engine="${1:?usage: $0 <mariadb|mysql>}"
[[ "$engine" == mariadb || "$engine" == mysql ]] || { echo "engine must be mariadb or mysql"; exit 2; }
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/dev/test"
set -a; . "${T1A_ENV:-/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/t1a.env}"; set +a
EVID="$ROOT/${WP10_EVID_DIR:-owner-review/WP10/evidence/load}"
OUT="$EVID/load-$engine-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$OUT"
set +e
docker compose run --rm -v "$OUT:/load-out" -e WP10_LOAD_OUT=/load-out -e WP1_DB_HOST="$engine" php82 \
  tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox dev/wp10/StagingLoadTest.php 2>&1 \
  | grep -v -E 'Container .* (Creating|Created|Starting|Started|Running|Waiting|Healthy)' > "$OUT/phpunit.out"
rc=${PIPESTATUS[0]}
set -e
echo "exit=$rc" >> "$OUT/phpunit.out"
cat "$OUT/load.log" 2>/dev/null; tail -6 "$OUT/phpunit.out"
exit "$rc"
