import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

from monitor import ProcessMonitor, process_tree, owned_process, process_identity


class MonitorTests(unittest.TestCase):
    def test_owned_zombie_is_exited_not_an_argument_mismatch(self):
        marker = Path('/asyncara-owned-exit-control')
        child = subprocess.Popen([sys.executable, '-c', 'pass', str(marker)])
        try:
            deadline = time.monotonic() + 5
            while process_identity(child.pid)[0] != 'Z':
                if time.monotonic() > deadline:
                    self.fail('Child did not exit')
                time.sleep(.01)
            # This is the independently reproduced old observer failure.
            self.assertNotIn(os.fsencode(marker), (Path('/proc') / str(child.pid) / 'cmdline').read_bytes().split(b'\0'))
            self.assertIsNone(owned_process(child.pid, marker))
        finally:
            child.wait(timeout=5)
        self.assertIsNone(owned_process(child.pid, marker))

    def test_owned_identity_rejects_live_mismatch_and_reuse(self):
        marker = Path('/asyncara-owned-live-control')
        child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)', str(marker)])
        try:
            stamp = owned_process(child.pid, marker)
            self.assertIsInstance(stamp, int)
            self.assertEqual(owned_process(child.pid, marker, stamp), stamp)
            with self.assertRaisesRegex(RuntimeError, 'unexpected executable arguments'):
                owned_process(child.pid, Path('/wrong-owner'))
            with self.assertRaisesRegex(RuntimeError, 'identity changed'):
                owned_process(child.pid, marker, stamp + 1)
            with patch('monitor.process_identity', side_effect=[('R', stamp), ('Z', stamp)]):
                self.assertIsNone(owned_process(child.pid, Path('/wrong-owner')))
        finally:
            child.terminate()
            child.wait(timeout=5)

    def test_child_descriptors_and_virtual_memory_are_observed(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'observations.jsonl'
            child = subprocess.Popen([sys.executable, '-c',
                'import os,time; files=[open("/dev/null") for _ in range(12)]; '
                'print(os.getpid(),flush=True); time.sleep(30)'], stdout=subprocess.PIPE, text=True)
            try:
                pid = int(child.stdout.readline())
                with ProcessMonitor(path, .02):
                    deadline = time.monotonic() + 3
                    while not path.exists() or len(path.read_text().splitlines()) < 3:
                        if time.monotonic() > deadline:
                            self.fail('Missing process observations')
                        time.sleep(.01)
                records = [json.loads(line) for line in path.read_text().splitlines()]
                for record in records:
                    self.assertEqual(record['rootPid'], os.getpid())
                    found = [p for p in record['processes'] if p['pid'] == pid]
                    self.assertEqual(len(found), 1)
                    self.assertGreaterEqual(found[0]['fds'], 15)
                    self.assertGreater(found[0]['virtualKiB'], 0)
                    self.assertGreater(found[0]['rssKiB'], 0)
                    self.assertGreater(found[0]['startTicks'], 0)
            finally:
                child.terminate()
                child.wait(timeout=5)
                child.stdout.close()

    def test_existing_evidence_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'observations.jsonl'
            path.write_text('preserved\n')
            with self.assertRaisesRegex(RuntimeError, 'OS observer failed'):
                with ProcessMonitor(path, .02):
                    time.sleep(.05)
            self.assertEqual(path.read_text(), 'preserved\n')

    def test_owned_daemon_is_observed_and_pid_mismatch_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            marker = directory / 'private-data'
            child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)', str(marker)])
            try:
                pid_file = directory / 'postmaster.pid'
                pid_file.write_text(str(child.pid) + '\n')
                path = directory / 'daemon.jsonl'
                # Model a daemon which no longer appears among our descendants.
                with patch('monitor.process_tree', side_effect=lambda pid: [] if pid == os.getpid() else process_tree(pid)):
                    with ProcessMonitor(path, .02, [(pid_file, marker)]):
                        time.sleep(.08)
                records = [json.loads(line) for line in path.read_text().splitlines()]
                self.assertTrue(records)
                self.assertTrue(all(any(p['pid'] == child.pid for p in r['processes']) for r in records))
                with self.assertRaisesRegex(RuntimeError, 'OS observer failed'):
                    with ProcessMonitor(directory / 'mismatch.jsonl', .02, [(pid_file, directory / 'wrong-data')]):
                        time.sleep(.08)
            finally:
                child.terminate()
                child.wait(timeout=5)

    def test_inaccessible_child_descriptors_are_reported_explicitly(self):
        child = subprocess.Popen([sys.executable, '-c',
            'import os,time; print(os.getpid(),flush=True); time.sleep(30)'], stdout=subprocess.PIPE, text=True)
        try:
            pid = int(child.stdout.readline())
            original = Path.iterdir
            def restricted(path):
                if path == Path('/proc') / str(pid) / 'fd':
                    raise PermissionError(13, 'Permission denied', str(path))
                return original(path)
            with patch.object(Path, 'iterdir', restricted):
                record = next(r for r in process_tree(os.getpid()) if r['pid'] == pid)
            self.assertIsNone(record['fds'])
            self.assertIsNone(record['sockets'])
            self.assertTrue(record['unavailable'])
            self.assertGreater(record['rssKiB'], 0)
        finally:
            child.terminate()
            child.wait(timeout=5)
            child.stdout.close()


if __name__ == '__main__':
    unittest.main()
