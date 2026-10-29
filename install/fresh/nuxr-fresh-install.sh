#!/usr/bin/env bash
# ITC NetCore V1.0 - FRESH INSTALL on a NEW, EMPTY database (owner decision 2026-10-29). Command-line replacement for
# the web installer (install/index.php .. step5.php) plus the post-installer steps of
# docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md section 2 (steps 2-8 and 12) and docs/PRODUCTION_CONFIGURATION_CHECKLIST.md.
#
# Usage (as root, from the VERIFIED ARTIFACT COPY outside the document root, e.g. <artifact-dir>/install/fresh/):
#   nuxr-fresh-install.sh --app-root=<document root> --env-file=<0600 file> [options]
#   nuxr-fresh-install.sh --generate-secrets=<new file>
#
# Options:
#   --app-root=DIR        the unpacked, verified release (nuxr-artifact-verify.sh --extract-to) = web document root
#   --env-file=FILE       operator parameters and secrets (templates/install.env.example); mode 0600/0400, owned by you
#   --create-db           create the database when it does not exist (utf8mb4 / utf8mb4_general_ci). Without it a
#                         missing database is refused. An EXISTING database is never created, altered or emptied.
#   --web-user=USER       the PHP-FPM user (default www-data); its primary group owns config.php
#   --php=BIN             PHP 8.2 CLI binary (default: php8.2, else php)
#   --state-dir=DIR       installer state, lock and logs (default /var/lib/itc-netcore/fresh-install), mode 0700
#   --log-dir=DIR         application log directory for the scheduled jobs (default /var/log/itcnc)
#   --installer=strip     (default) delete the web installer (install/*.php, install/*.sql, css, img, fresh) and keep
#                         install/freeradius/ read-only: preflight radius_checks reads the expected CHECK expressions
#                         from it. The web server must deny /install/ (nginx/itc-netcore.conf.example).
#   --installer=remove    delete install/ completely (runbook section 2 step 4 literally). Preflight radius_checks
#                         then can no longer compare the CHECK expressions (it only sees that the tables exist).
#   --no-enable-radius    do not store the setting radius_enable=1 (by default it is stored: without it the preflight
#                         RADIUS checks cannot run, defect ACC-1; it is the SuperAdmin "Enable Radius = Yes" setting)
#   --install-cron        install cron/itc-netcore.cron.example as /etc/cron.d/itc-netcore (paths and user substituted)
#   --dry-run             read-only: prerequisites, database gate, account check and the plan; writes nothing
#   --generate-secrets=F  write a NEW env file F (mode 0600) with generated passwords for the migration, application
#                         and FreeRADIUS accounts; the operator then fills in the remaining lines. Prints no secret.
#
# Safety contract:
#   * the target database must be proven EMPTY (0 tables / views / routines / events / triggers) by an administrator
#     that can see every schema object, or not exist (and --create-db given); otherwise the run is REFUSED before any
#     change. The shipped schema files begin with DROP TABLE IF EXISTS: they are only ever loaded into that empty
#     database, and the emptiness is re-checked immediately before loading;
#   * accounts that already exist are refused (they could carry privileges on other databases, ISO-01);
#   * no secret is printed, put on a command line or written to a log; database clients read credentials from 0600
#     option files in a private temporary directory that is removed on exit;
#   * idempotent: a state file records every completed phase. A re-run after success changes nothing in the database
#     and re-runs the file-permission, installer-removal and verification phases. A re-run after a failure resumes
#     after the base schema only when the state file proves this installer loaded it; a failure DURING the base schema
#     load is never resumed (start again on a new empty database).
#
# Exit codes: 0 installed and verified; 2 prerequisites missing; 3 refused (database not empty / unknown, accounts
# exist, unsafe file, existing installation); 4 a step failed (schema, migration, grants, preflight, health); 5 usage;
# 1 internal error.
set -euo pipefail
umask 077

readonly EXIT_INTERNAL=1 EXIT_PREREQ=2 EXIT_REFUSED=3 EXIT_STEP=4 EXIT_USAGE=5
readonly SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
readonly TEMPLATE_CONFIG=$SELF_DIR/templates/config.php.tmpl
readonly RENDER=$SELF_DIR/lib/render-config.php
readonly CRON_EXAMPLE=$SELF_DIR/cron/itc-netcore.cron.example

APP_ROOT="" ENV_FILE="" CREATE_DB=0 WEB_USER=www-data PHP="" STATE_DIR=/var/lib/itc-netcore/fresh-install
LOG_DIR=/var/log/itcnc INSTALLER_MODE=strip ENABLE_RADIUS=1 INSTALL_CRON=0 DRY_RUN=0 GEN_SECRETS=""

ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
say() { printf '%s %s\n' "$(ts)" "$*"; }
phase() { printf '\n%s == %s\n' "$(ts)" "$*"; }
die() { local c=$1; shift; printf '%s ERROR: %s\n' "$(ts)" "$*" >&2; exit "$c"; }
refuse() { printf '%s REFUSED: %s\n' "$(ts)" "$*" >&2; printf '%s nothing was changed by this run in the database or the application tree\n' "$(ts)" >&2; exit "$EXIT_REFUSED"; }
usage() { sed -n '2,49p' "$0" | sed 's/^# \{0,1\}//'; exit "$EXIT_USAGE"; }

for a in "$@"; do
  case $a in
    --app-root=*) APP_ROOT=${a#*=} ;;
    --env-file=*) ENV_FILE=${a#*=} ;;
    --create-db) CREATE_DB=1 ;;
    --web-user=*) WEB_USER=${a#*=} ;;
    --php=*) PHP=${a#*=} ;;
    --state-dir=*) STATE_DIR=${a#*=} ;;
    --log-dir=*) LOG_DIR=${a#*=} ;;
    --installer=strip) INSTALLER_MODE=strip ;;
    --installer=remove) INSTALLER_MODE=remove ;;
    --no-enable-radius) ENABLE_RADIUS=0 ;;
    --install-cron) INSTALL_CRON=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --generate-secrets=*) GEN_SECRETS=${a#*=} ;;
    -h|--help) usage ;;
    *) printf 'unknown argument: %s\n' "$a" >&2; usage ;;
  esac
done

