#!/usr/bin/env bash
# WP10 bounded ACCOUNTING load (test tooling only; disposable FreeRADIUS + database of the isolated nuxbill-reseller-test
# compose project; never a production service, never an authentication / admission / pool-exhaustion test).
#   dev/wp9/t1a_run.sh up ; scratchpad/fr_up.sh ; dev/wp10/acct_load.sh <mariadb|mysql> ; dev/wp9/t1a_run.sh down
# Environment: ACCTLOAD_SUBS (default 500,2000)  ACCTLOAD_INTERIMS (60,30)  ACCTLOAD_SECONDS (40)  ACCTLOAD_PASS_EVERY (5)
set -euo pipefail
engine="${1:?usage: $0 <mariadb|mysql>}"
[[ "$engine" == mariadb || "$engine" == mysql ]] || { echo "engine must be mariadb or mysql"; exit 2; }
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/dev/test"
set -a; . "${T1A_ENV:-/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/t1a.env}"; set +a
EVID="$ROOT/${WP10_EVID_DIR:-owner-review/WP10/evidence/capacity}"
OUT="$EVID/acct-$engine-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$OUT"
# container CPU / memory of the FreeRADIUS and database containers every 5 s (host docker CLI, read-only)
( while true; do docker stats --no-stream --format '{{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}' "nuxbill-reseller-test-freeradius-$engine-1" "nuxbill-reseller-test-$engine-1" 2>/dev/null | sed "s/^/$(date -u +%H:%M:%S) /" >> "$OUT/containers.txt"; sleep 5; done ) &
SAMPLER=$!
trap 'kill $SAMPLER 2>/dev/null || true' EXIT
set +e
docker compose run --rm -v "$OUT:/acct-out" -e ACCTLOAD_OUT=/acct-out -e ACCTLOAD_SUBS="${ACCTLOAD_SUBS:-500,2000}" -e ACCTLOAD_INTERIMS="${ACCTLOAD_INTERIMS:-60,30}" \
  -e ACCTLOAD_SECONDS="${ACCTLOAD_SECONDS:-40}" -e ACCTLOAD_PASS_EVERY="${ACCTLOAD_PASS_EVERY:-5}" -e WP1_DB_HOST="$engine" php82 \
  tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox dev/wp10/AcctLoadTest.php 2>&1 \
  | grep -v -E 'Container .* (Creating|Created|Starting|Started|Running|Waiting|Healthy)' > "$OUT/phpunit.out"
rc=${PIPESTATUS[0]}
set -e
echo "exit=$rc" >> "$OUT/phpunit.out"
# FreeRADIUS error lines in the window (pool / SQL problems would show here)
docker logs --since 15m "nuxbill-reseller-test-freeradius-$engine-1" 2>&1 | grep -i -E "error|pool|exhaust|no connection" | tail -20 > "$OUT/freeradius-errors.txt" || true
tail -3 "$OUT/phpunit.out"; echo "evidence: $OUT"
exit "$rc"
