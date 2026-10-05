#!/usr/bin/env bash
# WP7 PWA lab (test tooling only, owner decision OD-W7-4).
#   dev/pwa-lab/lab.sh up     start the disposable lab (isolated nuxbill-reseller-test compose project)
#   dev/pwa-lab/lab.sh sync   copy the working tree's runtime files into the running lab's app copy
#   dev/pwa-lab/lab.sh down   stop the lab container (the compose databases are left to their owner)
# The lab publishes ONLY on 127.0.0.1 (18099 root installation, 18098 /billing/ subdirectory installation), so the
# browser origin is http://localhost:<port> - a secure context without touching any TLS. Never used for production.
# Environment: WP1_DB_ROOT_PASSWORD / NUXR_RADIUS_SECRET must match the running test databases (as for
# dev/test/run-tests.sh). PWALAB_STATE (default /srv/phpnuxbill/pwalab) receives pwalab-ready.json.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
STATE="${PWALAB_STATE:-/srv/phpnuxbill/pwalab}"
NAME=nuxbill-reseller-test-pwalab
cd "$ROOT/dev/test"
: "${WP1_DB_ROOT_PASSWORD:?set WP1_DB_ROOT_PASSWORD (throwaway root password of the test databases)}"
: "${NUXR_RADIUS_SECRET:?set NUXR_RADIUS_SECRET}"
export WP1_DB_ROOT_PASSWORD NUXR_RADIUS_SECRET

case "${1:-}" in
  up)
    mkdir -p "$STATE"; rm -f "$STATE/pwalab-ready.json" "$STATE/pwalab-stop"
    docker compose up -d mariadb freeradius-mariadb >/dev/null
    st=""
    for i in $(seq 1 120); do
      st="$(docker compose ps --format '{{.Service}}={{.Health}}' 2>/dev/null | tr '\n' ' ' || true)"
      if [[ "$st" == *"mariadb=healthy"* ]]; then break; fi
      sleep 2
    done
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker compose run -d --name "$NAME" -p 127.0.0.1:18099:8099 -p 127.0.0.1:18098:8098 -v "$STATE:/pwalab-state" \
      -e WP1_DB_HOST=mariadb php82 tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result dev/pwa-lab/PwaLabTest.php >/dev/null
    for i in $(seq 1 90); do [ -f "$STATE/pwalab-ready.json" ] && break; sleep 4; done
    [ -f "$STATE/pwalab-ready.json" ] || { echo "lab did not start"; docker logs --tail 40 "$NAME"; exit 1; }
    echo "pwa lab up: http://localhost:18099/  and  http://localhost:18098/billing/"
    ;;
  sync)
    DIR="$(python3 -c "import json;print(json.load(open('$STATE/pwalab-ready.json'))['dir'])")"
    docker exec "$NAME" sh -c "cp -r /repo/ui/ui/. $DIR/ui/ui/ && cp -r /repo/system/autoload/. $DIR/system/autoload/ \
      && cp -r /repo/system/controllers/. $DIR/system/controllers/ && cp -r /repo/system/lan/. $DIR/system/lan/ \
      && for f in nuxr-sw.js nuxr-offline.html; do [ -f /repo/\$f ] && cp /repo/\$f $DIR/\$f || true; done \
      && rm -f $DIR/ui/compiled/*.php"
    echo synced
    ;;
  down)
    touch "$STATE/pwalab-stop" 2>/dev/null || true
    sleep 3
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    echo down
    ;;
  *) echo "usage: $0 up|sync|down"; exit 2 ;;
esac
