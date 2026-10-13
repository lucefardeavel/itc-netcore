#!/usr/bin/env bash
# WP8 A3 final remediation: focused runs on the harness DB containers (started exactly as dev/test/run-tests.sh does).
#   run-focused.sh pre    the NEW regression tests against the PRE-remediation product files (backup, mounted read-only
#                         over /repo): they must fail (evidence that they detect the defects); MariaDB
#   run-focused.sh post   every affected suite against the working tree, MariaDB and MySQL
set -uo pipefail
MODE=${1:?pre|post}
PRE=/srv/phpnuxbill/wp8-a3-remediation-backup-20261013T145704Z
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
docker compose build php82 >/dev/null
run() { # label host mounts... -- phpunit-args...
  local label=$1 host=$2; shift 2
  local mounts=(); while [ "$1" != "--" ]; do mounts+=("$1"); shift; done; shift
  echo "## $(date -u +%FT%TZ) ${label} engine=${host}"
  docker compose run --rm -e WP1_DB_HOST="$host" "${mounts[@]}" php82 tests/vendor/bin/phpunit -c tests/phpunit.xml "$@" 2>&1 | grep -vE "^ Container |^\s*$"
  echo "exit=${PIPESTATUS[0]}"
}
if [ "$MODE" = mid ]; then
  MID=/tmp/claude-0/-root-Downloads/ccd890f1-0769-4fe7-b94c-4fb60f2282e1/scratchpad/a3mid/intermediate
  BASE=()
  for f in system/autoload/Reseller/Activation/DriftReport.php system/autoload/Reseller/Domain/LegacyReprovisionService.php \
           system/autoload/Reseller/Radius/CoAClient.php system/autoload/Reseller/Radius/RadiusProvisioner.php \
           system/autoload/Reseller/Radius/RadiusRepository.php system/controllers/customers.php system/devices/Radius.php; do
    BASE+=(-v "$MID/$f:/repo/$f:ro")
  done
  run "INTERMEDIATE (first remediation) RenameRemediationTest R-1/R-2/R-5" mariadb "${BASE[@]}" -- --filter 'testR1TheTargetOfAPairRenamedOnlyOnceIsStillChecked|testR2AKeyOfALaterPairIsNotExemptForAnEarlierPair|testR5OneDisconnectPerSessionInsideALegacyRenameScope' tests/Integration/Wp5b/RenameRemediationTest.php
  run "INTERMEDIATE unit R-3 guard" mariadb "${BASE[@]}" -- --filter testTheTargetCheckIsInsideTheRenameTransaction tests/Unit/Wp5b/RenameOldSessionUnitTest.php
  run "INTERMEDIATE R2 R-4" mariadb "${BASE[@]}" -- --filter testANumericLookingPppoeUsernameIsComparedStrictly tests/Integration/Wp4/RadiusLegacyRenameTest.php
  exit 0
fi
if [ "$MODE" = pre ]; then
  BASE=()
  for f in system/autoload/Reseller/Activation/DriftReport.php system/autoload/Reseller/Domain/LegacyReprovisionService.php \
           system/autoload/Reseller/Radius/CoAClient.php system/autoload/Reseller/Radius/RadiusProvisioner.php \
           system/autoload/Reseller/Radius/RadiusRepository.php system/controllers/customers.php system/devices/Radius.php; do
    BASE+=(-v "$PRE/$f:/repo/$f:ro")
  done
  run "PRE-REMEDIATION RenameRemediationTest" mariadb "${BASE[@]}" -- tests/Integration/Wp5b/RenameRemediationTest.php
  run "PRE-REMEDIATION unit (new tests)" mariadb "${BASE[@]}" -- --filter 'testOldSessionNoticeType|testRenamePairsDropPairsThatKeepTheKey|testTheTargetCheckIsInsideTheRenameTransaction|testTheDriftIgnoresRefusals' tests/Unit/Wp5b/RenameOldSessionUnitTest.php
  run "PRE-REMEDIATION R2 (new tests)" mariadb "${BASE[@]}" -- --filter 'testPppoeUsernameHeldByAnotherCustomer|testAPolicyUnderTheNewName' tests/Integration/Wp4/RadiusLegacyRenameTest.php
  run "PRE-REMEDIATION repository (new test)" mariadb "${BASE[@]}" -- --filter testAnExistingExactTargetIsRefusedWhenTheCallerDoesNotOwnIt tests/Integration/Wp4/RadiusRenameIntegrityTest.php
  exit 0
fi
for h in mariadb mysql; do
  for t in tests/Unit/Wp5b/RenameOldSessionUnitTest.php tests/Unit/Wp5b/Wp5bSourceGuardTest.php \
           tests/Integration/Wp4/RadiusRenameIntegrityTest.php tests/Integration/Wp4/RadiusLegacyRenameTest.php \
           tests/Integration/Wp4/RadiusRepositoryTest.php tests/Integration/Wp4/RadiusDeviceLifecycleTest.php \
           tests/Integration/Wp5b/RenameRemediationTest.php tests/Integration/Wp5b/RenameIntegrityTest.php \
           tests/Integration/Wp5b/RenameOldSessionTest.php tests/Integration/Wp5b/LegacyReprovisionTest.php \
           tests/Integration/Wp5b/DriftReportTest.php tests/Integration/Wp5b/MikrotikDeviceTest.php \
           tests/Integration/Wp5b/PluginBypassTest.php tests/Integration/Wp5b/LegacyEntrypointClosureTest.php; do
    run "$t" "$h" -- "$t"
  done
done
