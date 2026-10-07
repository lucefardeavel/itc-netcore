#!/bin/sh
# WP8 lab FreeRADIUS (development/test only): the project configuration from install/freeradius with the NAS clients
# loaded from the PHPNuxBill `nas` table (read_clients = yes, client_table = nas) - the production arrangement - and
# only a static localhost client in clients.conf. Message-Authenticator enforcement comes from the project virtual
# server itself (install/freeradius/sites-available/phpnuxbill, owner decision OD-W8-13); nothing here relaxes or
# tightens it. Runs in debug mode (-X) because the lab evidence needs the request handling; the container log is
# ephemeral and evidence excerpts are redacted (dev/wp8/redact.py).
set -eu
R=/etc/freeradius
cp /nuxr/install/dictionary.phpnuxbill "$R/dictionary.phpnuxbill"
grep -q 'dictionary.phpnuxbill' "$R/dictionary" || echo '$INCLUDE dictionary.phpnuxbill' >> "$R/dictionary"
cp /nuxr/install/mods-available/nuxr_counters "$R/mods-enabled/nuxr_counters"
sed -e 's/read_clients = no/read_clients = yes/' /nuxr/test/sql > "$R/mods-enabled/sql"
grep -q 'read_clients = yes' "$R/mods-enabled/sql"
rm -f "$R"/sites-enabled/* "$R/mods-enabled/eap"
cp /nuxr/install/sites-available/phpnuxbill "$R/sites-enabled/phpnuxbill"
printf 'client localhost {\n\tipaddr = 127.0.0.1\n\tsecret = $ENV{NUXR_RADIUS_SECRET}\n\trequire_message_authenticator = yes\n}\n' > "$R/clients.conf"
# OD-W8-16 Option F checkrad GATE (lab only, off unless dev/wp8/checkrad_gate.py created the marker inside this
# disposable container): Simultaneous-Use verification by the STOCK FreeRADIUS 3.2.8 checkrad (nas.type
# mikrotik_snmp, community from naspasswd), with delete_stale_sessions = no so a stale radacct row is never zapped.
# The image's checkrad was built without net-snmp, so autoconf left its two program paths empty ($snmpget = "",
# $snmpwalk = ""); the lab copy sets exactly those two build-time paths and nothing else. A trace wrapper records
# argv (no community) and the exit code of every checkrad run for the evidence; it never changes the result.
if [ -f "$R/wp8-checkrad-gate" ]; then
	command -v snmpwalk >/dev/null || echo "[wp8] checkrad gate: snmpwalk NOT installed"
	sed -i -e 's/delete_stale_sessions = yes/delete_stale_sessions = no/' "$R/mods-enabled/sql"
	grep -q 'delete_stale_sessions = no' "$R/mods-enabled/sql"
	sed -e 's|^\$snmpget\t= "";|$snmpget\t= "/usr/bin/snmpget";|' -e 's|^\$snmpwalk\t= "";|$snmpwalk\t= "/usr/bin/snmpwalk";|' /usr/sbin/checkrad > "$R/wp8-checkrad"
	install -m 0755 /nuxr/wp8/checkrad-trace.sh "$R/wp8-checkrad-trace"
	chmod 0755 "$R/wp8-checkrad"
	sed -i -e "s|^checkrad = .*|checkrad = $R/wp8-checkrad-trace|" "$R/radiusd.conf"
	echo "[wp8] checkrad gate: $(grep -E '^checkrad = ' "$R/radiusd.conf") $(grep -E 'delete_stale_sessions' "$R/mods-enabled/sql" | tr -s '\t ' ' ') checkrad-diff-lines=$(diff /usr/sbin/checkrad "$R/wp8-checkrad" | grep -c '^[<>]') naspasswd=$(stat -c '%a %U' "$R/naspasswd" 2>/dev/null || echo missing)"
fi
echo "[wp8] TZ=${TZ:-unset} sql: $(grep -E 'read_clients|client_table' "$R/mods-enabled/sql" | tr -s '\t ' ' ' | tr '\n' ' ')"
echo "[wp8] global: $(sed -n '/^security {/,/^}/p' "$R/radiusd.conf" | grep -E '^\s*require_message_authenticator' | tr -s '\t ' ' ')"
exec freeradius -X
