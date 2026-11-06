#!/usr/bin/env python3
"""Measure owned FPM workers with strace or a test-only native poll wrapper."""
import argparse
import concurrent.futures
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import signal
import shlex
import shutil
import socket
import subprocess
import time

from analyze_waits import check_diagnostic_logs

root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--seconds', type=float, default=300)
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--instrumentation', choices=['syscall', 'poll', 'both', 'wait'], default='syscall')
parser.add_argument('--workers', type=int, default=1)
args = parser.parse_args()
if not 0 < args.seconds <= 3600:
    parser.error('Duration must be positive and at most one hour')
if not 1 <= args.workers <= 4:
    parser.error('Diagnostic worker count must be between one and four')
out = args.output.resolve()
out.mkdir(mode=0o700)  # Never overwrite earlier evidence.
extension = (root / os.environ.get('ASYNCARA_EXTENSION', 'build/asyncara.so')).resolve()
subprocess.run(['python3', str(root / 'scripts/check-build.py'), str(extension)], check=True)
child_env, probe = os.environ.copy(), None
if args.instrumentation == 'wait':
    if child_env.get('LD_PRELOAD'):
        raise RuntimeError('Wait diagnostic requires a clean LD_PRELOAD environment')
    library, wait_log = out / 'wait-probe.so', out / 'waits.jsonl'
    compile_command = shlex.split(os.environ.get('CC', 'cc')) + [
        '-std=c11', '-Wall', '-Wextra', '-Werror', '-O2', '-shared', '-fPIC',
        str(root / 'tests/soak/wait_probe.c'), '-ldl', '-o', str(library)]
    with (out / 'probe-build.log').open('x') as log:
        subprocess.run(compile_command, stdout=log, stderr=subprocess.STDOUT, check=True)
    wait_log.touch(mode=0o600, exist_ok=False)
    child_env.update(LD_PRELOAD=str(library), ASYNCARA_WAIT_LOG=str(wait_log))
    probe = {'compileCommand':compile_command, 'binarySha256':hashlib.sha256(library.read_bytes()).hexdigest(),
        'sourceSha256':hashlib.sha256((root/'tests/soak/wait_probe.c').read_bytes()).hexdigest(),
        'scope':'Linux libc poll/epoll_wait only, CLOCK_MONOTONIC and per-thread CPU; >50ms waits logged after measurement; no persistent descriptors, payloads or Zend access.'}
os.environ['ASYNCARA_FPM_DIR'] = str(out)
spec = importlib.util.spec_from_file_location('audit', root / 'scripts/audit-fpm.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)
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
pm.max_children={args.workers}
clear_env=yes
catch_workers_output=yes
security.limit_extensions=.php
php_admin_flag[log_errors]=on
php_admin_value[error_log]={out}/php-errors.log
''')
# -D keeps FPM as our direct child. Trace only control/readiness syscalls;
# do not capture request bodies, credentials, or arbitrary application data.
command = [os.environ.get('FPM_BIN', 'php-fpm8.5'),
    '-n', '-F', '-d', 'extension=' + str(extension), '-d', 'display_errors=0', '-y', str(config)]
if args.instrumentation in ('poll', 'both', 'wait'):
    prepend = out / 'fpm_poll_profile.php'
    shutil.copy2(root / 'tests/soak/fpm_poll_profile.php', prepend)
    command += ['-d', 'auto_prepend_file=' + str(prepend)]
if args.instrumentation in ('syscall', 'both'):
    command = ['strace', '-D', '-f', '-ttt', '-T', '-e',
        'trace=epoll_wait,epoll_pwait,poll,ppoll,select,pselect6,futex,nanosleep,clock_nanosleep,mmap,munmap,madvise',
        '-o', str(out / 'syscalls.log')] + command
report = {'status': 'RUNNING', 'command': command, 'requestedSeconds': args.seconds,
    'startedUtc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'instrumentation': args.instrumentation, 'workers': args.workers,
    'waitProbe': probe,
    'source': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(),
    'gitStatus': subprocess.check_output(['git', 'status', '--short'], cwd=root, text=True),
    'extensionSha256': hashlib.sha256(extension.read_bytes()).hexdigest(),
    'requestSha256': hashlib.sha256((root / 'tests/audit/fpm_request.php').read_bytes()).hexdigest(),
    'observerFiles': {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in
        ['tests/soak/fpm_trace.py', 'tests/soak/fpm_poll_profile.php', 'scripts/audit-fpm.py']},
    'note': 'Instrumentation changes timing; targeted HTTP/stream/SQLite/files diagnostic, not a full FPM lifecycle or endurance test.'}
start, requests = None, 0
try:
    with (out / 'startup.log').open('x') as log:
        process = subprocess.Popen(command, stdout=log, stderr=log, start_new_session=True, env=child_env)
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
            with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor, (out / 'responses.jsonl').open('x') as responses:
                while time.monotonic() - start < args.seconds:
                    pending = [executor.submit(audit.request, port, requests + i,
                        'io' if (requests + i) % 2 == 0 else 'stream') for i in range(args.workers)]
                    for future in pending:
                        response = future.result()
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
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
                raise RuntimeError('Traced FPM failed graceful shutdown')
        if probe is not None:
            # Inspect only diagnostic files, not application bodies. A probe I/O
            # failure exits 125 and must never become a passing measurement.
            if not (out / 'waits.jsonl').stat().st_size:
                raise RuntimeError('Wait probe did not initialize')
        if args.instrumentation in ('poll', 'both', 'wait'):
            report['errorLogs'] = check_diagnostic_logs(out)
except BaseException as error:
    report.update(status='FAIL', error=repr(error))
    raise
finally:
    audit.server.shutdown()
    audit.server.server_close()
    report.update(finishedUtc=datetime.datetime.now(datetime.timezone.utc).isoformat(),
        recordedResponses=requests)
    if start is not None:
        report['elapsedThroughCleanupSeconds'] = time.monotonic() - start
    (out / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report), flush=True)
