#!/usr/bin/env python3
"""WP8 A2-R FEASIBILITY GATE G-SQL / G-ID at the SQL level, on BOTH lab engines (MariaDB 10.11 and MySQL 8.0 of the
disposable compose project nuxbill-reseller-test). Lab tooling only: it creates and drops the throwaway database
`a2r_sqlgate` on each engine (install/radius.sql + the PRODUCTION upgrade install/freeradius/nuxr_admission.sql, checked with nuxr_admission_check.sql) and
never touches nuxr_radius or any other database. The MySQL container must be running (dev/wp8/a2r_gate.sh starts it).

  a2r_sql_gate.py run      -> JSON summary on stdout, evidence appended to $NUXR_WP8_STATE/evidence/a2r.jsonl"""
import json, os, subprocess, sys, time
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(f'{HERE}/../..')
STATE = os.environ.get('NUXR_WP8_STATE', '/var/lib/nuxr-wp8')
ENGINES = {'mariadb-10.11': ('nuxbill-reseller-test-mariadb-1', 'mariadb', 'MARIADB_ROOT_PASSWORD'),
           'mysql-8.0': ('nuxbill-reseller-test-mysql-1', 'mysql', 'MYSQL_ROOT_PASSWORD')}
DBN = 'a2r_sqlgate'
EVID = f'{STATE}/evidence/a2r-impl.jsonl'
N = 20


def run(engine, q, db=DBN, check=False):
    c, cli, pw = ENGINES[engine]
    p = subprocess.run(['docker', 'exec', '-i', c, 'sh', '-c', f'exec {cli} -uroot -p"${pw}" -N -B {db}'], input=q, capture_output=True, text=True, timeout=300)
    if check and p.returncode != 0:
        raise RuntimeError(f'{engine}: {p.stderr.strip()[-300:]}')
    return p.stdout.strip(), p.stderr.replace('Warning: Using a password on the command line interface can be insecure.', '').strip()


def parallel(engine, stmts):
    """each statement in its own client session, released together at an absolute time (server-side SLEEP barrier);
    returns per session: ROW_COUNT() or the error line"""
    c, cli, pw = ENGINES[engine]
    t = time.time() + 2.0
    script = 'rm -f /tmp/a2r.*\n'
    for i, s in enumerate(stmts):
        q = f"DO SLEEP(GREATEST(0, {t:.3f} - UNIX_TIMESTAMP(NOW(6)))); {s}; SELECT ROW_COUNT();"
        script += f'( {cli} -uroot -p"${pw}" -N -B {DBN} -e "{q}" > /tmp/a2r.{i} 2>&1 ) &\n'
    script += 'wait\nfor f in /tmp/a2r.*; do echo "$(basename $f) $(grep -v "Using a password" $f | tail -1)"; done\nrm -f /tmp/a2r.*\n'
    p = subprocess.run(['docker', 'exec', '-i', c, 'bash', '-s'], input=script, capture_output=True, text=True, timeout=120)
    out = {}
    for l in p.stdout.splitlines():
        k, _, v = l.partition(' ')
        out[int(k.split('.')[1])] = v
    return [out.get(i) for i in range(len(stmts))]


def ins(user_hex, tok, asid='81000001', state='PENDING'):
    return (f"INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at) "
            f"VALUES (UNHEX('{user_hex}'), '{state}', '{tok}', '10.88.0.2', '{asid}', '02:00:00:00:00:01', 'ether3', '{tok}', UNHEX('{user_hex}'), 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")


def tok(i, r):
    return f'{r:08x}{i:024x}'


def setup(engine):
    run(engine, f'DROP DATABASE IF EXISTS {DBN}; CREATE DATABASE {DBN} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', db='', check=True)
    run(engine, open(f'{REPO}/install/radius.sql').read(), check=True)
    run(engine, open(f'{REPO}/install/freeradius/nuxr_admission.sql').read(), check=True)
    assert run(engine, open(f'{REPO}/install/freeradius/nuxr_admission_check.sql').read())[0].splitlines()[-1] == 'nuxr_admission=READY'