gen_password() { # 43 URL-safe characters (256 bits) from the kernel CSPRNG
  head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=\n'
}

# ------------------------------------------------------------------------------------------------------------------
# --generate-secrets: a new 0600 env file, passwords generated, nothing printed
# ------------------------------------------------------------------------------------------------------------------
if [[ -n $GEN_SECRETS ]]; then
  [[ -e $GEN_SECRETS || -L $GEN_SECRETS ]] && refuse "$GEN_SECRETS already exists (never overwritten)"
  [[ -d $(dirname "$GEN_SECRETS") ]] || die "$EXIT_USAGE" "directory of $GEN_SECRETS does not exist"
  tmpf=$(mktemp "$(dirname "$GEN_SECRETS")/.nuxr-env.XXXXXX")
  {
    printf '# ITC NetCore V1.0 fresh install parameters - generated %s by nuxr-fresh-install.sh. Mode 0600. NEVER share.\n' "$(ts)"
    printf '# Fill in every empty line; see install/fresh/templates/install.env.example for the meaning of each key.\n'
    printf 'NUXR_DB_HOST=\nNUXR_DB_NAME=\nNUXR_DB_ADMIN_USER=\nNUXR_DB_ADMIN_PASSWORD=\n'
    printf 'NUXR_DB_MIG_USER=itcnc_mig\nNUXR_DB_MIG_PASSWORD=%s\n' "$(gen_password)"
    printf 'NUXR_DB_APP_USER=itcnc_app\nNUXR_DB_APP_PASSWORD=%s\n' "$(gen_password)"
    printf 'NUXR_DB_RADIUS_USER=itcnc_radius\nNUXR_DB_RADIUS_PASSWORD=%s\n' "$(gen_password)"
    printf 'NUXR_DB_CLIENT_HOST=\nNUXR_DB_RADIUS_HOSTS=\nNUXR_APP_URL=\n'
  } > "$tmpf"
  chmod 0600 "$tmpf"
  mv -n "$tmpf" "$GEN_SECRETS"
  [[ -e $tmpf ]] && { rm -f "$tmpf"; refuse "$GEN_SECRETS appeared while generating (not overwritten)"; }
  say "generated $GEN_SECRETS (mode 0600; three passwords generated, not shown). Fill in the empty lines."
  exit 0
fi

