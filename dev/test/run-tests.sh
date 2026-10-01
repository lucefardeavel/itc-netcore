#!/usr/bin/env bash
# Development/test only: runs the reseller project test suite on PHP 8.2 against disposable
# MariaDB 10.11 and MySQL 8.0 containers (owner decisions C5 / Q1). Never touches production.
#
#   dev/test/run-tests.sh                 # full matrix on both engines
#   dev/test/run-tests.sh --filter X      # extra arguments are passed to PHPUnit
#
# Prerequisites: docker with the compose plugin; tests/vendor installed from tests/composer.json
# (composer install --working-dir=tests --prefer-source).
set -euo pipefail
cd "$(dirname "$0")"

export WP1_DB_ROOT_PASSWORD="${WP1_DB_ROOT_PASSWORD:-$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
KEEP="${WP1_KEEP_CONTAINERS:-0}"

cleanup() { if [ "$KEEP" != "1" ]; then docker compose down -v --remove-orphans >/dev/null 2>&1 || true; fi; }
trap cleanup EXIT

docker compose up -d mariadb mysql >/dev/null
for i in $(seq 1 120); do
  st="$(docker compose ps --format '{{.Service}}={{.Health}}' | tr '\n' ' ')"
  if [[ "$st" == *"mariadb=healthy"* && "$st" == *"mysql=healthy"* ]]; then break; fi
  sleep 2
done
docker compose build php82 >/dev/null

overall=0
for host in mariadb mysql; do
  echo "================ engine: ${host} ================"
  docker compose run --rm -e WP1_DB_HOST="${host}" php82 -v | head -1
  if ! docker compose run --rm -e WP1_DB_HOST="${host}" php82 tests/vendor/bin/phpunit -c tests/phpunit.xml "$@"; then
    overall=1
  fi
done
echo "================ variant registry reproducibility ================"
if ! docker compose run --rm php82 dev/tools/build-variant-registry.php --hosts=mariadb,mysql --password-env=WP1_DB_ROOT_PASSWORD --check; then
  overall=1
fi
exit "$overall"