def gate(engine):
    res = {'version': run(engine, 'SELECT VERSION()', db='')[0], 'sql_mode': run(engine, 'SELECT @@GLOBAL.sql_mode', db='')[0],
           'tx_isolation': run(engine, 'SELECT @@GLOBAL.transaction_isolation', db='')[0],
           'collation_connection_default': run(engine, 'SELECT @@GLOBAL.collation_connection, @@GLOBAL.character_set_server, @@GLOBAL.collation_server', db='')[0]}
    setup(engine)
    u = 'nuxr_a2r_sql'.encode().hex().upper()
    # 1. atomic INSERT on the primary key: 20 sessions x 30 rounds -> exactly one row count 1 per round, the rest 1062
    r1 = []
    for r in range(30):
        run(engine, 'DELETE FROM nuxr_admission')
        out = parallel(engine, [ins(u, tok(i, r), asid=f'8100{i:04x}') for i in range(N)])
        r1.append({'ok': sum(1 for x in out if x == '1'), 'dup1062': sum(1 for x in out if x and '1062' in x), 'other': [x for x in out if x != '1' and not (x and '1062' in x)][:3],
                   'rows': run(engine, 'SELECT COUNT(*) FROM nuxr_admission')[0]})
    res['1_insert_race'] = {'rounds': len(r1), 'exactly_one_winner_every_round': all(x['ok'] == 1 and x['dup1062'] == N - 1 and x['rows'] == '1' for x in r1), 'sample': r1[:2]}
    # 2. conditional take-over of an ENDED claim (state + previous token): exactly one winner per round
    r2 = []
    for r in range(30):
        run(engine, 'DELETE FROM nuxr_admission; ' + ins(u, tok(999, r), state='ENDED'))
        out = parallel(engine, [f"UPDATE nuxr_admission SET prev_token = token, prev_state = state, state = 'PENDING', token = '{tok(i, r + 100)}', updated_at = UTC_TIMESTAMP(6) "
                                f"WHERE username = UNHEX('{u}') AND state = 'ENDED' AND token = '{tok(999, r)}'" for i in range(N)])
        r2.append({'ok': out.count('1'), 'zero': out.count('0'), 'other': [x for x in out if x not in ('0', '1')][:3]})
    res['2_cas_takeover_race'] = {'exactly_one_winner_every_round': all(x['ok'] == 1 and x['zero'] == N - 1 for x in r2), 'sample': r2[:2]}
    # 3. attach (same attempt) racing the creator's conditional release (attach_count = 0): never both
    r3 = []
    for r in range(30):
        run(engine, 'DELETE FROM nuxr_admission; ' + ins(u, tok(7, r)))
        st = [f"UPDATE nuxr_admission SET attach_count = attach_count + 1 WHERE username = UNHEX('{u}') AND state = 'PENDING' AND token = '{tok(7, r)}'" for _ in range(N - 1)]
        st.append(f"UPDATE nuxr_admission SET state = 'ENDED', end_reason = 'pre_accept_failure' WHERE username = UNHEX('{u}') AND state = 'PENDING' AND token = '{tok(7, r)}' AND attach_count = 0")
        out = parallel(engine, st)
        fin = run(engine, 'SELECT state, attach_count FROM nuxr_admission')[0].split('\t')
        att = sum(1 for x in out[:-1] if x == '1')
        rel = out[-1]
        r3.append({'release': rel, 'attached': att, 'final': fin, 'consistent': (rel == '1' and att == 0 and fin == ['ENDED', '0']) or (rel == '0' and fin == ['PENDING', str(att)] and att >= 1)})
    res['3_attach_vs_release'] = {'never_released_after_attach': all(x['consistent'] for x in r3), 'release_won': sum(1 for x in r3 if x['release'] == '1'), 'attach_won': sum(1 for x in r3 if x['release'] == '0'), 'sample': r3[:3]}
    # 4. errors versus empty results (the sentinel format)
    run(engine, 'DELETE FROM nuxr_admission')
    res['4_sentinel'] = {
        'no_row_plain_select': run(engine, f"SELECT state FROM nuxr_admission WHERE username = UNHEX('{u}')"),
        'no_row_sentinel': run(engine, f"SELECT CONCAT('r=', COALESCE((SELECT state FROM nuxr_admission WHERE username = UNHEX('{u}')), 'none'))"),
        'missing_table': run(engine, "SELECT CONCAT('r=', COALESCE((SELECT state FROM nuxr_admission_missing WHERE username = 'x'), 'none'))"),
        'duplicate_key_plain_insert': run(engine, ins(u, tok(1, 1)) + '; ' + ins(u, tok(2, 1)) + '; SELECT ROW_COUNT()'),
    }
    # 5. INSERT IGNORE hides failures (duplicate -> 0 rows, over-long key -> silently TRUNCATED row) -> plain INSERT
    run(engine, 'DELETE FROM nuxr_admission')
    longu = ('x' * 70).encode().hex().upper()
    res['5_insert_ignore'] = {
        'duplicate': run(engine, ins(u, tok(3, 3)) + '; ' + ins(u, tok(4, 3)).replace('INSERT INTO', 'INSERT IGNORE INTO') + '; SELECT ROW_COUNT(); SHOW WARNINGS'),
        'overlong_plain': run(engine, ins(longu, tok(5, 3)) + '; SELECT ROW_COUNT()'),
        'overlong_ignore': run(engine, ins(longu, tok(6, 3)).replace('INSERT INTO', 'INSERT IGNORE INTO') + '; SELECT ROW_COUNT(); SELECT CHAR_LENGTH(username) FROM nuxr_admission WHERE token = \'' + tok(6, 3) + '\''),
    }
    # 6. UTC: DATETIME from UTC_TIMESTAMP(6) does not depend on the session time zone (NOW(6) does)
    res['6_utc'] = {'global_tz': run(engine, 'SELECT @@GLOBAL.time_zone, @@system_time_zone')[0],
                    'session_plus3': run(engine, "SET time_zone = '+03:00'; SELECT UTC_TIMESTAMP(6), NOW(6), TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), NOW(6))")[0],
                    'session_utc': run(engine, "SET time_zone = '+00:00'; SELECT UTC_TIMESTAMP(6), NOW(6)")[0],
                    'host_utc': time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime())}
    # 7. identity: which spellings the radcheck lookup (utf8mb4_general_ci) equates, and the canonical key per spelling
    bases = ['nuxr_a2r_idcase', 'Nuxr_A2R_Mixed', "nuxr.a2r-o'neil", 'nuxr_a2r_café', 'nuxr_a2r_straße', 'nuxr a2r space']
    run(engine, 'DELETE FROM radcheck')
    for b in bases:
        run(engine, f"INSERT INTO radcheck (username, attribute, op, value) VALUES (CONVERT(X'{b.encode().hex()}' USING utf8mb4), 'Cleartext-Password', ':=', 'x')")
    idres = []
    for b in bases:
        vs = sorted({b, b.upper(), b.lower(), b + ' ', ' ' + b, b.replace('é', 'e'), b.replace('é', 'E'), b.replace('é', 'é'), b.replace('ß', 'ss'), b.replace('ß', 's'),
                     b.replace('a', 'á', 1), b.replace('a', 'à', 1), b.replace('i', 'ı', 1), b.replace('_', '＿')})
        row = []
        for v in vs:
            # the literal as FreeRADIUS sends it: a plain utf8mb4 string literal (coercible), so the COLUMN collation
            # (utf8mb4_general_ci from install/radius.sql) decides on both engines
            o, e = run(engine, f"SET NAMES utf8mb4; SELECT CONCAT('k=', COALESCE((SELECT HEX(username) FROM radcheck WHERE username = _utf8mb4 X'{v.encode().hex()}' ORDER BY CAST(username AS BINARY) LIMIT 1), '-'))")
            e = '\n'.join(l for l in e.splitlines() if 'Using a password' not in l)
            ok = o.startswith('k=')
            row.append({'variant': v, 'error': None if ok else (e[-120:] or 'no output'), 'matches': ok and o != 'k=-', 'canonical_hex': o[2:] if ok and o != 'k=-' else None})
        idres.append({'base': b, 'matching_spellings': [x['variant'] for x in row if x['matches']], 'distinct_canonical_keys': len({x['canonical_hex'] for x in row if x['matches']}),
                      'non_matching': [x['variant'] for x in row if not x['matches'] and not x['error']], 'errors': [(x['variant'], x['error']) for x in row if x['error']]})
    # the binary PRIMARY KEY never merges two different canonical names, and the canonical key of every equivalent
    # spelling is the same row
    res['7_identity'] = {'per_base': idres, 'one_key_per_subscriber': all(x['distinct_canonical_keys'] == 1 for x in idres), 'errors': sum(len(x['errors']) for x in idres)}
    # 8. affected rows with and without CLIENT_FOUND_ROWS (rlm_sql_mysql) - through PDO
    res['8_found_rows'] = found_rows(engine)
    run(engine, f'DROP DATABASE IF EXISTS {DBN}', db='')
    res['pass'] = (res['1_insert_race']['exactly_one_winner_every_round'] and res['2_cas_takeover_race']['exactly_one_winner_every_round']
                   and res['3_attach_vs_release']['never_released_after_attach'] and res['7_identity']['one_key_per_subscriber'] and res['7_identity']['errors'] == 0)
    return res


