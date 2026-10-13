#!/usr/bin/env bash
# WP8 A3 final pre-acceptance probes: harness DB containers exactly as dev/test/run-tests.sh starts them; probes mounted
# read-only at /probe (nothing written into the repository); baseline = the HEAD product files from the scope2 backup,
# bind-mounted read-only over /repo. Containers removed at the end.
set -uo pipefail
P=/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/a3probe
B=/srv/phpnuxbill/wp8-a3-scope2-backup-20261013T101117Z
cd /srv/phpnuxbill/dev/dev/test
export WP1_DB_ROOT_PASSWORD="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
export NUXR_RADIUS_SECRET="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
trap 'docker compose down -v --remove-orphans >/dev/null 2>&1 || true' EXIT
docker compose up -d mariadb mysql freeradius-mariadb freeradius-mysql >/dev/null
for i in $(seq 1 120); do
  st="$(docker compose ps --format '{{.Service}}={{.Health}}' | tr '\n' ' ')"
  if [[ "$st" == *"mariadb=healthy"* && "$st" == *"mysql=healthy"* ]]; then break; fi
  sleep 2
done
BASE=()
for f in system/autoload/Reseller/Activation/DriftReport.php system/autoload/Reseller/Domain/LegacyReprovisionService.php \
         system/autoload/Reseller/Radius/CoAClient.php system/autoload/Reseller/Radius/RadiusProvisioner.php \
         system/autoload/Reseller/Radius/RadiusRepository.php system/controllers/customers.php system/devices/Radius.php; do
  BASE+=(-v "$B/$f:/repo/$f:ro")
done
run() { # label host extra-args... -- phpunit-args...
  local label=$1 host=$2; shift 2
  local mounts=(); while [ "$1" != "--" ]; do mounts+=("$1"); shift; done; shift
  echo "## $(date -u +%FT%TZ) ${label} engine=${host}"
  docker compose run --rm -e WP1_DB_HOST="$host" -v "$P:/probe:ro" "${mounts[@]}" php82 tests/vendor/bin/phpunit -c tests/phpunit.xml "$@" 2>&1 | grep -vE "^ Container |^\s*$"
  echo "exit=${PIPESTATUS[0]}"
}
for h in mariadb mysql; do
  run "A3 BaselineComparableProbeTest" "$h" -- /probe/BaselineComparableProbeTest.php
  run "BASELINE(HEAD product files) BaselineComparableProbeTest" "$h" "${BASE[@]}" -- /probe/BaselineComparableProbeTest.php
done
