#!/usr/bin/env python3
"""Reuse one isolated FPM worker; verify request state, descriptors and threads."""
import json, os, pathlib, signal, socket, struct, subprocess, time

root = pathlib.Path(__file__).resolve().parent.parent
os.chdir(root)
subprocess.run(['python3', 'scripts/check-build.py', 'build/asyncara.so'], check=True)
directory = root / 'build/fpm-lifecycle'
directory.mkdir(exist_ok=True)
with socket.socket() as probe:
    probe.bind(('127.0.0.1', 0))
    port = probe.getsockname()[1]
config = directory / 'pool.conf'
config.write_text(f'''[global]
pid = {directory}/fpm.pid
error_log = {directory}/error.log
daemonize = no
[lifecycle]
listen = 127.0.0.1:{port}
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = yes
catch_workers_output = yes
security.limit_extensions = .php
''')

def record(kind, content):
    return struct.pack('!BBHHBB', 1, kind, 1, len(content), 0, 0) + content

def exact(stream, size):
    value = b''
    while len(value) < size:
        part = stream.recv(size - len(value))
        if not part:
            raise RuntimeError('Unexpected FastCGI EOF')
        value += part
    return value

def request():
    params = {'SCRIPT_FILENAME': str(root / 'tests/fpm_request.php'),
              'REQUEST_METHOD': 'GET', 'SERVER_PROTOCOL': 'HTTP/1.1',
              'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_NAME': 'localhost',
              'REMOTE_ADDR': '127.0.0.1', 'CONTENT_LENGTH': '0'}
    def length(n):
        return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)
    encoded = b''.join(length(len(k.encode())) + length(len(v.encode())) +
                       k.encode() + v.encode() for k, v in params.items())
    with socket.create_connection(('127.0.0.1', port), timeout=10) as stream:
        stream.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) +
                       record(4, b'') + record(5, b''))
        output = b''
        errors = b''
        while True:
            version, kind, rid, size, padding, _ = struct.unpack('!BBHHBB', exact(stream, 8))
            if version != 1 or rid != 1:
                raise RuntimeError('Invalid FastCGI frame')
            data = exact(stream, size)
            exact(stream, padding)
            if kind == 6:
                output += data
            elif kind == 7:
                errors += data
            elif kind == 3:
                if len(data) != 8 or struct.unpack('!IB3x', data) != (0, 0):
                    raise RuntimeError('FPM rejected request')
                break
    if errors:
        raise RuntimeError(errors.decode(errors='replace'))
    headers, body = output.split(b'\r\n\r\n', 1)
    if b'Status: 500' in headers:
        raise RuntimeError(output.decode(errors='replace'))
    result = json.loads(body)
    if result['value'] != 42:
        raise RuntimeError('Incorrect SQLite result')
    return result['pid']

log = (directory / 'startup.log').open('w')
process = subprocess.Popen([os.environ.get('PHP_FPM_BIN', 'php-fpm8.5'), '-n', '-F',
                            '-d', 'extension=' + str(root / 'build/asyncara.so'),
                            '-d', 'display_errors=0', '-y', str(config)],
                           stdout=log, stderr=log)
try:
    deadline = time.monotonic() + 10
    while True:
        if process.poll() is not None:
            raise RuntimeError('FPM startup failed; inspect ' + str(directory))
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                break
        except OSError:
            if time.monotonic() > deadline:
                raise
            time.sleep(.02)
    pids = set()
    samples = []
    for index in range(100):
        pid = request()
        pids.add(pid)
        if index % 10 == 9:
            proc = pathlib.Path('/proc') / str(pid)
            # END_REQUEST and thread joins precede final pipe/connection closes.
            # Sample only after the worker returns to accept(), not mid-cleanup.
            deadline = time.monotonic() + 2
            while 'accept' not in (proc / 'wchan').read_text():
                if time.monotonic() > deadline:
                    raise RuntimeError('FPM worker did not return to accept after request shutdown')
                time.sleep(.005)
            status = dict(line.split(':', 1) for line in (proc / 'status').read_text().splitlines())
            samples.append({'request': index + 1, 'fdCount': len(list((proc / 'fd').iterdir())),
                            'rssKiB': int(status['VmRSS'].split()[0]), 'threads': int(status['Threads'])})
    if len(pids) != 1:
        raise RuntimeError('Worker crashed or was replaced: ' + repr(pids))
    if len({sample['fdCount'] for sample in samples}) != 1:
        raise RuntimeError('File descriptors grew: ' + repr(samples))
    # RSS includes allocator caches. Require a plateau, not exact byte identity.
    if max(s['rssKiB'] for s in samples[3:]) - min(s['rssKiB'] for s in samples[3:]) > 4096:
        raise RuntimeError('RSS did not plateau within 4 MiB: ' + repr(samples))
    result = {'requests': 100, 'workerPid': next(iter(pids)), 'samples': samples, 'status': 'PASS'}
    (directory / 'results.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result, indent=2))
finally:
    process.send_signal(signal.SIGQUIT)
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait()
    log.close()
