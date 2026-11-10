#!/usr/bin/env python3
"""Private PostgreSQL fixture; no installed service/configuration is changed."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import time

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--module', required=True)
p.add_argument('--backend', choices=['epoll', 'poll'], required=True)
p.add_argument('--out', type=Path, required=True)
p.add_argument('--probe', action='store_true')
p.add_argument('--tls', action='store_true', help='Private verified localhost certificate; no system trust changes')
p.add_argument('--valgrind', type=Path)
p.add_argument('--valgrind-lib', type=Path)
p.add_argument('--debug-info', type=Path)
p.add_argument('--fpm', type=Path, help='Additional real one/four-worker request lifecycle fixture')
a = p.parse_args()
if a.fpm and (a.probe or a.valgrind):
    p.error('--fpm is a separate normal integration run, not a probe or Valgrind run')
root = Path(__file__).resolve().parents[2]
out = a.out.resolve()
out.mkdir(parents=True, exist_ok=False, mode=0o700)
pg = Path(os.environ.get('POSTGRES_BIN', '/usr/lib/postgresql/16/bin'))
sha = lambda f: hashlib.sha256(Path(f).read_bytes()).hexdigest()
module = Path(a.module).resolve()
env = os.environ | {'ASYNCARA_BACKEND': a.backend}
report = {'status': 'RUNNING', 'moduleSha256': sha(module), 'phpSha256': sha(a.php),
          'backend': a.backend, 'sourceManifest': {str(f.relative_to(root)): sha(f)
              for d in ['ext', 'src/Asyncara/PostgreSQL', 'tests/database']
              for f in (root/d).rglob('*') if f.is_file() and '__pycache__' not in str(f)},
          'postgres': subprocess.check_output([str(pg/'postgres'), '--version'], text=True).strip()}
server = None
started = time.monotonic()
try:
    with (out/'init.log').open('x') as log:
        subprocess.run([str(pg/'initdb'), '-D', str(out/'data'), '-A', 'trust', '--no-locale'],
                       stdout=log, stderr=log, check=True, timeout=30)
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1', 0))
        port = reserve.getsockname()[1]
    opts = {'host': '127.0.0.1', 'port': str(port), 'dbname': 'postgres', 'sslmode': 'disable'}
    tls_args = []
    if a.tls:
        with (out/'cert.log').open('x') as log:
            subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                '-days', '2', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
                '-keyout', str(out/'key.pem'), '-out', str(out/'cert.pem')],
                stdout=log, stderr=log, check=True, timeout=20)
        os.chmod(out/'key.pem', 0o600)
        opts.update(host='localhost', hostaddr='127.0.0.1', sslmode='verify-full', sslrootcert=str(out/'cert.pem'))
        tls_args = ['-c', 'ssl=on', '-c', 'ssl_cert_file='+str(out/'cert.pem'),
                    '-c', 'ssl_key_file='+str(out/'key.pem')]
    report['tls'] = a.tls
    (out/'options.json').write_text(json.dumps(opts))
    env['ASYNCARA_PG_TEST_OPTIONS'] = str(out/'options.json')
    with (out/'postgres.log').open('x') as log:
        server = subprocess.Popen([str(pg/'postgres'), '-D', str(out/'data'), '-h', '127.0.0.1',
            '-k', '', '-p', str(port)] + tls_args, stdout=log, stderr=log)
    command = [a.php, '-n', '-d', 'extension='+str(module),
               str(root/'tests/database/prepared_pg.php')] + (['probe'] if a.probe else [])
    if a.fpm:
        command = [sys.executable, str(root/'tests/database/prepared_pg_fpm.py'),
                   '--fpm', str(a.fpm.resolve()), '--module', str(module),
                   '--backend', a.backend, '--options', str(out/'options.json'),
                   '--out', str(out/'fpm')]
        report['fpmSha256'] = sha(a.fpm)
    if a.valgrind:
        env['USE_ZEND_ALLOC'] = '0'
        if a.valgrind_lib: env['VALGRIND_LIB'] = str(a.valgrind_lib.resolve())
        command = [str(a.valgrind.resolve()), '--keep-debuginfo=yes', '--leak-check=full',
            '--show-leak-kinds=all', '--errors-for-leak-kinds=definite,indirect', '--error-exitcode=99',
            '--log-file='+str(out/'valgrind.log')] + (
            ['--extra-debuginfo-path='+str(a.debug_info.resolve())] if a.debug_info else []) + command
    (out/'active.json').write_text(json.dumps({'serverPid': server.pid, 'command': command}))
    deadline = time.monotonic()+15
    while True:
        if server.poll() is not None:
            raise RuntimeError('Private PostgreSQL exited during startup')
        ready = subprocess.run([str(pg/'pg_isready'), '-h', '127.0.0.1', '-p', str(port),
            '-d', 'postgres', '-t', '1'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if ready.returncode == 0:
            break
        if time.monotonic() >= deadline:
            raise TimeoutError('Private PostgreSQL startup')
        time.sleep(.025)
    with (out/'php.log').open('x') as log:
        child = subprocess.Popen(command, cwd=root, env=env, stdout=log, stderr=log)
        (out/'active.json').write_text(json.dumps({'serverPid': server.pid, 'childPid': child.pid,
                                                 'command': command}))
        try:
            code = child.wait(timeout=90)
        except subprocess.TimeoutExpired:
            child.terminate()
            try: child.wait(timeout=5)
            except subprocess.TimeoutExpired: child.kill(); child.wait()
            raise
    report.update(status='PASS' if code == 0 else 'FAIL', exit=code, command=command)
    if code == 0 and not a.probe:
        report['shutdown'] = []
        for mode in ['pending', 'fatal', 'oom']:
            shutdown = [a.php, '-n', '-d', 'extension='+str(module),
                        '-d', 'memory_limit=8M' if mode == 'oom' else 'memory_limit=128M',
                        str(root/'tests/database/prepared_pg_shutdown.php'), mode]
            child_env = env | ({'USE_ZEND_ALLOC': '1'} if mode == 'oom' else {})
            if a.valgrind:
                shutdown = [str(a.valgrind.resolve()), '--keep-debuginfo=yes', '--leak-check=full',
                    '--show-leak-kinds=all', '--errors-for-leak-kinds=definite,indirect', '--error-exitcode=99',
                    '--log-file='+str(out/('valgrind-'+mode+'.log'))] + (
                    ['--extra-debuginfo-path='+str(a.debug_info.resolve())] if a.debug_info else []) + shutdown
            with (out/(mode+'.log')).open('x') as log:
                result = subprocess.run(shutdown, cwd=root, env=child_env, stdout=log, stderr=log, timeout=90 if a.valgrind else 15)
            expected = 255 if mode in ['fatal', 'oom'] else 0
            failures = []
            if result.returncode != expected:
                failures.append(f'Shutdown {mode} exit {result.returncode}, expected {expected}')
            if mode == 'oom' and 'Allowed memory size' not in (out/(mode+'.log')).read_text():
                failures.append('Missing deliberate PHP allocation exhaustion')
            # This private cluster has no application connections except this
            # test. Observe session release independently, not a PHP counter.
            deadline = time.monotonic()+5
            while True:
                remaining = subprocess.check_output([str(pg/'psql'), '-h', '127.0.0.1', '-p', str(port),
                    '-d', 'postgres', '-Atc', "SELECT count(*) FROM pg_stat_activity WHERE application_name='asyncara_cache_shutdown'"], text=True, timeout=5).strip()
                if remaining == '0': break
                if time.monotonic() >= deadline: raise TimeoutError('PostgreSQL shutdown owner still present')
                time.sleep(.01)
            report['shutdown'].append({'mode': mode, 'exit': result.returncode, 'expectedExit': expected,
                                       'status': 'FAIL' if failures else 'PASS', 'failures': failures,
                                       'remainingSessions': 0, 'command': shutdown,
                                       'valgrindInstrumented': bool(a.valgrind)})
            # These are independent processes. Preserve a failed memory check
            # while also collecting the remaining shutdown cases; never turn
            # an unexpected Valgrind exit into a passing result.
            if failures:
                report['status'] = 'FAIL'
except BaseException as exc:
    report.update(status='FAIL', error=repr(exc))
finally:
    if server is not None:
        if server.poll() is None:
            subprocess.run([str(pg/'pg_ctl'), '-D', str(out/'data'), '-m', 'fast', '-w', 'stop'],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20, check=True)
        report['serverExit'] = server.wait(timeout=5)
    report['seconds'] = time.monotonic()-started
    report['logHashes'] = {f.name: sha(f) for f in out.glob('*.log')}
    (out/'report.json').write_text(json.dumps(report, indent=2)+'\n')
    (out/'active.json').write_text(json.dumps({'status': 'TERMINAL'}))
print(json.dumps({k: v for k, v in report.items() if k != 'sourceManifest'}, indent=2))
raise SystemExit(0 if report['status'] == 'PASS' else 1)
