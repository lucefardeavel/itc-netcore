# About this checklist

Tick every line **on the target** before go-live and after every significant change, and keep the evidence (command output and who checked it — never a secret). The rows come from `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`, `install/freeradius/README.md`, `docs/STAGING_DEPLOYMENT_PACKAGE.md` §3 and §5, `docs/V1_TARGET_ACCEPTANCE.md` (TA-10) and the source code. Items marked **[P]** are automated by `php system/reseller/preflight.php`.

> [!WARNING]
> Release Candidate, not yet production-accepted. TA-10 (security on the target) has not been run. The web-front rows W1–W6, W9 and W10 were checked in the lab by `install/fresh/nuxr-post-install-verify.sh` on a disposable instance with a self-signed certificate (MariaDB 10.11 and MySQL 8.0); a real certificate, proxy and target remain TA-10. This checklist does not replace the legal review (LIC-1) or a penetration test.

# 1. Network exposure

| # | Item | How to verify | ☐ |
|---|---|---|---|
| N1 | The database is reachable only from the application and FreeRADIUS hosts, never from the internet (checklist A7) | Firewall rules; a connection attempt from another host fails | ☐ |
| N2 | NAS to FreeRADIUS: UDP 1812/1813 only; no NAT between NAS and FreeRADIUS | Firewall rules; `radacct.nasipaddress = nas.nasname` | ☐ |
| N3 | Application to NAS: UDP 3799 (or `nas.ports`); on the NAS, accept only from the application host, then drop every other source | `/ip firewall filter print`; a CoA from another host is dropped | ☐ |
| N4 | FreeRADIUS to NAS: UDP 161 (SNMP read-only, for the session guard); accept only from the FreeRADIUS address | `/ip firewall filter print`; `/snmp community print` | ☐ |
| N5 | RouterOS API ports 8728 / 8729 **not opened** (not used by the release profile) | Port scan from the application network | ☐ |
| N6 | Operator SSH only from the administration network | Firewall rules | ☐ |
| N7 | NTP UDP 123 from every host to the internal NTP servers | `chronyc sources` | ☐ |

# 2. Web front and TLS

| # | Item | How to verify | ☐ |
|---|---|---|---|
| W1 | HTTPS only with a valid certificate; HTTP redirects to HTTPS; HSTS set by the web server | `curl -I http://…`, `curl -I https://…` | ☐ |
| W2 | `APP_URL` is the HTTPS address **[P stage]** | preflight `stage` | ☐ |
| W3 | Not reachable from the web: `config.php`, `system/` (except the routed entry), `install/`, `dev/`, `tests/`, `docs/`, `owner-review/`, `.git/`, `*.sql`, `*.sh` | `curl -I https://…/config.php` answers 403 / 404. The `install/fresh/` nginx example implements these deny rules and a PHP entry-point allowlist; `nuxr-post-install-verify.sh` checks 27 sensitive paths (each must answer 403 or 404) | ☐ |
| W4 | Web installer removed after installation (`nuxr-fresh-install.sh`, default `--installer=strip`: `install/*.php`, `*.sql`, `css`, `img` deleted). **`install/freeradius/` stays, read-only and blocked by the web server**: preflight `radius_checks` reads its expected CHECK expressions from it and FAILs when they are missing (G-1, fixed in `33624129`). `--installer=remove` is therefore not usable | `curl -I https://…/install/` and `…/install/freeradius/nuxr_admission.sql` answer 404; `ls -l <app-root>/install/freeradius` shows no write access for the PHP user | ☐ |
| W5 | Web updater disabled (`update.php` answers 403) | `curl -I https://…/update.php` | ☐ |
| W6 | Headers `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'`, `X-Content-Type-Options: nosniff`; session cookie `HttpOnly; SameSite=Lax; Secure` | `curl -I https://…/?_route=login` | ☐ |
| W7 | `display_errors = Off`; errors logged to a file outside the document root **[P stage]** | preflight `stage` | ☐ |
| W8 | Behind a TLS-terminating proxy: the backend sets `HTTPS=on` (nginx `fastcgi_param HTTPS on;`); never trust `X-Forwarded-Proto` unless the backend is reachable from the proxy alone; `APP_URL` pinned to `https://` (checklist B9) | Checks W1 / W6 through the proxy; preflight `stage.https` | ☐ |
| W9 | Login rate limiting at the HTTPS front for the staff and portal login routes. **The application has no built-in login throttling or lockout.** The `install/fresh/` nginx example includes a login-POST rate limit (lab: 12 bogus POSTs gave `302` ×6, then `429` ×6) | TA-10: a few failed logins only, observe the front's behaviour | ☐ |
| W10 | `ui/compiled/`, `system/uploads/`, `pages/` writable by the PHP user only; the code tree read-only for it | `ls -l` | ☐ |
| W11 | Web-server access logs protected: the public QR-code generator (`/qrcode/?data=…`) needs no session, and data placed in its query string (for example a voucher code) can reach the access log | Log file permissions; review who reads access logs | ☐ |

