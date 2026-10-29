#!/usr/bin/env bash
# ITC NetCore V1.0 - restore a backup set into a NEW database, optionally replay archived binlogs to a point in time,
# then verify the copy (docs/BACKUP_RESTORE_RUNBOOK.md §3). It never touches the live database unless explicitly told to.
#
#   nuxr-restore.sh [--config F] --set <latest|<yyyymmddThhmmssZ>|<dir>> --target-db <new name>
#                   [--target-radius-db <new name>]           (required when the set holds a separate RADIUS DB)
#                   [--stop-datetime 'YYYY-MM-DD HH:MM:SS'    PITR: replay binlog events BEFORE this UTC time
#                    | --stop-position <binlog file>:<pos>    PITR: replay up to (excluding) this event position
#                    | --pitr-to-end]                         PITR: replay everything in the archive
#                   [--binlog-dir DIR]                        archived binlogs (default $BACKUP_DIR/binlog/<archive_tag>)
#                   [--i-understand-overwrite <db>]           allow replacing <db> although it is not empty (repeatable)
#                   [--allow-unsafe-binlog-statements]        do not refuse DROP/CREATE DATABASE or source-qualified SQL
#                   [--decode-only <file>]                    write the decoded replay SQL (0600) and stop before applying
#                   [--fingerprint-out <file>]                write the per-table fingerprint of the restored app DB
#                   [--skip-app-verify]
#   nuxr-restore.sh [--config F] --verify-only --target-db <db> [--target-radius-db <db>] [--fingerprint-out <file>]
#
# Safety rules:
#   - the target must not exist, or exist and be EMPTY (no table, view, routine, event, trigger). A non-empty target is
#     refused unless --i-understand-overwrite names exactly that database; it is then dropped after a grace period
#     (NUXR_OVERWRITE_GRACE, default 10 s) and recreated;
#   - every file is checked against MANIFEST.sha256 (and the encrypted manifest) before it is decrypted;
#   - the replay SQL is decoded first and scanned: CREATE/DROP/ALTER DATABASE and statements that still name the SOURCE
#     database are refused (they would act on the live database), table DDL is reported;
#   - the restore session does not write the target server's binlog (RESTORE_SKIP_BINLOG=1, needs the privilege);
#   - RESTORE_EXPECT_SERVER_ID refuses to run against any other server.
# Exit codes: 0 OK, 1 failure, 4 decryption failed, 6 refused by a safety rule, 7 checksum mismatch, 8 load failed,
#             9 binlog chain incomplete / replay failed, 10 verification failed, 64 usage, 75 locked, 78 configuration.
set -euo pipefail
NUXR_TOOL=nuxr-restore
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nuxr-backup-lib.sh
. "$HERE/nuxr-backup-lib.sh"

CONFIG=/etc/itcnc/backup.conf; SETARG=""; TGT=""; TGT_R=""; STOP_DT=""; STOP_POS=""; TO_END=0; BLDIR=""
OVERWRITE=(); UNSAFE_OK=0; DECODE_ONLY=""; FP_OUT=""; SKIP_APP=0; VERIFY_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --config) CONFIG=${2:?}; shift 2 ;;
    --set) SETARG=${2:?}; shift 2 ;;
    --target-db) TGT=${2:?}; shift 2 ;;
    --target-radius-db) TGT_R=${2:?}; shift 2 ;;
    --stop-datetime) STOP_DT=${2:?}; shift 2 ;;
    --stop-position) STOP_POS=${2:?}; shift 2 ;;
    --pitr-to-end) TO_END=1; shift ;;
    --binlog-dir) BLDIR=${2:?}; shift 2 ;;
    --i-understand-overwrite) OVERWRITE+=("${2:?}"); shift 2 ;;
    --allow-unsafe-binlog-statements) UNSAFE_OK=1; shift ;;
    --decode-only) DECODE_ONLY=${2:?}; shift 2 ;;
    --fingerprint-out) FP_OUT=${2:?}; shift 2 ;;
    --skip-app-verify) SKIP_APP=1; shift ;;
    --verify-only) VERIFY_ONLY=1; shift ;;
    -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
    *) nuxr_die $NUXR_E_USAGE "unknown option $1 (see --help)" ;;
  esac
