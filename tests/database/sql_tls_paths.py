#!/usr/bin/env python3
"""Run separate PHP processes for irreversible path-policy controls.

Used by the existing private mTLS server fixtures; does not own another server.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import time

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--module', required=True)
p.add_argument('--out', type=Path, required=True)
modes = ['relative', 'invalid', 'policy', 'late-policy', 'native-policy-ca', 'native-policy-cert', 'native-policy-key']
p.add_argument('--modes', nargs='+', choices=modes, default=modes)
a = p.parse_args()
root = Path(__file__).resolve().parents[2]
out = a.out.resolve(); out.mkdir(mode=0o700, parents=True, exist_ok=False)
report = {'status':'RUNNING', 'rows':[]}
try:
    for mode in a.modes:
        command = [a.php, '-n', '-d', 'extension='+str(Path(a.module).resolve()), str(root/'tests/database/sql_tls_paths.php'), mode]
        started = time.monotonic()
        with (out/(mode+'.log')).open('x') as log:
            child = subprocess.Popen(command, cwd=root, stdin=subprocess.DEVNULL, stdout=log, stderr=log)
            (out/'active.json').write_text(json.dumps({'pid':child.pid, 'command':command}))
            try: code = child.wait(timeout=30)
            except subprocess.TimeoutExpired:
                child.terminate()
                try: child.wait(timeout=5)
                except subprocess.TimeoutExpired: child.kill(); child.wait()
                raise
        raw = (out/(mode+'.log')).read_bytes()
        row = {'mode':mode, 'command':command, 'exit':code, 'seconds':time.monotonic()-started, 'sha256':hashlib.sha256(raw).hexdigest()}
        report['rows'].append(row)
        if code: raise RuntimeError('TLS filename control failed: '+mode)
        row['result'] = json.loads(raw)
        assert row['result']['status'] == 'PASS'
        (out/'report.json').write_text(json.dumps(report, indent=2))
    report['status'] = 'PASS'
except BaseException as error:
    report.update(status='FAIL', error=repr(error))
finally:
    (out/'report.json').write_text(json.dumps(report, indent=2)+'\n')
    (out/'active.json').write_text('{"status":"TERMINAL"}')
print(json.dumps(report, indent=2))
raise SystemExit(0 if report['status'] == 'PASS' else 1)
