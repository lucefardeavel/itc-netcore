#!/usr/bin/env bash
# ITC NetCore V1.0 - archive CLOSED binary logs to the backup store (point-in-time recovery chain), MariaDB 10.11 and
# MySQL 8.0. Run it every few minutes (systemd timer / cron, README.md): the archive interval bounds the data-loss
# window when the database host itself is lost.
#
#   nuxr-binlog-archive.sh [--config /etc/itcnc/backup.conf] [--no-flush] [--no-offhost] [--check-only]
#
# Steps: check the server's binlog settings -> FLUSH BINARY LOGS (BINLOG_FLUSH=1, closes the current file so the
# archive is current) -> for every closed binlog not yet archived: fetch it (BINLOG_MODE=remote: mysqlbinlog
# --read-from-remote-server --raw, needs no access to the datadir; BINLOG_MODE=local: copy from BINLOG_DIR on the
# database host) -> check its size against SHOW BINARY LOGS -> sha256 of the raw file -> compress + encrypt into
#   $BACKUP_DIR/binlog/<ARCHIVE_TAG>/<name>.<gz|zst>.<enc|age|gpg>
#   $BACKUP_DIR/binlog/<ARCHIVE_TAG>/<name>.sha256   (sha256sum -c format, of the encrypted file)
#   $BACKUP_DIR/binlog/<ARCHIVE_TAG>/<name>.info     (sizes, raw sha256, first / last event time UTC)
# -> optional off-host copy (OFFHOST_BINLOG_CMD, default OFFHOST_CMD). Pruning is done by nuxr-backup.sh (binlogs older
# than the PITR start of the oldest retained full set). The server's own binlog expiry must stay longer than the
# archive interval plus any outage of this job (README.md "Server settings").
#
# Exit codes: 0 OK, 1 failure, 4 encryption failed, 5 off-host copy failed, 11 binlog archiving failed or chain gap,
#             64 usage, 75 another run is active, 78 configuration (log_bin OFF, missing tool, ...).
set -euo pipefail
NUXR_TOOL=nuxr-binlog-archive
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nuxr-backup-lib.sh
. "$HERE/nuxr-backup-lib.sh"

CONFIG=/etc/itcnc/backup.conf; FLUSH=""; OFFHOST=1; CHECK_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --config) CONFIG=${2:?}; shift 2 ;;
    --no-flush) FLUSH=0; shift ;;
    --no-offhost) OFFHOST=0; shift ;;
    --check-only) CHECK_ONLY=1; shift ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) nuxr_die $NUXR_E_USAGE "unknown option $1 (see --help)" ;;
  esac