def found_rows(engine):
    host = 'mariadb' if engine.startswith('mariadb') else 'mysql'
    php = r'''
$out = [];
foreach ([false, true] as $fr) {
    $pdo = new PDO("mysql:host=HOST;dbname=a2r_sqlgate;charset=utf8mb4", "root", getenv("WP1_DB_ROOT_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => $fr]);
    $pdo->exec("DELETE FROM nuxr_admission");
    $pdo->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at) VALUES ('fr', 'PENDING', REPEAT('a', 32), '10.88.0.2', '1', 'm', 'p', REPEAT('b', 32), 'fr', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
    $same = $pdo->exec("UPDATE nuxr_admission SET state = 'PENDING' WHERE username = 'fr' AND state = 'PENDING'");
    $attach = $pdo->exec("UPDATE nuxr_admission SET attach_count = attach_count + 1, updated_at = UTC_TIMESTAMP(6) WHERE username = 'fr' AND state = 'PENDING'");
    $miss = $pdo->exec("UPDATE nuxr_admission SET state = 'ENDED' WHERE username = 'fr' AND state = 'ACTIVE'");
    $out[$fr ? 'found_rows' : 'changed_rows'] = ['update_matching_no_change' => $same, 'update_attach_changes' => $attach, 'update_no_match' => $miss];
}
echo json_encode($out);'''.replace('HOST', host)
    p = subprocess.run(['docker', 'run', '--rm', '-i', '--network', 'nuxbill-reseller-test_default', '-e', 'WP1_DB_ROOT_PASSWORD', 'nuxbill-reseller-test-php82', 'php'],
                       input='<?php ' + php, capture_output=True, text=True, timeout=120, env={**os.environ, 'WP1_DB_ROOT_PASSWORD': SEC['WP1_DB_ROOT_PASSWORD']})
    try:
        return json.loads(p.stdout)
    except Exception:
        return {'error': (p.stdout + p.stderr)[-300:]}


SEC = dict(l.strip().split('=', 1) for l in open(f'{STATE}/secrets.env') if '=' in l and not l.startswith('#'))

if __name__ == '__main__':
    if sys.argv[1:] != ['run']:
        print(__doc__)
        sys.exit(2)
    out = {e: gate(e) for e in ENGINES}
    line = json.dumps({'t': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'case': 'G-SQL.engines', 'verdict': {e: out[e]['pass'] for e in out}, 'detail': out}, default=str)
    for v in sorted({v for v in SEC.values() if len(v) >= 6}, key=len, reverse=True):
        line = line.replace(v, '[REDACTED]')
    with open(EVID, 'a') as f:
        f.write(line + '\n')
    print(json.dumps({e: {k: (v if k in ('pass', 'version', 'sql_mode') else '...') for k, v in out[e].items()} for e in out}, indent=1))
