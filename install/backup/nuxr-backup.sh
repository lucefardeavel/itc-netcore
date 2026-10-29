#!/usr/bin/env bash
# ITC NetCore V1.0 - consistent, encrypted logical backup of the application database (and of the RADIUS database
# when it is separate), plus an encrypted copy of the secret files (config.php ...). docs/BACKUP_RESTORE_RUNBOOK.md §2.
#
#   nuxr-backup.sh [--config /etc/itcnc/backup.conf] [--label <word>] [--no-offhost] [--no-prune] [--prune-only]
#
# One run creates one backup SET:  $BACKUP_DIR/full/<UTC yyyymmddThhmmssZ>/
#   <db>.sql.<gz|zst>.<enc|age|gpg>   one per database, dump made with --single-transaction (InnoDB snapshot, no lock
#                                      held on the application), routines, triggers, events, hex blobs, utf8mb4,
#                                      with the binlog coordinates of the snapshot (PITR start point) when log_bin=ON
#   secrets.tar.<gz|zst>.<ext>        the files listed in SECRET_FILES
#   backup.info                       metadata (UTC times, engine, versions, sizes, sha256, binlog coordinates, release)
#   MANIFEST.sha256                   sha256 of every file above (sha256sum -c format)
#   MANIFEST.sha256.<ext>             the manifest, encrypted (tamper evidence for the plaintext manifest)
# The set is written as <ts>.partial and renamed when complete, so a crashed run never looks like a backup.
# Then: optional off-host copy (OFFHOST_CMD), then retention pruning (RETENTION_DAYS / RETENTION_MIN_KEEP), which also
# removes archived binlogs older than the oldest retained set.
#
# Exit codes: 0 OK, 1 failure, 3 dump failed, 4 encryption failed, 5 off-host copy failed (set kept locally),
#             64 usage, 75 another run is active, 78 configuration / prerequisite error.
set -euo pipefail
NUXR_TOOL=nuxr-backup
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nuxr-backup-lib.sh
. "$HERE/nuxr-backup-lib.sh"

CONFIG=/etc/itcnc/backup.conf; LABEL=""; OFFHOST=1; PRUNE=1; PRUNE_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --config) CONFIG=${2:?}; shift 2 ;;
    --label) LABEL=${2:?}; [[ "$LABEL" =~ ^[A-Za-z0-9_.-]{1,40}$ ]] || nuxr_die $NUXR_E_USAGE "label: [A-Za-z0-9_.-]{1,40}"; shift 2 ;;
    --no-offhost) OFFHOST=0; shift ;;
    --no-prune) PRUNE=0; shift ;;
    --prune-only) PRUNE_ONLY=1; shift ;;
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    *) nuxr_die $NUXR_E_USAGE "unknown option $1 (see --help)" ;;
  esac
done

nuxr_load_config "$CONFIG"
nuxr_lock nuxr-backup
mkdir -p "$BACKUP_DIR/full" "$BACKUP_DIR/binlog"
chmod 0700 "$BACKUP_DIR" "$BACKUP_DIR/full" "$BACKUP_DIR/binlog"

