#!/usr/bin/env python3
"""Compare resident TLS connections with unload/reload in isolated libpq builds.

Expected sanitizer findings are recorded, never suppressed or called passes.
Libraries JSON maps descriptive version labels to absolute libpq.so paths.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import time

root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--libraries', type=Path, required=True)
args = parser.parse_args()
out = args.output.resolve()
if out.exists():
    parser.error('Preserve evidence: use a new output directory')
out.mkdir(mode=0o700, parents=True)
libraries = json.loads(args.libraries.read_text())
pgbin = Path(os.environ.get('POSTGRES_BIN', '/usr/lib/postgresql/16/bin'))


def setup(command, name):
    with (out / (name + '.log')).open('x') as log:
        subprocess.run(command, stdout=log, stderr=log, check=True)


setup(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2',
       '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
       '-keyout', str(out / 'key.pem'), '-out', str(out / 'cert.pem')], 'certificate')
os.chmod(out / 'key.pem', 0o600)
setup([str(pgbin / 'initdb'), '-D', str(out / 'data'), '-A', 'trust', '--no-locale'], 'initdb')
setup(['cc', '-O1', '-g', '-Wall', '-Wextra', '-Werror', '-fno-omit-frame-pointer',
       '-fsanitize=address,undefined', '-I' + str(root / '.local/sysroot/usr/include/postgresql'),
       str(root / 'tests/dependencies/libpq_lifecycle.c'), '-ldl', '-o', str(out / 'asan-probe')], 'build-asan')
setup(['cc', '-O1', '-g', '-Wall', '-Wextra', '-Werror',
       '-I' + str(root / '.local/sysroot/usr/include/postgresql'),
       str(root / 'tests/dependencies/libpq_lifecycle.c'), '-ldl', '-o', str(out / 'plain-probe')], 'build-plain')
with socket.socket() as reserve:
    reserve.bind(('127.0.0.1', 0))
    port = reserve.getsockname()[1]
config = out / 'connection.txt'
config.write_text(f'host=localhost hostaddr=127.0.0.1 port={port} dbname=postgres '
                  f'sslmode=verify-full sslrootcert={out}/cert.pem connect_timeout=30')
os.chmod(config, 0o600)
reports = []
with (out / 'postgres.log').open('x') as log:
    server = subprocess.Popen([str(pgbin / 'postgres'), '-D', str(out / 'data'),
        '-k', str(out), '-h', '127.0.0.1', '-p', str(port), '-c', 'ssl=on',
        '-c', 'ssl_cert_file=' + str(out / 'cert.pem'), '-c', 'ssl_key_file=' + str(out / 'key.pem')],
        stdout=log, stderr=log)
    try:
        deadline = time.monotonic() + 15
        while True:
            if server.poll() is not None:
                raise RuntimeError('Private PostgreSQL fixture failed')
            ready = subprocess.run([str(pgbin / 'pg_isready'), '-h', '127.0.0.1',
                '-p', str(port), '-d', 'postgres', '-t', '1'],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=3)
            if ready.returncode == 0:
                break
            if time.monotonic() > deadline:
                raise RuntimeError('PostgreSQL did not become ready for queries')
            time.sleep(.05)
        cases = [('resident', 1, 500, 'resident'), ('one-unload', 1, 1, 'unload'),
                 ('many-connections', 1, 500, 'unload'), ('repeated-unload', 20, 5, 'unload')]
        for label, library in libraries.items():
            if not label.replace('.', '').replace('-', '').isalnum():
                raise ValueError('Unsafe library label')
            library = str(Path(library).resolve(strict=True))
            for name, loads, count, mode in cases:
                command = [str(out / 'asan-probe'), library, str(config), str(loads), str(count), mode]
                env = os.environ | {'ASAN_OPTIONS': 'detect_leaks=1:halt_on_error=1',
                                    'UBSAN_OPTIONS': 'halt_on_error=1:print_stacktrace=1'}
                begin = time.monotonic()
                result = subprocess.run(command, env=env, text=True, capture_output=True, timeout=120)
                stdout, stderr = out / f'{label}-{name}.jsonl', out / f'{label}-{name}.log'
                stdout.write_text(result.stdout)
                stderr.write_text(result.stderr)
                reports.append({'library': label, 'path': library,
                    'librarySha256': hashlib.sha256(Path(library).read_bytes()).hexdigest(),
                    'case': name, 'loads': loads, 'connectionsPerLoad': count, 'exit': result.returncode,
                    'seconds': time.monotonic() - begin, 'stdout': stdout.name, 'stderr': stderr.name,
                    'stderrSha256': hashlib.sha256(stderr.read_bytes()).hexdigest()})
                (out / 'report.json').write_text(json.dumps(reports, indent=2) + '\n')
                print(label, name, 'exit', result.returncode, flush=True)
                # 0 is clean and 1 can be an actual leak finding. Never accept a crash,
                # failed TLS/query, or timeout as the anticipated unload diagnostic.
                completed = [json.loads(line) for line in result.stdout.splitlines()]
                cycles = [r for r in completed if r.get('type') == 'cycle']
                if (result.returncode not in (0, 1) or len(cycles) != loads or
                    (result.returncode == 1 and 'LeakSanitizer: detected memory leaks' not in result.stderr)):
                    raise RuntimeError('Dependency probe did not complete its workload')
            vg = root / '.local/sysroot/usr/bin/valgrind'
            if vg.exists():
                env = os.environ | {'VALGRIND_LIB': str(root / '.local/sysroot/usr/libexec/valgrind')}
                for name, loads, count, mode in [('unload', 1, 1, 'unload'),
                                                ('resident', 1, 100, 'resident'),
                                                ('reload', 20, 5, 'unload')]:
                    command = [str(vg), '--keep-debuginfo=yes',
                        '--extra-debuginfo-path=' + str(root / '.local/debug'),
                        '--leak-check=full', '--show-leak-kinds=all', '--error-exitcode=99',
                        '--log-file=' + str(out / f'{label}-valgrind-{name}.log'),
                        str(out / 'plain-probe'), library, str(config), str(loads), str(count), mode]
                    result = subprocess.run(command, env=env, text=True, capture_output=True, timeout=300)
                    (out / f'{label}-valgrind-{name}-output.log').write_text(result.stdout + result.stderr)
                    reports.append({'library': label, 'case': 'valgrind-' + name, 'exit': result.returncode,
                                    'log': f'{label}-valgrind-{name}.log'})
                    (out / 'report.json').write_text(json.dumps(reports, indent=2) + '\n')
                    cycles = [json.loads(line) for line in result.stdout.splitlines()
                              if json.loads(line).get('type') == 'cycle']
                    if result.returncode not in (0, 99) or len(cycles) != loads:
                        raise RuntimeError('Valgrind dependency probe did not finish')
    finally:
        server.terminate()
        try:
            server.wait(timeout=15)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
            raise RuntimeError('Private PostgreSQL fixture failed to stop')
print('Dependency observations recorded; inspect findings, not only process exits.')
