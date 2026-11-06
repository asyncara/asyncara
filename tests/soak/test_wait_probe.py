"""Real libc forwarding, clocks, errno/signals, concurrent logging and fd cleanup."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest


class WaitProbeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory()
        cls.directory = Path(cls.temporary.name)
        cls.probe, cls.control = cls.directory/'probe.so', cls.directory/'control'
        compiler = shlex.split(os.environ.get('CC', 'cc')) + ['-std=c11', '-Wall', '-Wextra', '-Werror', '-O2']
        subprocess.run(compiler + ['-shared', '-fPIC', str(Path(__file__).with_name('wait_probe.c')),
                       '-ldl', '-o', str(cls.probe)], check=True)
        subprocess.run(compiler + ['-pthread', str(Path(__file__).with_name('wait_probe_control.c')),
                       '-o', str(cls.control)], check=True)

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def test_forwarding_with_and_without_probe_and_concurrent_records(self):
        plain = subprocess.run([str(self.control)], capture_output=True, text=True, timeout=10)
        self.assertEqual(plain.returncode, 0, plain.stderr)
        log = self.directory/'waits.jsonl'
        log.touch(exist_ok=False)
        env = os.environ | {'LD_PRELOAD':str(self.probe), 'ASYNCARA_WAIT_LOG':str(log)}
        children = [subprocess.Popen([str(self.control)], env=env, stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, text=True) for _ in range(4)]
        try:
            for child in children:
                stdout, stderr = child.communicate(timeout=10)
                self.assertEqual(child.returncode, 0, stderr)
                self.assertEqual(stderr, '')
                result = json.loads(stdout)
                self.assertEqual(result['fdsBefore'], result['fdsAfter'])
            rows = [json.loads(line) for line in log.read_text().splitlines()]
            for child in children:
                own = [row for row in rows if row['pid'] == child.pid]
                self.assertEqual(sum(row['type']=='loaded' for row in own), 1)
                waits = [row for row in own if row['type']=='wait']
                for operation in ['poll', 'epoll_wait']:
                    expired = [row for row in waits if row['operation']==operation and row['result']==0]
                    self.assertEqual(len(expired), 1)
                    self.assertEqual(expired[0]['timeoutMs'], 120)
                    self.assertGreaterEqual(expired[0]['elapsedNs'], 120_000_000)
                for row in waits:
                    self.assertEqual(row['pid'], row['tid'])
                    self.assertEqual(row['elapsedNs'], row['finishedMonotonicNs']-row['startedMonotonicNs'])
                    self.assertGreater(row['elapsedNs'], 50_000_000)
                    self.assertGreaterEqual(row['threadCpuNs'], 0)
        finally:
            for child in children:
                if child.poll() is None:
                    child.kill()
                child.communicate()

    def test_missing_log_is_an_explicit_failed_diagnostic(self):
        env = os.environ | {'LD_PRELOAD':str(self.probe), 'ASYNCARA_WAIT_LOG':str(self.directory/'missing')}
        result = subprocess.run([str(self.control)], env=env, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 125)
        self.assertIn('Asyncara wait probe failed', result.stderr)
        self.assertEqual(result.stdout, '')

    def test_native_threads_keep_separate_errno_cpu_and_complete_records(self):
        log = self.directory/'thread-waits.jsonl'
        log.touch(exist_ok=False)
        env = os.environ | {'LD_PRELOAD':str(self.probe), 'ASYNCARA_WAIT_LOG':str(log)}
        result = subprocess.run([str(self.control), 'threads'], env=env,
                                capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout + result.stderr, '')
        rows = [json.loads(line) for line in log.read_text().splitlines()]
        pid = rows[0]['pid']
        waits = [row for row in rows if row['type']=='wait']
        self.assertEqual(len(waits), 8)
        self.assertEqual(len({row['tid'] for row in waits}), 4)
        for row in waits:
            self.assertEqual(row['pid'], pid)
            self.assertNotEqual(row['tid'], pid)
            self.assertEqual(row['result'], 0)
            self.assertGreaterEqual(row['threadCpuNs'], 0)


if __name__ == '__main__':
    unittest.main()
