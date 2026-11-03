#!/usr/bin/env python3
"""Record unsuppressed callback-type diagnostics with a matching Clang PHP build."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--php', type=Path, required=True)
parser.add_argument('--extension', type=Path, required=True)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
php, extension, out = args.php.resolve(), args.extension.resolve(), args.output.resolve()
if out.exists():
    parser.error('Preserve evidence: choose a new output directory')
out.mkdir(parents=True)
report = {'classification': 'RUNNING', 'phpSha256': hashlib.sha256(php.read_bytes()).hexdigest(),
          'extensionSha256': hashlib.sha256(extension.read_bytes()).hexdigest(), 'runs': []}
cases = [('without', ['-v'], False), ('with', ['-d', 'extension=' + str(extension), '-v'], False),
         ('without-halt', ['-v'], True), ('with-halt', ['-d', 'extension=' + str(extension), '-v'], True),
         ('information', ['-d', 'extension=' + str(extension), '--ri', 'asyncara'], False),
         ('tasks', ['-d', 'extension=' + str(extension), 'tests/audit/regressions.php', 'tasks', '1000'], False)]
try:
    for name, arguments, halt in cases:
        command = [str(php), '-n'] + arguments
        env = os.environ | {'ASAN_OPTIONS': 'detect_leaks=1:halt_on_error=1',
                            'UBSAN_OPTIONS': 'print_stacktrace=1:halt_on_error=' + str(int(halt))}
        result = subprocess.run(command, cwd=root, env=env, text=True, capture_output=True, timeout=120)
        log = out / (name + '.log')
        log.write_text(result.stdout + result.stderr)
        functions = re.findall(r'call to function (.*?) through pointer', result.stderr)
        report['runs'].append({'name': name, 'command': command, 'exit': result.returncode,
            'diagnostics': result.stderr.count('runtime error:'), 'functions': functions,
            'log': log.name, 'sha256': hashlib.sha256(log.read_bytes()).hexdigest()})
        (out / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
        if result.returncode not in (0, 1):
            raise RuntimeError('Unexpected instrumented process failure; inspect log')
    report['classification'] = 'DIAGNOSTIC_OBSERVATIONS_NOT_A_CLEAN_TEST_PASS'
finally:
    (out / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
print(report['classification'])