done
[[ -n "$TGT" ]] || nuxr_die $NUXR_E_USAGE "--target-db is required"
nuxr_require_dbname "$TGT"
[[ -z "$TGT_R" ]] || nuxr_require_dbname "$TGT_R"
[[ -z "$TGT_R" || "$TGT_R" != "$TGT" ]] || nuxr_die $NUXR_E_USAGE "--target-radius-db must differ from --target-db"
n_pitr=0; [[ -n "$STOP_DT" ]] && n_pitr=$((n_pitr+1)); [[ -n "$STOP_POS" ]] && n_pitr=$((n_pitr+1)); [[ $TO_END == 1 ]] && n_pitr=$((n_pitr+1))
(( n_pitr <= 1 )) || nuxr_die $NUXR_E_USAGE "use only one of --stop-datetime, --stop-position, --pitr-to-end"
if [[ -n "$STOP_DT" ]]; then
  [[ "$STOP_DT" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}:[0-9]{2}$ ]] || nuxr_die $NUXR_E_USAGE "--stop-datetime 'YYYY-MM-DD HH:MM:SS' (UTC)"
  date -u -d "$STOP_DT" +%s >/dev/null 2>&1 || nuxr_die $NUXR_E_USAGE "--stop-datetime is not a valid time"
fi
if [[ -n "$STOP_POS" ]]; then
  [[ "$STOP_POS" =~ ^([A-Za-z0-9_.-]+\.[0-9]{6,}):([0-9]+)$ ]] || nuxr_die $NUXR_E_USAGE "--stop-position <binlog file>:<position>"
  STOP_FILE=${BASH_REMATCH[1]}; STOP_P=${BASH_REMATCH[2]}
fi
PITR=$(( n_pitr ))

nuxr_load_config "$CONFIG"
[[ -n "$RESTORE_DEFAULTS_FILE" ]] || nuxr_die $NUXR_E_CONFIG "RESTORE_DEFAULTS_FILE must be set (an administrative account for the restore)"
R=$RESTORE_DEFAULTS_FILE
nuxr_lock nuxr-restore
nuxr_connect "$R" restore "$RESTORE_EXPECT_SERVER_ID"
T_ALL=$(nuxr_now)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/nuxr-restore.XXXXXX")
nuxr_on_exit "rm -rf '$WORK'"

post_checks() { # <app db> <radius db or ""> -> fingerprint, finance counts, application verification
  local db=$1 rdb=$2 fp="$WORK/fp.$1" rc=0
  nuxr_fingerprint "$R" "$db" > "$fp"
  nuxr_result "restored.$db" "$(nuxr_fingerprint_summary "$fp")"
  [[ -z "$FP_OUT" ]] || { install -m 0600 "$fp" "$FP_OUT"; nuxr_info "fingerprint written to $FP_OUT"; }
  if [[ -n "$rdb" ]]; then nuxr_fingerprint "$R" "$rdb" > "$WORK/fp.$rdb"; nuxr_result "restored.$rdb" "$(nuxr_fingerprint_summary "$WORK/fp.$rdb")"; fi
  nuxr_result "finance_counts.$db" "$(nuxr_finance_counts "$R" "$db")"
  if [[ $SKIP_APP == 1 ]]; then nuxr_result verify.app SKIPPED; return 0; fi
  local t0; t0=$(nuxr_now)
  nuxr_app_verify "$R" "$db" "${rdb:-$db}" || rc=$?
  nuxr_result verify.seconds "$(nuxr_elapsed "$t0")"
  if [[ $rc == 0 ]]; then nuxr_result verify.app OK; else nuxr_result verify.app FAILED; fi
  return $rc
}

if [[ $VERIFY_ONLY == 1 ]]; then
  nuxr_db_exists "$R" "$TGT" || nuxr_die $NUXR_E_USAGE "$TGT does not exist"
  rc=0; post_checks "$TGT" "$TGT_R" || rc=$?
  exit $rc
fi

