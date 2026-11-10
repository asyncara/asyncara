#!/usr/bin/env python3
"""Isolated Rust owner tests and native C fail-stop integration.

The fault-enabled library is linked only into the test executable. Do not link
it into a production PHP module. No global toolchain or PHP configuration changes.
"""
import argparse
import hashlib
import json
import os
import pathlib
import resource
import shlex
import signal
import subprocess
import time

p = argparse.ArgumentParser()
p.add_argument('--output', required=True)
a = p.parse_args()
root = pathlib.Path(__file__).resolve().parents[2]
out = pathlib.Path(a.output).resolve()
out.mkdir(parents=True, exist_ok=False)
sha = lambda path: hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()
manifest = {str(f.relative_to(root)): sha(f) for d in ['ext', 'tests/http']
            for f in (root / d).rglob('*') if f.is_file() and '__pycache__' not in str(f)}
(out / 'identity.json').write_text(json.dumps({'manifest': manifest, 'environment': {
    k: os.environ.get(k) for k in ['CC', 'EXTRA_CFLAGS', 'EXTRA_LDFLAGS', 'RUSTUP_HOME', 'CARGO_HOME']},
    'scope': 'fault injection and ownership diagnostics; not throughput or production memory certification'}, indent=2) + '\n')
env = os.environ | {'CARGO_TARGET_DIR': str(out / 'cargo')}
rows = []
start = time.monotonic()


def run(name, command, timeout=180):
    began = time.monotonic()
    log = out / (name + '.log')
    with log.open('xb') as f:
        child = subprocess.Popen(list(map(str, command)), cwd=root, env=env, stdout=f, stderr=subprocess.STDOUT, start_new_session=True)
        (out / 'active.json').write_text(json.dumps({'pid': child.pid, 'command': list(map(str, command)), 'status': 'RUNNING'}))
        try:
            code = child.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            # Own the complete compiler/test group, not just Cargo's parent.
            try: os.killpg(child.pid, signal.SIGTERM)
            except ProcessLookupError: pass
            try: child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                try: os.killpg(child.pid, signal.SIGKILL)
                except ProcessLookupError: pass
                child.wait()
            code = 124
    rows.append({'name': name, 'command': list(map(str, command)), 'exit': code,
                 'wallSeconds': time.monotonic() - began, 'timeoutSeconds': timeout, 'logSha256': sha(log)})
    (out / 'active.json').write_text(json.dumps({'pid': child.pid, 'status': 'TERMINAL', 'exit': code}))
    (out / 'report.json').write_text(json.dumps({'status': 'FAIL' if code else 'RUNNING', 'commands': rows}, indent=2) + '\n')
    print(name, code, flush=True)
    if code:
        raise SystemExit(1)


resource.setrlimit(resource.RLIMIT_CORE, (0, 0))  # This test process and its children only.
# A fresh AWS-LC dependency build can exceed the test-execution watchdog before
# any test starts. Keep compilation separate; runtime test deadlines stay180s.
run('rust-test-build', ['cargo', 'test', '--release', '--locked', '--manifest-path', 'ext/hyper/Cargo.toml', '--lib', '--no-run'], timeout=600)
run('rust-owners', ['cargo', 'test', '--release', '--locked', '--manifest-path', 'ext/hyper/Cargo.toml', '--lib', '--', '--nocapture'])
run('rust-fault-build', ['cargo', 'build', '--release', '--locked', '--manifest-path', 'ext/hyper/Cargo.toml', '--features', 'ffi-fault-tests'], timeout=600)
library = out / 'cargo/release/libasyncara_hyper_bridge.a'
binary = out / 'hyper-faults'
run('native-fault-build', shlex.split(os.environ.get('CC', 'cc')) +
    ['-std=c11', '-D_GNU_SOURCE', '-DHAVE_ASYNCARA_HYPER=1', '-O2', '-g', '-Wall', '-Wextra', '-Werror',
     '-I.local/sysroot/usr/include', '-I.local/sysroot/usr/include/x86_64-linux-gnu'] +
    shlex.split(os.environ.get('EXTRA_CFLAGS', '')) +
    ['tests/http/hyper_faults.c', 'ext/backend.c', 'ext/workers.c', 'ext/memory.c', library,
     '-pthread', '-l:libcurl.so.4', '-l:libsqlite3.so.0', '-ldl', '-lm', '-Wl,--wrap=ar_hyper_free'] +
    shlex.split(os.environ.get('EXTRA_LDFLAGS', '')) + ['-o', binary])
for backend in [0, 1]:
    for point in range(11, 19):
        run(f'fault-{point}-{backend}', [binary, point, backend])
assert all(sha(root / name) == digest for name, digest in manifest.items())
(out / 'report.json').write_text(json.dumps({'status': 'PASS', 'wallSeconds': time.monotonic() - start,
    'binarySha256': sha(binary), 'librarySha256': sha(library), 'commands': rows}, indent=2) + '\n')
