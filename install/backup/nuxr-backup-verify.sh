#!/usr/bin/env bash
# ITC NetCore V1.0 - periodic restore test: restore a backup set into a SCRATCH database, check it, record the result,
# drop the scratch database. A backup that was never restored is not a backup (docs/BACKUP_RESTORE_RUNBOOK.md §4).
#
#   nuxr-backup-verify.sh [--config F] [--set latest|<ts>|<dir>] [--with-binlogs] [--keep] [--skip-app-verify]
#
#   - scratch name: ${VERIFY_DB_PREFIX}_<set ts> (and ..._r for a separate RADIUS DB); refused if it already exists;
#   - nuxr-restore.sh does the integrity work: manifest + encrypted manifest, decryption, load, fingerprint, finance row
#     counts, migrate verify and health --finance on the copy (APP_DIR set);
#   - --with-binlogs also replays the whole archived binlog chain from the set's start coordinate (--pitr-to-end), which
#     proves the PITR chain is complete, decryptable and applicable;
#   - the per-table row counts of a plain restore are written to <set>/VERIFIED.<db>.rows (the "source backup record" of
#     the runbook) and the result to <set>/VERIFIED and $BACKUP_DIR/verify/verify-<UTC>.txt;
#   - the scratch databases are dropped afterwards (only those this run created), unless --keep.
# Run it on a host whose database server can hold a second copy (ideally not the production server: RESTORE_DEFAULTS_FILE
# may point at a separate scratch server). Exit codes: those of nuxr-restore.sh; 6 if the scratch name exists.
set -euo pipefail
NUXR_TOOL=nuxr-backup-verify
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nuxr-backup-lib.sh
. "$HERE/nuxr-backup-lib.sh"

CONFIG=/etc/itcnc/backup.conf; SETARG=latest; WITH_BL=0; KEEP=0; SKIP_APP=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --config) CONFIG=${2:?}; shift 2 ;;
    --set) SETARG=${2:?}; shift 2 ;;
    --with-binlogs) WITH_BL=1; shift ;;
    --keep) KEEP=1; shift ;;
    --skip-app-verify) SKIP_APP=(--skip-app-verify); shift ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) nuxr_die $NUXR_E_USAGE "unknown option $1 (see --help)" ;;
  esac
done
nuxr_load_config "$CONFIG"
nuxr_lock nuxr-backup-verify
[[ -n "$RESTORE_DEFAULTS_FILE" ]] || nuxr_die $NUXR_E_CONFIG "RESTORE_DEFAULTS_FILE must be set"
nuxr_connect "$RESTORE_DEFAULTS_FILE" verify "$RESTORE_EXPECT_SERVER_ID"
SET=$(nuxr_set_dir "$SETARG") || nuxr_die $NUXR_E_USAGE "backup set not found: $SETARG"
TS=$(basename "$SET")
read -r -a SRC_DBS <<< "$(nuxr_info_get "$SET" databases)"
nuxr_require_dbname "$VERIFY_DB_PREFIX"
S_APP="${VERIFY_DB_PREFIX}_${TS,,}"; S_RAD=""
[[ ${#SRC_DBS[@]} -gt 1 ]] && S_RAD="${S_APP}_r"
for d in $S_APP $S_RAD; do
  nuxr_require_dbname "$d"
  nuxr_db_exists "$RESTORE_DEFAULTS_FILE" "$d" && nuxr_die $NUXR_E_REFUSED "scratch database $d already exists: drop it or pick another VERIFY_DB_PREFIX"
done
created=()
cleanup_scratch() {
  [[ $KEEP == 1 ]] && { nuxr_info "--keep: scratch databases kept: ${created[*]:-none}"; return 0; }
  local d
  for d in "${created[@]:-}"; do
    [[ -n "$d" && "$d" == "${VERIFY_DB_PREFIX}_"* ]] || continue
    nuxr_sql "$RESTORE_DEFAULTS_FILE" "SET SESSION sql_log_bin=0; DROP DATABASE IF EXISTS \`$d\`" 2>/dev/null \
      || nuxr_sql "$RESTORE_DEFAULTS_FILE" "DROP DATABASE IF EXISTS \`$d\`" || true
    nuxr_info "scratch database $d dropped"
  done
}
nuxr_on_exit cleanup_scratch
created=("$S_APP"); [[ -n "$S_RAD" ]] && created+=("$S_RAD")

args=(--config "$CONFIG" --set "$SET" --target-db "$S_APP" "${SKIP_APP[@]}")
[[ -n "$S_RAD" ]] && args+=(--target-radius-db "$S_RAD")
[[ $WITH_BL == 1 ]] && args+=(--pitr-to-end)
mkdir -p "$BACKUP_DIR/verify"; chmod 0700 "$BACKUP_DIR/verify"
REPORT="$BACKUP_DIR/verify/verify-$(date -u +%Y%m%dT%H%M%SZ).txt"
t0=$(nuxr_now)
rc=0
"$HERE/nuxr-restore.sh" "${args[@]}" > "$REPORT.out" || rc=$?
cat "$REPORT.out"

if [[ $rc == 0 && $WITH_BL == 0 ]]; then
  # record the per-table row counts of the snapshot (only from a plain restore: a PITR copy is a later state)
  i=0
  for src in "${SRC_DBS[@]}"; do
    d=$S_APP; [[ $i == 1 ]] && d=$S_RAD
    if [[ ! -f "$SET/VERIFIED.$src.rows" ]]; then
      nuxr_fingerprint "$RESTORE_DEFAULTS_FILE" "$d" | awk '!/^#/ { print $1, $2 }' > "$SET/VERIFIED.$src.rows" && chmod 0600 "$SET/VERIFIED.$src.rows"
    fi
    i=$((i + 1))
  done
fi
{
  echo "verified_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "set=$SET"
  echo "with_binlogs=$WITH_BL"
  echo "exit=$rc"
  echo "seconds=$(nuxr_elapsed "$t0")"
  grep -E '^(checksum|load|replay|restored|finance_counts|verify|restore)\.' "$REPORT.out" || true
} > "$REPORT"
rm -f "$REPORT.out"
cp "$REPORT" "$SET/VERIFIED" 2>/dev/null && chmod 0600 "$SET/VERIFIED" || true
nuxr_result verify.report "$REPORT"
nuxr_result verify.exit "$rc"
exit $rc
