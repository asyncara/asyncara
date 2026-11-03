"""Verify diagnostic forwarding/logging without loading Asyncara or starting FPM."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class ProfileTests(unittest.TestCase):
    def test_return_exception_and_fatal_records(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            shutil.copy2(Path(__file__).with_name('fpm_poll_profile.php'), folder / 'profile.php')
            script = folder / 'request.php'
            script.write_text('''<?php
function asyncara_poll(array $readers, array $writers, int $timeout): array {
    usleep(60000);
    if ($timeout === 2) throw new RuntimeException('original native error');
    return [$readers, $writers, $timeout];
}
require __DIR__ . '/profile.php';
$_SERVER['AUDIT_ID'] = 'control';
if (Asyncara\\asyncara_poll([3], [4], 1) !== [[3], [4], 1]) exit(10);
try {
    Asyncara\\asyncara_poll([], [], 2);
    exit(11);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'original native error') exit(12);
}
// The global function must remain unchanged and produce no profile entry.
if (asyncara_poll([5], [], 0) !== [[5], [], 0]) exit(13);
''')
            command = [os.environ.get('PHP_BIN', 'php'), '-n', str(script)]
            result = subprocess.run(command, capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(result.stdout + result.stderr, '')
            records = [json.loads(line) for line in (folder / 'native-polls.jsonl').read_text().splitlines()]
            self.assertEqual(len(records), 2)
            self.assertEqual([r['timeoutMs'] for r in records], [1, 2])
            self.assertEqual([r['readers'] for r in records], [1, 0])
            self.assertEqual([r['writers'] for r in records], [1, 0])
            for record in records:
                self.assertEqual(record['request'], 'control')
                self.assertGreater(record['elapsedNs'], 50_000_000)
                self.assertGreaterEqual(record['cpuUs'], 0)
            self.assertFalse((folder / 'fatal-requests.jsonl').exists())
            script.write_text("<?php require __DIR__ . '/profile.php'; throw new RuntimeException('fatal control');")
            result = subprocess.run(command, capture_output=True, text=True, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            failures = [json.loads(line) for line in (folder / 'fatal-requests.jsonl').read_text().splitlines()]
            self.assertEqual(len(failures), 1)
            self.assertEqual(failures[0]['error']['type'], 1)
            self.assertIn('fatal control', failures[0]['error']['message'])


if __name__ == '__main__':
    unittest.main()
