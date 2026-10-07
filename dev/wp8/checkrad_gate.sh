#!/usr/bin/env bash
# WP8 lab only - OD-W8-16 Option F checkrad GATE, every mode in one pass against the RUNNING lab (dev/wp8/lab.sh up):
#   1. mikrotik_snmp, SNMP healthy   Gates A, B, C, D (router-side SNMP loss), Hotspot 1 / 2 / accounting lost
#   2. mikrotik_snmp, no SNMP client Gate D
#   3. mikrotik_snmp, checkrad error Gate D
#   4. nas.type other                Gate E (control)
# then every gate switch is undone (checkrad_gate.py off). Mode switches restart FreeRADIUS, so each mode is its own
# PHPUnit run. Evidence: $NUXR_WP8_STATE/ctl/evidence.jsonl (cases OD16F.*).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export NUXR_WP8_STATE="${NUXR_WP8_STATE:-/var/lib/nuxr-wp8}"
G() { python3 "$HERE/checkrad_gate.py" "$@" > /dev/null || { echo "gate switch $* failed"; exit 1; }; }
T() { echo "== $1"; "$HERE/lab.sh" test --filter "CheckradGateTest::$1" 2>&1 | grep -E '^(OK|Tests:|FAILURES|There w|[0-9]+\) )' ; }
G on;                          T testSnmp
G fault snmpwalk-missing;      T testFaultWalk
G fault checkrad-error;        T testFaultError
G fault restore; G nas-type other; T testOther
G off
python3 "$HERE/checkrad_gate.py" state | grep -E '"enabled"|marker|delete_stale|10\.88\.0\.2'
