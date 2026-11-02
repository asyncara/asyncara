#!/usr/bin/env python3
import os, pathlib, subprocess, tempfile

root = pathlib.Path(__file__).resolve().parent.parent
extension = root / os.environ.get('ASYNCARA_EXTENSION', 'build/asyncara.so')
subprocess.run(['python3', str(root/'scripts/check-build.py'), str(extension)], check=True)
with tempfile.NamedTemporaryFile(prefix='asyncara-fatal-') as fixture:
    fixture.truncate(12 * 1024 * 1024)
    command = [os.environ.get('PHP_BIN', 'php'), '-n', '-d', 'memory_limit=8M',
               '-d', 'extension='+str(extension), str(root/'tests/fatal_memory.php'), fixture.name]
    result = subprocess.run(command, capture_output=True, text=True, timeout=20)
    if result.returncode != 255 or 'Allowed memory size' not in result.stdout + result.stderr:
        raise RuntimeError('Unexpected fatal cleanup result: '+repr(result))
    print('PHP memory-limit bailout and native request cleanup: PASS')
