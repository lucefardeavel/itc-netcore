# ITC NetCore product guide (tooling)

Builds the illustrated Arabic guide to every part of ITC NetCore: 23 chapters, 111 screenshots taken from the running system.
The output is a web page plus two PDFs: one at full quality, and one light (JPEG) for sending. Owner requests 2026-10-18.

- Published copy: the private Artifact https://claude.ai/artifact/8JQ8xt6D5y8cc1V5XjWPDy.
- The guide's own text carries no upstream-credit or test-environment wording (owner request). The product keeps its credits (footer, About, `NOTICE`, `LICENSE`).
- Images and PDFs are build output. They are **not** committed.

## Run

```bash
dev/guide/build.sh                 # output in $GUIDE_WORK (default /tmp/itc-guide)
```

The build runs only in the isolated test lab (`nuxbill-reseller-test` compose project, `dev/pwa-lab/lab.sh`), on synthetic data, and never touches production. It needs Docker, plus Python 3 with Playwright (Chromium) and Pillow.

## Steps (each file)

| File | Step |
|---|---|
| `demo_prep.sh` | Prepares the lab copy for display (database of the lab only). <br>• Arabic display names for staff and subscribers (`demo_names.sql`) and clear staff usernames (`demo_usernames.sql`). <br>• USD currency, Baghdad time zone, Arabic default language, balance and coupons enabled. <br>• Removes the WP2 probe plugin, device and seed rows. <br>• Arabic texts for the announcement, registration and voucher pages. <br>• Writes `staff.json` (lab passwords) to `$GUIDE_WORK` only. |
| `demo_flows.py` | Creates the activity **through the application's own screens**, capturing the form shots (`$GUIDE_WORK/flows`). <br>• Activations by an agent, a sub-agent and a sales rep. <br>• A 50 GB quota plan, its pricing and reseller commerce. <br>• Provider funding; a NAS with the documentation address `192.0.2.10`. <br>• Six vouchers; a plan change; an agent-to-sub-agent transfer. |
| `screens.json` + `shots.py` | The screens to capture (desktop 1440 × 900, phone 390 × 844, Arabic and English, staff roles and the customer portal). Each image ends above the application footer. |
| `build_images.py` | PNG → WebP plus `dims.json`; cuts the footer off the form shots. |
| `gen.py` + `style.css.part` | The guide's content (chapters, captions, numbered steps) → `booklet/index.html`. |
| `export_pdf.py` | Exports `ITC-NetCore-Guide.pdf` (full quality) and `ITC-NetCore-Guide-lite.pdf` (about 5 MB). |

To publish a new version, publish `booklet/index.html` with `booklet/img/*` to the same Artifact URL.

## Facts the content relies on (verified on the system, 2026-10-18)

- Staff toggle the language from their personal menu; customers choose it at the top of the portal.
- Reseller-owned customers are always activated through the activation screen. The legacy recharge / balance / coupon paths serve direct customers.
- Backup restore is done at the database level (the web restore is disabled on reseller installations).
- In this release the network is driven through RADIUS; direct MikroTik API drivers are disabled in the release profile.
