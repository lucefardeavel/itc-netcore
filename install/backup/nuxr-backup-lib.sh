# shellcheck shell=bash
# ITC NetCore V1.0 - backup / point-in-time-recovery tooling: shared functions.
# Sourced by nuxr-backup.sh, nuxr-binlog-archive.sh, nuxr-restore.sh, nuxr-backup-verify.sh and
# nuxr-db-fingerprint.sh. Never executed on its own. See README.md in this directory.
#
# Rules this file enforces for every tool:
#   - no credential ever appears on a command line or in a log: the database clients read them from 0600 option
#     files (--defaults-extra-file), the encryption key is read by openssl / gpg from a 0600 key file;
#   - every database name the tools put into SQL is checked against ^[A-Za-z0-9_]{1,64}$;
#   - the configuration file is shell code: it must be owned by root (or the invoking user) and not writable by
#     group or other.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "nuxr-backup-lib.sh is sourced by the nuxr-* tools; it is not a command" >&2
  exit 64
fi

# ---------------------------------------------------------------- exit codes (README.md "Exit codes")
readonly NUXR_E_OK=0
readonly NUXR_E_FAIL=1        # unexpected failure
readonly NUXR_E_DUMP=3        # logical dump failed
readonly NUXR_E_CRYPT=4       # compression / encryption / decryption failed
readonly NUXR_E_OFFHOST=5     # the set is complete locally but the off-host copy failed
readonly NUXR_E_REFUSED=6     # a safety rule refused the operation (non-empty target, unsafe binlog statement, ...)
readonly NUXR_E_CHECKSUM=7    # a checksum or manifest does not match
readonly NUXR_E_RESTORE=8     # loading the dump failed
readonly NUXR_E_REPLAY=9      # binlog chain incomplete or replay failed
readonly NUXR_E_VERIFY=10     # the restored copy failed a verification (fingerprint, verify, health)
readonly NUXR_E_BINLOG=11     # binlog archiving failed
readonly NUXR_E_USAGE=64      # bad command line
readonly NUXR_E_LOCKED=75     # another run holds the lock (temporary; retry later)
readonly NUXR_E_CONFIG=78     # configuration / prerequisite error

NUXR_TOOL="${NUXR_TOOL:-nuxr}"
NUXR_CLEANUP=()

# ---------------------------------------------------------------- logging
nuxr_log() {
  local lvl=$1; shift
  local line
  line="$(date -u +%Y-%m-%dT%H:%M:%SZ) ${NUXR_TOOL}[$$] ${lvl} $*"
  printf '%s\n' "$line" >&2
  if [[ -n "${LOG_FILE:-}" ]]; then printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null || true; fi
}
nuxr_info() { nuxr_log INFO "$@"; }
nuxr_warn() { nuxr_log WARN "$@"; }
nuxr_err()  { nuxr_log ERROR "$@"; }
nuxr_die()  { local code=$1; shift; nuxr_log ERROR "$*"; exit "$code"; }
# key=value result lines on stdout (machine-readable summary; never a secret)
nuxr_result() { printf '%s=%s\n' "$1" "$2"; nuxr_log RESULT "$1=$2"; }