[[ -n $APP_ROOT && -n $ENV_FILE ]] || usage
[[ $(id -u) -eq 0 ]] || die "$EXIT_USAGE" "run as root (the installer sets ownership and modes of the application tree)"
[[ -d $APP_ROOT ]] || die "$EXIT_USAGE" "--app-root does not exist: $APP_ROOT"
APP_ROOT=$(cd "$APP_ROOT" && pwd)              # logical path: a "current" symlink stays a symlink in cron / state
APP_ROOT_REAL=$(cd "$APP_ROOT" && pwd -P)
case $SELF_DIR/ in "$APP_ROOT_REAL"/*) die "$EXIT_USAGE" "run the installer from the verified artifact copy OUTSIDE the document root (not from $APP_ROOT)";; esac
for f in "$TEMPLATE_CONFIG" "$RENDER"; do [[ -f $f ]] || die "$EXIT_INTERNAL" "package file missing: $f"; done

# ------------------------------------------------------------------------------------------------------------------
# env file: parsed (never sourced), whitelisted keys, validated
# ------------------------------------------------------------------------------------------------------------------
readonly KEYS="NUXR_DB_HOST NUXR_DB_NAME NUXR_DB_ADMIN_USER NUXR_DB_ADMIN_PASSWORD NUXR_DB_MIG_USER NUXR_DB_MIG_PASSWORD NUXR_DB_APP_USER NUXR_DB_APP_PASSWORD NUXR_DB_RADIUS_USER NUXR_DB_RADIUS_PASSWORD NUXR_DB_CLIENT_HOST NUXR_DB_RADIUS_HOSTS NUXR_APP_URL"
for k in $KEYS; do printf -v "$k" '%s' ""; done
[[ -f $ENV_FILE && ! -L $ENV_FILE ]] || die "$EXIT_USAGE" "env file not found (or a symbolic link): $ENV_FILE"
mode=$(stat -c %a "$ENV_FILE"); owner=$(stat -c %u "$ENV_FILE")
[[ $mode == 600 || $mode == 400 ]] || refuse "env file $ENV_FILE has mode $mode; required 0600 or 0400"
[[ $owner == "$(id -u)" ]] || refuse "env file $ENV_FILE is not owned by the user running the installer"
case $(cd "$(dirname "$ENV_FILE")" && pwd -P)/ in "$APP_ROOT_REAL"/*) refuse "env file is inside the document root";; esac
n=0
while IFS= read -r line || [[ -n $line ]]; do
  n=$((n + 1)); line=${line%$'\r'}
  [[ $line =~ ^[[:space:]]*(#.*)?$ ]] && continue
  [[ $line =~ ^([A-Z0-9_]+)=(.*)$ ]] || die "$EXIT_USAGE" "env file line $n is not KEY=VALUE"
  k=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}
  if [[ $v =~ ^\"(.*)\"$ ]] || [[ $v =~ ^\'(.*)\'$ ]]; then v=${BASH_REMATCH[1]}; fi
  [[ " $KEYS " == *" $k "* ]] || die "$EXIT_USAGE" "env file line $n: unknown key $k"
  printf -v "$k" '%s' "$v"
done < "$ENV_FILE"

for k in $KEYS; do [[ -n ${!k} ]] || die "$EXIT_USAGE" "env file: $k is empty"; done
[[ $NUXR_DB_HOST =~ ^[A-Za-z0-9._:-]{1,255}$ ]] || die "$EXIT_USAGE" "NUXR_DB_HOST: invalid host"
[[ $NUXR_DB_NAME =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "$EXIT_USAGE" "NUXR_DB_NAME: use 1-64 characters A-Z a-z 0-9 _"
for k in NUXR_DB_ADMIN_USER NUXR_DB_MIG_USER NUXR_DB_APP_USER NUXR_DB_RADIUS_USER; do
  [[ ${!k} =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "$EXIT_USAGE" "$k: use 1-32 characters A-Z a-z 0-9 _"
done
[[ $NUXR_DB_MIG_USER != "$NUXR_DB_APP_USER" && $NUXR_DB_MIG_USER != "$NUXR_DB_RADIUS_USER" && $NUXR_DB_APP_USER != "$NUXR_DB_RADIUS_USER" ]] \
  || die "$EXIT_USAGE" "the migration, application and FreeRADIUS accounts must be three different users"
for k in NUXR_DB_MIG_USER NUXR_DB_APP_USER NUXR_DB_RADIUS_USER; do
  [[ ${!k} != "$NUXR_DB_ADMIN_USER" ]] || die "$EXIT_USAGE" "$k must differ from the administrator account"
done
for k in NUXR_DB_MIG_PASSWORD NUXR_DB_APP_PASSWORD NUXR_DB_RADIUS_PASSWORD; do
  [[ ${!k} =~ ^[A-Za-z0-9._~+=:,@%^-]{20,128}$ ]] || die "$EXIT_USAGE" "$k: 20-128 characters from A-Z a-z 0-9 . _ ~ + = : , @ % ^ - (value not shown)"
done
[[ $NUXR_DB_MIG_PASSWORD != "$NUXR_DB_APP_PASSWORD" && $NUXR_DB_MIG_PASSWORD != "$NUXR_DB_RADIUS_PASSWORD" && $NUXR_DB_APP_PASSWORD != "$NUXR_DB_RADIUS_PASSWORD" \
   && $NUXR_DB_ADMIN_PASSWORD != "$NUXR_DB_MIG_PASSWORD" && $NUXR_DB_ADMIN_PASSWORD != "$NUXR_DB_APP_PASSWORD" ]] \
  || die "$EXIT_USAGE" "every account needs its own password"
[[ $NUXR_DB_ADMIN_PASSWORD != *$'\n'* ]] || die "$EXIT_USAGE" "NUXR_DB_ADMIN_PASSWORD contains a line break"
[[ $NUXR_DB_CLIENT_HOST =~ ^[A-Za-z0-9._%:-]{1,255}$ ]] || die "$EXIT_USAGE" "NUXR_DB_CLIENT_HOST: invalid host part"
for h in $NUXR_DB_RADIUS_HOSTS; do [[ $h =~ ^[A-Za-z0-9._%:-]{1,255}$ ]] || die "$EXIT_USAGE" "NUXR_DB_RADIUS_HOSTS: invalid host part $h"; done
NUXR_APP_URL=${NUXR_APP_URL%/}
[[ $NUXR_APP_URL =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$ ]] || die "$EXIT_USAGE" "NUXR_APP_URL: not an http(s) URL without query"
DB=$NUXR_DB_NAME
q() { printf "'%s'" "$1"; }                     # quoted SQL literal of a validated identifier-like value
acct() { printf "'%s'@'%s'" "$1" "$2"; }        # account literal
APP_ACCT=$(acct "$NUXR_DB_APP_USER" "$NUXR_DB_CLIENT_HOST")
MIG_ACCT=$(acct "$NUXR_DB_MIG_USER" "$NUXR_DB_CLIENT_HOST")
RADIUS_ACCTS=(); for h in $NUXR_DB_RADIUS_HOSTS; do RADIUS_ACCTS+=("$(acct "$NUXR_DB_RADIUS_USER" "$h")"); done

# ------------------------------------------------------------------------------------------------------------------
# private temporary directory, state, lock, log
# ------------------------------------------------------------------------------------------------------------------
TMPD=$(mktemp -d /tmp/nuxr-fresh.XXXXXXXX)
cleanup() { rm -rf "$TMPD"; }
trap cleanup EXIT
STATE_FILE=$STATE_DIR/${NUXR_DB_HOST//[^A-Za-z0-9._-]/_}__${DB}.state
if [[ $DRY_RUN -eq 0 ]]; then
  mkdir -p "$STATE_DIR"; chmod 0700 "$STATE_DIR"
  exec 9>"$STATE_DIR/.lock"
  flock -n 9 || refuse "another nuxr-fresh-install.sh run holds $STATE_DIR/.lock"
  LOG_FILE=$STATE_DIR/install-$(date -u +%Y%m%dT%H%M%SZ).log
  exec > >(tee -a "$LOG_FILE") 2>&1
  say "log: $LOG_FILE (no secret is ever written to it)"
fi
state_has() { [[ -f $STATE_FILE ]] && grep -qx "phase_$1=done" "$STATE_FILE"; }
state_set() { [[ $DRY_RUN -eq 1 ]] && return 0; state_has "$1" && return 0; printf 'phase_%s=done\n' "$1" >> "$STATE_FILE"; printf 'phase_%s_at=%s\n' "$1" "$(ts)" >> "$STATE_FILE"; }
state_get() { [[ -f $STATE_FILE ]] && sed -n "s/^$1=//p" "$STATE_FILE" | tail -1; }

say "ITC NetCore V1.0 fresh install - app root $APP_ROOT, database $DB on $NUXR_DB_HOST$([[ $DRY_RUN -eq 1 ]] && printf ' (DRY RUN: read-only)')"
[[ -f $APP_ROOT/RELEASE_SHA ]] && say "release SHA (RELEASE_SHA): $(tr -d ' \r\n' < "$APP_ROOT/RELEASE_SHA")"

# ------------------------------------------------------------------------------------------------------------------
phase "1 prerequisites (checklist A1, A2-A6)"
# ------------------------------------------------------------------------------------------------------------------
missing=0
if [[ -z $PHP ]]; then PHP=$(command -v php8.2 || command -v php || true); fi
[[ -n $PHP && -x $(command -v "$PHP" || true) ]] || { say "MISSING php (PHP 8.2 CLI)"; exit "$EXIT_PREREQ"; }
phpv=$("$PHP" -r 'echo PHP_VERSION;')
if [[ $phpv == 8.2.* ]]; then say "ok   PHP $phpv ($PHP)"; else say "MISSING PHP 8.2 (found $phpv; the tested runtime is 8.2)"; missing=1; fi
PHP_MODS=$("$PHP" -m)
for ext in pdo_mysql curl gd mbstring zip; do
  if grep -qix "$ext" <<<"$PHP_MODS"; then say "ok   php extension $ext"; else say "MISSING php extension $ext"; missing=1; fi
done
grep -qix 'xdebug' <<<"$PHP_MODS" && say "warn Xdebug is loaded in the CLI (not for production)"
fpm=$(command -v php-fpm8.2 || command -v php-fpm || true)
if [[ -n $fpm ]]; then say "ok   PHP-FPM $("$fpm" -v 2>/dev/null | head -1 | awk '{print $2}') ($fpm)"; else say "MISSING php-fpm (php8.2-fpm)"; missing=1; fi
DBC=$(command -v mariadb || command -v mysql || true)
if [[ -n $DBC ]]; then say "ok   database client $("$DBC" --version | head -1)"; else say "MISSING database client (mariadb-client or mysql-client)"; missing=1; fi
if id -u "$WEB_USER" >/dev/null 2>&1; then WEB_GROUP=$(id -gn "$WEB_USER"); say "ok   web user $WEB_USER (group $WEB_GROUP)"; else say "MISSING web user $WEB_USER"; missing=1; fi
for t in setpriv flock sha256sum tar find; do command -v "$t" >/dev/null || { say "MISSING tool $t"; missing=1; }; done
for f in index.php init.php config.sample.php install/phpnuxbill.sql install/radius.sql install/freeradius/nuxr_admission.sql \
         install/freeradius/nuxr_hs_admission.sql system/reseller/migrate.php system/reseller/grants.php \
         system/reseller/preflight.php system/reseller/health.php; do
  if [[ -f $APP_ROOT/$f ]]; then :; elif state_has installer_removed && [[ $f == install/* ]]; then :; else
    say "MISSING release file $f in the app root (is --app-root an unpacked ITC NetCore release?)"; missing=1; fi
done
[[ $missing -eq 0 ]] || die "$EXIT_PREREQ" "prerequisites missing (see the MISSING lines); nothing was changed"

as_web() { setpriv --reuid="$WEB_USER" --regid="$WEB_GROUP" --init-groups -- "$@"; }
cnf() { # <file> <user> <password>: MySQL option file, 0600, password quoted and escaped
  local p=${3//\\/\\\\}; p=${p//\"/\\\"}
  printf '[client]\nhost=%s\nuser=%s\npassword="%s"\ndefault-character-set=utf8mb4\n' "$NUXR_DB_HOST" "$2" "$p" > "$1"
  chmod 0600 "$1"
}
cnf "$TMPD/admin.cnf" "$NUXR_DB_ADMIN_USER" "$NUXR_DB_ADMIN_PASSWORD"
cnf "$TMPD/mig.cnf" "$NUXR_DB_MIG_USER" "$NUXR_DB_MIG_PASSWORD"
# SQL always goes through stdin (never argv); -N -B: plain tab-separated values
sql_admin() { "$DBC" --defaults-extra-file="$TMPD/admin.cnf" -N -B "$@"; }
sql_mig() { "$DBC" --defaults-extra-file="$TMPD/mig.cnf" -N -B "$@" "$DB"; }

sql_admin <<<"SELECT 1" >/dev/null 2>"$TMPD/err" || { sed 's/^/     /' "$TMPD/err"; die "$EXIT_PREREQ" "cannot connect to $NUXR_DB_HOST as the administrator $NUXR_DB_ADMIN_USER"; }
ver=$(sql_admin <<<"SELECT VERSION()")
if [[ ${ver,,} == *mariadb* ]]; then ENGINE=mariadb; [[ $ver == 10.11.* ]] && say "ok   MariaDB $ver" || say "warn MariaDB $ver is outside the tested policy (10.11)"
else ENGINE=mysql; [[ $ver == 8.0.* ]] && say "ok   MySQL $ver" || say "warn MySQL $ver is outside the tested policy (8.0)"; fi
if [[ $ENGINE == mariadb ]]; then iso_var=tx_isolation; else iso_var=transaction_isolation; fi
read -r g_iso g_tz <<<"$(sql_admin <<<"SELECT @@GLOBAL.$iso_var, @@GLOBAL.time_zone")"
[[ $g_iso == REPEATABLE-READ ]] && say "ok   global isolation REPEATABLE-READ (A3)" || { say "MISSING global isolation REPEATABLE-READ (A3, found $g_iso)"; missing=1; }
off=$(sql_admin <<<"SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())")
if [[ $g_tz == '+00:00' || ( $g_tz == SYSTEM && $off == 0 ) ]]; then say "ok   global time_zone $g_tz, offset ${off}s (A4)"; else say "MISSING global time_zone '+00:00' (A4, found $g_tz, offset ${off}s)"; missing=1; fi
if [[ $ENGINE == mariadb ]]; then
  [[ $(sql_admin <<<"SELECT @@GLOBAL.check_constraint_checks") == 1 ]] && say "ok   check_constraint_checks = ON (A6)" || { say "MISSING check_constraint_checks = ON (A6)"; missing=1; }
fi
skew=$(( $(sql_admin <<<"SELECT UNIX_TIMESTAMP()") - $(date +%s) )); skew=${skew#-}
[[ $skew -le 5 ]] && say "ok   database / application clock skew ${skew}s (A5)" || { say "MISSING clock skew <= 5 s (A5, found ${skew}s)"; missing=1; }
# administrator: global SELECT (sees every schema object, so the emptiness proof is complete), CREATE USER, GRANT OPTION
grantee="CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')"
privs=$(sql_admin <<<"SELECT CONCAT(PRIVILEGE_TYPE, ':', IS_GRANTABLE) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = $grantee")
for p in SELECT 'CREATE USER'; do
  grep -q "^$p:" <<<"$privs" && say "ok   administrator has global $p" || { say "MISSING administrator global privilege $p"; missing=1; }
done
grep -q ':YES$' <<<"$privs" && say "ok   administrator has GRANT OPTION" || { say "MISSING administrator GRANT OPTION"; missing=1; }
if [[ $CREATE_DB -eq 1 ]]; then grep -q '^CREATE:' <<<"$privs" && say "ok   administrator has global CREATE (--create-db)" || { say "MISSING administrator global CREATE (--create-db)"; missing=1; }; fi
[[ $missing -eq 0 ]] || die "$EXIT_PREREQ" "database prerequisites missing (see the MISSING lines); nothing was changed"

# ------------------------------------------------------------------------------------------------------------------
phase "2 database gate: the target must be new and EMPTY"
# ------------------------------------------------------------------------------------------------------------------
inventory() {
  sql_admin <<SQL
SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = $(q "$DB")),
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = $(q "$DB")),
       (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = $(q "$DB")),
       (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = $(q "$DB")),
       (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = $(q "$DB")),
       COALESCE((SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = $(q "$DB")), '-')
SQL
}
read -r db_exists n_tables n_routines n_events n_triggers db_coll <<<"$(inventory)"
say "inventory: exists=$db_exists tables/views=$n_tables routines=$n_routines events=$n_events triggers=$n_triggers collation=$db_coll"
RESUME=0
if state_has base_schema; then
  [[ $(state_get app_root) == "$APP_ROOT" ]] || refuse "state file $STATE_FILE belongs to app root $(state_get app_root), not $APP_ROOT"
  [[ $db_exists == 1 ]] || refuse "the state file says this installer loaded $DB, but the database no longer exists"
  RESUME=1; say "resume: the state file proves this installer created the schema of $DB (install id $(state_get install_id)); the emptiness gate is not repeated and the base schema is never loaded again"
elif [[ -f $STATE_FILE ]] && grep -qx 'phase_base_schema_started=done' "$STATE_FILE"; then
  refuse "a previous run was interrupted DURING the base schema load of $DB. It is never resumed: create a new, empty database (choose a new NUXR_DB_NAME), or have a DBA verify and drop $DB, and remove $STATE_FILE"
else
  [[ -f $APP_ROOT/config.php ]] && refuse "$APP_ROOT/config.php exists but no installer state proves it is ours: an existing installation is never overwritten"
  if [[ $db_exists == 0 ]]; then
    [[ $CREATE_DB -eq 1 ]] || refuse "database $DB does not exist; create it (utf8mb4_general_ci) or pass --create-db. An unknown name is never guessed"
    say "database $DB does not exist: it will be created (--create-db)"
  else
    if [[ $n_tables != 0 || $n_routines != 0 || $n_events != 0 || $n_triggers != 0 ]]; then
      names=$(sql_admin <<<"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = $(q "$DB") ORDER BY TABLE_NAME LIMIT 10" | tr '\n' ' ')
      refuse "database $DB is NOT EMPTY ($n_tables tables/views, $n_routines routines, $n_events events, $n_triggers triggers; first tables: ${names:-none}). The shipped schema files DROP existing tables: this installer never loads them into a non-empty database. Use a new, empty database"
    fi
    [[ $db_coll == utf8mb4_general_ci ]] || refuse "database $DB is empty but its default collation is $db_coll; create it with CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci (B-L09). It is not altered by this installer"
    say "ok   database $DB exists and is EMPTY (0 tables, views, routines, events, triggers; utf8mb4_general_ci)"
  fi
fi

# accounts: must not exist (unless this installer created them, recorded in the state file)
acct_exists() { sql_admin <<<"SELECT COUNT(*) FROM mysql.user WHERE User = $(q "$1") AND Host = $(q "$2")"; }
if ! state_has accounts; then
  for pair in "$NUXR_DB_MIG_USER@$NUXR_DB_CLIENT_HOST" "$NUXR_DB_APP_USER@$NUXR_DB_CLIENT_HOST" $(for h in $NUXR_DB_RADIUS_HOSTS; do printf '%s ' "$NUXR_DB_RADIUS_USER@$h"; done); do
    u=${pair%%@*}; h=${pair#*@}
    [[ $(acct_exists "$u" "$h") == 0 ]] || refuse "account '$u'@'$h' already exists; this installer only creates new accounts (an existing one may hold privileges elsewhere, ISO-01)"
  done
  say "ok   accounts $MIG_ACCT, $APP_ACCT, ${RADIUS_ACCTS[*]} do not exist yet"
fi

if [[ $DRY_RUN -eq 1 ]]; then
  phase "DRY RUN plan (nothing was written)"
  [[ $db_exists == 0 ]] && say "would CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
  say "would create $MIG_ACCT (ALL PRIVILEGES ON $DB.*), $APP_ACCT, ${RADIUS_ACCTS[*]} (passwords from the env file)"
  say "would re-check emptiness, load install/phpnuxbill.sql and install/radius.sql with the migration account"
  say "would write $APP_ROOT/config.php (root:$WEB_GROUP 0640) with the runtime account and new app / API secrets"
  say "would run migrate.php status, preflight, migrate, verify, migrate (second run: nothing to do)"
  say "would load install/freeradius/nuxr_admission.sql, nuxr_hs_admission.sql and require the three READY checks"
  [[ $ENABLE_RADIUS -eq 1 ]] && say "would store tbl_appconfig radius_enable=1 (ACC-1)"
  say "would generate grants with grants.php, review them, apply them as the administrator"
  say "would copy pages_template/ to pages/, set ownership (code root-owned read-only; pages, system/uploads, system/cache, ui/cache, ui/compiled writable by $WEB_USER)"
  say "would remove the web installer (--installer=$INSTALLER_MODE), create $LOG_DIR$([[ $INSTALL_CRON -eq 1 ]] && printf ', install /etc/cron.d/itc-netcore')"
  say "would run preflight.php --setup and health.php --finance as $WEB_USER (both must exit 0)"
  say "dry_run=PASS"
  exit 0
fi

if [[ ! -f $STATE_FILE ]]; then
  { printf '# nuxr-fresh-install.sh state - do not edit\n'; printf 'install_id=%s\n' "$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    printf 'db_host=%s\ndb_name=%s\napp_root=%s\nstarted_at=%s\n' "$NUXR_DB_HOST" "$DB" "$APP_ROOT" "$(ts)"; } > "$STATE_FILE"
  chmod 0600 "$STATE_FILE"
fi

# ------------------------------------------------------------------------------------------------------------------
phase "3 database and accounts (runbook 2 step 2)"
# ------------------------------------------------------------------------------------------------------------------
if ! state_has database; then
  if [[ $db_exists == 0 ]]; then
    sql_admin <<<"CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" || die "$EXIT_STEP" "CREATE DATABASE failed"
    say "created database $DB (utf8mb4_general_ci)"
  fi
  state_set database
fi
if ! state_has accounts; then
  {
    printf "CREATE USER %s IDENTIFIED BY '%s';\n" "$MIG_ACCT" "$NUXR_DB_MIG_PASSWORD"
    printf "CREATE USER %s IDENTIFIED BY '%s';\n" "$APP_ACCT" "$NUXR_DB_APP_PASSWORD"
    for a in "${RADIUS_ACCTS[@]}"; do printf "CREATE USER %s IDENTIFIED BY '%s';\n" "$a" "$NUXR_DB_RADIUS_PASSWORD"; done
    printf 'GRANT ALL PRIVILEGES ON `%s`.* TO %s;\n' "$DB" "$MIG_ACCT"
  } > "$TMPD/accounts.sql"
  sql_admin < "$TMPD/accounts.sql" || die "$EXIT_STEP" "creating the accounts failed (see the database error above)"
  rm -f "$TMPD/accounts.sql"
  state_set accounts
  say "created $MIG_ACCT (ALL PRIVILEGES ON \`$DB\`.*), $APP_ACCT and ${RADIUS_ACCTS[*]} (no privileges yet)"
fi
sql_mig <<<"SELECT 1" >/dev/null || die "$EXIT_STEP" "the migration account cannot connect to $DB from this host (check NUXR_DB_CLIENT_HOST)"

# ------------------------------------------------------------------------------------------------------------------
phase "4 base schema (installer step 4: install/phpnuxbill.sql + install/radius.sql)"
# ------------------------------------------------------------------------------------------------------------------
if ! state_has base_schema; then
  read -r db_exists n_tables n_routines n_events n_triggers db_coll <<<"$(inventory)"
  [[ $db_exists == 1 && $n_tables == 0 && $n_routines == 0 && $n_events == 0 && $n_triggers == 0 && $db_coll == utf8mb4_general_ci ]] \
    || refuse "re-check before loading: $DB is no longer empty ($n_tables tables) or has collation $db_coll; nothing loaded"
  say "re-check: $DB still empty; loading"
  state_set base_schema_started
  sql_mig < "$APP_ROOT/install/phpnuxbill.sql" || die "$EXIT_STEP" "install/phpnuxbill.sql failed: this database is now partial; start again on a new empty database"
  sql_mig < "$APP_ROOT/install/radius.sql" || die "$EXIT_STEP" "install/radius.sql failed: this database is now partial; start again on a new empty database"
  state_set base_schema
  say "base schema loaded: $(sql_admin <<<"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = $(q "$DB")") tables"
else
  say "skip: base schema loaded by this installer earlier"
fi

# ------------------------------------------------------------------------------------------------------------------
phase "5 config.php from install/fresh/templates/config.php.tmpl (checklist B9, C1-C5)"
# ------------------------------------------------------------------------------------------------------------------
if ! state_has config; then
  rm -f "$APP_ROOT/config.php.nuxr-new"
  ( export NUXR_CFG_APP_URL=$NUXR_APP_URL NUXR_CFG_DB_HOST=$NUXR_DB_HOST NUXR_CFG_DB_NAME=$DB \
           NUXR_CFG_DB_USER=$NUXR_DB_APP_USER NUXR_CFG_DB_PASS=$NUXR_DB_APP_PASSWORD
    "$PHP" "$RENDER" "$TEMPLATE_CONFIG" "$APP_ROOT/config.php.nuxr-new" ) || die "$EXIT_STEP" "rendering config.php failed"
  "$PHP" -l "$APP_ROOT/config.php.nuxr-new" >/dev/null || die "$EXIT_STEP" "rendered config.php does not parse"
  chown root:"$WEB_GROUP" "$APP_ROOT/config.php.nuxr-new"; chmod 0640 "$APP_ROOT/config.php.nuxr-new"
  mv -f "$APP_ROOT/config.php.nuxr-new" "$APP_ROOT/config.php"
  state_set config
  say "config.php written (root:$WEB_GROUP 0640; runtime account $NUXR_DB_APP_USER; new 64-hex app_secret and api_secret; APP_URL $NUXR_APP_URL)"
else
  [[ -f $APP_ROOT/config.php ]] || die "$EXIT_STEP" "state says config.php was written, but it is missing"
  say "skip: config.php written by this installer earlier (secrets kept)"
fi
[[ $NUXR_APP_URL == https://* ]] || say "warn APP_URL is not https: preflight stage.https will WARN (not acceptable on a target, TA-01)"

# ------------------------------------------------------------------------------------------------------------------
phase "6 file ownership and writable paths (checklist B7; code tree read-only for $WEB_USER)"
# ------------------------------------------------------------------------------------------------------------------
if [[ ! -d $APP_ROOT/pages ]]; then
  [[ -d $APP_ROOT/pages_template ]] || die "$EXIT_STEP" "neither pages/ nor pages_template/ exists"
  cp -a "$APP_ROOT/pages_template" "$APP_ROOT/pages"; say "pages_template/ copied to pages/ (installer step 5)"
fi
[[ -d $APP_ROOT/pages_template ]] && { rm -rf "$APP_ROOT/pages_template"; say "pages_template/ removed (installer step 5)"; }
chown -R root:root "$APP_ROOT_REAL"            # real path: chown -R does not follow a symlinked root
chmod -R u+rwX,go+rX,go-w "$APP_ROOT_REAL"
WRITABLE=(pages system/uploads system/cache ui/cache ui/compiled)
for d in "${WRITABLE[@]}"; do
  mkdir -p "$APP_ROOT_REAL/$d"
  chown -R "$WEB_USER:$WEB_GROUP" "$APP_ROOT_REAL/$d"
  chmod -R u+rwX,go+rX,go-w "$APP_ROOT_REAL/$d"
done
chown root:"$WEB_GROUP" "$APP_ROOT/config.php"; chmod 0640 "$APP_ROOT/config.php"
mkdir -p "$LOG_DIR"; chown "$WEB_USER:$WEB_GROUP" "$LOG_DIR"; chmod 0750 "$LOG_DIR"
state_set permissions
say "code tree root:root (no write for group/other); writable by $WEB_USER: ${WRITABLE[*]}; config.php root:$WEB_GROUP 0640; log dir $LOG_DIR"

# ------------------------------------------------------------------------------------------------------------------
phase "7 reseller schema migrations (runbook 2 step 6; --db-host = the account's host, register X-24)"
# ------------------------------------------------------------------------------------------------------------------
migrate() { # <command>: runs as the web user; the password reaches PHP only through the environment
  ( export NUXR_MIG_PW=$NUXR_DB_MIG_PASSWORD USER=$WEB_USER; cd "$APP_ROOT"
    as_web "$PHP" system/reseller/migrate.php "$1" --config=config.php --db-host="$NUXR_DB_HOST" --db-user="$NUXR_DB_MIG_USER" --db-password-env=NUXR_MIG_PW )
}
for c in status preflight; do
  rc=0; migrate "$c" > "$TMPD/m.out" 2>&1 || rc=$?
  sed 's/^/     /' "$TMPD/m.out"; say "migrate.php $c exit=$rc"
  [[ $rc -eq 0 ]] || die "$EXIT_STEP" "migrate.php $c exit $rc (2 NOT_READY: nothing changed; fix the cause and re-run)"
  if ! state_has migrations && ! grep -q '^variant: V1_FRESH_INSTALL$' "$TMPD/m.out"; then
    die "$EXIT_STEP" "migrate.php $c does not recognise the fresh install schema (variant V1_FRESH_INSTALL expected)"
  fi
done
for c in migrate verify migrate; do
  rc=0; migrate "$c" > "$TMPD/m.out" 2>&1 || rc=$?
  sed 's/^/     /' "$TMPD/m.out"; say "migrate.php $c exit=$rc"
  [[ $rc -eq 0 ]] || die "$EXIT_STEP" "migrate.php $c exit $rc (3 FAILED: only 'migrate.php rollback --all' may run; see runbook section 4)"
done
grep -qE 'APPLIED|applied' "$TMPD/m.out" || say "warn second migrate output did not list the applied migrations"
if grep -qE '(PENDING|APPLYING)' "$TMPD/m.out"; then die "$EXIT_STEP" "second migrate still reports pending work"; fi
state_set migrations
say "migrations applied and verified; the second migrate had nothing to do"

# ------------------------------------------------------------------------------------------------------------------
phase "8 FreeRADIUS admission tables (runbook 2 step 7)"
# ------------------------------------------------------------------------------------------------------------------
FR=$APP_ROOT/install/freeradius
if ! state_has admission; then
  for f in nuxr_admission.sql nuxr_hs_admission.sql; do sql_mig < "$FR/$f" > /dev/null || die "$EXIT_STEP" "$f failed"; say "loaded $f"; done
fi
if [[ -d $FR ]]; then
  cat "$FR/nuxr_admission_check.sql" "$FR/nuxr_hs_admission_check.sql" "$FR/nuxr_nasreload_collation_check.sql" | sql_mig > "$TMPD/chk.out"
  sed 's/^/     /' "$TMPD/chk.out"
  [[ $(grep -cE '^(nuxr_admission|nuxr_hs_admission|nasreload_collation)=READY$' "$TMPD/chk.out") -eq 3 ]] || die "$EXIT_STEP" "a readiness check is not READY"
  say "nuxr_admission, nuxr_hs_admission and nasreload_collation READY"
else
  say "skip readiness checks: install/freeradius was removed earlier (--installer=remove); recorded READY in the state file"
fi
state_has admission || state_set admission

# ------------------------------------------------------------------------------------------------------------------
phase "9 RADIUS setting (radius_enable, defect ACC-1 / register X-25)"
# ------------------------------------------------------------------------------------------------------------------
if [[ $ENABLE_RADIUS -eq 1 ]] && ! state_has radius_setting; then
  sql_mig <<'SQL' || die "$EXIT_STEP" "storing radius_enable failed"
INSERT INTO tbl_appconfig (setting, value) SELECT 'radius_enable', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tbl_appconfig WHERE setting = 'radius_enable');
UPDATE tbl_appconfig SET value = '1' WHERE setting = 'radius_enable';
SQL
  state_set radius_setting; say "tbl_appconfig radius_enable = 1 (the SuperAdmin setting 'Enable Radius: Yes')"
elif [[ $ENABLE_RADIUS -eq 0 ]]; then say "skip (--no-enable-radius): enable RADIUS as SuperAdmin before the preflight"
else say "skip: stored earlier"; fi

# ------------------------------------------------------------------------------------------------------------------
phase "10 least-privilege grants (runbook 2 step 8, checklist D1/D2)"
# ------------------------------------------------------------------------------------------------------------------
if ! state_has grants; then
  # grants.php derives the statements from the tables that exist: it connects with the migration account (sees every
  # table) through a private, temporary config file
  ( export NUXR_CFG_APP_URL=$NUXR_APP_URL NUXR_CFG_DB_HOST=$NUXR_DB_HOST NUXR_CFG_DB_NAME=$DB \
           NUXR_CFG_DB_USER=$NUXR_DB_MIG_USER NUXR_CFG_DB_PASS=$NUXR_DB_MIG_PASSWORD
    "$PHP" "$RENDER" "$TEMPLATE_CONFIG" "$TMPD/grants-config.php" >/dev/null ) || die "$EXIT_STEP" "temporary grants config failed"
  "$PHP" "$APP_ROOT/system/reseller/grants.php" --config="$TMPD/grants-config.php" --app="$APP_ACCT" --radius="${RADIUS_ACCTS[0]}" > "$TMPD/grants.sql" \
    || die "$EXIT_STEP" "grants.php failed"
  rm -f "$TMPD/grants-config.php"
  for a in "${RADIUS_ACCTS[@]:1}"; do grep -F " TO ${RADIUS_ACCTS[0]};" "$TMPD/grants.sql" | sed "s/ TO ${RADIUS_ACCTS[0]};\$/ TO $a;/" >> "$TMPD/grants.sql"; done
  # review (checklist D1 / D2): nothing global or database-wide, append-only tables, no billing table for FreeRADIUS
  n=$(grep -c '^GRANT ' "$TMPD/grants.sql")
  if grep -qE '^GRANT .* ON (\*\.\*|`[^`]+`\.\*) ' "$TMPD/grants.sql"; then die "$EXIT_STEP" "grants review: a global or database-wide grant was generated"; fi
  for t in tbl_manager_ledger tbl_reseller_audit_events tbl_reseller_activation_events; do
    grep -qxF "GRANT SELECT, INSERT ON \`$DB\`.\`$t\` TO $APP_ACCT;" "$TMPD/grants.sql" || die "$EXIT_STEP" "grants review: $t is not append-only (SELECT, INSERT) for the application account"
  done
  for a in "${RADIUS_ACCTS[@]}"; do
    if grep -F " TO $a;" "$TMPD/grants.sql" | grep '`tbl_' >/dev/null; then die "$EXIT_STEP" "grants review: the FreeRADIUS account $a would get a billing table"; fi
  done
  grep -q " TO ${RADIUS_ACCTS[0]};" "$TMPD/grants.sql" || die "$EXIT_STEP" "grants review: no FreeRADIUS grants generated"
  sql_admin < "$TMPD/grants.sql" || die "$EXIT_STEP" "applying the grants failed"
  install -m 0600 "$TMPD/grants.sql" "$STATE_DIR/grants-applied-$(date -u +%Y%m%dT%H%M%SZ).sql"
  state_set grants
  say "reviewed and applied $n GRANT statements (copy in $STATE_DIR; it contains no password)"
else
  say "skip: grants applied earlier"
fi
glob=$(sql_admin <<<"SHOW GRANTS FOR $APP_ACCT" | grep -E ' ON (\*\.\*|`[^`]+`\.\*) ' | grep -vE '^GRANT USAGE ON \*\.\*' || true)
[[ -z $glob ]] || die "$EXIT_STEP" "the application account holds global / database-wide privileges: $glob"
say "ok   $APP_ACCT holds no global or database-wide privilege"

# ------------------------------------------------------------------------------------------------------------------
phase "11 remove the web installer (runbook 2 step 4, checklist B4) - mode $INSTALLER_MODE"
# ------------------------------------------------------------------------------------------------------------------
if [[ -d $APP_ROOT/install ]]; then
  if [[ $INSTALLER_MODE == remove ]]; then
    rm -rf "$APP_ROOT/install"; say "install/ removed completely (preflight radius_checks can no longer compare the CHECK expressions)"
  else
    find "$APP_ROOT/install" -mindepth 1 -maxdepth 1 ! -name freeradius -exec rm -rf {} +
    chown -R root:root "$APP_ROOT_REAL/install"; chmod -R go-w "$APP_ROOT_REAL/install"
    say "web installer removed (install/*.php, *.sql, css, img); kept read-only: $(ls "$APP_ROOT/install" | tr '\n' ' ')(preflight radius_checks reads it; the web server denies /install/)"
  fi
fi
ls "$APP_ROOT"/install/*.php >/dev/null 2>&1 && die "$EXIT_STEP" "installer PHP files are still present"
state_has installer_removed || state_set installer_removed

if [[ $INSTALL_CRON -eq 1 ]]; then
  [[ -f $CRON_EXAMPLE ]] || die "$EXIT_INTERNAL" "package file missing: $CRON_EXAMPLE"
  sed -e "s#/srv/itcnc/app/current#$APP_ROOT#g" -e "s#/var/log/itcnc#$LOG_DIR#g" -e "s#\bwww-data\b#$WEB_USER#g" "$CRON_EXAMPLE" > "$TMPD/cron"
  install -m 0644 -o root -g root "$TMPD/cron" /etc/cron.d/itc-netcore
  say "scheduled jobs installed: /etc/cron.d/itc-netcore (checklist I1-I4, I6)"
fi

# ------------------------------------------------------------------------------------------------------------------
phase "12 setup preflight and health (runbook 2 step 12) as $WEB_USER"
# ------------------------------------------------------------------------------------------------------------------
rc=0; ( cd "$APP_ROOT" && as_web "$PHP" system/reseller/preflight.php --setup ) > "$TMPD/pf.out" 2>&1 || rc=$?
sed 's/^/     /' "$TMPD/pf.out"; say "preflight.php --setup exit=$rc"
[[ $rc -eq 0 ]] || die "$EXIT_STEP" "setup preflight failed (exit $rc): resolve every FAIL line, then re-run this installer (it resumes)"
rc=0; ( cd "$APP_ROOT" && as_web "$PHP" system/reseller/health.php --finance ) > "$TMPD/h.out" 2>&1 || rc=$?
sed 's/^/     /' "$TMPD/h.out"; say "health.php --finance exit=$rc"
[[ $rc -eq 0 ]] || die "$EXIT_STEP" "health.php --finance exit $rc (1 WARNING, 2 CRITICAL, 3 UNKNOWN)"
state_has complete || state_set complete

phase "INSTALLED - next steps (the installer does not do these)"
cat <<EOF
  1. Sign in at $NUXR_APP_URL/admin as admin / admin. The application forces the password change (SEC-18, C7):
     set a strong unique password from the secret store, sign out and in again.
  2. Settings (SuperAdmin): CompanyName (seeded 'PHPNuxBill'), time zone (seeded Asia/Jakarta), currency and number
     format (seeded Rp. , .). RADIUS is $( [[ $ENABLE_RADIUS -eq 1 ]] && printf 'already enabled' || printf 'NOT enabled: enable it now (ACC-1)').
  3. HTTPS and the web boundary: install nginx/itc-netcore.conf.example and php-fpm/pool.conf.example (certificate,
     host name, socket), then run nuxr-post-install-verify.sh (TA-10 items).
  4. Scheduled jobs and logs: cron/itc-netcore.cron.example$( [[ $INSTALL_CRON -eq 1 ]] && printf ' (installed)'), logrotate/itc-netcore.example.
  5. FreeRADIUS (checklist G) and every NAS (docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md section 2, type mikrotik_snmp);
     then the RELEASE preflight without --setup must exit 0 (runbook 2 step 12, TA-01).
  6. Backups (docs/BACKUP_RESTORE_RUNBOOK.md) with a separate backup account; restore drill before go-live (F2).
  7. Move the secrets of $ENV_FILE into the secret store and delete the file (at least NUXR_DB_ADMIN_PASSWORD).
     The migration password is not in config.php; keep it only in the secret store (D3).
EOF
say "fresh_install=PASS database=$DB app_root=$APP_ROOT state=$STATE_FILE"
