#!/usr/bin/env bash
# ITC NetCore V1.0 - post-install verification. NON-DESTRUCTIVE: it only reads files, sends GET / HEAD requests and
# runs the read-only product tools. Run it as root on the application host after nuxr-fresh-install.sh and after the
# web server configuration (nginx/itc-netcore.conf.example) is active.
#
#   nuxr-post-install-verify.sh --app-root=DIR --base-url=https://<host> [--web-user=www-data] [--php=php8.2]
#                               [--cron-file=/etc/cron.d/itc-netcore] [--release] [--resolve=<host:port:addr>]...
#                               [--insecure]
#
#   --release     run the RELEASE preflight (no --setup): only after every NAS is onboarded (runbook 2 step 12)
#   --resolve     passed to curl (repeatable), e.g. to reach the site on 127.0.0.1 before DNS points to it
#   --insecure    accept a self-signed certificate (disposable test instances only; never on a target)
#
# Checks: web (login 200, admin route, security headers and cookie flags, HTTP->HTTPS redirect, blocked paths and the
# installer not reachable), files (installer gone from disk, config.php mode/owner, writable paths, code tree not
# writable by the PHP user), tools (preflight, health --finance), cron (file present with the documented commands,
# working-directory rule of cron.php, PHP lint, quota report read-only).
# Exit codes: 0 every check PASS (WARN lines allowed); 1 at least one FAIL; 5 usage.
set -euo pipefail
umask 077

APP_ROOT="" BASE="" WEB_USER=www-data PHP="" CRON_FILE=/etc/cron.d/itc-netcore RELEASE=0 INSECURE=0
CURL_EXTRA=()
for a in "$@"; do
  case $a in
    --app-root=*) APP_ROOT=${a#*=} ;;
    --base-url=*) BASE=${a#*=} ;;
    --web-user=*) WEB_USER=${a#*=} ;;
    --php=*) PHP=${a#*=} ;;
    --cron-file=*) CRON_FILE=${a#*=} ;;
    --release) RELEASE=1 ;;
    --resolve=*) CURL_EXTRA+=(--resolve "${a#*=}") ;;
    --insecure) INSECURE=1 ;;
    -h|--help) sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 5 ;;
    *) printf 'unknown argument: %s\n' "$a" >&2; exit 5 ;;
  esac
done
[[ -n $APP_ROOT && -n $BASE ]] || { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 5; }
[[ -d $APP_ROOT ]] || { echo "app root not found: $APP_ROOT" >&2; exit 5; }
BASE=${BASE%/}
[[ $(id -u) -eq 0 ]] || { echo "run as root (file ownership checks and running the tools as $WEB_USER)" >&2; exit 5; }
[[ -n $PHP ]] || PHP=$(command -v php8.2 || command -v php)
WEB_GROUP=$(id -gn "$WEB_USER")
REAL=$(cd "$APP_ROOT" && pwd -P)
[[ $INSECURE -eq 1 ]] && CURL_EXTRA+=(-k)
TMPD=$(mktemp -d); trap 'rm -rf "$TMPD"' EXIT

FAILS=0 WARNS=0 PASSES=0
pass() { PASSES=$((PASSES + 1)); printf 'PASS  %-34s %s\n' "$1" "$2"; }
warn() { WARNS=$((WARNS + 1)); printf 'WARN  %-34s %s\n' "$1" "$2"; }
fail() { FAILS=$((FAILS + 1)); printf 'FAIL  %-34s %s\n' "$1" "$2"; }
as_web() { setpriv --reuid="$WEB_USER" --regid="$WEB_GROUP" --init-groups -- "$@"; }
http() { # <url> -> prints "<code>" and stores the headers in $TMPD/h, the body in $TMPD/b
  : > "$TMPD/h"; : > "$TMPD/b"
  curl -sS -o "$TMPD/b" -D "$TMPD/h" -w '%{http_code}' --max-time 20 "${CURL_EXTRA[@]}" "$1" 2>"$TMPD/curl.err" || true
}
hdr() { { grep -i "^$1:" "$TMPD/h" || true; } | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }  # a missing header is '' (never aborts set -e)

echo "== web ($BASE)"
code=$(http "$BASE/?_route=login")
if [[ $code == 200 ]]; then pass web.login_page "GET /?_route=login -> 200"; else fail web.login_page "GET /?_route=login -> $code $(cat "$TMPD/curl.err" 2>/dev/null)"; fi
if grep -qi '<form' "$TMPD/b"; then pass web.login_form "login page contains a form"; else fail web.login_form "no form in the login page"; fi
[[ $(hdr X-Frame-Options) == SAMEORIGIN ]] && pass hdr.x_frame_options SAMEORIGIN || fail hdr.x_frame_options "got '$(hdr X-Frame-Options)'"
[[ $(hdr Content-Security-Policy) == *"frame-ancestors 'self'"* ]] && pass hdr.csp_frame_ancestors "$(hdr Content-Security-Policy)" || fail hdr.csp_frame_ancestors "got '$(hdr Content-Security-Policy)'"
[[ $(hdr X-Content-Type-Options) == nosniff ]] && pass hdr.x_content_type_options nosniff || fail hdr.x_content_type_options "got '$(hdr X-Content-Type-Options)'"
for h in X-Frame-Options X-Content-Type-Options Content-Security-Policy; do
  c=$(grep -ci "^$h:" "$TMPD/h" || true); [[ $c -eq 1 ]] || warn "hdr.single_${h,,}" "$h appears $c times"
