#!/usr/bin/env bash
# WP9 focused regression helper (test-only): runs each listed test path on each engine via t1a_run.sh.
#   WP9_EVID_DIR=<dir> dev/wp9/regress.sh "<engines>" <path> [<path> ...]
set -uo pipefail
cd "$(dirname "$0")/../.."
engines="$1"; shift
rc_all=0
for e in $engines; do
  for p in "$@"; do
    echo "== $e $p"
    out="$(dev/wp9/t1a_run.sh test "$e" "$p" 2>&1)"; rc=$?
    echo "$out" | grep -E "^(OK|Tests:|FAILURES|ERRORS)|✘|exit=" | head -20
    [ $rc -ne 0 ] && rc_all=1
  done
done
echo "ALL_EXIT=$rc_all"
exit $rc_all
