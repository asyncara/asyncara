#!/usr/bin/env python3
"""Short prepared-cache FPM integration using the unchanged audit lifecycle runner."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import time

p = argparse.ArgumentParser()
p.add_argument('--fpm', required=True, type=Path)
p.add_argument('--module', required=True, type=Path)
p.add_argument('--backend', choices=['epoll', 'poll'], required=True)
p.add_argument('--options', required=True, type=Path)
p.add_argument('--out', required=True, type=Path)
a = p.parse_args()
root = Path(__file__).resolve().parents[2]
out = a.out.resolve()
out.mkdir(parents=True, exist_ok=False)
os.environ.update(ASYNCARA_FPM_DIR=str(out), ASYNCARA_EXTENSION=str(a.module.resolve()), FPM_BIN=str(a.fpm.resolve()))
spec = importlib.util.spec_from_file_location('audit', root/'scripts/audit-fpm.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)
sys.path.insert(0, str(root/'tests/fast'))
from fpm_client import request

source = root/'tests/database/prepared_pg_fpm_request.php'
fixture = out/'selected-backend.php'
fixture.write_text('<?php putenv('+json.dumps('ASYNCARA_BACKEND='+a.backend)+'); putenv('
    +json.dumps('ASYNCARA_PG_TEST_OPTIONS='+str(a.options.resolve()))+'); require '+json.dumps(str(source))+';\n')

def checked_request(port, index, mode):
    value = request(audit, port, index, 'io' if mode == 'lifecycle' else mode, fixture)
    if 'before' in value:
        assert value['before']['eventLoopBackend'] == a.backend
    return value

audit.request = checked_request
results = []
started = time.monotonic()
report = {'status': 'RUNNING', 'backend': a.backend}
try:
    for case in [('A-lifecycle', 1, 20, 'io', False), ('PostgreSQL-four-workers', 4, 40, 'io', True)]:
        results.append(audit.run(*case))
        # Independently verify that native libpq sessions were actually released.
        options = json.loads(a.options.read_text())
        pg = Path(os.environ.get('POSTGRES_BIN', '/usr/lib/postgresql/16/bin'))
        deadline = time.monotonic()+5
        while True:
            remaining = subprocess.check_output([str(pg/'psql'), '-h', '127.0.0.1', '-p', options['port'],
                '-d', options['dbname'], '-Atc', "SELECT count(*) FROM pg_stat_activity WHERE application_name='asyncara_cache_fpm'"], text=True, timeout=5).strip()
            if remaining == '0': break
            if time.monotonic() >= deadline: raise RuntimeError('FPM left a PostgreSQL session alive')
            time.sleep(.01)
        results[-1]['remainingDatabaseSessions'] = 0
        print(case[0], 'PASS', flush=True)
    report['status'] = 'PASS'
except BaseException as exc:
    report.update(status='FAIL', error=repr(exc))
    raise
finally:
    audit.server.shutdown()
    audit.server.server_close()
    sha = lambda f: hashlib.sha256(f.read_bytes()).hexdigest()
    report.update(seconds=time.monotonic()-started, cases=results,
        identities={str(f): sha(f) for f in [source, fixture, root/'scripts/audit-fpm.py',
                    root/'tests/fast/fpm_client.py', a.module.resolve(), a.fpm.resolve()]})
    (out/'report.json').write_text(json.dumps(report, indent=2)+'\n')
