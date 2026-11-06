#!/usr/bin/env python3
"""Correlate completed FPM diagnostic logs using exact monotonic call intervals.

The temporary SQLite index bounds resident history; original logs are unchanged.
Only main-thread waits wholly inside a PHP native-call interval are attributed.
"""
import argparse
from contextlib import closing
import hashlib
import json
from pathlib import Path
import sqlite3
import tempfile

MAX_FILE_BYTES = 256 * 1024 * 1024
MAX_DIAGNOSTIC_LOGS = 4096
ERROR_LOG_NAMES = {'error.log', 'php-errors.log', 'startup.log', 'fpm.log'}
ERROR_MARKERS = (b'Asyncara wait probe failed:', b'Asyncara poll profile failed:')


def diagnostic_log_files(folder):
    files = {}
    for path in folder.rglob('*.log'):
        if path.name not in ERROR_LOG_NAMES:
            continue
        if len(files) >= MAX_DIAGNOSTIC_LOGS:
            raise ValueError('Diagnostic error-log capacity exceeded')
        files[path.relative_to(folder).as_posix()] = path
    return files


def check_diagnostic_logs(folder):
    """Expected PHP OOM cases cannot hide a failed measurement logger."""
    files = diagnostic_log_files(folder)
    initial = {name: fingerprint(path) for name, path in files.items()}
    for path in files.values():
        size = 0
        with path.open('rb') as stream:
            for count in range(1_000_001):
                line = stream.readline(65537)
                if not line:
                    break
                size += len(line)
                if count == 1_000_000 or len(line) > 65536 or size > MAX_FILE_BYTES:
                    raise ValueError('Diagnostic error-log capacity exceeded: ' + str(path))
                if any(marker in line for marker in ERROR_MARKERS):
                    raise ValueError('Diagnostic instrumentation failed: ' + str(path))
    final = {name: fingerprint(path) for name, path in diagnostic_log_files(folder).items()}
    if initial != final:
        raise ValueError('Error logs changed during analysis; wait for diagnostic completion')
    return initial


def records(path, maximum=1_000_000):
    if path.stat().st_size > MAX_FILE_BYTES:
        raise ValueError('Diagnostic input exceeds 256MiB: ' + str(path))
    size = 0
    with path.open('rb') as stream:
        for count in range(maximum + 1):
            line = stream.readline(65537)
            if not line:
                return
            if count == maximum or len(line) > 65536:
                raise ValueError('Diagnostic record/line capacity exceeded: ' + str(path))
            size += len(line)
            if size > MAX_FILE_BYTES:
                raise ValueError('Diagnostic input exceeds 256MiB: ' + str(path))
            yield json.loads(line)


def integer(record, name):
    value = record[name]
    if type(value) is not int or not -(2**63) <= value < 2**63:
        raise ValueError('Expected a signed 64-bit integer: ' + name)
    return value


def interval(record):
    start, end = integer(record, 'startedMonotonicNs'), integer(record, 'finishedMonotonicNs')
    if end < start or end - start != integer(record, 'elapsedNs'):
        raise ValueError('Invalid monotonic interval')
    return start, end


def fingerprint(path):
    if path.stat().st_size > MAX_FILE_BYTES:
        raise ValueError('Diagnostic input exceeds 256MiB: ' + str(path))
    digest = hashlib.sha256()
    size = 0
    with path.open('rb') as stream:
        while chunk := stream.read(1048576):
            size += len(chunk)
            if size > MAX_FILE_BYTES:
                raise ValueError('Diagnostic input exceeds 256MiB: ' + str(path))
            digest.update(chunk)
    return {'sha256': digest.hexdigest(), 'bytes': path.stat().st_size}