# ---------------------------------------------------------------- cleanup on exit
nuxr_on_exit() { NUXR_CLEANUP+=("$1"); }
nuxr__run_cleanup() {
  local rc=$? i
  for (( i=${#NUXR_CLEANUP[@]}-1; i>=0; i-- )); do eval "${NUXR_CLEANUP[$i]}" || true; done
  return $rc
}
trap nuxr__run_cleanup EXIT

nuxr_now() { date +%s.%N; }
nuxr_elapsed() { awk -v a="$1" -v b="$(date +%s.%N)" 'BEGIN { printf "%.2f", b - a }'; }

# ---------------------------------------------------------------- validation helpers
nuxr_valid_dbname() { [[ "$1" =~ ^[A-Za-z0-9_]{1,64}$ ]]; }
nuxr_require_dbname() { nuxr_valid_dbname "$1" || nuxr_die $NUXR_E_USAGE "invalid database name '$1' (allowed: A-Z a-z 0-9 _ , at most 64)"; }

# file must exist, be a regular file owned by the invoking user (or root) and have no group/other permission bits
nuxr_check_secret_file() {
  local f=$1 label=$2 perm owner
  [[ -f "$f" ]] || nuxr_die $NUXR_E_CONFIG "$label not found: $f"
  perm=$(stat -c %a "$f"); owner=$(stat -c %u "$f")
  (( (8#$perm & 8#077) == 0 )) || nuxr_die $NUXR_E_CONFIG "$label must be mode 0600 or stricter (is $perm): $f"
  [[ "$owner" == 0 || "$owner" == "$(id -u)" ]] || nuxr_die $NUXR_E_CONFIG "$label must be owned by root or the invoking user: $f"
}

nuxr_require_cmd() {
  local c
  for c in "$@"; do command -v "$c" >/dev/null 2>&1 || nuxr_die $NUXR_E_CONFIG "required command not found: $c"; done
}

# ---------------------------------------------------------------- configuration
nuxr_load_config() {
  local f=$1 perm owner
  [[ -f "$f" ]] || nuxr_die $NUXR_E_CONFIG "configuration file not found: $f (copy backup.conf.example)"
  perm=$(stat -c %a "$f"); owner=$(stat -c %u "$f")
  (( (8#$perm & 8#022) == 0 )) || nuxr_die $NUXR_E_CONFIG "configuration $f is group/other writable (mode $perm); it is sourced as shell code"
  [[ "$owner" == 0 || "$owner" == "$(id -u)" ]] || nuxr_die $NUXR_E_CONFIG "configuration $f must be owned by root or the invoking user"
  # shellcheck disable=SC1090
  . "$f"
  NUXR_CONFIG_FILE=$f
  : "${ENGINE:=auto}"
  : "${APP_DB:?APP_DB must be set in $f}"
  : "${RADIUS_DB:=}"
  [[ "$RADIUS_DB" == "$APP_DB" ]] && RADIUS_DB=""
  : "${BACKUP_DIR:?BACKUP_DIR must be set in $f}"
  : "${DB_DEFAULTS_FILE:?DB_DEFAULTS_FILE must be set in $f}"
  : "${BINLOG_DEFAULTS_FILE:=$DB_DEFAULTS_FILE}"
  : "${RESTORE_DEFAULTS_FILE:=}"
  : "${CLIENT_BIN_DIR:=}"
  : "${ENCRYPTION:=openssl}"
  : "${ENC_KEY_FILE:=}"
  : "${OPENSSL_ITER:=200000}"
  : "${AGE_RECIPIENTS_FILE:=}"
  : "${AGE_IDENTITY_FILE:=}"
  : "${COMPRESS:=gzip}"
  : "${RETENTION_DAYS:=14}"
  : "${RETENTION_MIN_KEEP:=7}"
  : "${OFFHOST_CMD:=}"
  : "${SECRET_FILES:=}"
  : "${SECRET_FILES_REQUIRED:=1}"
  : "${EXPECT_SERVER_ID:=}"
  : "${RESTORE_EXPECT_SERVER_ID:=}"
  : "${BINLOG_MODE:=remote}"
  : "${BINLOG_DIR:=}"
  : "${BINLOG_FLUSH:=1}"
  : "${ARCHIVE_TAG:=}"
  : "${RESTORE_SKIP_BINLOG:=1}"
  : "${RESTORE_COLLATION:=utf8mb4_general_ci}"
  : "${APP_DIR:=}"
  : "${APP_CONFIG:=${APP_DIR:+$APP_DIR/config.php}}"
  : "${APP_RUN_AS:=}"
  : "${PHP_BIN:=php}"
  : "${VERIFY_DB_HOST:=}"
  : "${VERIFY_DB_PORT:=}"
  : "${VERIFY_HEALTH_REQUIRED:=db schema finance}"
  : "${VERIFY_TMPDIR:=/var/tmp}"
  : "${VERIFY_DB_PREFIX:=nuxr_verify}"
  : "${FINANCE_TABLES:=tbl_manager_ledger tbl_manager_wallets tbl_manager_transfers tbl_manager_wallet_reservations tbl_reseller_activations}"
  : "${LOCK_DIR:=/run/lock}"
  : "${LOG_FILE:=}"
  : "${DUMP_EXTRA_OPTS:=}"
  nuxr_require_dbname "$APP_DB"
  [[ -z "$RADIUS_DB" ]] || nuxr_require_dbname "$RADIUS_DB"
  case "$ENCRYPTION" in openssl|age|gpg) ;; *) nuxr_die $NUXR_E_CONFIG "ENCRYPTION must be openssl, age or gpg";; esac
  case "$COMPRESS" in gzip|zstd) ;; *) nuxr_die $NUXR_E_CONFIG "COMPRESS must be gzip or zstd";; esac
  case "$BINLOG_MODE" in remote|local) ;; *) nuxr_die $NUXR_E_CONFIG "BINLOG_MODE must be remote or local";; esac
  if [[ -n "$LOG_FILE" ]]; then mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true; fi
  umask 077
}

# the databases a backup set holds: the application DB, plus the RADIUS DB when it is separate
nuxr_dbs() { if [[ -n "$RADIUS_DB" ]]; then echo "$APP_DB $RADIUS_DB"; else echo "$APP_DB"; fi; }

# ---------------------------------------------------------------- client binaries
# nuxr_bin <client|dump|binlog>: the engine's own tool (CLIENT_BIN_DIR first, then PATH)
nuxr_bin() {
  local kind=$1 c cands
  case "$ENGINE:$kind" in
    mariadb:client) cands="mariadb mysql" ;;
    mariadb:dump)   cands="mariadb-dump mysqldump" ;;
    mariadb:binlog) cands="mariadb-binlog mysqlbinlog" ;;
    mysql:client)   cands="mysql" ;;
    mysql:dump)     cands="mysqldump" ;;
    mysql:binlog)   cands="mysqlbinlog" ;;
    auto:client)    cands="mariadb mysql" ;;
    *) return 1 ;;
  esac
  for c in $cands; do
    if [[ -n "$CLIENT_BIN_DIR" && -x "$CLIENT_BIN_DIR/$c" ]]; then echo "$CLIENT_BIN_DIR/$c"; return 0; fi
  done
  for c in $cands; do
    if command -v "$c" >/dev/null 2>&1; then command -v "$c"; return 0; fi
  done
  return 1
}