<!-- pagebreak -->

# 3. Application secrets (`config.php`, mode 0640, owner root:php-user)

| # | Item | How to verify | ☐ |
|---|---|---|---|
| S1 | `$app_secret`: 32+ random characters, unique to this instance **[P keys]** | preflight `keys` | ☐ |
| S2 | `$api_secret`: 32+ random characters, different from every other secret (or the API stays disabled) **[P keys]** | preflight `keys` | ☐ |
| S3 | `$db_pass` is the least-privilege application account, not root | preflight `grants` | ☐ |
| S4 | `$legacy_devices_enabled` absent or `false`; `$radius_rest_secret` absent (FreeRADIUS SQL only) **[P profile]** | preflight `profile` | ☐ |
| S5 | `$_app_stage = 'Live'` **[P stage]** | preflight `stage` | ☐ |
| S6 | Default `admin` password changed (enforced by the application) | First login | ☐ |
| S7 | No secret on a command line, in shell history, tickets, chat or logs | Review | ☐ |
| S8 | Secret-setting fields (SMTP password, Telegram bot, proxy auth, GitHub token, Tawk.to key) are write-only; an empty field keeps the stored value | Settings pages show no stored secret | ☐ |
| S9 | The installer env file (`nuxr-fresh-install.sh --generate-secrets`) was mode 0600 (0644 is refused); after the installation its secrets are moved to the secret store and the file is deleted | `ls -l`; secret-store record | ☐ |

# 4. Database accounts (least privilege)

| # | Item | How to verify | ☐ |
|---|---|---|---|
| D1 | Application account from `grants.php --app=…`: no global privileges; append-only (SELECT, INSERT) on the ledger, audit and activation-event tables; no DELETE on finance tables **[P grants]** | preflight `grants` | ☐ |
| D2 | FreeRADIUS account from `grants.php --radius=…`: SELECT on the policy tables; INSERT / UPDATE on `radacct` and the admission tables; nothing on the application tables | `SHOW GRANTS FOR …` | ☐ |
| D3 | Separate migration (DDL) account, used only by `migrate.php` in a maintenance window; its password not in `config.php` | Review | ☐ |
| D4 | No account can read another instance's database (Option A isolation) | `SHOW GRANTS` | ☐ |
| D5 | Only the FreeRADIUS and application accounts can read the `nas` table (it holds the NAS secrets) | `SHOW GRANTS` | ☐ |
| D6 | Each operator who releases admission claims has their own database account (no shared login); the audit records the database-attested account | `SHOW GRANTS`; audit sample | ☐ |
| D7 | MariaDB `check_constraint_checks = ON` globally; strict `sql_mode` **[P radius_checks]** | preflight `radius_checks` | ☐ |
| D8 | Backup and restore accounts created per `install/backup/backup.my.cnf.example` (least privilege for the backup account); `grants.php` generates no backup or monitoring account | `SHOW GRANTS` | ☐ |

# 5. FreeRADIUS

| # | Item | How to verify | ☐ |
|---|---|---|---|
| R1 | Shipped site and modules; `delete_stale_sessions = no`; `radpostauth` disabled (the stock query stores the cleartext password); EAP disabled or isolated | Configuration diff | ☐ |
| R2 | Only PAP and CHAP accepted by the virtual server | Configuration review | ☐ |
| R3 | Guard credential file `${confdir}/nuxr/nas_snmp` mode 0600 (or 0400), owner `freerad`; never in Git; communities never in argv; never stored in the NAS "Community" field of the admin UI | `ls -l`; `ps` | ☐ |
| R4 | Threaded under the service manager, never `-X` in production | `ps` | ☐ |
| R5 | Message-Authenticator required on every Access-Request | `install/freeradius/README.md` "Message-Authenticator" | ☐ |
| R6 | `rlm_rest` endpoint (`radius.php`) off (no `$radius_rest_secret`) | preflight `profile` | ☐ |

