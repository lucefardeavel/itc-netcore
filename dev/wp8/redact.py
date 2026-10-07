#!/usr/bin/env python3
"""WP8 evidence redaction (lab tooling): stdin -> stdout. Replaces every value from $NUXR_WP8_STATE/secrets.env and
every secret-like attribute value (User-Password, Cleartext-Password, CHAP-Password, secret=, password=, community=)."""
import os, re, sys
STATE = os.environ.get('NUXR_WP8_STATE', '/var/lib/nuxr-wp8')
vals = [l.strip().split('=', 1)[1] for f in ('secrets.env', 'snmp.env') if os.path.exists(f'{STATE}/{f}')
        for l in open(f'{STATE}/{f}') if '=' in l and not l.startswith('#')]   # snmp.env: OD-W8-16 checkrad gate community
text = sys.stdin.read()
for v in sorted({v for v in vals if len(v) >= 6}, key=len, reverse=True):
    text = text.replace(v, '[REDACTED]')
text = re.sub(r'((?:User|Cleartext|CHAP)-Password\s*[:=]+\s*)("?)[^\s",}]+("?)', r'\1\2[REDACTED]\3', text)
text = re.sub(r'("?(?:secret|password|community)"?\s*[=:]\s*)("?)[^\s",}\[]+("?)', r'\1\2[REDACTED]\3', text, flags=re.I)
sys.stdout.write(text)