# the tool must belong to the engine: a MariaDB mysqldump / mysqlbinlog against MySQL 8.0 (or the reverse) is refused
nuxr_check_tool_flavor() {
  local bin=$1 v real flavor
  v=$("$bin" --version 2>&1 | head -1)
  real=$(basename "$(readlink -f "$bin")")
  # mariadb-binlog prints only "Ver 3.5 ...": the resolved binary name identifies it; MySQL tools print "Ver 8.x / 9.x"
  if [[ "$v" == *MariaDB* || "$real" == mariadb* ]]; then flavor=mariadb
  elif [[ "$v" =~ Ver\ (8|9)\.[0-9]+ ]]; then flavor=mysql
  else flavor=unknown; fi
  if [[ "$ENGINE" == mariadb && "$flavor" != mariadb ]]; then nuxr_die $NUXR_E_CONFIG "$bin is not the MariaDB tool ($v); set CLIENT_BIN_DIR"; fi
  if [[ "$ENGINE" == mysql && "$flavor" != mysql ]]; then nuxr_die $NUXR_E_CONFIG "$bin is not the MySQL 8.0 tool ($v); install the MySQL client tools or set CLIENT_BIN_DIR"; fi
  echo "$v"
}

# nuxr_sql <defaults-file> <sql>: tab-separated rows, no header. The option file is the first argument (client rule).
nuxr_sql() {
  local df=$1; shift
  "$NUXR_CLIENT" --defaults-extra-file="$df" --batch --skip-column-names -e "$*"
}

