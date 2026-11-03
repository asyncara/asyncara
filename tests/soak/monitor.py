"""Linux process-tree observations, streamed without retaining sample history."""
import errno
import json
import os
from pathlib import Path
import threading
import time


def process_tree(pid: int) -> list[dict]:
    pending, seen, records = [(pid, None)], set(), []
    while pending:
        current, parent = pending.pop()
        if current in seen:
            continue
        seen.add(current)
        if len(seen) > 1024:
            raise RuntimeError('Observer process-tree limit exceeded')
        path = Path('/proc') / str(current)
        try:
            status = dict(line.split(':', 1) for line in (path / 'status').read_text().splitlines())
            if parent is not None and int(status['PPid']) != parent:
                continue
            children = [int(n) for n in (path / f'task/{current}/children').read_text().split()]
            start = int((path / 'stat').read_text().rsplit(')', 1)[1].split()[19])
            unavailable = []
            try:
                fds = list((path / 'fd').iterdir())
                sockets = 0
                for fd in fds:
                    try:
                        sockets += os.readlink(fd).startswith('socket:')
                    except FileNotFoundError:
                        pass  # A descriptor can close between listing and reading.
                    except PermissionError:
                        sockets = None
                        unavailable.append('socket links: EACCES (process dumpability/exec transition)')
                        break
                fd_count = len(fds)
            except PermissionError:
                fd_count = sockets = None
                unavailable.append('descriptors and sockets: EACCES (process dumpability/exec transition)')
            records.append({'pid': current, 'ppid': int(status['PPid']),
                'startTicks': start, 'name': status['Name'].strip(),
                'rssKiB': int(status.get('VmRSS', '0 kB').split()[0]),
                'virtualKiB': int(status.get('VmSize', '0 kB').split()[0]),
                'threads': int(status['Threads']), 'fds': fd_count, 'sockets': sockets,
                'children': children, 'unavailable': unavailable})
            pending.extend((child, current) for child in children)
        except OSError as error:
            if error.errno not in (errno.ENOENT, errno.ESRCH):
                raise
            # A child can be reaped during observation; never attach by name to
            # another user's process. PID plus startTicks identifies instances.
    return records


class ProcessMonitor:
    def __init__(self, output: Path, interval: float = 5.0, owned_pid_files=()):
        if interval <= 0:
            raise ValueError('Positive monitor interval required')
        self.output, self.interval = output, interval
        # pg_ctl reparents its server. Only accept a PID file owned by this
        # fixture and verify the exact private data-directory argv element.
        self.owned_pid_files = owned_pid_files
        self.stop = threading.Event()
        self.error = None
        self.thread = threading.Thread(target=self._run, daemon=True)

    def _run(self):
        start = time.monotonic()
        try:
            with self.output.open('x') as stream:
                while True:
                    records = process_tree(os.getpid())
                    seen = {record['pid'] for record in records}
                    for pid_file, marker in self.owned_pid_files:
                        try:
                            pid = int(pid_file.read_text().splitlines()[0])
                            argv = (Path('/proc') / str(pid) / 'cmdline').read_bytes().split(b'\0')
                            if os.fsencode(marker) not in argv:
                                raise RuntimeError('Owned fixture PID has unexpected executable arguments')
                            for record in process_tree(pid):
                                if record['pid'] not in seen:
                                    records.append(record)
                                    seen.add(record['pid'])
                        except OSError as error:
                            if error.errno not in (errno.ENOENT, errno.ESRCH):
                                raise
                    stream.write(json.dumps({'seconds': time.monotonic() - start,
                        'rootPid': os.getpid(), 'processCount': len(records),
                        'processes': records}) + '\n')
                    stream.flush()
                    if self.stop.wait(self.interval):
                        break
        except BaseException as error:
            self.error = error
            self.output.with_suffix('.error.json').write_text(
                json.dumps({'error': repr(error), 'path': getattr(error, 'filename', None)}) + '\n')

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, kind, value, traceback):
        self.stop.set()
        self.thread.join(timeout=10)
        if self.thread.is_alive():
            raise RuntimeError('OS observer did not stop')
        if self.error is not None and kind is None:
            raise RuntimeError('OS observer failed; inspect its error report') from self.error
