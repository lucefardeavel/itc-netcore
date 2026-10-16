# UI / browser evidence (PWA lab, 127.0.0.1 only; tip after XSS-1 + customer a11y head fixes)

- `ui_audit.json`: `dev/wp10/ui_audit.py`, 130 page loads (desktop English 1366x800, phone Arabic RTL 390x844, tablet
  Arabic RTL 820x1180; roles sa / ad / A / B / A1 / rp + customer login). Staff pages: no horizontal overflow, Arabic
  pages `dir=rtl`, axe wcag2a/aa serious + critical = 0, no console errors except the expected 403 of the denial cases
  (A settings/app, A1 finance/overview; the Report role has no REPORT_READ / CUSTOMER_READ in the accepted WP2 model).
  Customer login page (customer portal: out of WP6C scope, OD-3): `lang` and pinch-zoom fixed in this round; open:
  colour contrast of the theme's default buttons / links, no RTL layout in Arabic (product scope, owner decision).
  Screenshots (130) are kept outside Git (no images in the repository).
- `pwa_history.json`: red-team row #15 back / forward after logout, online and offline, then the next user: PASS
  (positive control: A's data present before logout). A first run reported a leak that was a tool false positive: the
  marker "Staff A" matched the next user's own name "Staff A1" on its own dashboard; the markers are now word-bounded.
- `pwa_suite.out`: WP7 PWA browser suite 32 / 32 PASS (T00-T24) on the final templates (SRI, cookie / framing headers,
  escaped output).