# connect once, detect the engine (ENGINE=auto) and resolve the tools. Sets NUXR_CLIENT NUXR_SERVER_VERSION.
nuxr_connect() {
  local df=$1 label=$2 expect=${3:-} v sid
  nuxr_check_secret_file "$df" "option file ($label)"
  NUXR_CLIENT=$(nuxr_bin client) || nuxr_die $NUXR_E_CONFIG "no mariadb / mysql client found"
  v=$(nuxr_sql "$df" "SELECT VERSION()") || nuxr_die $NUXR_E_CONFIG "cannot connect with $df ($label)"
  NUXR_SERVER_VERSION=$v
  if [[ "$ENGINE" == auto ]]; then
    if [[ "$v" == *MariaDB* ]]; then ENGINE=mariadb; else ENGINE=mysql; fi
  fi
  if [[ "$ENGINE" == mariadb && "$v" != *MariaDB* ]] || [[ "$ENGINE" == mysql && "$v" == *MariaDB* ]]; then
    nuxr_die $NUXR_E_CONFIG "ENGINE=$ENGINE but the server reports '$v'"
  fi
  NUXR_CLIENT=$(nuxr_bin client) || nuxr_die $NUXR_E_CONFIG "no $ENGINE client found"
  sid=$(nuxr_sql "$df" "SELECT @@server_id")
  if [[ -n "$expect" && "$sid" != "$expect" ]]; then
    nuxr_die $NUXR_E_CONFIG "connected ($label) to server_id=$sid, but the configuration expects $expect: wrong server, refused"
  fi
  NUXR_SERVER_ID=$sid
  nuxr_info "connected ($label): $ENGINE $v server_id=$sid"
}

# read one value of the [client] group of an option file (used for the PHP verification connection only)
nuxr_cnf_get() {
  local f=$1 key=$2
  awk -v k="$key" '
    /^[[:space:]]*\[/ { g = $0; gsub(/[[:space:]\[\]]/, "", g); next }
    g == "client" || g == "mysql" || g == "mariadb" || g == "client-server" {
      line = $0; sub(/^[[:space:]]+/, "", line)
      if (line ~ "^" k "[[:space:]]*=") {
        sub("^" k "[[:space:]]*=[[:space:]]*", "", line); sub(/[[:space:]]+$/, "", line)
        if (line ~ /^".*"$/ || line ~ /^\047.*\047$/) line = substr(line, 2, length(line) - 2)
        v = line
      }
    }
    END { if (v != "") print v }' "$f"
}

# ---------------------------------------------------------------- compression / encryption
nuxr_cext() { [[ "$COMPRESS" == zstd ]] && echo zst || echo gz; }
nuxr_compress() { if [[ "$COMPRESS" == zstd ]]; then zstd -q -T0 -6 -c; else gzip -6 -c; fi; }
nuxr_decompress_ext() { # by file extension, so a set made with gzip restores whatever COMPRESS says now
  case "$1" in *.zst.*|*.zst) zstd -dcq ;; *) gzip -dc ;; esac
}
nuxr_eext() { case "$ENCRYPTION" in openssl) echo enc ;; age) echo age ;; gpg) echo gpg ;; esac; }

nuxr_check_crypto_prereqs() { # $1 = encrypt|decrypt
  case "$ENCRYPTION" in
    openssl) nuxr_require_cmd openssl; nuxr_check_secret_file "$ENC_KEY_FILE" "ENC_KEY_FILE" ;;
    gpg)     nuxr_require_cmd gpg; nuxr_check_secret_file "$ENC_KEY_FILE" "ENC_KEY_FILE" ;;
    age)     nuxr_require_cmd age
             if [[ "$1" == encrypt ]]; then [[ -f "$AGE_RECIPIENTS_FILE" ]] || nuxr_die $NUXR_E_CONFIG "AGE_RECIPIENTS_FILE not found"
             else nuxr_check_secret_file "$AGE_IDENTITY_FILE" "AGE_IDENTITY_FILE"; fi ;;
  esac
  [[ "$COMPRESS" == zstd ]] && nuxr_require_cmd zstd || nuxr_require_cmd gzip
}

