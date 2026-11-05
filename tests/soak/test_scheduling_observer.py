import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

from monitor import process_identity
from scheduling_observer import hierarchy, cgroup_paths


class SchedulingObserverTests(unittest.TestCase):
    def test_live_owned_runner_records_real_hierarchy_and_counters(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            marker = folder/'tests/soak/fpm.py'
            child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)', str(marker)])
            try:
                state = folder/'state.json'
                state.write_text(json.dumps({'status':'RUNNING', 'sourceDirectory':str(folder),
                    'jobs':[{'kind':'fpm','status':'RUNNING','pid':child.pid,'startedUtc':'live-control'}]}))
                output = folder/'output'
                command = [sys.executable, str(Path(__file__).with_name('scheduling_observer.py')),
                           '--state',str(state),'--output',str(output),'--seconds','.15','--interval','.02']
                run = subprocess.run(command,capture_output=True,text=True,timeout=10)
                self.assertEqual(run.returncode,0,run.stdout+run.stderr)
                rows = [json.loads(line) for line in (output/'samples.jsonl').read_text().splitlines()]
                self.assertTrue(rows)
                expected = [str(path) for path in cgroup_paths(child.pid)]
                for row in rows:
                    self.assertEqual(list(row['cgroups']),expected)
                    self.assertEqual(row['exitedRoots'],[])
                    self.assertEqual(row['processes'][0]['pid'],child.pid)
                    self.assertGreater(row['processes'][0]['startTicks'],0)
                    self.assertIsInstance(row['processes'][0]['schedstat'],str)
                    self.assertIsInstance(row['cgroups'][expected[0]]['cpu.stat'],str)
            finally:
                child.terminate()
                child.wait(timeout=5)

    def test_hierarchy_includes_limits_on_all_ancestors(self):
        root = Path('/private/cgroup-mount')
        self.assertEqual(hierarchy('/users/session', root),
                         [root/'users/session', root/'users', root])
        self.assertEqual(hierarchy('/tenant/session', root, '/tenant'),
                         [root/'session', root])
        for member in ['../escape', '/users/../../escape', '/unrelated']:
            with self.assertRaises(ValueError):
                hierarchy(member, root, '/tenant')

    def test_current_linux_membership_maps_to_existing_directory(self):
        paths = cgroup_paths(os.getpid())
        self.assertIsInstance(paths, list, paths)
        self.assertTrue(paths)
        self.assertTrue(all(path.is_dir() for path in paths))
        self.assertTrue((paths[0]/'cpu.stat').is_file())

    def test_exiting_owned_runner_is_not_reported_as_foreign(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            marker = folder/'tests/soak/fpm.py'
            child = subprocess.Popen([sys.executable, '-c', 'pass', str(marker)])
            try:
                deadline = time.monotonic()+5
                while process_identity(child.pid)[0] != 'Z':
                    if time.monotonic()>deadline:
                        self.fail('Child did not become zombie')
                    time.sleep(.01)
                state = folder/'state.json'
                state.write_text(json.dumps({'status':'RUNNING', 'sourceDirectory':str(folder),
                    'jobs':[{'kind':'fpm','status':'RUNNING','pid':child.pid,'startedUtc':'control'}]}))
                output = folder/'output'
                command = [sys.executable, str(Path(__file__).with_name('scheduling_observer.py')),
                           '--state',str(state),'--output',str(output),'--seconds','.15','--interval','.02']
                run = subprocess.run(command,capture_output=True,text=True,timeout=10)
                self.assertEqual(run.returncode,0,run.stdout+run.stderr)
                self.assertEqual(json.loads((output/'report.json').read_text())['status'],'COMPLETE')
                rows = [json.loads(line) for line in (output/'samples.jsonl').read_text().splitlines()]
                self.assertTrue(rows)
                self.assertTrue(all(row['exitedRoots']==[child.pid] and row['processes']==[] for row in rows))
                original = (output/'samples.jsonl').read_bytes()
                again = subprocess.run(command,capture_output=True,text=True,timeout=10)
                self.assertNotEqual(again.returncode,0)
                self.assertEqual((output/'samples.jsonl').read_bytes(),original)
            finally:
                child.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
