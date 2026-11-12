#!/usr/bin/env python3
"""Bounded native accept retry loop; use accept_fault.py for real PHP/kernel I/O."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import resource
import shlex
import subprocess
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--library', required=True)
parser.add_argument('--out', required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
os.chdir(root)
out = Path(args.out).resolve()
out.mkdir(parents=True, exist_ok=False)
resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
sha = lambda path: hashlib.sha256(Path(path).read_bytes()).hexdigest()
codec = json.loads(subprocess.check_output(['python3', 'scripts/hyper-codecs.py']))
binary = out / 'accept-budget'
command = shlex.split(os.environ.get('CC', 'cc')) + [
    '-std=c11', '-D_GNU_SOURCE', '-DHAVE_ASYNCARA_HYPER=1', '-O2', '-g',
    '-Wall', '-Wextra', '-Werror', '-I.local/sysroot/usr/include',
    '-I.local/sysroot/usr/include/x86_64-linux-gnu'] + codec['cflags'] + [
    'tests/server/accept_budget.c', 'ext/backend.c', 'ext/workers.c', 'ext/memory.c',
    'ext/hyper_decode.c', str(Path(args.library).resolve()), '-pthread',
    '-l:libcurl.so.4', '-l:libsqlite3.so.0', '-ldl', '-lm', '-lz', '-Wl,--wrap=accept4',
] + codec['libs'] + shlex.split(os.environ.get('EXTRA_CFLAGS', '')) + shlex.split(
    os.environ.get('EXTRA_LDFLAGS', '')) + ['-o', str(binary)]
report = {'librarySha256': sha(args.library), 'commands': [], 'status': 'RUNNING'}
for name, cmd in [('build', command), ('run', [str(binary)])]:
    start = time.monotonic()
    with (out / (name+'.log')).open('x') as log:
        result = subprocess.run(cmd, stdout=log, stderr=log, timeout=120)
    report['commands'].append({'name': name, 'command': cmd, 'exit': result.returncode,
        'seconds': time.monotonic()-start, 'logSha256': sha(out/(name+'.log'))})
    if result.returncode:
        report['status'] = 'FAIL'
        break
else:
    report.update(status='PASS', transientErrors=64000, boundedTurns=1000,
                  fatalErrors=1, binarySha256=sha(binary))
(out/'report.json').write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2))
raise SystemExit(report['status'] != 'PASS')