# stdin -> stdout. The key is passed as a FILE reference (openssl -pass file:, gpg --passphrase-file), never as a value.
nuxr_encrypt() {
  case "$ENCRYPTION" in
    openssl) openssl enc -aes-256-cbc -pbkdf2 -iter "$OPENSSL_ITER" -salt -pass "file:$ENC_KEY_FILE" ;;
    age)     age -e -R "$AGE_RECIPIENTS_FILE" ;;
    gpg)     gpg --batch --quiet --no-tty --pinentry-mode loopback --passphrase-file "$ENC_KEY_FILE" \
               --symmetric --cipher-algo AES256 --s2k-digest-algo SHA512 -o - ;;
  esac
}
nuxr_decrypt() { # $1 = encrypted file (method from its extension); output on stdout
  local f=$1 iter=${2:-$OPENSSL_ITER}
  case "$f" in
    *.enc) openssl enc -d -aes-256-cbc -pbkdf2 -iter "$iter" -pass "file:$ENC_KEY_FILE" -in "$f" ;;
    *.age) age -d -i "$AGE_IDENTITY_FILE" "$f" ;;
    *.gpg) gpg --batch --quiet --no-tty --pinentry-mode loopback --passphrase-file "$ENC_KEY_FILE" -d "$f" ;;
    *) return 1 ;;
  esac
}

nuxr_sha256() { sha256sum "$1" | cut -c1-64; }
nuxr_size() { stat -c %s "$1"; }

