#!/usr/bin/env bash
# WP8 lab only - OD-W8-16 Option G GATE, every mode in one pass against the RUNNING lab (dev/wp8/lab.sh up):
#   1. shipped     stock checkrad exactly as shipped (cannot SNMP in the pinned image -> defers): discriminator,
#                  Gates A-I, hostile usernames, Hotspot 1 / 2 / accounting lost - the guard is the backstop
#   2. functional  lab-only functional stock checkrad: the stock layer rejects first; SNMP loss -> stock fails open,
#                  the guard still rejects
# then every gate switch is undone (option_g_gate.py off). `on` restarts FreeRADIUS, so each mode is its own PHPUnit
# run. Evidence: $NUXR_WP8_STATE/ctl/evidence.jsonl (cases OD16G.*).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export NUXR_WP8_STATE="${NUXR_WP8_STATE:-/var/lib/nuxr-wp8}"
G() { python3 "$HERE/option_g_gate.py" "$@" > /dev/null || { echo "gate switch $* failed"; exit 1; }; }
T() { echo "== $1"; "$HERE/lab.sh" test --filter "OptionGGateTest::$1" 2>&1 | grep -E '^(OK|Tests:|FAILURES|There w|[0-9]+\) )' ; }
G on shipped;     T 'test(Discriminator|Shipped)'
G on functional;  T testFunctional
G off
python3 "$HERE/option_g_gate.py" state | grep -E '"enabled"|option_g=|marker=|delete_stale|nasb'
