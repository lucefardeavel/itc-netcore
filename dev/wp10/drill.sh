#!/usr/bin/env bash
# WP10 backup / restore / migration-rollback drill (test tooling only; disposable databases of the isolated
# nuxbill-reseller-test compose project; never production).
#
#   dev/wp9/t1a_run.sh up                 start the disposable MariaDB 10.11 + MySQL 8.0 (throwaway credentials)
#   dev/wp10/drill.sh <mariadb|mysql>     run dev/wp10/BackupRestoreDrillTest.php on that engine and serve its
#                                         dump / restore requests with the engine's own client inside the DB container
#   dev/wp9/t1a_run.sh down
#
# The password never reaches a command line: the client reads it from the container's own MARIADB_ROOT_PASSWORD /
# MYSQL_ROOT_PASSWORD through MYSQL_PWD. Dumps stay in the host state directory (mode 0700, outside git); only their
# size, SHA-256 and the drill log are copied to the evidence directory.
set -euo pipefail
engine="${1:?usage: $0 <mariadb|mysql>}"
[[ "$engine" == mariadb || "$engine" == mysql ]] || { echo "engine must be mariadb or mysql"; exit 2; }
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/dev/test"
ENVF="${T1A_ENV:-/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/t1a.env}"
set -a; . "$ENVF"; set +a
umask 077
STATE="${WP10_STATE:-/srv/phpnuxbill/wp10state}/drill-$engine-$(date -u +%Y%m%dT%H%M%SZ)"
EVID="$ROOT/${WP10_EVID_DIR:-owner-review/WP10/evidence/drill}"
mkdir -p "$STATE" "$EVID"
chmod 0777 "$STATE"   # the php82 container writes its requests / log here; the directory's parent stays 0700

if [ "$engine" = mariadb ]; then
  DUMP='MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --user=root --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "$1"'
  LOAD='MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb --user=root --default-character-set=utf8mb4 "$1"'
  VERS='exec mariadb-dump --version'
else
  DUMP='MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --user=root --single-transaction --routines --triggers --events --hex-blob --set-gtid-purged=OFF --default-character-set=utf8mb4 "$1"'
  LOAD='MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --default-character-set=utf8mb4 "$1"'
  VERS='exec mysqldump --version'
fi
echo "tool: $(docker compose exec -T "$engine" sh -c "$VERS")" > "$STATE/host.log"

docker compose run --rm -v "$STATE:/drill-state" -e WP1_DB_HOST="$engine" php82 \
  tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox dev/wp10/BackupRestoreDrillTest.php > "$STATE/phpunit.out" 2>&1 &
pid=$!
n=1
while kill -0 "$pid" 2>/dev/null; do
  req="$STATE/request-$n.json"
  if [ -f "$req" ]; then
    read -r op db file < <(python3 -I -c 'import json,sys; r=json.load(open(sys.argv[1])); print(r["op"], r["db"], r["file"])' "$req")
    ok=false; bytes=0; sha=""; t0=$(date +%s.%N)
    if [[ "$db" =~ ^wp1_[a-z]+_[0-9a-f]{10}$ && "$file" =~ ^B[01]-(mariadb|mysql)\.sql$ ]]; then
      case "$op" in
        dump)    docker compose exec -T "$engine" sh -c "$DUMP" sh "$db" > "$STATE/$file" 2>> "$STATE/host.log" && ok=true ;;
        restore) docker compose exec -T "$engine" sh -c "$LOAD" sh "$db" < "$STATE/$file" >> "$STATE/host.log" 2>&1 && ok=true ;;
      esac
      bytes=$(stat -c %s "$STATE/$file" 2>/dev/null || echo 0); sha=$(sha256sum "$STATE/$file" | cut -c1-64)
    else
      echo "refused request $n: db=$db file=$file" >> "$STATE/host.log"
    fi
    secs=$(python3 -c "import time; print(round(time.time() - $t0, 2))")
    echo "step $n $op $file db=$db ok=$ok bytes=$bytes sha256=$sha seconds=$secs" >> "$STATE/host.log"
    printf '{"ok":%s,"bytes":%s,"sha256":"%s","seconds":%s}\n' "$ok" "$bytes" "$sha" "$secs" > "$STATE/done-$n.json"
    n=$((n + 1))
  fi
  sleep 0.3
done
set +e; wait "$pid"; rc=$?; set -e
ts="$(basename "$STATE")"
grep -v -E 'Container .* (Creating|Created|Starting|Started|Running|Waiting|Healthy)' "$STATE/phpunit.out" > "$EVID/$ts-phpunit.out" || true
cp "$STATE/host.log" "$EVID/$ts-host.log"
[ -f "$STATE/drill.log" ] && cp "$STATE/drill.log" "$EVID/$ts-drill.log"
echo "exit=$rc" >> "$EVID/$ts-phpunit.out"
tail -25 "$EVID/$ts-phpunit.out"
exit "$rc"
