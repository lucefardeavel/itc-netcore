#!/usr/bin/env python3
"""Minimal RouterOS API client (plain API, TCP 8728) - WP8 LAB TOOLING ONLY, used to configure and observe the lab
CHR. The application's own RouterOS compatibility evidence uses the vendored PEAR2\\Net\\RouterOS client
(dev/wp8/api_probe.php, tests/Integration/Wp8).

CLI: rosapi.py HOST PORT USER PASSFILE|- WORD [WORD...]   -> JSON {login_s, cmd_s, reply:[[type, {k: v}]]}"""
import hashlib, json, socket, sys, time


class Ros:
    def __init__(self, host, port=8728, timeout=30.0):
        self.s = socket.create_connection((host, port), timeout=timeout)

    @staticmethod
    def _wlen(n):
        if n < 0x80: return bytes([n])
        if n < 0x4000: return (n | 0x8000).to_bytes(2, 'big')
        if n < 0x200000: return (n | 0xC00000).to_bytes(3, 'big')
        if n < 0x10000000: return (n | 0xE0000000).to_bytes(4, 'big')
        return b'\xF0' + n.to_bytes(4, 'big')

    def _read(self, n):
        out = b''
        while len(out) < n:
            c = self.s.recv(n - len(out))
            if not c: raise ConnectionError('socket closed')
            out += c
        return out

    def _rlen(self):
        b = self._read(1)[0]
        if b < 0x80: return b
        if b < 0xC0: return ((b & 0x3F) << 8) | self._read(1)[0]
        if b < 0xE0: return ((b & 0x1F) << 16) | int.from_bytes(self._read(2), 'big')
        if b < 0xF0: return ((b & 0x0F) << 24) | int.from_bytes(self._read(3), 'big')
        return int.from_bytes(self._read(4), 'big')

    def talk(self, words):
        """one sentence -> list of (reply, attrs) up to and including !done / !fatal"""
        self.s.sendall(b''.join(self._wlen(len(w.encode())) + w.encode() for w in words) + b'\x00')
        out = []
        while True:
            words_in = []
            while True:
                n = self._rlen()
                if n == 0: break
                words_in.append(self._read(n).decode('utf-8', 'replace'))
            if not words_in: continue
            attrs = {}
            for w in words_in[1:]:
                if w.startswith('='):
                    k, _, v = w[1:].partition('='); attrs[k] = v
                elif w.startswith('.'):
                    k, _, v = w[1:].partition('='); attrs['.' + k] = v
            out.append((words_in[0], attrs))
            if words_in[0] in ('!done', '!fatal'): return out

    def rows(self, words):
        r = self.talk(words)
        traps = [a for k, a in r if k == '!trap']
        if traps: raise RuntimeError(f'{words[0]}: {traps}')
        return [a for k, a in r if k == '!re']

    def login(self, user, pw):
        r = self.talk(['/login', '=name=' + user, '=password=' + pw])
        if r and r[-1][0] == '!done' and 'ret' in r[-1][1]:  # RouterOS < 6.43 challenge login
            chal = bytes.fromhex(r[-1][1]['ret'])
            r = self.talk(['/login', '=name=' + user, '=response=00' + hashlib.md5(b'\x00' + pw.encode() + chal).hexdigest()])
        if any(k == '!trap' for k, _ in r): raise PermissionError('RouterOS login refused')
        return self

    def close(self):
        self.s.close()


if __name__ == '__main__':
    host, port, user, pwfile = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
    pw = '' if pwfile == '-' else open(pwfile).read().strip()
    t0 = time.time(); ros = Ros(host, port).login(user, pw); t1 = time.time(); res = ros.talk(sys.argv[5:]); t2 = time.time()
    print(json.dumps({'login_s': round(t1 - t0, 3), 'cmd_s': round(t2 - t1, 3), 'reply': res}, indent=1))
