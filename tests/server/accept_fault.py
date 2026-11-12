#!/usr/bin/env python3
"""Linux accept4 error isolation against real App requests and readiness waits.

The test shim changes only one syscall result at a time. The independent peer
requires a control acknowledgement and leaves an unrelated request pending.
Structural/resource errors must still fail. Outputs are never overwritten.
Use --linked-shim with a separately linked --wrap=accept4 test module on PHP
builds using RTLD_DEEPBIND; their loader bypasses preload symbol interposition.
"""
import argparse
import asyncio
import errno
import hashlib
import json
import os
from pathlib import Path
import select
import socket
import struct
import subprocess
import time
import traceback

from run import response

ROOT = Path(__file__).resolve().parents[2]
RETRY = ['ECONNABORTED', 'ENETDOWN', 'EPROTO', 'ENOPROTOOPT', 'EHOSTDOWN',
         'ENONET', 'EHOSTUNREACH', 'EOPNOTSUPP', 'ENETUNREACH', 'EINTR', 'EAGAIN']


async def exercise(port, control, names, fatal, report):
    async def connect():
        return await asyncio.open_connection('127.0.0.1', port)

    async def request(path):
        reader, writer = await connect()
        try:
            writer.write(f'GET {path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n'.encode())
            await writer.drain()
            return await response(reader)
        finally:
            writer.close()
            await writer.wait_closed()

    async def state():
        status, _, body = await request('/state')
        assert status == 200, (status, body)
        return json.loads(body)

    reader, writer = await connect()
    writer.write(b'GET /hold HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n')
    await writer.drain()
    held = asyncio.create_task(response(reader))
    try:
        for _ in range(200):
            before = await state()
            if before['ticks'] >= 2:
                break
            await asyncio.sleep(.001)
        assert before['ticks'] >= 2 and before['pending'] == 1 and not held.done(), before
        report['before'] = before
        report['faults'] = []
        for name in names:
            number = getattr(errno, name)
            control.send(struct.pack('=i', number))
            probe = asyncio.create_task(request('/probe'))
            ack = await asyncio.wait_for(asyncio.get_running_loop().sock_recv(control, 4), 5)
            assert ack == struct.pack('=i', number), (name, ack)
            report['faults'].append({'errno': name, 'injectionAcknowledged': True})
            if fatal:
                try:
                    result = await probe
                except (ConnectionError, asyncio.IncompleteReadError):
                    result = None
                assert result is None, ('fatal error swallowed', name, result)
                return
            status, _, body = await probe
            assert status == 200 and body == b'alive', (name, status, body)
            current = await state()
            assert current['pending'] == 1 and current['cancelled'] == 0 and not held.done(), current
            assert current['native']['connections'] <= 3 and current['fds'] <= before['fds'] + 1, current
            report['faults'][-1]['state'] = current
        assert (await request('/release'))[0] == 200
        assert (await held)[2] == b'released'
        after = await state()
        assert after['pending'] == 0 and after['cancelled'] == 0, after
        assert after['native']['bufferBytes'] == 0, after
        report['after'] = after
        assert (await request('/stop'))[2] == b'stopped'
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except ConnectionError:
            pass  # A fatal-listener negative control closes this held socket.
        if not held.done():
            held.cancel()
        await asyncio.gather(held, return_exceptions=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--module', required=True)
    parser.add_argument('--backend', choices=['epoll', 'poll'], default='epoll')
    parser.add_argument('--out', required=True)
    parser.add_argument('--preload', help='Optional sanitizer runtime; loaded before the test shim in PHP only')
    parser.add_argument('--linked-shim', action='store_true', help='Module contains the explicit test-only accept4 wrapper')
    parser.add_argument('--fault', choices=RETRY + ['EBADF', 'EMFILE', 'ENFILE', 'ENOMEM', 'ENOBUFS', 'EINVAL'])
    args = parser.parse_args()
    out = Path(args.out).resolve()
    out.mkdir(parents=True, exist_ok=False)
    sha = lambda path: hashlib.sha256(Path(path).read_bytes()).hexdigest()
    shim = out / 'accept-fault.so'
    build = ['cc', '-std=c11', '-O2', '-g', '-Wall', '-Wextra', '-Werror', '-fPIC', '-shared',
             str(ROOT / 'tests/server/accept_fault.c'), '-ldl', '-o', str(shim)]
    if not args.linked_shim:
        with (out / 'build.log').open('x') as log:
            subprocess.run(build, stdout=log, stderr=log, check=True)
    else:
        symbols = subprocess.check_output(['nm', '-D', args.module], text=True)
        assert ' T __wrap_accept4\n' in symbols, 'Test module does not contain the accept4 wrapper'
    source = {str(path.relative_to(ROOT)): sha(path) for directory in ['ext', 'src', 'tests/server']
              for path in (ROOT / directory).rglob('*') if path.is_file() and '__pycache__' not in path.parts}
    (out / 'source-manifest.json').write_text(json.dumps(source, indent=2, sort_keys=True))
    control, child = socket.socketpair(socket.AF_UNIX, socket.SOCK_SEQPACKET)
    control.setblocking(False)
    command = [args.php, '-n', '-d', 'extension=' + str(Path(args.module).resolve()),
               str(ROOT / 'tests/server/accept_fixture.php')]
    # A pre-existing sanitizer preload must remain first, if explicitly supplied.
    env = os.environ | {'ASYNCARA_BACKEND': args.backend,
                        'ASYNCARA_TEST_ACCEPT_CONTROL_FD': str(child.fileno()),
                        'LD_PRELOAD': ':'.join(filter(None, [args.preload, os.environ.get('LD_PRELOAD'),
                                                           None if args.linked_shim else str(shim)]))}
    fatal = args.fault is not None and args.fault not in RETRY
    names = [args.fault] if args.fault else RETRY
    report = {'command': command, 'backend': args.backend, 'faultsRequested': names,
              'expectedFatal': fatal, 'moduleSha256': sha(args.module), 'status': 'RUNNING',
              'shimSha256': sha(ROOT / 'tests/server/accept_fault.c') if args.linked_shim else sha(shim),
              'injection': 'explicit test-only link wrapper' if args.linked_shim else 'preload',
              'fixtureSha256': sha(ROOT / 'tests/server/accept_fixture.php')}
    start = time.monotonic()
    with (out / 'stderr.log').open('x') as log:
        proc = subprocess.Popen(command, env=env, pass_fds=[child.fileno()], stdout=subprocess.PIPE,
                                stderr=log, text=True)
        child.close()
        report['pid'] = proc.pid
        (out / 'launch.json').write_text(json.dumps(report, indent=2))
        try:
            assert select.select([proc.stdout], [], [], 10)[0], 'no READY record'
            line = proc.stdout.readline()
            (out / 'ready.txt').write_text(line)
            assert line.startswith('READY '), line
            asyncio.run(exercise(int(line.strip().rsplit(':', 1)[1]), control, names, fatal, report))
            tail = proc.communicate(timeout=5)[0]
            (out / 'stdout.log').write_text(tail)
            assert proc.returncode == (97 if fatal else 0), (proc.returncode, tail)
            records = [json.loads(line) for line in tail.splitlines()]
            final = records[-1]
            assert final['done'] and final['pending'] == 0, final
            for key in ['activeTasks', 'readyTasks', 'activeTimers', 'ioWatchers', 'pendingNative']:
                assert final['runtime'][key] == 0, (key, final)
            assert final['cancelled'] == (1 if fatal else 0), final
            report.update(status='PASS', final=final)
        except BaseException as error:
            report.update(status='FAIL', error=repr(error), traceback=traceback.format_exc())
        finally:
            control.close()
            # Only the test child we launched may be terminated on a failed test.
            if proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(5)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait()
            if not (out / 'stdout.log').exists():
                (out / 'stdout.log').write_text(proc.stdout.read())
            report.update(exit=proc.returncode, seconds=time.monotonic()-start)
    report['artifacts'] = {path.name: sha(path) for path in out.iterdir() if path.is_file()}
    (out / 'report.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report, indent=2))
    return report['status'] != 'PASS'


if __name__ == '__main__':
    raise SystemExit(main())
