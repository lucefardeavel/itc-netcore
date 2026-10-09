# Development/test only (WP4 harness and WP8 lab). The pinned FreeRADIUS 3.2.8 image plus the net-snmp command line
# tools that the PPPoE session guard (install/freeradius/bin/nuxr_pppoe_session_guard, WP8 OD-W8-16 Option G) runs:
# snmpget (health probe) and snmpbulkwalk (session table). The tools are part of the image, so a recreated container
# always has them (no package installation at container run time). Exact package version: a changed or withdrawn
# version fails the build visibly instead of changing the tools silently. Nothing else is added or changed; the stock
# checkrad stays exactly as shipped (its build-time SNMP program paths remain empty).
FROM freeradius/freeradius-server:3.2.8@sha256:af6fd34a5b7826b6245008732f43fbc0432eb23d23e5de7106371b2941e70a4b

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends snmp=5.9.1+dfsg-1ubuntu2.9 \
 && rm -rf /var/lib/apt/lists/* \
 && test -x /usr/bin/snmpget && test -x /usr/bin/snmpbulkwalk
