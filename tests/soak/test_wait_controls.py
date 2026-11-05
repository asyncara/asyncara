"""Validate wait-control commands and measurements, without a latency threshold."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest


class WaitControlTests(unittest.TestCase):
    def test_backend_selection_and_invalid_arguments(self):
        with tempfile.TemporaryDirectory() as directory:
            binary = str(Path(directory) / 'wait-control')
            subprocess.run(shlex.split(os.environ.get('CC', 'cc')) + [
                '-std=c11', '-D_POSIX_C_SOURCE=200809L', '-Wall', '-Wextra', '-Werror', '-O2',
                str(Path(__file__).with_name('epoll_latency.c')), '-o', binary], check=True)
            for backend in [None, 'epoll', 'poll', 'nanosleep']:
                command = [binary, '.03'] + ([backend] if backend else [])
                result = subprocess.run(command, capture_output=True, text=True, timeout=10)
                self.assertEqual(result.returncode, 0, result.stderr)
                rows = [json.loads(line) for line in result.stdout.splitlines()]
                self.assertEqual(rows[-1]['type'], 'complete')
                self.assertGreaterEqual(rows[-1]['seconds'], .03)
                self.assertGreater(rows[-1]['waits'], 0)
                self.assertEqual(rows[-1]['over50ms'], len(rows) - 1)
                self.assertTrue(all(row['backend'] == (backend or 'epoll') for row in rows))
                for row in rows[:-1]:
                    self.assertEqual(row['requestedMilliseconds'], 5)
                    self.assertGreater(row['elapsedSeconds'], .05)
                    self.assertGreaterEqual(row['cpuSeconds'], 0)
            for arguments in [[], ['0'], ['-1'], ['nan'], ['inf'], ['3601'], ['.1x'],
                              ['.1', 'unknown'], ['.1', 'epoll', 'extra']]:
                result = subprocess.run([binary] + arguments, capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 2, arguments)
                self.assertIn('Usage:', result.stderr)
                self.assertEqual(result.stdout, '')


if __name__ == '__main__':
    unittest.main()