def analyze(folder):
    error_logs = check_diagnostic_logs(folder)
    files = {name: folder/name for name in ['waits.jsonl', 'native-polls.jsonl', 'fatal-requests.jsonl']
             if (folder/name).exists()}
    if 'waits.jsonl' not in files:
        raise ValueError('Wait probe log is required')
    initial = {name: fingerprint(path) for name, path in files.items()}
    # Full FPM lifecycle diagnostics deliberately include hundreds of OOM cases.
    fatal = {(integer(row, 'pid'), row['request']) for row in records(files['fatal-requests.jsonl'], 4096)} \
        if 'fatal-requests.jsonl' in files else set()
    result = {'waitRecords': 0, 'nativeCalls': 0, 'nativeCallsWithLongWait': 0,
              'matchedWaits': 0, 'largestMatchedWaits': [], 'fatalRequestCalls': []}
    with tempfile.TemporaryDirectory(prefix='asyncara-wait-index-') as temporary, \
            closing(sqlite3.connect(str(Path(temporary)/'index.sqlite'))) as database:
        database.execute('PRAGMA cache_size=-2048')
        database.execute('CREATE TABLE waits (pid INTEGER, start INTEGER, end INTEGER, row TEXT)')
        for row in records(files['waits.jsonl']):
            if row['type'] == 'loaded':
                continue
            if row['type'] != 'wait':
                raise ValueError('Unknown wait record')
            start, end = interval(row)
            result['waitRecords'] += 1
            if integer(row, 'pid') == integer(row, 'tid'):
                database.execute('INSERT INTO waits VALUES (?, ?, ?, ?)',
                                 (row['pid'], start, end, json.dumps(row)))
        database.execute('CREATE INDEX interval_lookup ON waits(pid,start)')
        database.commit()
        latest = {}
        for call in records(files['native-polls.jsonl']) if 'native-polls.jsonl' in files else []:
            pid, (start, end) = integer(call, 'pid'), interval(call)
            if start < latest.get(pid, 0):
                raise ValueError('Overlapping/out-of-order native calls')
            if pid not in latest and len(latest) >= 4096:
                raise ValueError('Diagnostic process identity capacity exceeded')
            latest[pid] = end
            result['nativeCalls'] += 1
            count, elapsed, last_end = 0, 0, start
            for entry, in database.execute('SELECT row FROM waits WHERE pid=? AND start>=? AND start<=? AND end<=? ORDER BY start',
                                          (pid, start, end, end)):
                wait = json.loads(entry)
                if wait['startedMonotonicNs'] < last_end:
                    raise ValueError('Overlapping/duplicate main-thread waits')
                last_end = wait['finishedMonotonicNs']
                count += 1
                elapsed += wait['elapsedNs']
                item = {'request': call['request'], 'nativeElapsedNs': call['elapsedNs'], 'wait': wait}
                result['largestMatchedWaits'].append(item)
                result['largestMatchedWaits'].sort(key=lambda item: item['wait']['elapsedNs'], reverse=True)
                del result['largestMatchedWaits'][20:]
            result['matchedWaits'] += count
            result['nativeCallsWithLongWait'] += count > 0
            if (pid, call['request']) in fatal:
                if len(result['fatalRequestCalls']) >= 1024:
                    raise ValueError('Fatal-request summary capacity exceeded')
                result['fatalRequestCalls'].append({'call': call, 'matchedWaits': count,
                    'matchedWaitNs': elapsed, 'remainingNativeIntervalNs': call['elapsedNs'] - elapsed})
    final = {name: fingerprint(folder/name) for name in ['waits.jsonl', 'native-polls.jsonl', 'fatal-requests.jsonl']
             if (folder/name).exists()}
    final_errors = {name: fingerprint(path) for name, path in diagnostic_log_files(folder).items()}
    if initial != final or error_logs != final_errors:
        raise ValueError('Logs changed during analysis; wait for diagnostic completion')
    result['files'] = initial
    result['errorLogs'] = error_logs
    result['boundary'] = ('Top 20 matched waits retained; complete raw logs preserved. Only waits above 50ms are logged. '
        'Unmatched time is not automatically blocking or CPU work. Intervals include descheduling near libc call entry/exit '
        'and measurement overhead; this is not a kernel off-CPU trace. Temporary index uses a 2MiB SQLite page cache; '
        '256MiB/file, one million records/file, 64KiB lines, 4096 native PIDs, 4096 fatal records '
        'and 1024 fatal-call summaries are hard failure bounds. At most 4096 known FPM error logs are checked '
        'for instrumentation failures with the same per-file byte/line/record limits. '
        'The cache size is not a total process memory limit.')
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Preserve evidence: choose a new output')
    result = analyze(args.directory)
    with args.output.open('x') as stream:
        json.dump(result, stream, indent=2)
        stream.write('\n')
