#!/usr/bin/env python3
"""Minimal FPM progress control. SIGSTOP targets ONLY this fixture's child.

No runtime instrumentation or original acceptance assertion is changed. A
deliberate process stop is a diagnostic counterexample, never an endurance pass
or an attribution of historical failures. All evidence is retained in a new dir.
"""
import argparse
import hashlib
import http.server
import json
import os
from pathlib import Path
import signal
import socket
import struct
import subprocess
import threading
import time

ROOT = Path(__file__).resolve().parents[2]


def exact(sock, size):
    result = bytearray()
    while len(result) < size:
        data = sock.recv(size - len(result))
        if not data:
            raise RuntimeError('FastCGI EOF')
        result.extend(data)
    return bytes(result)


def request(port, values):
    def length(n):
        return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)
    def record(kind, data):
        return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data
    params = dict(SCRIPT_FILENAME=str(ROOT/'tests/audit/fpm_progress_request.php'),
                  REQUEST_METHOD='GET', SERVER_PROTOCOL='HTTP/1.1',
                  GATEWAY_INTERFACE='CGI/1.1', CONTENT_LENGTH='0', **values)
    data = b''.join(length(len(k.encode())) + length(len(v.encode())) + k.encode() + v.encode()
                    for k, v in params.items())
    with socket.create_connection(('127.0.0.1', port), timeout=10) as sock:
        sock.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, data) + record(4, b'') + record(5, b''))
        output, errors = b'', b''
        while True:
            version, kind, rid, size, padding, _ = struct.unpack('!BBHHBB', exact(sock, 8))
            if version != 1 or rid != 1:
                raise RuntimeError('Invalid FastCGI response')
            chunk = exact(sock, size); exact(sock, padding)
            if kind == 6: output += chunk
            if kind == 7: errors += chunk
            if kind == 3: break
        if errors or b'Status: 500' in output:
            raise RuntimeError(repr(output + errors))
        return json.loads(output.split(b'\r\n\r\n', 1)[1])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    out = Path(args.output).resolve()
    out.mkdir(mode=0o700, parents=True, exist_ok=False)
    module = (ROOT/os.environ.get('ASYNCARA_EXTENSION', 'build/asyncara.so')).resolve()
    fpm = os.environ.get('FPM_BIN', 'php-fpm8.5')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    conf = out/'fpm.conf'
    conf.write_text(f'[global]\ndaemonize=no\nerror_log={out}/error.log\n[control]\n'
                    f'listen=127.0.0.1:{port}\npm=static\npm.max_children=1\npm.max_requests=0\n'
                    f'catch_workers_output=yes\nphp_admin_value[error_log]={out}/php-errors.log\n')
    observations, handler_errors = [], []
    worker = None
    worker_handle = None
    case = 'baseline'
    gate = out/'timer-gate'

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args): pass
        def do_GET(self):
            stopped = False
            try:
                if case.startswith('stopped-'):
                    # Verify the child is still owned by this private master.
                    children = Path(f'/proc/{master.pid}/task/{master.pid}/children').read_text().split()
                    if str(worker) not in children:
                        raise RuntimeError('Refusing signal to a non-owned process')
                    signal.pidfd_send_signal(worker_handle, signal.SIGSTOP); stopped = True
                    deadline = time.monotonic() + 2
                    while '\nState:\tT' not in Path(f'/proc/{worker}/status').read_text():
                        if time.monotonic() > deadline: raise RuntimeError('Worker did not stop')
                        time.sleep(.001)
                    observations.append({'event': 'worker-stopped', 'monotonicNs': time.monotonic_ns(),
                                         'schedstat': Path(f'/proc/{worker}/schedstat').read_text().strip()})
                if case == 'gated':
                    deadline = time.monotonic() + 3
                    while not gate.exists():
                        if time.monotonic() > deadline: raise RuntimeError('No timer/worker progress before gate deadline')
                        time.sleep(.001)
                else:
                    time.sleep(.2)
                body = b'progress-control'
                self.send_response(200); self.send_header('Content-Length', str(len(body))); self.end_headers()
                self.wfile.write(body); self.wfile.flush()
                observations.append({'event': 'server-response-sent', 'case': case,
                                     'monotonicNs': time.monotonic_ns(), 'workerStopped': stopped})
                if stopped:
                    time.sleep(.05)
            except BaseException as error:
                handler_errors.append(repr(error))
            finally:
                if stopped:
                    observations.append({'event': 'worker-resuming', 'monotonicNs': time.monotonic_ns(),
                                         'schedstat': Path(f'/proc/{worker}/schedstat').read_text().strip()})
                    signal.pidfd_send_signal(worker_handle, signal.SIGCONT)

    server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
    report = {'status': 'RUNNING', 'source': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
              'extension': str(module), 'extensionSha256': hashlib.sha256(module.read_bytes()).hexdigest(),
              'php': subprocess.check_output([fpm, '-n', '-v'], text=True), 'cases': [],
              'observations': observations, 'handlerErrors': handler_errors,
              'boundary': 'Injected SIGSTOP counterexample, not attribution of historical failures or acceptance certification.'}
    with (out/'startup.log').open('x') as log:
        master = subprocess.Popen([fpm, '-n', '-F', '-d', 'extension='+str(module),
                                   '-d', 'display_errors=0', '-y', str(conf)], stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 10
            while True:
                if master.poll() is not None: raise RuntimeError('FPM startup failed')
                children = Path(f'/proc/{master.pid}/task/{master.pid}/children').read_text().split()
                if children:
                    worker = int(children[0])
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                    except OSError: pass
                if time.monotonic() > deadline: raise RuntimeError('FPM readiness deadline')
                time.sleep(.01)
            # A pidfd prevents cleanup signals ever targeting a recycled PID.
            worker_handle = os.pidfd_open(worker)
            for case in ['baseline', *['stopped-'+str(i) for i in range(8)], 'gated', 'recovery']:
                result = request(port, {'PROGRESS_URL': f'http://127.0.0.1:{server.server_port}/',
                                        'PROGRESS_CASE': case, 'PROGRESS_GATE': str(gate)})
                report['cases'].append({'case': case, **result})
                assert result['pid'] == worker, 'Unexpected worker replacement'
                assert result['body'] == 'progress-control' and result['status'] == 200
                if not case.startswith('stopped-'):
                    assert result['acceptanceSatisfied'], 'Uninjected progress control failed'
                assert not handler_errors, handler_errors
            # A process stop need not lose ticks: the exact arrival/resumption
            # order matters. Record every trial, never equate this control with
            # production acceptance or silently retry a failed acceptance run.
            pairs = [x for x in observations if x['event'] in ('worker-stopped', 'worker-resuming')]
            assert len(pairs) == 16
            for before, after in zip(pairs[::2], pairs[1::2]):
                assert after['monotonicNs'] - before['monotonicNs'] >= 200_000_000
                assert before['schedstat'] == after['schedstat'], 'Stopped worker executed unexpectedly'
            report['injectedMissedProgressCases'] = [x['case'] for x in report['cases']
                if x['case'].startswith('stopped-') and not x['acceptanceSatisfied']]
            report['status'] = 'PASS'
        except BaseException as error:
            report['status'] = 'FAIL'; report['error'] = repr(error)
            raise
        finally:
            # Only the master created here is stopped; always resume its child.
            if master.poll() is None:
                if worker_handle is not None:
                    try: signal.pidfd_send_signal(worker_handle, signal.SIGCONT)
                    except ProcessLookupError: pass
                master.send_signal(signal.SIGQUIT)
                try: master.wait(timeout=10)
                except subprocess.TimeoutExpired: master.kill(); master.wait()
            server.shutdown(); server.server_close(); thread.join()
            if worker_handle is not None: os.close(worker_handle)
            (out/'report.json').write_text(json.dumps(report, indent=2)+'\n')
    print(json.dumps({'status': report['status'], 'cases': [(x['case'], x['ticksWhilePending']) for x in report['cases']]}))


if __name__ == '__main__':
    main()
