#!/usr/bin/env python3
"""Unsuppressed dependency-only diagnostic; FAIL remains FAIL on affected curl."""
import argparse
import hashlib
import json
import os
import pathlib
import shlex
import subprocess
import time

p = argparse.ArgumentParser()
p.add_argument('--output', required=True)
p.add_argument('--valgrind', default='valgrind')
p.add_argument('--debug-info')
a = p.parse_args()
root = pathlib.Path(__file__).resolve().parents[2]
out = pathlib.Path(a.output).resolve()
out.mkdir(parents=True, exist_ok=False)
rows = []
sha = lambda path: hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()

def run(name, command):
    start = time.monotonic()
    command = list(map(str, command))
    with (out / (name + '.log')).open('xb') as log:
        child = subprocess.Popen(command, cwd=root, stdout=log, stderr=subprocess.STDOUT)
        (out / 'active.json').write_text(json.dumps({'pid': child.pid, 'command': command, 'status': 'RUNNING'}))
        try:
            code = child.wait(timeout=60)
        except subprocess.TimeoutExpired:
            child.terminate(); child.wait(timeout=5); code = 124
    rows.append({'name': name, 'command': command, 'exit': code,
                 'seconds': time.monotonic()-start, 'logSha256': sha(out / (name+'.log'))})
    (out / 'active.json').write_text(json.dumps({'pid': child.pid, 'status': 'TERMINAL', 'exit': code}))
    return code

binary = out / 'curl-trailer-control'
if run('build', shlex.split(os.environ.get('CC', 'cc')) + [
    '-std=c11', '-Wall', '-Wextra', '-Werror', '-g', '-I.local/sysroot/usr/include',
    '-I.local/sysroot/usr/include/x86_64-linux-gnu', 'tests/http/curl_trailer_control.c',
    '-l:libcurl.so.4', '-o', binary]):
    raise SystemExit('Control build failed; inspect build.log')
with (out / 'peer.log').open('xb') as log:
    peer = subprocess.Popen(['python3', 'tests/http/transfer_geometry_server.py'], cwd=root,
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=log, text=True)
    (out / 'peer.json').write_text(json.dumps({'pid': peer.pid, 'status': 'RUNNING'}))
    try:
        port = json.loads(peer.stdout.readline())['port']
        for close in [0, 1]:
            command = [a.valgrind, '--leak-check=full', '--show-leak-kinds=all',
                       '--errors-for-leak-kinds=definite,indirect', '--error-exitcode=99']
            if a.debug_info:
                command += ['--extra-debuginfo-path=' + a.debug_info, '--keep-debuginfo=yes']
            run('reuse' if close == 0 else 'close-control', command +
                [binary, f'http://127.0.0.1:{port}/chunked/1', close])
    finally:
        peer.stdin.close(); peer.stdout.read(); code = peer.wait(timeout=5)
        (out / 'peer.json').write_text(json.dumps({'pid': peer.pid, 'status': 'TERMINAL', 'exit': code}))
report = {'status': 'PASS' if code == 0 and all(r['exit'] == 0 for r in rows) else 'FAIL',
          'binarySha256': sha(binary), 'sourceSha256': sha(root/'tests/http/curl_trailer_control.c'),
          'scope': 'No PHP/Asyncara linked. Close control does not replace reuse result.', 'commands': rows}
(out / 'report.json').write_text(json.dumps(report, indent=2)+'\n')
print(json.dumps(report, indent=2))
raise SystemExit(0 if report['status']=='PASS' else 1)