# ------------------------------------------------------------------ retention
prune() {
  local sets n keep cutoff s ts age_ok oldest_file base seq f nf=0 nb=0
  mapfile -t sets < <(find "$BACKUP_DIR/full" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' | sort)
  n=${#sets[@]}
  keep=$RETENTION_MIN_KEEP
  cutoff=$(( $(date -u +%s) - RETENTION_DAYS * 86400 ))
  for (( i=0; i < n - keep; i++ )); do
    s=${sets[$i]}; ts=$(basename "$s")
    age_ok=$(date -u -d "${ts:0:4}-${ts:4:2}-${ts:6:2} ${ts:9:2}:${ts:11:2}:${ts:13:2}" +%s)
    if (( age_ok < cutoff )) && [[ -f "$s/backup.info" ]]; then
      rm -rf -- "$s"; nf=$((nf + 1)); nuxr_info "retention: removed set $ts"
    fi
  done
  # stale partial sets (a crashed run) older than one day
  find "$BACKUP_DIR/full" -mindepth 1 -maxdepth 1 -type d -name '*.partial' -mmin +1440 -print -exec rm -rf -- {} + 2>/dev/null | sed 's/^/retention: removed partial /' >&2 || true
  # archived binlogs older than the PITR start of the oldest retained set are no longer needed
  s=$(find "$BACKUP_DIR/full" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' | sort | head -1)
  if [[ -n "$s" ]]; then
    local tag; tag=$(nuxr_info_get "$s" archive_tag)
    oldest_file=""
    for db in $(nuxr_info_get "$s" databases); do
      f=$(nuxr_info_get "$s" "db.$db.binlog_file")
      [[ -n "$f" ]] || continue
      if [[ -z "$oldest_file" ]] || (( $(nuxr_binlog_seq "$f") < $(nuxr_binlog_seq "$oldest_file") )); then oldest_file=$f; fi
    done
    if [[ -n "$tag" && -n "$oldest_file" && -d "$BACKUP_DIR/binlog/$tag" ]]; then
      base=$(nuxr_binlog_base "$oldest_file"); seq=$(nuxr_binlog_seq "$oldest_file")
      for f in "$BACKUP_DIR/binlog/$tag/$base".*.info; do
        [[ -e "$f" ]] || continue
        local name; name=$(basename "$f" .info)
        if (( $(nuxr_binlog_seq "$name") < seq )); then
          rm -f -- "$BACKUP_DIR/binlog/$tag/$name".*; nb=$((nb + 1))
        fi
      done
    fi
  fi
  nuxr_result prune.sets_removed "$nf"
  nuxr_result prune.binlogs_removed "$nb"
}

if [[ $PRUNE_ONLY == 1 ]]; then prune; exit 0; fi

# ------------------------------------------------------------------ prerequisites
nuxr_require_cmd sha256sum tee head tail tar
nuxr_check_crypto_prereqs encrypt
nuxr_connect "$DB_DEFAULTS_FILE" backup "$EXPECT_SERVER_ID"
DUMP=$(nuxr_bin dump) || nuxr_die $NUXR_E_CONFIG "no dump tool for $ENGINE"
DUMP_VERSION=$(nuxr_check_tool_flavor "$DUMP")
LOG_BIN=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT @@log_bin")
[[ "$LOG_BIN" == ON ]] && LOG_BIN=1
BINLOG_FORMAT=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT @@binlog_format")
[[ "$LOG_BIN" == 1 ]] || nuxr_warn "log_bin is OFF on the server: this set can be restored, but no point-in-time recovery beyond it is possible"
[[ "$LOG_BIN" != 1 || "$BINLOG_FORMAT" == ROW ]] || nuxr_warn "binlog_format=$BINLOG_FORMAT; ROW is required for a reliable PITR replay"
: "${ARCHIVE_TAG:=server-$NUXR_SERVER_ID}"

for db in $(nuxr_dbs); do
  nuxr_db_exists "$DB_DEFAULTS_FILE" "$db" || nuxr_die $NUXR_E_CONFIG "database $db does not exist (or the backup account cannot see it)"
  non_innodb=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT GROUP_CONCAT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB'")
  if [[ -n "$non_innodb" && "$non_innodb" != NULL ]]; then
    nuxr_warn "$db: non-InnoDB tables are not covered by the --single-transaction snapshot: $non_innodb"
  fi
done

TS=$(date -u +%Y%m%dT%H%M%SZ)
SET="$BACKUP_DIR/full/$TS"
PART="$SET.partial"
[[ ! -e "$SET" && ! -e "$PART" ]] || nuxr_die $NUXR_E_FAIL "set $TS already exists"
mkdir -m 0700 "$PART"
WORK=$(mktemp -d "${TMPDIR:-/tmp}/nuxr-backup.XXXXXX")
nuxr_on_exit "rm -rf '$WORK'"
nuxr_on_exit "[[ -d '$PART' ]] && rm -rf '$PART' && echo 'incomplete set removed: $PART' >&2"
CEXT=$(nuxr_cext); EEXT=$(nuxr_eext)
T_ALL=$(nuxr_now)

{
  echo "format=nuxr-backup-1"
  echo "created_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "label=$LABEL"
  echo "engine=$ENGINE"
  echo "server_version=$NUXR_SERVER_VERSION"
  echo "server_id=$NUXR_SERVER_ID"
  echo "server_host=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT @@hostname")"
  echo "dump_tool=$DUMP_VERSION"
  echo "encryption=$ENCRYPTION"
  echo "openssl_iter=$OPENSSL_ITER"
  echo "compress=$COMPRESS"
  echo "log_bin=$LOG_BIN"
  echo "binlog_format=$BINLOG_FORMAT"
  echo "archive_tag=$ARCHIVE_TAG"
  echo "databases=$(nuxr_dbs)"
  if [[ -n "$APP_DIR" && -d "$APP_DIR" ]]; then
    echo "release_dir=$(readlink -f "$APP_DIR")"
    [[ -f "$APP_DIR/version.json" ]] && echo "release_version=$(tr -d '\n' < "$APP_DIR/version.json" | sed -nE 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/p')"
  fi
} > "$PART/backup.info"

# ------------------------------------------------------------------ dumps
dump_db() {
  local db=$1 out="$PART/$1.sql.$CEXT.$EEXT" hdr="$WORK/$1.hdr" tl="$WORK/$1.tail" errf="$WORK/$1.err" t0 secs ps coord bf bp
  local opts=(--defaults-extra-file="$DB_DEFAULTS_FILE" --single-transaction --quick --routines --triggers --events
              --hex-blob --default-character-set=utf8mb4 --no-tablespaces)
  if [[ "$ENGINE" == mysql ]]; then
    opts+=(--set-gtid-purged=OFF)
    if [[ "$LOG_BIN" == 1 ]]; then
      if [[ "$("$DUMP" --help 2>/dev/null || true)" == *--source-data* ]]; then opts+=(--source-data=2); else opts+=(--master-data=2); fi
    fi
  else
    [[ "$LOG_BIN" == 1 ]] && opts+=(--master-data=2)
  fi
  # shellcheck disable=SC2206
  local extra=($DUMP_EXTRA_OPTS)
  echo "db.$db.started_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$PART/backup.info"
  t0=$(nuxr_now)
  set +e
  "$DUMP" "${opts[@]}" "${extra[@]}" "$db" 2> "$errf" \
    | tee -p >(head -c 65536 > "$hdr") >(tail -c 512 > "$tl") \
    | nuxr_compress | nuxr_encrypt > "$out"
  ps=("${PIPESTATUS[@]}")
  set -e
  sleep 0.2; for _ in 1 2 3 4 5 6 7 8 9 10; do [[ -s "$tl" ]] && break; sleep 0.5; done
  secs=$(nuxr_elapsed "$t0")
  if [[ -s "$errf" ]]; then sed "s/^/  $db dump stderr: /" "$errf" >&2; [[ -n "$LOG_FILE" ]] && sed "s/^/  $db dump stderr: /" "$errf" >> "$LOG_FILE"; fi
  [[ "${ps[0]}" == 0 ]] || nuxr_die $NUXR_E_DUMP "$db: dump exited ${ps[0]}"
  [[ "${ps[2]}" == 0 ]] || nuxr_die $NUXR_E_CRYPT "$db: compression exited ${ps[2]}"
  [[ "${ps[3]}" == 0 ]] || nuxr_die $NUXR_E_CRYPT "$db: encryption exited ${ps[3]}"
  grep -q -- '-- Dump completed' "$tl" || nuxr_die $NUXR_E_DUMP "$db: the dump stream does not end with '-- Dump completed'"
  coord=$(sed -nE "s/^-- CHANGE (MASTER|REPLICATION SOURCE) TO (MASTER|SOURCE)_LOG_FILE='([^']+)', *(MASTER|SOURCE)_LOG_POS=([0-9]+).*/\3 \5/p" "$hdr" | head -1)
  bf=${coord% *}; bp=${coord#* }
  if [[ "$LOG_BIN" == 1 && -z "$coord" ]]; then nuxr_die $NUXR_E_DUMP "$db: no binlog coordinates in the dump header"; fi
  {
    echo "db.$db.file=$(basename "$out")"
    echo "db.$db.bytes=$(nuxr_size "$out")"
    echo "db.$db.sha256=$(nuxr_sha256 "$out")"
    echo "db.$db.seconds=$secs"
    echo "db.$db.binlog_file=${coord:+$bf}"
    echo "db.$db.binlog_pos=${coord:+$bp}"
    echo "db.$db.tables=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE'")"
  } >> "$PART/backup.info"
  nuxr_info "$db: dump OK in ${secs}s, $(nuxr_size "$out") bytes encrypted${coord:+, binlog $bf:$bp}"
}
for db in $(nuxr_dbs); do dump_db "$db"; done

# ------------------------------------------------------------------ secret files
SECRETS_RC=0
if [[ -n "$SECRET_FILES" ]]; then
  rel=(); missing=()
  for f in $SECRET_FILES; do
    if [[ -f "$f" && -r "$f" ]]; then rel+=("${f#/}"); else missing+=("$f"); fi
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    if [[ "$SECRET_FILES_REQUIRED" == 1 ]]; then nuxr_err "secret files missing or unreadable: ${missing[*]}"; SECRETS_RC=1
    else nuxr_warn "secret files missing or unreadable: ${missing[*]}"; fi
  fi
  if [[ ${#rel[@]} -gt 0 ]]; then
    sout="$PART/secrets.tar.$CEXT.$EEXT"
    set +e
    tar -C / -cf - -- "${rel[@]}" | nuxr_compress | nuxr_encrypt > "$sout"
    ps=("${PIPESTATUS[@]}")
    set -e
    [[ "${ps[0]}" == 0 && "${ps[1]}" == 0 && "${ps[2]}" == 0 ]] || nuxr_die $NUXR_E_CRYPT "secret files archive failed (${ps[*]})"
    { echo "secrets.file=$(basename "$sout")"; echo "secrets.paths=${SECRET_FILES}"; echo "secrets.missing=${missing[*]:-}"; } >> "$PART/backup.info"
  fi
else
  nuxr_warn "SECRET_FILES is empty: config.php is NOT in this set (docs/BACKUP_RESTORE_RUNBOOK.md §1)"
fi

# ------------------------------------------------------------------ binlog state at the end, manifest
if [[ "$ENGINE" == mysql ]]; then
  echo "gtid_executed_at_end=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT REPLACE(@@GLOBAL.gtid_executed, '\n', '')")" >> "$PART/backup.info"
else
  echo "gtid_binlog_pos_at_end=$(nuxr_sql "$DB_DEFAULTS_FILE" "SELECT @@GLOBAL.gtid_binlog_pos")" >> "$PART/backup.info"
fi
echo "finished_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$PART/backup.info"
echo "total_seconds=$(nuxr_elapsed "$T_ALL")" >> "$PART/backup.info"
( cd "$PART" && find . -maxdepth 1 -type f ! -name 'MANIFEST.sha256*' -printf '%f\n' | sort | xargs -r sha256sum > MANIFEST.sha256 )
nuxr_encrypt < "$PART/MANIFEST.sha256" > "$PART/MANIFEST.sha256.$EEXT" || nuxr_die $NUXR_E_CRYPT "manifest encryption failed"
chmod 0600 "$PART"/*
mv "$PART" "$SET"
sync -f "$SET" 2>/dev/null || sync
nuxr_info "set complete: $SET"

nuxr_result set "$SET"
nuxr_result set.ts "$TS"
for db in $(nuxr_dbs); do
  nuxr_result "db.$db.bytes" "$(nuxr_info_get "$SET" "db.$db.bytes")"
  nuxr_result "db.$db.seconds" "$(nuxr_info_get "$SET" "db.$db.seconds")"
  nuxr_result "db.$db.binlog" "$(nuxr_info_get "$SET" "db.$db.binlog_file"):$(nuxr_info_get "$SET" "db.$db.binlog_pos")"
done
nuxr_result total_seconds "$(nuxr_info_get "$SET" total_seconds)"

# ------------------------------------------------------------------ off-host copy, retention
RC=0
if [[ $OFFHOST == 1 && -n "$OFFHOST_CMD" ]]; then
  t0=$(nuxr_now)
  if ( cd "$BACKUP_DIR" && bash -c "$OFFHOST_CMD" nuxr-offhost "full/$TS" ); then
    nuxr_result offhost OK; nuxr_result offhost.seconds "$(nuxr_elapsed "$t0")"
  else
    nuxr_err "off-host copy failed; the set is kept locally"; nuxr_result offhost FAILED; RC=$NUXR_E_OFFHOST
  fi
elif [[ -z "$OFFHOST_CMD" ]]; then
  nuxr_warn "OFFHOST_CMD is empty: no off-host copy (the runbook requires at least one off-site copy)"
  nuxr_result offhost NOT_CONFIGURED
fi
[[ $PRUNE == 1 ]] && prune
if [[ $SECRETS_RC != 0 && $RC == 0 ]]; then RC=$NUXR_E_FAIL; fi
exit "$RC"