# ------------------------------------------------------------------ 1. the set and its integrity
[[ -n "$SETARG" ]] || nuxr_die $NUXR_E_USAGE "--set is required"
SET=$(nuxr_set_dir "$SETARG") || nuxr_die $NUXR_E_USAGE "backup set not found: $SETARG"
[[ -f "$SET/backup.info" && -f "$SET/MANIFEST.sha256" ]] || nuxr_die $NUXR_E_CHECKSUM "$SET is not a complete backup set"
nuxr_info "set $SET (created $(nuxr_info_get "$SET" created_utc), engine $(nuxr_info_get "$SET" engine) $(nuxr_info_get "$SET" server_version))"
[[ "$(nuxr_info_get "$SET" engine)" == "$ENGINE" ]] || nuxr_die $NUXR_E_REFUSED "the set was taken from $(nuxr_info_get "$SET" engine), the target server is $ENGINE: cross-engine restore is not supported"
ITER=$(nuxr_info_get "$SET" openssl_iter); ITER=${ITER:-$OPENSSL_ITER}
SET_ENC=$(nuxr_info_get "$SET" encryption); ENCRYPTION=${SET_ENC:-$ENCRYPTION}
nuxr_check_crypto_prereqs decrypt
t0=$(nuxr_now)
( cd "$SET" && sha256sum -c --quiet --strict MANIFEST.sha256 ) || nuxr_die $NUXR_E_CHECKSUM "MANIFEST.sha256 check failed for $SET"
EM=$(find "$SET" -maxdepth 1 -name 'MANIFEST.sha256.*' | head -1)
if [[ -n "$EM" ]]; then
  nuxr_decrypt "$EM" "$ITER" > "$WORK/manifest" 2>/dev/null || nuxr_die $NUXR_E_CRYPT "cannot decrypt $EM (wrong key?)"
  cmp -s "$WORK/manifest" "$SET/MANIFEST.sha256" || nuxr_die $NUXR_E_CHECKSUM "the encrypted manifest differs from MANIFEST.sha256: the set was altered"
fi
nuxr_result checksum.seconds "$(nuxr_elapsed "$t0")"
nuxr_info "checksums OK"

read -r -a SRC_DBS <<< "$(nuxr_info_get "$SET" databases)"
SRC_APP=${SRC_DBS[0]}; SRC_RAD=${SRC_DBS[1]:-}
if [[ -n "$SRC_RAD" && -z "$TGT_R" ]]; then nuxr_die $NUXR_E_USAGE "the set holds a separate RADIUS database ($SRC_RAD): give --target-radius-db"; fi
declare -A MAP=(["$SRC_APP"]="$TGT")
[[ -z "$SRC_RAD" ]] || MAP["$SRC_RAD"]="$TGT_R"

# ------------------------------------------------------------------ 2. target safety
allowed_overwrite() { local d; for d in "${OVERWRITE[@]:-}"; do [[ "$d" == "$1" ]] && return 0; done; return 1; }
for src in "${!MAP[@]}"; do
  tgt=${MAP[$src]}
  if nuxr_db_exists "$R" "$tgt"; then
    objs=$(nuxr_db_object_count "$R" "$tgt")
    if [[ "$objs" != 0 ]]; then
      if ! allowed_overwrite "$tgt"; then
        nuxr_die $NUXR_E_REFUSED "target $tgt exists and is NOT empty ($objs objects): refused. Restore into a new name, or pass --i-understand-overwrite $tgt"
      fi
      grace=${NUXR_OVERWRITE_GRACE:-10}
      nuxr_warn "OVERWRITE: $tgt holds $objs objects and will be DROPPED in ${grace}s (Ctrl-C to abort)"
      sleep "$grace"
      nuxr_sql "$R" "DROP DATABASE \`$tgt\`"
      nuxr_warn "OVERWRITE: $tgt dropped"
    else
      nuxr_info "target $tgt exists and is empty"
    fi
  fi
done

