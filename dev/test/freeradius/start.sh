#!/bin/sh
# Development/test only: starts FreeRADIUS 3.2 with the project configuration (install/freeradius) against
# the disposable test database $NUXR_RADIUS_DB_HOST / nuxr_radius. No published ports.
set -eu
R=/etc/freeradius
cp /nuxr/install/dictionary.phpnuxbill "$R/dictionary.phpnuxbill"
grep -q 'dictionary.phpnuxbill' "$R/dictionary" || echo '$INCLUDE dictionary.phpnuxbill' >> "$R/dictionary"
cp /nuxr/install/mods-available/nuxr_counters "$R/mods-enabled/nuxr_counters"
cp /nuxr/test/sql "$R/mods-enabled/sql"
rm -f "$R"/sites-enabled/* "$R/mods-enabled/eap"
cp /nuxr/install/sites-available/phpnuxbill "$R/sites-enabled/phpnuxbill"
cp /nuxr/test/clients.conf "$R/clients.conf"
# WP8 A1 - PPPoE session guard (OD-W8-16 Option G), installed exactly as install/freeradius/README.md describes.
mkdir -p "$R/nuxr"; chmod 0755 "$R/nuxr"
install -m 0755 /nuxr/install/bin/nuxr_pppoe_session_guard "$R/nuxr/nuxr_pppoe_session_guard"
cp /nuxr/install/mods-available/nuxr_pppoe_session_guard "$R/mods-enabled/nuxr_pppoe_session_guard"
# WP8 H-2B - Hotspot slot verifier, installed exactly as install/freeradius/README.md describes (same credential file)
install -m 0755 /nuxr/install/bin/nuxr_hotspot_slot_verifier "$R/nuxr/nuxr_hotspot_slot_verifier"
cp /nuxr/install/mods-available/nuxr_hotspot_slot_verifier "$R/mods-enabled/nuxr_hotspot_slot_verifier"
# Test credential file: one entry for the TEST-NET NAS 192.0.2.10 (never reachable, see clients.conf) with a random
# per-start community (not a secret: nothing answers it). 192.0.2.11 deliberately has no entry.
(umask 077; printf '192.0.2.10 nuxrtest%s\n' "$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')" > "$R/nuxr/nas_snmp")
chown freerad:freerad "$R/nuxr/nas_snmp"; chmod 0600 "$R/nuxr/nas_snmp"
# fail fast instead of a half-configured server
test -x "$R/nuxr/nuxr_pppoe_session_guard"
test -x /usr/bin/snmpget
test -x /usr/bin/snmpbulkwalk
grep -q '^[[:space:]]*nuxr_pppoe_session_guard {' "$R/sites-enabled/phpnuxbill"
test -x "$R/nuxr/nuxr_hotspot_slot_verifier"
grep -q '^[[:space:]]*nuxr_hotspot_slot_verifier {' "$R/sites-enabled/phpnuxbill"
grep -q 'delete_stale_sessions = no' "$R/mods-enabled/sql"
exec freeradius -f -l stdout