# ---------------------------------------------------------------- off-host copy with a backlog
# Every item (a path relative to $BACKUP_DIR) gets a marker in $BACKUP_DIR/.offhost/ once a copy that included it
# succeeded. Each run ships its new items plus every earlier item still unmarked, so one failed copy is retried by the
# next run instead of being forgotten, and the run keeps exiting 5 while a backlog remains.
nuxr_offhost_marker() { local p=${1//\//__}; printf '%s/.offhost/%s' "$BACKUP_DIR" "$p"; }
nuxr_offhost_unmarked() { # item... -> the items without a marker, one per line
  local i; for i in "$@"; do [[ -e "$(nuxr_offhost_marker "$i")" ]] || printf '%s\n' "$i"; done
}
nuxr_offhost_pending_sets() { # complete sets not yet copied off-host
  local s items=()
  while IFS= read -r s; do items+=("full/$(basename "$s")"); done < <(find "$BACKUP_DIR/full" -mindepth 1 -maxdepth 1 -type d \
    -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' 2>/dev/null | sort)
  [[ ${#items[@]} -eq 0 ]] || nuxr_offhost_unmarked "${items[@]}"
}
nuxr_offhost_pending_binlogs() { # $1 = archive tag -> data, .sha256 and .info of every archived binlog not yet copied
  local d="$BACKUP_DIR/binlog/$1" f name rel out
  [[ -d "$d" ]] || return 0
  for f in "$d"/*.info; do
    [[ -e "$f" ]] || continue
    name=$(basename "$f" .info); rel="binlog/$1/$name.info"
    [[ -e "$(nuxr_offhost_marker "$rel")" ]] && continue
    out=$(sed -n 's/^file=//p' "$f")
    printf '%s\n' "binlog/$1/$out" "binlog/$1/$name.sha256" "$rel"
  done
}
nuxr_offhost_ship() { # cmd item... -> 0 copied (markers written), 1 failed (no marker written)
  local cmd=$1 i; shift
  [[ $# -gt 0 ]] || return 0
  mkdir -p "$BACKUP_DIR/.offhost"; chmod 0700 "$BACKUP_DIR/.offhost"
  ( cd "$BACKUP_DIR" && bash -c "$cmd" nuxr-offhost "$@" ) || return 1
  for i in "$@"; do : > "$(nuxr_offhost_marker "$i")"; done
}

# ---------------------------------------------------------------- locking
nuxr_lock() {
  local name=$1 f
  mkdir -p "$LOCK_DIR" 2>/dev/null || true
  f="$LOCK_DIR/$name.lock"
  if command -v flock >/dev/null 2>&1; then
    exec {NUXR_LOCK_FD}>>"$f" || nuxr_die $NUXR_E_CONFIG "cannot open lock file $f"
    flock -n "$NUXR_LOCK_FD" || nuxr_die $NUXR_E_LOCKED "another $name run holds $f; not started"
  else
    if ! mkdir "$f.d" 2>/dev/null; then
      local pid; pid=$(cat "$f.d/pid" 2>/dev/null || true)
      if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then nuxr_die $NUXR_E_LOCKED "another $name run (pid $pid) holds $f.d; not started"; fi
      nuxr_warn "removing stale lock $f.d (pid ${pid:-?} is gone)"
      rm -rf "$f.d"; mkdir "$f.d" || nuxr_die $NUXR_E_LOCKED "cannot take lock $f.d"
    fi
    echo $$ > "$f.d/pid"
    nuxr_on_exit "rm -rf '$f.d'"
  fi
}

# ---------------------------------------------------------------- binlog names
nuxr_binlog_base() { echo "${1%.*}"; }
nuxr_binlog_seq()  { local s=${1##*.}; echo $((10#$s)); }

# ---------------------------------------------------------------- backup sets
nuxr_set_dir() { # latest | <timestamp> | <path>  -> absolute set directory
  local s=$1
  if [[ "$s" == latest ]]; then
    s=$(find "$BACKUP_DIR/full" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' 2>/dev/null | sort | tail -1)
    [[ -n "$s" ]] || return 1
    echo "$s"; return 0
  fi
  if [[ -d "$s" ]]; then (cd "$s" && pwd); return 0; fi
  [[ -d "$BACKUP_DIR/full/$s" ]] && { echo "$BACKUP_DIR/full/$s"; return 0; }
  return 1
}
nuxr_info_get() { # <set dir> <key>
  sed -n "s/^$2=//p" "$1/backup.info" | head -1
}

# ---------------------------------------------------------------- database inspection
nuxr_db_exists() { [[ "$(nuxr_sql "$1" "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$2'")" == 1 ]]; }
nuxr_db_object_count() { # tables + views + routines + events + triggers
  nuxr_sql "$1" "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$2')
    + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$2')
    + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$2')
    + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$2')"
}

# canonical fingerprint: one line per base table "<table> <rows> <sha256 of all rows, PK order> <sha256 of definition>",
# then object counts. Independent of the database NAME, so a restored copy compares equal to its source.
nuxr_fingerprint() {
  local df=$1 db=$2 t pk rows data ddl
  nuxr_require_dbname "$db"
  while IFS= read -r t; do
    [[ "$t" =~ ^[A-Za-z0-9_$]{1,64}$ ]] || { echo "$t UNSUPPORTED-NAME"; continue; }
    pk=$(nuxr_sql "$df" "SELECT GROUP_CONCAT(CONCAT('\`', COLUMN_NAME, '\`') ORDER BY ORDINAL_POSITION SEPARATOR ',') FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$t' AND CONSTRAINT_NAME='PRIMARY'")
    rows=$(nuxr_sql "$df" "SELECT COUNT(*) FROM \`$db\`.\`$t\`")
    if [[ -n "$pk" && "$pk" != NULL ]]; then
      data=$(nuxr_sql "$df" "SET time_zone='+00:00'; SELECT * FROM \`$db\`.\`$t\` ORDER BY $pk" | sha256sum | cut -c1-64)
    else
      data=$(nuxr_sql "$df" "SET time_zone='+00:00'; SELECT * FROM \`$db\`.\`$t\`" | LC_ALL=C sort | sha256sum | cut -c1-64)
    fi
    ddl=$(nuxr_sql "$df" "SHOW CREATE TABLE \`$db\`.\`$t\`" | cut -f2- \
      | sed -E 's/ AUTO_INCREMENT=[0-9]+//; s/ CHARACTER SET [A-Za-z0-9_]+ COLLATE ([A-Za-z0-9_]+)/ COLLATE \1/g' | sha256sum | cut -c1-64)
    echo "$t $rows $data $ddl"
  done < <(nuxr_sql "$df" "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
  echo "#VIEWS $(nuxr_sql "$df" "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$db'")"
  echo "#ROUTINES $(nuxr_sql "$df" "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$db'")"
  echo "#TRIGGERS $(nuxr_sql "$df" "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$db'")"
  echo "#EVENTS $(nuxr_sql "$df" "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$db'")"
}
nuxr_fingerprint_summary() { # <fingerprint file>
  awk '!/^#/ { t++; r += $2 } END { printf "%d tables, %d rows", t, r }' "$1"
  printf ', digest %s' "$(sha256sum "$1" | cut -c1-16)"
}

# ---------------------------------------------------------------- application verification
# nuxr_app_verify <defaults-file> <app db> [<radius db>]
# Runs, against the given (restored) databases, the checks of docs/BACKUP_RESTORE_RUNBOOK.md §3 step 2:
#   php system/reseller/migrate.php verify  (exit 0 required)
#   php system/reseller/health.php --json --finance from a throwaway copy of APP_DIR whose config.php includes the real
#   one and then re-points $db_* / $radius_* at the restored copy (VERIFY_HEALTH_REQUIRED checks must be OK).
# The password is read from the option file and given to the PHP processes in their environment only.
nuxr_app_verify() {
  local df=$1 db=$2 rdb=${3:-$2} host port sock user phphost rc=0 out tmp run=() st
  if [[ -z "$APP_DIR" ]]; then nuxr_warn "APP_DIR not set: application verification NOT_RUN"; nuxr_result verify.app NOT_RUN; return 0; fi
  [[ -f "$APP_DIR/system/reseller/migrate.php" && -f "$APP_DIR/system/reseller/health.php" ]] || nuxr_die $NUXR_E_CONFIG "APP_DIR=$APP_DIR is not an ITC NetCore tree"
  [[ -r "$APP_CONFIG" ]] || nuxr_die $NUXR_E_CONFIG "APP_CONFIG not readable: $APP_CONFIG"
  nuxr_require_cmd "$PHP_BIN"
  host=${VERIFY_DB_HOST:-$(nuxr_cnf_get "$df" host)}; host=${host:-localhost}
  port=${VERIFY_DB_PORT:-$(nuxr_cnf_get "$df" port)}
  sock=$(nuxr_cnf_get "$df" socket)
  user=$(nuxr_cnf_get "$df" user)
  [[ -n "$user" ]] || nuxr_die $NUXR_E_CONFIG "no user= in $df"
  # the application builds its DSN as "mysql:host=$db_host;dbname=..."; a port or socket is appended to the host part
  phphost=$host
  if [[ -n "$port" && "$host" != localhost ]]; then phphost="$host;port=$port"; fi
  if [[ -n "$sock" && "$host" == localhost ]]; then phphost="localhost;unix_socket=$sock"; fi
  if [[ -n "$APP_RUN_AS" && "$(id -u)" == 0 ]]; then run=(runuser -u "$APP_RUN_AS" --); fi

  export NUXR_VERIFY_DB_HOST="$phphost" NUXR_VERIFY_DB_NAME="$db" NUXR_VERIFY_RADIUS_NAME="$rdb" NUXR_VERIFY_DB_USER="$user"
  NUXR_VERIFY_DB_PASS=$(nuxr_cnf_get "$df" password); export NUXR_VERIFY_DB_PASS
  export NUXR_MIG_PW="$NUXR_VERIFY_DB_PASS"
  nuxr_on_exit "unset NUXR_VERIFY_DB_PASS NUXR_MIG_PW"

  out=$( "${run[@]}" "$PHP_BIN" -d error_log=/dev/null "$APP_DIR/system/reseller/migrate.php" verify --config="$APP_CONFIG" \
          --db-host="$phphost" --db-name="$db" --db-user="$user" --db-password-env=NUXR_MIG_PW 2>&1 ) || rc=$?
  printf '%s\n' "$out" | grep -E '^(result:|info:|  R[0-9]{4}|problem|  -)' | sed 's/^/  migrate-verify: /' >&2 || true
  nuxr_result verify.migrate_exit "$rc"
  [[ $rc == 0 ]] || { unset NUXR_VERIFY_DB_PASS NUXR_MIG_PW; return $NUXR_E_VERIFY; }

  tmp=$(mktemp -d "$VERIFY_TMPDIR/nuxr-verify.XXXXXX")
  nuxr_on_exit "rm -rf '$tmp'"
  mkdir -p "$tmp/root" && cp -a "$APP_DIR/." "$tmp/root/" || nuxr_die $NUXR_E_FAIL "cannot copy APP_DIR for the verification run"
  {
    echo "<?php"
    echo "// generated by nuxr-restore.sh for one verification run, removed afterwards; holds no secret"
    echo "require $("$PHP_BIN" -r 'echo var_export($argv[1], true);' "$(readlink -f "$APP_CONFIG")");"
    echo "\$db_host = getenv('NUXR_VERIFY_DB_HOST'); \$db_name = getenv('NUXR_VERIFY_DB_NAME');"
    echo "\$db_user = getenv('NUXR_VERIFY_DB_USER'); \$db_pass = getenv('NUXR_VERIFY_DB_PASS');"
    echo "\$radius_host = \$db_host; \$radius_name = getenv('NUXR_VERIFY_RADIUS_NAME');"
    echo "\$radius_user = \$db_user; \$radius_pass = \$db_pass; \$radius_password = \$db_pass;"
  } > "$tmp/root/config.php"
  if [[ -n "$APP_RUN_AS" && "$(id -u)" == 0 ]]; then chown -R "$APP_RUN_AS" "$tmp"; fi
  rc=0
  out=$( "${run[@]}" "$PHP_BIN" -d error_log=/dev/null "$tmp/root/system/reseller/health.php" --json --finance 2>/dev/null ) || rc=$?
  unset NUXR_VERIFY_DB_PASS NUXR_MIG_PW
  st=$(printf '%s' "$out" | "$PHP_BIN" -r '$j = json_decode(stream_get_contents(STDIN), true); if (!is_array($j)) { exit(3); } foreach ($j["checks"] as $c) { echo $c["check"], "=", $c["status"], "\n"; }') \
    || { nuxr_err "health.php produced no JSON (exit $rc)"; nuxr_result verify.health_exit "$rc"; return $NUXR_E_VERIFY; }
  nuxr_result verify.health_exit "$rc"
  nuxr_result verify.health "$(echo "$st" | tr '\n' ' ' | sed 's/ $//')"
  local c bad=0
  for c in $VERIFY_HEALTH_REQUIRED; do
    if ! grep -qx "$c=OK" <<< "$st"; then nuxr_err "health check $c is not OK on the restored copy"; bad=1; fi
  done
  NUXR_LAST_HEALTH=$st
  [[ $bad == 0 ]] || return $NUXR_E_VERIFY
  nuxr_info "application verification OK (migrate verify exit 0; health $VERIFY_HEALTH_REQUIRED OK; health exit $rc)"
  return 0
}

nuxr_finance_counts() { # <defaults-file> <db>
  local df=$1 db=$2 t n out=""
  for t in $FINANCE_TABLES; do
    nuxr_valid_dbname "$t" || continue
    n=$(nuxr_sql "$df" "SELECT COUNT(*) FROM \`$db\`.\`$t\`" 2>/dev/null || echo "-")
    out+="$t:$n "
  done
  echo "${out% }"
}