# ------------------------------------------------------------------ 3. load the dumps
T_LOAD=$(nuxr_now)
for src in "${!MAP[@]}"; do
  tgt=${MAP[$src]}
  f="$SET/$(nuxr_info_get "$SET" "db.$src.file")"
  [[ -f "$f" ]] || nuxr_die $NUXR_E_CHECKSUM "dump file of $src missing"
  nuxr_sql "$R" "CREATE DATABASE IF NOT EXISTS \`$tgt\` CHARACTER SET utf8mb4 COLLATE $RESTORE_COLLATION"
  pre=""; [[ "$RESTORE_SKIP_BINLOG" == 1 ]] && pre="SET SESSION sql_log_bin=0;"
  t1=$(nuxr_now)
  set +e
  nuxr_decrypt "$f" "$ITER" | nuxr_decompress_ext "$f" | cat <(printf '%s\n' "$pre") - \
    | "$NUXR_CLIENT" --defaults-extra-file="$R" --default-character-set=utf8mb4 "$tgt" 2> "$WORK/load.err"
  ps=("${PIPESTATUS[@]}")
  set -e
  [[ -s "$WORK/load.err" ]] && sed "s/^/  load $tgt: /" "$WORK/load.err" >&2
  [[ "${ps[0]}" == 0 ]] || nuxr_die $NUXR_E_CRYPT "$src: decryption failed (exit ${ps[0]})"
  [[ "${ps[1]}" == 0 ]] || nuxr_die $NUXR_E_CRYPT "$src: decompression failed (exit ${ps[1]})"
  [[ "${ps[3]}" == 0 ]] || nuxr_die $NUXR_E_RESTORE "$src -> $tgt: load failed (exit ${ps[3]})"
  nuxr_result "load.$tgt.seconds" "$(nuxr_elapsed "$t1")"
  nuxr_info "$src -> $tgt loaded"
done
nuxr_result load.seconds "$(nuxr_elapsed "$T_LOAD")"