# 6. NAS (RouterOS)

| # | Item | How to verify | ☐ |
|---|---|---|---|
| A1 | Per-NAS RADIUS secret: unique, 32+ random characters, generated on the operator workstation, never reused across NAS or instances | Rotation log | ☐ |
| A2 | `require-message-auth=yes-for-request-resp`; RADIUS timeout 3 s | `/radius print detail` | ☐ |
| A3 | SNMP: `public` disabled; one read-only community restricted to the FreeRADIUS address; the community differs from the RADIUS secret | `/snmp community print` | ☐ |
| A4 | No local `/ppp secret` or hotspot users that shadow RADIUS identities | `/ppp secret print` | ☐ |
| A5 | NAS type `mikrotik_snmp` in the application **[P nas]** | preflight `nas.verification` | ☐ |
| A6 | NTP enabled from two internal servers by IP | `/system ntp client print` | ☐ |

# 7. Application and code

| # | Item | How to verify | ☐ |
|---|---|---|---|
| C1 | Only reviewed plugins installed; inventory matches the approved list (plugins are fully trusted code and can bypass the reseller authority) **[P plugins]** | `php system/reseller/plugins.php --expect=<inventory>`; preflight `--plugins-approved=…` | ☐ |
| C2 | No payment gateway installed unless reviewed and re-checked on the target | `system/paymentgateway/` listing | ☐ |
| C3 | Staff accounts: no shared logins; leavers disabled; at least two named SuperAdmins (the last active SuperAdmin is protected) — **Recommended practice** for the count | Settings > Administrator Users | ☐ |
| C4 | Customer impersonation by Admins is audited (`customer.impersonate`); review the audit periodically | Audit sample | ☐ |
| C5 | Web database restore refused on this installation (expected) | Settings database page | ☐ |
| C6 | R-HF-CARRY-01 (**OPEN, owner decision**): the scoped existence oracles (`autoload` PPPoE hints, `customers/add-post`) are closed on the final-prep branch, the static IP is validated and unique on save, and special IPv4 classes are refused (round 3, `b2f7837b`); **low** unauthenticated enumeration remains through the forgot-password / forgot-username and self-registration pages. **Recommended practice:** leave customer self-registration disabled unless the ISP needs it | Settings; owner decision record | ☐ |
| C7 | Refused customer creates are logged with the actor id in the PHP error log (`customer.create_identity_refused`, `identity.static_ip_taken`); there is no in-app audit trace and no rate limit for them. Review the error log for repeated refusals by one Agent or Sales user, including over API tokens | Error-log review | ☐ |

# 8. Backups and secret rotation

| # | Item | ☐ |
|---|---|---|
| B1 | Backups encrypted at rest and in transit (`install/backup/`: AES-256-CBC with PBKDF2, or `age` / `gpg`); access limited to the instance operators; never attached to tickets or chat | ☐ |
| B2 | `config.php` backed up separately and encrypted (the encrypted `secrets.tar` of each backup set) | ☐ |
| B6 | The backup encryption key is a 0600 file, never on a command line and never in the backup store; a sealed copy is kept **off the host**. With `age`, the database host holds only the public recipient | ☐ |
| B7 | Backup configuration and option files mode 0600 (the tools refuse group-writable or 0644 files, exit 78); the off-host copy keeps the files private and uses an encrypted transport | ☐ |
| B3 | NAS secret rotation at least yearly, and immediately after staff turnover, a suspected leak or a backup exposure (NAS runbook §3) | ☐ |
| B4 | `$app_secret` / `$api_secret` rotation procedure known (rotation invalidates sessions / API tokens) | ☐ |
| B5 | Rotation log: date, NAS or secret name, operator — never the secret | ☐ |

# 9. Incident triggers

- `reconcile.php finance-check` exit 4 or health `finance` CRITICAL: a write outside the finance services. Treat as a security incident.
- `preflight.php` `grants` FAIL: the runtime account has more than least privilege.
- `plugins` FAIL: unknown or modified plugin code.
- Unexpected staff accounts, unexplained finance corrections, or secrets found in logs.

Escalation: `change_management_escalation`.

# 10. Sign-off

| Section | Checked by | Date | Evidence reference |
|---|---|---|---|
| 1 Network | | | |
| 2 Web / TLS | | | |
| 3 Secrets | | | |
| 4 Database | | | |
| 5 FreeRADIUS | | | |
| 6 NAS | | | |
| 7 Application | | | |
| 8 Backups / rotation | | | |
