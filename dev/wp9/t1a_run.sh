#!/usr/bin/env bash
# WP9 T1-A runner (test-only). Disposable MariaDB 10.11 and MySQL 8.0 of the nuxbill-reseller-test compose project ONLY
# (no FreeRADIUS containers, no CHR, no published ports, tmpfs storage). Throwaway credentials are generated here and
# passed through the environment, never on a command line or in the evidence.
#
#   dev/wp9/t1a_run.sh up                      start mariadb + mysql and wait until healthy
#   dev/wp9/t1a_run.sh test <engine> <file>    run ONE test file on mariadb | mysql (evidence -> owner-review/WP9/evidence/t1a)
#   dev/wp9/t1a_run.sh down                    remove the containers, networks and volumes of the project
set -euo pipefail
cd "$(dirname "$0")/../test"
STATE=/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/t1a.env
EVID="${WP9_EVID_DIR:-owner-review/WP9/evidence/t1a}"   # WP9-A1 sets owner-review/WP9/evidence/a1
case "${1:-}" in
  up)
    umask 077
    printf 'WP1_DB_ROOT_PASSWORD=%s\nNUXR_RADIUS_SECRET=%s\n' "$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')" "$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')" > "$STATE"
    set -a; . "$STATE"; set +a
    docker compose up -d mariadb mysql >/dev/null
    for i in $(seq 1 120); do
      st="$(docker compose ps --format '{{.Service}}={{.Health}}' | tr '\n' ' ')"
      if [[ "$st" == *"mariadb=healthy"* && "$st" == *"mysql=healthy"* ]]; then echo "healthy: $st"; exit 0; fi
      sleep 2
    done
    echo "not healthy: $st"; exit 1 ;;
  test)
    engine="$2"; file="$3"
    set -a; . "$STATE"; set +a
    mkdir -p "../../$EVID"
    log="../../$EVID/console-$engine-$(basename "$file" .php)-$(date -u +%Y%m%dT%H%M%SZ).out"
    { echo "command: docker compose run --rm -e WP1_DB_HOST=$engine -e WP9_EVIDENCE=/repo/$EVID/obs-$engine.jsonl php82 tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox $file"; echo "start: $(date -u +%FT%TZ)"; } > "$log"
    set +e
    docker compose run --rm -e WP1_DB_HOST="$engine" -e WP9_EVIDENCE="/repo/$EVID/obs-$engine.jsonl" php82 \
      tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox "$file" >> "$log" 2>&1
    rc=$?
    set -e
    echo "exit=$rc end: $(date -u +%FT%TZ)" >> "$log"
    grep -v -E 'Container .* (Creating|Created|Starting|Started|Running|Waiting|Healthy)' "$log" | tail -60
    exit $rc ;;
  down)
    if [ -f "$STATE" ]; then set -a; . "$STATE"; set +a; else export WP1_DB_ROOT_PASSWORD=x NUXR_RADIUS_SECRET=x; fi
    docker compose down -v --remove-orphans
    rm -f "$STATE" ;;
  *) echo "usage: $0 up | test <mariadb|mysql> <file> | down"; exit 2 ;;
esac