# ------------------------------------------------------------------ 4. point-in-time replay
if (( PITR > 0 )); then
  T_REPLAY=$(nuxr_now)
  [[ "$(nuxr_info_get "$SET" log_bin)" == 1 ]] || nuxr_die $NUXR_E_REPLAY "the set was taken with log_bin OFF: no PITR start point"
  BINLOG_BIN=$(nuxr_bin binlog) || nuxr_die $NUXR_E_CONFIG "no mysqlbinlog / mariadb-binlog for $ENGINE"
  nuxr_check_tool_flavor "$BINLOG_BIN" > /dev/null
  TAG=$(nuxr_info_get "$SET" archive_tag)
  BLDIR=${BLDIR:-$BACKUP_DIR/binlog/$TAG}
  [[ -d "$BLDIR" ]] || nuxr_die $NUXR_E_REPLAY "binlog archive not found: $BLDIR"
  # earliest start among the databases of the set
  START_FILE=""
  for src in "${!MAP[@]}"; do
    f=$(nuxr_info_get "$SET" "db.$src.binlog_file")
    [[ -n "$f" ]] || nuxr_die $NUXR_E_REPLAY "no binlog coordinates recorded for $src"
    if [[ -z "$START_FILE" ]] || (( $(nuxr_binlog_seq "$f") < $(nuxr_binlog_seq "$START_FILE") )); then START_FILE=$f; fi
  done
  BASE=$(nuxr_binlog_base "$START_FILE"); SSEQ=$(nuxr_binlog_seq "$START_FILE")
  mapfile -t NAMES < <(find "$BLDIR" -maxdepth 1 -name "$BASE.*.info" -printf '%f\n' | sed 's/\.info$//' | sort -t. -k2,2n)
  CHAIN=()
  for nm in "${NAMES[@]}"; do (( $(nuxr_binlog_seq "$nm") >= SSEQ )) && CHAIN+=("$nm"); done
  (( ${#CHAIN[@]} > 0 )) || nuxr_die $NUXR_E_REPLAY "no archived binlog from $START_FILE on in $BLDIR"
  [[ "${CHAIN[0]}" == "$START_FILE" ]] || nuxr_die $NUXR_E_REPLAY "chain broken: the archive starts at ${CHAIN[0]}, the set needs $START_FILE"
  prev=$((SSEQ - 1))
  for nm in "${CHAIN[@]}"; do
    s=$(nuxr_binlog_seq "$nm")
    (( s == prev + 1 )) || nuxr_die $NUXR_E_REPLAY "chain broken: binlog after $(printf '%s.%06d' "$BASE" "$prev") is missing (next archived: $nm)"
    prev=$s
  done
  if [[ -n "$STOP_POS" ]]; then
    keep=(); found=0
    for nm in "${CHAIN[@]}"; do keep+=("$nm"); [[ "$nm" == "$STOP_FILE" ]] && { found=1; break; }; done
    [[ $found == 1 ]] || nuxr_die $NUXR_E_REPLAY "--stop-position file $STOP_FILE is not in the archived chain"
    CHAIN=("${keep[@]}")
  fi
  LAST=${CHAIN[-1]}
  ARCH_END=$(sed -n 's/^last_event_utc=//p' "$BLDIR/$LAST.info")
  nuxr_result replay.chain "${CHAIN[0]}..$LAST (${#CHAIN[@]} files)"
  nuxr_result replay.archive_end_utc "$ARCH_END"
  if [[ -n "$STOP_DT" && -n "$ARCH_END" ]]; then
    if (( $(date -u -d "${ARCH_END%Z}" +%s) < $(date -u -d "$STOP_DT" +%s) - 1 )); then
      nuxr_warn "the archive ends at $ARCH_END, before the requested stop time $STOP_DT UTC: changes after $ARCH_END are NOT in the archive"
    fi
  fi
  # verify + decrypt the chain into the private work directory
  t1=$(nuxr_now)
  mkdir -m 0700 "$WORK/binlog"
  for nm in "${CHAIN[@]}"; do
    ( cd "$BLDIR" && sha256sum -c --quiet --strict "$nm.sha256" ) || nuxr_die $NUXR_E_CHECKSUM "$nm: archive checksum mismatch"
    ef="$BLDIR/$(sed -n 's/^file=//p' "$BLDIR/$nm.info")"
    it=$(sed -n 's/^openssl_iter=//p' "$BLDIR/$nm.info"); it=${it:-$ITER}
    set +e
    nuxr_decrypt "$ef" "$it" | nuxr_decompress_ext "$ef" > "$WORK/binlog/$nm"
    ps=("${PIPESTATUS[@]}")
    set -e
    [[ "${ps[0]}" == 0 && "${ps[1]}" == 0 ]] || nuxr_die $NUXR_E_CRYPT "$nm: decrypt/decompress failed"
    [[ "$(nuxr_sha256 "$WORK/binlog/$nm")" == "$(sed -n 's/^raw_sha256=//p' "$BLDIR/$nm.info")" ]] || nuxr_die $NUXR_E_CHECKSUM "$nm: raw sha256 mismatch after decryption"
  done
  nuxr_result replay.fetch_seconds "$(nuxr_elapsed "$t1")"

  for src in "${!MAP[@]}"; do
    tgt=${MAP[$src]}
    sf=$(nuxr_info_get "$SET" "db.$src.binlog_file"); sp=$(nuxr_info_get "$SET" "db.$src.binlog_pos")
    files=()
    for nm in "${CHAIN[@]}"; do (( $(nuxr_binlog_seq "$nm") >= $(nuxr_binlog_seq "$sf") )) && files+=("$WORK/binlog/$nm"); done
    # --rewrite-db maps the events to the new name. The engines differ in the order of the two filters (lab drill):
    # MySQL's mysqlbinlog rewrites first and --database must name the NEW database; MariaDB's mariadb-binlog filters
    # first and --database must name the SOURCE database. --disable-log-bin keeps the replay out of the target's binlog.
    if [[ "$ENGINE" == mysql ]]; then fdb=$tgt; else fdb=$src; fi
    opts=(--no-defaults "--database=$fdb" "--rewrite-db=$src->$tgt" "--start-position=$sp")
    [[ "$RESTORE_SKIP_BINLOG" == 1 ]] && opts+=(--disable-log-bin)
    [[ "$ENGINE" == mysql ]] && opts+=(--skip-gtids)
    [[ -n "$STOP_DT" ]] && opts+=("--stop-datetime=$STOP_DT")
    [[ -n "$STOP_POS" ]] && opts+=("--stop-position=$STOP_P")
    t1=$(nuxr_now)
    sql="$WORK/replay.$tgt.sql"
    TZ=UTC "$BINLOG_BIN" "${opts[@]}" "${files[@]}" > "$sql" 2> "$WORK/decode.err" \
      || { sed 's/^/  decode: /' "$WORK/decode.err" >&2; nuxr_die $NUXR_E_REPLAY "$src: binlog decode failed"; }
    nuxr_result "replay.$tgt.decode_seconds" "$(nuxr_elapsed "$t1")"
    # safety scan of the statements (the base64 BINLOG row images cannot contain '.', '`' or spaces)
    bad=$(grep -n -v '^#' "$sql" | grep -i -E '^[0-9]+:(.*[^A-Za-z0-9_])?(CREATE|DROP|ALTER)[[:space:]]+(DATABASE|SCHEMA)([^A-Za-z0-9_]|$)' | head -5 || true)
    srcref=$(grep -n -v '^#' "$sql" | grep -E "^[0-9]+:(.*[^A-Za-z0-9_])?(\`$src\`|$src)\." | head -5 || true)
    srcuse=$(grep -n -i -E "^use[[:space:]]+\`?$src\`?([^A-Za-z0-9_]|$)" "$sql" | head -5 || true)
    ddl=$(grep -c -i -E "^[^#]*\b(CREATE|DROP|ALTER|TRUNCATE|RENAME)[[:space:]]+TABLE\b" "$sql" || true)
    [[ "$ddl" == 0 ]] || nuxr_warn "$src: the replay range contains $ddl table DDL statement(s) (a migration or a DROP TABLE?): review with --decode-only"
    if [[ -n "$bad$srcref$srcuse" ]]; then
      nuxr_err "$src: the replay range holds statements that act on a database by name:"
      printf '%s\n' "$bad" "$srcref" "$srcuse" | sed '/^$/d; s/^/    line /' | cut -c1-200 >&2
      [[ $UNSAFE_OK == 1 ]] || nuxr_die $NUXR_E_REFUSED "refused (choose an earlier --stop-datetime / --stop-position, or --allow-unsafe-binlog-statements after review)"
      nuxr_warn "--allow-unsafe-binlog-statements given: applying anyway"
    fi
    last=$(sed -nE 's/^#([0-9]{2})([0-9]{2})([0-9]{2}) +([0-9]{1,2}):([0-9]{2}:[0-9]{2}) server id.*/20\1-\2-\3 0\4:\5/p' "$sql" | sed -E 's/ 0([0-9]{2}):/ \1:/' | tail -1)
    nuxr_result "replay.$tgt.last_event_utc" "${last:-none}"
    nrows=$(grep -c -E "^#.*(Write_rows|Update_rows|Delete_rows)" "$sql" || true)
    nuxr_result "replay.$tgt.rows_events" "$nrows"
    [[ "$nrows" != 0 ]] || nuxr_warn "$src: the replay range holds no row event for $src (nothing written since the backup, or a filter problem)"
    if [[ -n "$DECODE_ONLY" ]]; then
      install -m 0600 "$sql" "$DECODE_ONLY.$tgt.sql"
      nuxr_info "decoded replay SQL written to $DECODE_ONLY.$tgt.sql; not applied (--decode-only)"
      continue
    fi
    t1=$(nuxr_now)
    "$NUXR_CLIENT" --defaults-extra-file="$R" --binary-mode --default-character-set=utf8mb4 "$tgt" < "$sql" 2> "$WORK/apply.err" \
      || { sed 's/^/  apply: /' "$WORK/apply.err" >&2; nuxr_die $NUXR_E_REPLAY "$src -> $tgt: replay failed"; }
    nuxr_result "replay.$tgt.apply_seconds" "$(nuxr_elapsed "$t1")"
  done
  rm -rf "$WORK/binlog"
  nuxr_result replay.seconds "$(nuxr_elapsed "$T_REPLAY")"
  [[ -z "$DECODE_ONLY" ]] || exit 0
fi

# ------------------------------------------------------------------ 5. verification
RC=0
if (( PITR == 0 )); then
  # without PITR the copy must equal the dump snapshot: compare with the row counts nuxr-backup-verify.sh recorded
  for src in "${!MAP[@]}"; do
    rec="$SET/VERIFIED.$src.rows"
    if [[ -f "$rec" ]]; then
      nuxr_fingerprint "$R" "${MAP[$src]}" | awk '!/^#/ { print $1, $2 }' > "$WORK/rows.$src"
      if cmp -s "$rec" "$WORK/rows.$src"; then nuxr_result "rowcounts.${MAP[$src]}" "MATCH $rec"
      else nuxr_err "row counts of ${MAP[$src]} differ from $rec"; nuxr_result "rowcounts.${MAP[$src]}" MISMATCH; RC=$NUXR_E_VERIFY; fi
    fi
  done
fi
post_checks "$TGT" "$TGT_R" || RC=$?
nuxr_result restore.total_seconds "$(nuxr_elapsed "$T_ALL")"
nuxr_result restore.result "$([[ $RC == 0 ]] && echo OK || echo "FAILED($RC)")"
nuxr_info "restore finished: $TGT${TGT_R:+ + $TGT_R} (exit $RC). Switch-over is a separate, manual step: docs/BACKUP_RESTORE_RUNBOOK.md §3 step 3"
exit $RC