done
nuxr_load_config "$CONFIG"
: "${FLUSH:=$BINLOG_FLUSH}"
: "${OFFHOST_BINLOG_CMD:=$OFFHOST_CMD}"
nuxr_lock nuxr-binlog-archive
nuxr_connect "$BINLOG_DEFAULTS_FILE" binlog "$EXPECT_SERVER_ID"
BINLOG_BIN=$(nuxr_bin binlog) || nuxr_die $NUXR_E_CONFIG "no mysqlbinlog / mariadb-binlog for $ENGINE (install the engine's client tools or set CLIENT_BIN_DIR)"
nuxr_check_tool_flavor "$BINLOG_BIN" > /dev/null
: "${ARCHIVE_TAG:=server-$NUXR_SERVER_ID}"
[[ "$ARCHIVE_TAG" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] || nuxr_die $NUXR_E_CONFIG "ARCHIVE_TAG: [A-Za-z0-9_.-]{1,64}"

# ------------------------------------------------------------------ server settings check
q() { nuxr_sql "$BINLOG_DEFAULTS_FILE" "$1"; }
LOG_BIN=$(q "SELECT @@log_bin")
[[ "$LOG_BIN" == ON ]] && LOG_BIN=1
FMT=$(q "SELECT @@binlog_format")
ROWIMG=$(q "SELECT @@binlog_row_image")
SYNC=$(q "SELECT @@sync_binlog")
FLUSHTRX=$(q "SELECT @@innodb_flush_log_at_trx_commit")
if [[ "$ENGINE" == mysql ]]; then
  EXPIRE=$(q "SELECT @@binlog_expire_logs_seconds")
else
  EXPIRE=$(q "SELECT IF(@@binlog_expire_logs_seconds > 0, @@binlog_expire_logs_seconds, ROUND(@@expire_logs_days * 86400))" 2>/dev/null || q "SELECT ROUND(@@expire_logs_days * 86400)")
fi
nuxr_info "server: log_bin=$LOG_BIN binlog_format=$FMT binlog_row_image=$ROWIMG server_id=$NUXR_SERVER_ID sync_binlog=$SYNC innodb_flush_log_at_trx_commit=$FLUSHTRX binlog_expire_seconds=$EXPIRE"
[[ "$LOG_BIN" == 1 ]] || nuxr_die $NUXR_E_CONFIG "log_bin is OFF: enable binary logging (README.md 'Server settings') before archiving"
[[ "$NUXR_SERVER_ID" != 0 ]] || nuxr_die $NUXR_E_CONFIG "server_id is 0: set a unique server_id"
[[ "$FMT" == ROW ]] || nuxr_warn "binlog_format=$FMT: set binlog_format=ROW (statement-based events are not reliably replayable into a renamed database)"
[[ "$ROWIMG" == FULL ]] || nuxr_warn "binlog_row_image=$ROWIMG: FULL is recommended"
[[ "$SYNC" == 1 ]] || nuxr_warn "sync_binlog=$SYNC: set sync_binlog=1 so a committed transaction is in the binlog after a crash"
[[ "$FLUSHTRX" == 1 ]] || nuxr_warn "innodb_flush_log_at_trx_commit=$FLUSHTRX: 1 is required for durability"
if [[ "$EXPIRE" == 0 ]]; then nuxr_warn "binlogs never expire on the server: watch the disk"
elif (( EXPIRE < 172800 )); then nuxr_warn "binlog expiry ${EXPIRE}s is under 2 days: an outage of this job longer than that breaks the chain"; fi
nuxr_result binlog.server_checks "log_bin=$LOG_BIN format=$FMT row_image=$ROWIMG sync_binlog=$SYNC expire_s=$EXPIRE"
[[ $CHECK_ONLY == 1 ]] && exit 0

nuxr_require_cmd sha256sum
nuxr_check_crypto_prereqs encrypt
DIR="$BACKUP_DIR/binlog/$ARCHIVE_TAG"
mkdir -p "$DIR"; chmod 0700 "$BACKUP_DIR" "$BACKUP_DIR/binlog" "$DIR" 2>/dev/null || true
WORK=$(mktemp -d "${TMPDIR:-/tmp}/nuxr-binlog.XXXXXX")
nuxr_on_exit "rm -rf '$WORK'"
CEXT=$(nuxr_cext); EEXT=$(nuxr_eext)
if [[ "$BINLOG_MODE" == local ]]; then
  : "${BINLOG_DIR:=$(dirname "$(q "SELECT @@log_bin_basename")")}"
  [[ -d "$BINLOG_DIR" && -r "$BINLOG_DIR" ]] || nuxr_die $NUXR_E_CONFIG "BINLOG_DIR not readable: $BINLOG_DIR"
fi

T0=$(nuxr_now)
if [[ "$FLUSH" == 1 ]]; then
  q "FLUSH BINARY LOGS" || nuxr_die $NUXR_E_BINLOG "FLUSH BINARY LOGS failed (the archive account needs RELOAD)"
fi
mapfile -t LOGS < <(q "SHOW BINARY LOGS" | cut -f1,2)
(( ${#LOGS[@]} >= 1 )) || nuxr_die $NUXR_E_BINLOG "SHOW BINARY LOGS returned nothing"
ACTIVE=${LOGS[-1]%%$'\t'*}
unset 'LOGS[-1]'

# chain gap check: the oldest binlog still on the server must directly follow the newest archived one
newest_archived=$(find "$DIR" -maxdepth 1 -name '*.info' -printf '%f\n' 2>/dev/null | sed 's/\.info$//' | sort -t. -k2,2n | tail -1)
if [[ -n "$newest_archived" && ${#LOGS[@]} -gt 0 ]]; then
  first_on_server=${LOGS[0]%%$'\t'*}
  if [[ "$(nuxr_binlog_base "$first_on_server")" == "$(nuxr_binlog_base "$newest_archived")" ]] \
     && (( $(nuxr_binlog_seq "$first_on_server") > $(nuxr_binlog_seq "$newest_archived") + 1 )); then
    nuxr_err "CHAIN GAP: newest archived $newest_archived, oldest on the server $first_on_server; binlogs in between were purged before archiving"
    nuxr_err "point-in-time recovery across the gap is impossible: take a new full backup now (nuxr-backup.sh)"
    GAP=1
  fi
fi

decode_times() { # raw file -> "first last" event times (UTC, YYYY-MM-DDTHH:MM:SSZ)
  TZ=UTC "$BINLOG_BIN" --no-defaults --base64-output=DECODE-ROWS "$1" 2>/dev/null \
    | sed -nE 's/^#([0-9]{2})([0-9]{2})([0-9]{2}) +([0-9]{1,2}):([0-9]{2}:[0-9]{2}) server id.*/20\1-\2-\3T0\4:\5Z/p' \
    | sed -E 's/T0([0-9]{2}):/T\1:/' \
    | sed -n '1p;$p' | tr '\n' ' '
}

archived=(); n=0
for row in "${LOGS[@]}"; do
  name=${row%%$'\t'*}; size=${row#*$'\t'}
  [[ "$name" =~ ^[A-Za-z0-9_.-]+\.[0-9]{6,}$ ]] || nuxr_die $NUXR_E_BINLOG "unexpected binlog name '$name'"
  [[ -f "$DIR/$name.info" ]] && continue
  t1=$(nuxr_now)
  rm -f "$WORK/$name"
  if [[ "$BINLOG_MODE" == remote ]]; then
    "$BINLOG_BIN" --defaults-extra-file="$BINLOG_DEFAULTS_FILE" --read-from-remote-server --raw \
      --result-file="$WORK/" "$name" 2> "$WORK/fetch.err" \
      || { sed 's/^/  fetch: /' "$WORK/fetch.err" >&2; nuxr_die $NUXR_E_BINLOG "$name: remote fetch failed (the account needs REPLICATION SLAVE)"; }
  else
    cp --preserve=timestamps "$BINLOG_DIR/$name" "$WORK/$name" || nuxr_die $NUXR_E_BINLOG "$name: copy from $BINLOG_DIR failed"
  fi
  got=$(nuxr_size "$WORK/$name")
  [[ "$got" == "$size" ]] || nuxr_die $NUXR_E_BINLOG "$name: fetched $got bytes, the server reports $size"
  raw=$(nuxr_sha256 "$WORK/$name")
  read -r first last <<< "$(decode_times "$WORK/$name")"
  out="$name.$CEXT.$EEXT"
  set +e
  nuxr_compress < "$WORK/$name" | nuxr_encrypt > "$DIR/$out.tmp"
  ps=("${PIPESTATUS[@]}")
  set -e
  [[ "${ps[0]}" == 0 && "${ps[1]}" == 0 ]] || { rm -f "$DIR/$out.tmp"; nuxr_die $NUXR_E_CRYPT "$name: compress/encrypt failed (${ps[*]})"; }
  mv "$DIR/$out.tmp" "$DIR/$out"
  ( cd "$DIR" && sha256sum "$out" > "$name.sha256.tmp" && mv "$name.sha256.tmp" "$name.sha256" )
  {
    echo "name=$name"; echo "bytes=$size"; echo "raw_sha256=$raw"; echo "file=$out"
    echo "file_bytes=$(nuxr_size "$DIR/$out")"; echo "first_event_utc=${first:-}"; echo "last_event_utc=${last:-}"
    echo "archived_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"; echo "server_id=$NUXR_SERVER_ID"; echo "engine=$ENGINE"
    echo "encryption=$ENCRYPTION"; echo "openssl_iter=$OPENSSL_ITER"
  } > "$DIR/$name.info.tmp"
  mv "$DIR/$name.info.tmp" "$DIR/$name.info"     # the .info file marks the binlog as archived: written last
  chmod 0600 "$DIR/$out" "$DIR/$name.sha256" "$DIR/$name.info"
  rm -f "$WORK/$name"
  archived+=("binlog/$ARCHIVE_TAG/$out" "binlog/$ARCHIVE_TAG/$name.sha256" "binlog/$ARCHIVE_TAG/$name.info")
  n=$((n + 1))
  nuxr_info "archived $name ($size bytes, events ${first:-?} .. ${last:-?}) in $(nuxr_elapsed "$t1")s"
done

nuxr_result binlog.archived "$n"
nuxr_result binlog.active "$ACTIVE"
newest=$(find "$DIR" -maxdepth 1 -name '*.info' -printf '%f\n' | sed 's/\.info$//' | sort -t. -k2,2n | tail -1)
nuxr_result binlog.newest_archived "${newest:-none}"
[[ -n "$newest" ]] && nuxr_result binlog.newest_event_utc "$(sed -n 's/^last_event_utc=//p' "$DIR/$newest.info")"
nuxr_result binlog.seconds "$(nuxr_elapsed "$T0")"

RC=0
if [[ $OFFHOST == 1 && -n "$OFFHOST_BINLOG_CMD" && $n -gt 0 ]]; then
  if ( cd "$BACKUP_DIR" && bash -c "$OFFHOST_BINLOG_CMD" nuxr-offhost "${archived[@]}" ); then nuxr_result offhost OK
  else nuxr_err "off-host copy of the new binlogs failed (kept locally)"; nuxr_result offhost FAILED; RC=$NUXR_E_OFFHOST; fi
fi
[[ "${GAP:-0}" == 1 ]] && RC=$NUXR_E_BINLOG
exit "$RC"
