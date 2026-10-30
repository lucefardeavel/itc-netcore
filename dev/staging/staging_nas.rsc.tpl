# RouterOS configuration TEMPLATE for the APPROVED STAGING NAS (TA-03). PRINTED FOR THE OPERATOR - this repository never connects to a router.
# Replace every <PLACEHOLDER>. The RADIUS secret is generated for the test, passed through a 0600 file / the console, never pasted into tickets or logs.
# FIRST capture the current values (section 0) so that section 9 can restore them. Only objects named nuxr_tgt_* are created or removed.

# 0. capture the prior state (save the output in the evidence, secrets removed)
/radius print detail
/radius incoming print
/ppp aaa print
/ip hotspot profile print detail
/system ntp client print
/system clock print
/system resource print
/snmp print
/snmp community print detail

# 1. RADIUS client: nasname in the application's `nas` table MUST equal src-address (NAS-IP-Address); no NAT between NAS and FreeRADIUS
/radius add service=ppp,hotspot address=<FREERADIUS_ADDRESS> secret=<STAGING_NAS_SECRET> src-address=<NAS_SOURCE_ADDRESS> authentication-port=1812 accounting-port=1813 timeout=3s require-message-auth=yes-for-request-resp comment=nuxr_tgt_radius
# 2. Disconnect / CoA from the application host only
/radius incoming set accept=yes port=3799
# (drop first, then the accept placed before it by name: no print-index dependence in an imported .rsc)
/ip firewall filter add chain=input protocol=udp dst-port=3799 action=drop comment=nuxr_tgt_coa_drop
/ip firewall filter add chain=input protocol=udp dst-port=3799 src-address=<APPLICATION_HOST_ADDRESS> action=accept comment=nuxr_tgt_coa place-before=[find comment=nuxr_tgt_coa_drop]
# RouterOS takes a CoA only from the address of a /radius entry: if <APPLICATION_HOST_ADDRESS> is not <FREERADIUS_ADDRESS>,
# add an entry for it with the same secret (it is never used for AAA because FreeRADIUS answers first):
# /radius add service=ppp,hotspot address=<APPLICATION_HOST_ADDRESS> secret=<STAGING_NAS_SECRET> src-address=<NAS_SOURCE_ADDRESS> require-message-auth=yes-for-request-resp comment=nuxr_tgt_radius_coa
# 3. PPP AAA: RADIUS accounting with interim; no local PPP secret or hotspot user may shadow a tgt_* identity
/ppp aaa set use-radius=yes accounting=yes interim-update=1m
# 4. PPPoE test server on the isolated interface / VLAN (no customers), profile WITHOUT a local address / pool override
/ppp profile add name=nuxr_tgt_pppoe change-tcp-mss=yes
/interface pppoe-server server add service-name=nuxr_tgt interface=<TEST_INTERFACE> default-profile=nuxr_tgt_pppoe disabled=no
# 5. Hotspot test server on the isolated interface (RADIUS login and accounting)
/ip hotspot profile add name=nuxr_tgt_hs use-radius=yes radius-accounting=yes radius-interim-update=received login-by=http-chap
# (create the hotspot server / address pool on <TEST_INTERFACE> as the target's standard procedure does; objects named nuxr_tgt_*)
# 6. NTP (TA-02): enabled, UTC or a known offset
/system ntp client set enabled=yes servers=<NTP_SERVER_1>,<NTP_SERVER_2>
# the NAS clock must stay within 10 s of the application host (hard limit); check: /system clock print vs date -u
/system clock set time-zone-name=UTC
# 7. Read-only SNMP for the session guard of NAS type `mikrotik_snmp` (FreeRADIUS -> NAS UDP 161; without it the release preflight
#    `nas.verification` FAILs). The community is generated for the test and goes to the guard credential file `${confdir}/nuxr/nas_snmp`
#    (docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md section 2), never into tickets or logs.
/snmp community add name=<STAGING_SNMP_COMMUNITY> addresses=<FREERADIUS_ADDRESS>/32 read-access=yes write-access=no comment=nuxr_tgt_snmp
/snmp set enabled=yes
/ip firewall filter add chain=input protocol=udp dst-port=161 action=drop comment=nuxr_tgt_snmp_drop
/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=<FREERADIUS_ADDRESS> action=accept comment=nuxr_tgt_snmp_fr place-before=[find comment=nuxr_tgt_snmp_drop]

# 9. RESTORE at the end (values captured in section 0): remove only nuxr_tgt_* objects and put /radius incoming, /ppp aaa and the NTP client back
/radius remove [find comment=nuxr_tgt_radius]
/radius remove [find comment=nuxr_tgt_radius_coa]
/ip firewall filter remove [find comment=nuxr_tgt_coa]
/ip firewall filter remove [find comment=nuxr_tgt_coa_drop]
/snmp community remove [find comment=nuxr_tgt_snmp]
/ip firewall filter remove [find comment=nuxr_tgt_snmp_fr]
/ip firewall filter remove [find comment=nuxr_tgt_snmp_drop]
/interface pppoe-server server remove [find service-name=nuxr_tgt]
/ppp profile remove [find name=nuxr_tgt_pppoe]
/ip hotspot profile remove [find name=nuxr_tgt_hs]
# /radius incoming set accept=<prior> port=<prior>
# /ppp aaa set use-radius=<prior> accounting=<prior> interim-update=<prior>
# /system ntp client set enabled=<prior> servers=<prior>
# /snmp set enabled=<prior>
# For case X-1 (two NAS), apply this template to the second staging NAS too, with its own secret, community and source address.
