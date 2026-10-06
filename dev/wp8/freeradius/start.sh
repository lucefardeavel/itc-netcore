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
echo "[wp8] TZ=${TZ:-unset} sql: $(grep -E 'read_clients|client_table' "$R/mods-enabled/sql" | tr -s '\t ' ' ' | tr '\n' ' ')"
echo "[wp8] global: $(sed -n '/^security {/,/^}/p' "$R/radiusd.conf" | grep -E '^\s*require_message_authenticator' | tr -s '\t ' ' ')"
exec freeradius -X
