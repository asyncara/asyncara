#!/usr/bin/env python3
"""Diagnostic syscall trace of owned FPM workers; not an endurance substitute."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import time

root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--seconds', type=float, default=300)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
if not 0 < args.seconds <= 3600:
    parser.error('Duration must be positive and at most one hour')
out = args.output.resolve()
out.mkdir(mode=0o700)  # Never overwrite earlier evidence.
os.environ['ASYNCARA_FPM_DIR'] = str(out)
spec = importlib.util.spec_from_file_location('audit', root / 'scripts/audit-fpm.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)
extension = (root / os.environ.get('ASYNCARA_EXTENSION', 'build/asyncara.so')).resolve()
subprocess.run(['python3', str(root / 'scripts/check-build.py'), str(extension)], check=True)
with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    port = listener.getsockname()[1]
config = out / 'fpm.conf'
config.write_text(f'''[global]
daemonize=no
pid={out}/pid
error_log={out}/fpm.log
[trace]
listen=127.0.0.1:{port}
pm=static
pm.max_children=1
clear_env=yes
catch_workers_output=yes
security.limit_extensions=.php
php_admin_flag[log_errors]=on
php_admin_value[error_log]={out}/php-errors.log
''')
# -D keeps FPM as our direct child. Trace only control/readiness syscalls;
# do not capture request bodies, credentials, or arbitrary application data.
command = ['strace', '-D', '-f', '-ttt', '-T', '-e',
    'trace=epoll_wait,epoll_pwait,poll,ppoll,select,pselect6,futex,nanosleep,clock_nanosleep,mmap,munmap,madvise',
    '-o', str(out / 'syscalls.log'), os.environ.get('FPM_BIN', 'php-fpm8.5'),
    '-n', '-F', '-d', 'extension=' + str(extension), '-d', 'display_errors=0', '-y', str(config)]
report = {'status': 'RUNNING', 'command': command, 'requestedSeconds': args.seconds,
    'source': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(),
    'extensionSha256': hashlib.sha256(extension.read_bytes()).hexdigest(),
    'requestSha256': hashlib.sha256((root / 'tests/audit/fpm_request.php').read_bytes()).hexdigest(),
    'note': 'Tracing changes timing; use for diagnosis, not uninstrumented endurance certification.'}
try:
    with (out / 'startup.log').open('x') as log:
        process = subprocess.Popen(command, stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 10
            while True:
                if process.poll() is not None:
                    raise RuntimeError('Traced FPM startup failed; inspect startup.log')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    if time.monotonic() > deadline:
                        raise
                    time.sleep(.01)
            start = time.monotonic()
            requests = 0
            with (out / 'responses.jsonl').open('x') as responses:
                while time.monotonic() - start < args.seconds:
                    response = audit.request(port, requests, 'io' if requests % 2 == 0 else 'stream')
                    responses.write(json.dumps(response) + '\n')
                    responses.flush()
                    requests += 1
            report.update(status='PASS', seconds=time.monotonic() - start, requests=requests)
        finally:
            if process.poll() is None:
                process.send_signal(signal.SIGQUIT)
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
                raise RuntimeError('Traced FPM failed graceful shutdown')
except BaseException as error:
    report.update(status='FAIL', error=repr(error))
    raise
finally:
    audit.server.shutdown()
    audit.server.server_close()
    (out / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report), flush=True)
