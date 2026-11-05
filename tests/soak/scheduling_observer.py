#!/usr/bin/env python3
"""Read-only Linux scheduling observations for an explicitly owned FPM campaign.

No test result is changed. Output is diagnostic: sampling adds host load and
shared cgroup counters cannot uniquely attribute a delay to Asyncara.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import time

from monitor import owned_process, process_identity, process_tree


def read(path):
    try:
        return Path(path).read_text().strip()
    except OSError as error:
        return {'unavailable': error.errno}


def hierarchy(member, mount, mounted_root='/'):
    """Map a v2 membership to its mounted hierarchy; never traverse outside it."""
    member, mounted_root = PurePosixPath(member), PurePosixPath(mounted_root)
    if not member.is_absolute() or '..' in member.parts or not member.is_relative_to(mounted_root):
        raise ValueError('Cgroup membership does not map to this mount')
    mount = Path(mount)
    current = mount.joinpath(*member.relative_to(mounted_root).parts)
    paths = []
    while current.is_relative_to(mount):
        paths.append(current)
        if current == mount:
            return paths
        if len(paths) >= 64:
            raise ValueError('Cgroup ancestry exceeds observer bound')
        current = current.parent
    raise ValueError('Cgroup mapping escaped mount')


def cgroup_paths(pid):
    try:
        if os.readlink(f'/proc/{pid}/ns/cgroup') != os.readlink('/proc/self/ns/cgroup'):
            return {'unavailable': 'Different cgroup namespace'}
        member = next(line[3:] for line in Path(f'/proc/{pid}/cgroup').read_text().splitlines()
                      if line.startswith('0::'))
        for line in Path('/proc/self/mountinfo').read_text().splitlines():
            before, after = line.split(' - ', 1)
            if after.split()[0] == 'cgroup2':
                fields = before.split()
                # Linux mountinfo escapes whitespace/backslashes with octal.
                def unescape(value):
                    for encoded, literal in [('\\040', ' '), ('\\011', '\t'),
                                             ('\\012', '\n'), ('\\134', '\\')]:
                        value = value.replace(encoded, literal)
                    return value
                return hierarchy(member, unescape(fields[4]), unescape(fields[3]))
        return {'unavailable': 'No cgroup2 mount'}
    except (OSError, StopIteration, ValueError) as error:
        return {'unavailable': str(error)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--state', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--seconds', type=float, default=3600)
    parser.add_argument('--interval', type=float, default=.1)
    args = parser.parse_args()
    if not 0 < args.seconds <= 3600 or not .02 <= args.interval <= 60:
        parser.error('Seconds must be in (0,3600], interval in [0.02,60]')
    out = args.output.resolve()
    out.mkdir(mode=0o700)  # Existing evidence must not be replaced.
    state_path = args.state.resolve()
    initial = json.loads(state_path.read_text())
    source = Path(initial['sourceDirectory']).resolve()
    metadata = {'startedUtc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'state': str(state_path), 'sourceDirectory': str(source),
        'sourceCommit': initial.get('sourceCommit'), 'pid': os.getpid(),
        'intervalSeconds': args.interval, 'maximumSeconds': args.seconds,
        'observerFiles': {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in
                          [Path(__file__), Path(__file__).with_name('monitor.py')]},
        'boundary': 'External sampling adds load; missing counters are explicit. No application instrumentation, signals, settings, payloads or command-line logging. Cgroup and global counters can include unrelated work.'}
    (out / 'metadata.json').write_text(json.dumps(metadata, indent=2) + '\n')
    start, count, identities = time.monotonic(), 0, {}
    result = {'status': 'RUNNING'}
    try:
        with (out / 'samples.jsonl').open('x') as stream:
            while time.monotonic() - start < args.seconds:
                state = json.loads(state_path.read_text())
                if Path(state['sourceDirectory']).resolve() != source:
                    raise RuntimeError('Campaign source directory changed')
                jobs = [j for j in state['jobs'] if j['kind'] == 'fpm' and j['status'] == 'RUNNING']
                if not jobs and state['status'] not in ('RUNNING', 'RUNNING_WITH_FAILURE'):
                    result['reason'] = 'Owned campaign terminal'
                    break
                records, exited, groups = [], [], {}
                for job in jobs:
                    pid = job['pid']
                    key = (pid, job['startedUtc'])
                    stamp = owned_process(pid, source / 'tests/soak/fpm.py', identities.get(key))
                    if stamp is None:
                        exited.append(pid)
                        continue
                    identities[key] = stamp
                    paths = cgroup_paths(pid)
                    if isinstance(paths, dict):
                        groups[str(pid)] = paths
                    else:
                        for path in paths:
                            groups[str(path)] = {name: read(path / name) for name in
                                ['cpu.max', 'cpu.stat', 'cpu.pressure', 'cpu.weight']}
                    tree = process_tree(pid)
                    root_record = next((record for record in tree if record['pid'] == pid), None)
                    if root_record is None:
                        exited.append(pid)
                        continue
                    if root_record['startTicks'] != stamp:
                        raise RuntimeError('Owned runner PID/start identity changed during tree observation')
                    for record in tree:
                        proc = Path('/proc') / str(record['pid'])
                        record['schedstat'] = read(proc / 'schedstat')
                        record['waitChannel'] = read(proc / 'wchan')
                        record['timerSlackNs'] = read(proc / 'timerslack_ns')
                        after = process_identity(record['pid'])
                        if after is not None and after[1] == record['startTicks']:
                            records.append(record)
                row = {'unixSeconds': time.time(), 'monotonicNs': time.monotonic_ns(),
                    'elapsedSeconds': time.monotonic() - start, 'processes': records,
                    'exitedRoots': exited, 'cgroups': groups,
                    'cpuPressure': read('/proc/pressure/cpu'),
                    'globalCpuTicks': Path('/proc/stat').read_text().splitlines()[0]}
                stream.write(json.dumps(row) + '\n')
                stream.flush()
                count += 1
                time.sleep(args.interval)
        result['status'] = 'COMPLETE'
    except BaseException as error:
        result.update(status='FAIL', error=repr(error))
        raise
    finally:
        result.update(seconds=time.monotonic() - start, samples=count,
            finishedUtc=datetime.datetime.now(datetime.timezone.utc).isoformat())
        (out / 'report.json').write_text(json.dumps(result, indent=2) + '\n')


if __name__ == '__main__':
    main()
