#!/usr/bin/env bash
# ITC NetCore V1.0 - canonical fingerprint of a database: one line per base table
#   <table> <row count> <sha256 of all rows in primary-key order> <sha256 of SHOW CREATE TABLE without AUTO_INCREMENT=n>
# then the counts of views, routines, triggers and events. It does not depend on the database NAME, so a restored copy
# can be compared with its source (diff of two outputs). Read-only. Prints no row content.
#
#   nuxr-db-fingerprint.sh --defaults-file <0600 option file> --db <name> [--engine mariadb|mysql] [--client-bin-dir DIR]
#   nuxr-db-fingerprint.sh ... --compare <fingerprint file>       exit 0 identical, 10 different (differences listed)
set -euo pipefail
NUXR_TOOL=nuxr-db-fingerprint
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nuxr-backup-lib.sh
. "$HERE/nuxr-backup-lib.sh"
DF=""; DB=""; ENGINE=auto; CLIENT_BIN_DIR=""; CMP=""; LOG_FILE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --defaults-file) DF=${2:?}; shift 2 ;;
    --db) DB=${2:?}; shift 2 ;;
    --engine) ENGINE=${2:?}; shift 2 ;;
    --client-bin-dir) CLIENT_BIN_DIR=${2:?}; shift 2 ;;
    --compare) CMP=${2:?}; shift 2 ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
    *) nuxr_die $NUXR_E_USAGE "unknown option $1" ;;
  esac
done
[[ -n "$DF" && -n "$DB" ]] || nuxr_die $NUXR_E_USAGE "--defaults-file and --db are required"
nuxr_require_dbname "$DB"
nuxr_connect "$DF" fingerprint 2>/dev/null
nuxr_db_exists "$DF" "$DB" || nuxr_die $NUXR_E_USAGE "database $DB not found"
if [[ -z "$CMP" ]]; then nuxr_fingerprint "$DF" "$DB"; exit 0; fi
tmp=$(mktemp); trap 'rm -f "$tmp"' EXIT
nuxr_fingerprint "$DF" "$DB" > "$tmp"
if diff -u "$CMP" "$tmp" > /dev/null; then
  echo "IDENTICAL: $(nuxr_fingerprint_summary "$tmp")"; exit 0
fi
echo "DIFFERENT: $(nuxr_fingerprint_summary "$CMP") (reference) vs $(nuxr_fingerprint_summary "$tmp") ($DB)"
diff "$CMP" "$tmp" | grep -E '^[<>]' | awk '{ print $1, $2, "rows=" $3 }' | head -40
exit 10
