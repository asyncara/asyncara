import json
import gc
from pathlib import Path
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from analyze_waits import analyze, records


class AnalysisTests(unittest.TestCase):
    def test_fatal_and_process_identity_limits_fail_without_truncation(self):
        with tempfile.TemporaryDirectory() as directory:
            folder=Path(directory)
            (folder/'waits.jsonl').write_text('')
            fatal=folder/'fatal-requests.jsonl'
            fatal.write_text('{"pid":1,"request":"x"}\n'*4097)
            with self.assertRaisesRegex(ValueError,'capacity'):analyze(folder)
            fatal.unlink()
            calls=({'pid':pid,'request':'x','startedMonotonicNs':1,'finishedMonotonicNs':2,'elapsedNs':1}
                   for pid in range(1,4098))
            (folder/'native-polls.jsonl').write_text(''.join(json.dumps(call)+'\n' for call in calls))
            with self.assertRaisesRegex(ValueError,'identity capacity'):analyze(folder)

    def test_temporary_index_closes_without_garbage_collection(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder/'waits.jsonl').write_text('')
            before=len(list(Path('/proc/self/fd').iterdir()))
            enabled=gc.isenabled()
            gc.disable()
            try:
                for _ in range(10):self.assertEqual(analyze(folder)['waitRecords'],0)
                self.assertEqual(len(list(Path('/proc/self/fd').iterdir())),before)
            finally:
                if enabled:gc.enable()

    def test_only_same_thread_fully_contained_waits_are_attributed(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            def wait(pid, tid, start, end):
                return {'type':'wait','pid':pid,'tid':tid,'startedMonotonicNs':start,
                        'finishedMonotonicNs':end,'elapsedNs':end-start}
            rows = [wait(1,1,110,150), wait(1,2,120,160), wait(2,2,110,150),
                    wait(1,1,190,210), wait(1,1,80,105)]
            (folder/'waits.jsonl').write_text(''.join(json.dumps(r)+'\n' for r in rows))
            call={'pid':1,'request':'failed','startedMonotonicNs':100,'finishedMonotonicNs':200,'elapsedNs':100}
            (folder/'native-polls.jsonl').write_text(json.dumps(call)+'\n')
            (folder/'fatal-requests.jsonl').write_text('{"pid":1,"request":"failed"}\n')
            result=analyze(folder)
            self.assertEqual(result['matchedWaits'],1)
            self.assertEqual(result['nativeCallsWithLongWait'],1)
            self.assertEqual(result['fatalRequestCalls'][0]['matchedWaitNs'],40)
            self.assertEqual(result['fatalRequestCalls'][0]['remainingNativeIntervalNs'],60)

    def test_invalid_intervals_and_duplicate_waits_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            row={'type':'wait','pid':1,'tid':1,'startedMonotonicNs':100,'finishedMonotonicNs':200,'elapsedNs':99}
            (folder/'waits.jsonl').write_text(json.dumps(row)+'\n')
            with self.assertRaisesRegex(ValueError,'Invalid monotonic'):analyze(folder)
            row['elapsedNs']=100
            (folder/'waits.jsonl').write_text((json.dumps(row)+'\n')*2)
            (folder/'native-polls.jsonl').write_text(json.dumps(row|{'request':'x'})+'\n')
            with self.assertRaisesRegex(ValueError,'duplicate'):analyze(folder)

    def test_capacity_and_truncated_json_are_errors(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'rows'
            path.write_text('{}\n{}\n')
            with self.assertRaisesRegex(ValueError,'capacity'):list(records(path,1))
            path.write_text('{')
            with self.assertRaises(json.JSONDecodeError):list(records(path))
            path.write_text(' '*65537)
            with self.assertRaisesRegex(ValueError,'capacity'):list(records(path))
            with patch.object(Path, 'stat', return_value=SimpleNamespace(st_size=256*1024*1024+1)):
                with self.assertRaisesRegex(ValueError,'256MiB'):list(records(path))


if __name__ == '__main__':
    unittest.main()