done
cookie=$(grep -i '^Set-Cookie:' "$TMPD/h" | head -1 | tr -d '\r' || true)
if [[ -z $cookie ]]; then fail cookie.session "no Set-Cookie on the login page"
else
  flags=$(sed 's/^[^;]*//' <<<"$cookie")    # attributes only, never the cookie value
  [[ ${flags,,} == *httponly* ]] && pass cookie.httponly "HttpOnly" || fail cookie.httponly "missing ($flags)"
  [[ ${flags,,} == *samesite=lax* ]] && pass cookie.samesite "SameSite=Lax" || fail cookie.samesite "missing ($flags)"
  if [[ $BASE == https://* ]]; then [[ ${flags,,} == *secure* ]] && pass cookie.secure "Secure" || fail cookie.secure "missing ($flags)"; fi
fi
if [[ $BASE == https://* ]]; then
  [[ $(hdr Strict-Transport-Security) == *max-age=* ]] && pass hdr.hsts "$(hdr Strict-Transport-Security)" || fail hdr.hsts "no Strict-Transport-Security"
  code=$(http "http://${BASE#https://}/?_route=login")
  loc=$(hdr Location)
  if [[ $code == 301 || $code == 308 ]] && [[ $loc == https://* ]]; then pass web.http_redirect "http -> $code $loc"; else fail web.http_redirect "http -> $code '$loc'"; fi
else
  warn web.https "base URL is http: HTTPS checks skipped (B1 / TA-10 require HTTPS)"
fi
code=$(http "$BASE/admin/"); loc=$(hdr Location)
if [[ $code == 30[1278] && $loc == *_route=admin* ]]; then pass web.admin_route "GET /admin/ -> $code $loc"; else fail web.admin_route "GET /admin/ -> $code '$loc' (expected a redirect to ?_route=admin/)"; fi
code=$(http "$BASE/?_route=admin/")
[[ $code == 200 ]] && pass web.admin_login "GET /?_route=admin/ -> 200" || fail web.admin_login "GET /?_route=admin/ -> $code"
code=$(http "$BASE/system/uploads/paid.png")
[[ $code == 200 ]] && pass web.ui_asset "system/uploads/paid.png -> 200 (UI asset still served)" || warn web.ui_asset "system/uploads/paid.png -> $code (invoice templates load it)"
code=$(http "$BASE/update.php")
[[ $code == 403 ]] && pass web.update_php "update.php -> 403 (B5)" || fail web.update_php "update.php -> $code (B5 requires 403)"
for p in install/ install/index.php install/step4.php install/step5.php install/phpnuxbill.sql install/freeradius/nuxr_admission.sql \
         config.php config.sample.php .git/config .htaccess_firewall RELEASE_SHA system/ system/cron.php system/reseller/migrate.php \
         system/reseller/grants.php system/api.php system/uploads/x.php init.php radius.php dev/ tests/ docs/ owner-review/ \
         ui/compiled/ ui/ui/admin/header.tpl pages/ composer.json; do
  code=$(http "$BASE/$p")
  case $code in 403|404) pass "web.blocked" "/$p -> $code" ;; *) fail "web.blocked" "/$p -> $code (must not be served, B3 / B4)" ;; esac
done

echo "== files ($APP_ROOT)"
inst=$(find "$REAL/install" -maxdepth 1 -type f \( -name '*.php' -o -name '*.sql' \) 2>/dev/null | wc -l || true)
[[ $inst -eq 0 ]] && pass files.installer_removed "no install/*.php or install/*.sql on disk" || fail files.installer_removed "$inst installer files still on disk"
if [[ -d $REAL/install/freeradius ]]; then pass files.freeradius_kept "install/freeradius kept read-only (preflight radius_checks reads it)"
else warn files.freeradius_kept "install/freeradius absent: preflight radius_checks cannot compare the CHECK expressions"; fi
if [[ -f $REAL/config.php ]]; then
  m=$(stat -c '%a %U %G' "$REAL/config.php")
  [[ $m == "640 root $WEB_GROUP" ]] && pass files.config_php "config.php 0640 root:$WEB_GROUP" || fail files.config_php "config.php is '$m' (C: 0640 root:$WEB_GROUP)"
else fail files.config_php "config.php missing"; fi
for d in pages system/uploads system/cache ui/cache ui/compiled; do
  if [[ -d $REAL/$d ]] && as_web test -w "$REAL/$d"; then pass files.writable "$d writable by $WEB_USER"; else fail files.writable "$d not writable by $WEB_USER"; fi
done
w=$(as_web find "$REAL" \( -path "$REAL/pages" -o -path "$REAL/system/uploads" -o -path "$REAL/system/cache" -o -path "$REAL/ui/cache" -o -path "$REAL/ui/compiled" \) -prune -o -writable -print 2>/dev/null | head -5 || true)
[[ -z $w ]] && pass files.code_read_only "code tree not writable by $WEB_USER (B7)" || fail files.code_read_only "writable by $WEB_USER: $(tr '\n' ' ' <<<"$w")"
ww=$(find "$REAL" -perm -0002 ! -type l -print 2>/dev/null | head -3 || true)
[[ -z $ww ]] && pass files.no_world_writable "no world-writable file or directory" || fail files.no_world_writable "$ww"
as_web test -r "$REAL/config.php" && pass files.config_readable "config.php readable by $WEB_USER" || fail files.config_readable "config.php not readable by $WEB_USER"

echo "== tools (as $WEB_USER)"
mode=--setup; [[ $RELEASE -eq 1 ]] && mode=""
rc=0; ( cd "$REAL" && as_web "$PHP" system/reseller/preflight.php $mode ) > "$TMPD/pf" 2>&1 || rc=$?
sed 's/^/      /' "$TMPD/pf"
[[ $rc -eq 0 ]] && pass tools.preflight "preflight.php ${mode:-(release)} exit 0" || fail tools.preflight "preflight.php ${mode:-(release)} exit $rc"
grep -q '^WARN  stage.https' "$TMPD/pf" && warn tools.preflight_https "stage.https WARN (not acceptable on a target, TA-01)"
rc=0; ( cd "$REAL" && as_web "$PHP" system/reseller/health.php --finance ) > "$TMPD/hl" 2>&1 || rc=$?
sed 's/^/      /' "$TMPD/hl"
[[ $rc -eq 0 ]] && pass tools.health_finance "health.php --finance exit 0" || fail tools.health_finance "health.php --finance exit $rc"

echo "== cron (dry: nothing is executed that changes data)"
if [[ -f $CRON_FILE ]]; then
  pass cron.file "$CRON_FILE present ($(stat -c '%a %U' "$CRON_FILE"))"
  grep -qE "^[^#].*cd ${APP_ROOT}/system && php cron.php" "$CRON_FILE" && pass cron.cron_php "cron.php runs from system/ (X-01)" || fail cron.cron_php "no 'cd $APP_ROOT/system && php cron.php' line"
  grep -qE '^[^#].*quota\.php enforce --loop=55 --every=5' "$CRON_FILE" && pass cron.quota "quota enforcer every minute (I6)" || fail cron.quota "no quota enforcer line"
  grep -qE '^[^#].*health\.php --json --finance' "$CRON_FILE" && pass cron.health_finance "hourly finance health (I4)" || fail cron.health_finance "no health --finance line"
  grep -qE '^[^#].*health\.php --json( |>)' "$CRON_FILE" && pass cron.health "health every 5 minutes (I3)" || fail cron.health "no health line"
  grep -qE '^[^#].*cron_reminder\.php' "$CRON_FILE" && pass cron.reminder "reminders daily (I2)" || fail cron.reminder "no cron_reminder line"
  grep -E '^[^#]' "$CRON_FILE" | grep -E ' www-data | '"$WEB_USER"' ' >/dev/null && pass cron.user "jobs run as $WEB_USER" || warn cron.user "jobs do not name $WEB_USER"
else
  fail cron.file "$CRON_FILE missing (checklist I)"
fi
for f in system/cron.php system/cron_reminder.php system/reseller/quota.php system/reseller/health.php; do
  "$PHP" -l "$REAL/$f" >/dev/null 2>&1 && pass cron.lint "$f parses" || fail cron.lint "$f does not parse"
done
( cd "$REAL/system" && [[ -f ../init.php ]] ) && pass cron.cwd "from system/, ../init.php resolves" || fail cron.cwd "../init.php not found from system/"
rc=0; ( cd "$REAL" && as_web "$PHP" system/reseller/quota.php report ) > "$TMPD/q" 2>&1 || rc=$?
[[ $rc -eq 0 ]] && pass cron.quota_report "quota.php report (read-only) exit 0" || fail cron.quota_report "quota.php report exit $rc: $(tail -2 "$TMPD/q" | tr '\n' ' ')"

echo
echo "post_install_verify=$([[ $FAILS -eq 0 ]] && echo PASS || echo FAIL) pass=$PASSES warn=$WARNS fail=$FAILS"
[[ $FAILS -eq 0 ]]
