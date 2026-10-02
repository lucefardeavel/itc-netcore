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
exec freeradius -f -l stdout
