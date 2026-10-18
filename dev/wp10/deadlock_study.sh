#!/usr/bin/env bash
# WP10 O-DL study runner (test tooling only; disposable databases of the isolated nuxbill-reseller-test compose project; never production).
#   dev/wp9/t1a_run.sh up ; dev/wp10/deadlock_study.sh <mariadb|mysql> <runs> <rounds> <label> ; dev/wp9/t1a_run.sh down
# One run = one PHP process = <rounds> waves of 12 concurrent activations on one wallet. The server logs every deadlock it detects
# (innodb_print_all_deadlocks, disposable server) and the log is kept next to the result.
set -euo pipefail
engine="${1:?usage: $0 <mariadb|mysql> <runs> <rounds> <label>}"; runs="${2:?}"; rounds="${3:?}"; label="${4:?}"
[[ "$engine" == mariadb || "$engine" == mysql ]] || { echo "engine must be mariadb or mysql"; exit 2; }
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/dev/test"
set -a; . "${T1A_ENV:-/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/t1a.env}"; set +a
OUT="${DLSTUDY_EVID:-$ROOT/dlstudy-evidence}/$label-$engine"
mkdir -p "$OUT"
START="$(date -u +%Y-%m-%dT%H:%M:%S)"
C="nuxbill-reseller-test-$engine-1"
# the password stays inside the container (its own environment); nothing secret is printed or stored
docker exec "$C" sh -c 'export MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"; $(command -v mariadb || command -v mysql) -uroot -e "SET GLOBAL innodb_print_all_deadlocks=ON"'
for i in $(seq 1 "$runs"); do
  set +e
  docker compose run --rm -v "$OUT:/dl-out" -e DLSTUDY_OUT=/dl-out -e DLSTUDY_ROUNDS="$rounds" -e WP1_DB_HOST="$engine" php82 \
    tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox dev/wp10/DeadlockStudyTest.php 2>&1 \
    | grep -v -E 'Container .* (Creating|Created|Starting|Started|Running|Waiting|Healthy)' > "$OUT/phpunit-run$i.out"
  rc=${PIPESTATUS[0]}
  set -e
  echo "run $i exit=$rc: $(grep -E '^(OK|FAILURES|ERRORS)' "$OUT/phpunit-run$i.out" | head -1)"
done
docker logs --since "$START" "$C" 2>&1 | grep -i -A70 -E "deadlock detected|LATEST DETECTED DEADLOCK" >> "$OUT/server-deadlocks.txt" || true
echo "deadlocks detected by the server during this call: $(docker logs --since "$START" "$C" 2>&1 | grep -i -c -E 'deadlock detected|LATEST DETECTED DEADLOCK' || true)"
