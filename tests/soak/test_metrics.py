"""Compare against historical assertions and verify bounded observer memory."""
import random
import json
from pathlib import Path
import statistics
import tempfile
import tracemalloc
import unittest

from metrics import FpmGrowth, mixed_samples


def sample(pid, value, second=0):
    return {'pid': pid, 'rssKiB': value, 'threads': 1, 'fds': 6, 'seconds': second}


class GrowthTests(unittest.TestCase):
    def test_historical_result_equivalence(self):
        rng = random.Random(20261103)
        for _ in range(200):
            values = {pid: [rng.randint(10000, 30000) for _ in range(rng.randint(1, 150))]
                      for pid in range(4)}
            tracker = FpmGrowth([sample(pid, 24000) for pid in values])
            for pid, rss in values.items():
                for index, value in enumerate(rss):
                    tracker.add(sample(pid, value, index))
            result = tracker.summary()
            ranges, rises = {}, {}
            for pid, rss in values.items():
                if len(rss) <= 4:
                    continue
                ranges[pid] = max(rss[3:]) - min(rss[3:])
                low, rise = rss[3], 0
                for value in rss[3:]:
                    low = min(low, value)
                    rise = max(rise, value - low)
                rises[pid] = rise
            self.assertEqual(result['rssRangeKiBAfterWarmup'], ranges)
            self.assertEqual(result['maxRssIncreaseKiB'], rises)
            self.assertEqual(result['growthBeyondWarmupPeakKiB'],
                             {pid: max(0, max(rss) - 24000) for pid, rss in values.items()})

    def test_failure_boundaries_are_unchanged(self):
        tracker = FpmGrowth([sample(1, 10000)])
        with self.assertRaisesRegex(AssertionError, 'No steady-state'):
            tracker.check()
        tracker.add(sample(1, 18191))
        tracker.check()
        tracker.add(sample(1, 18192))
        with self.assertRaisesRegex(AssertionError, 'Persistent worker RSS'):
            tracker.check()
        with self.assertRaisesRegex(AssertionError, 'Unexpected worker'):
            tracker.add(sample(2, 10000))
        for key, value in [('threads', 2), ('fds', 7)]:
            with self.assertRaisesRegex(AssertionError, 'Idle resource'):
                tracker.add(sample(1, 10000) | {key: value})

    def test_long_campaign_observer_does_not_retain_samples(self):
        tracker = FpmGrowth([sample(pid, 20000) for pid in range(4)])
        tracemalloc.start()
        try:
            for index in range(200000):
                tracker.add(sample(index % 4, 20000 + index % 100, index * .2))
            current, peak = tracemalloc.get_traced_memory()
        finally:
            tracemalloc.stop()
        tracker.check()
        self.assertEqual(tracker.count, 200000)
        self.assertEqual(len(tracker.workers), 4)
        self.assertLess(current, 65536)
        self.assertLess(peak, 65536)

    def test_trend_retains_growth_direction(self):
        tracker = FpmGrowth([sample(1, 10000)])
        for index in range(20):
            tracker.add(sample(1, 10000 + index * 2, index))
        self.assertAlmostEqual(tracker.summary()['rssSlopeKiBPerHour'][1], 7200)

    def test_mixed_warmup_and_endpoint_windows_match_previous_harness(self):
        rng = random.Random(20261103)
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'samples.jsonl'
            for count in [0, 1, 2, 3, 7, 19, 20, 21, 1001]:
                values = [rng.randrange(20000, 50000) for _ in range(count)]
                with path.open('w') as stream:
                    for index, value in enumerate(values):
                        stream.write(json.dumps({'type': 'sample', 'seconds': index * 5,
                            'os': {'rssKiB': value, 'fds': 20, 'threads': 5, 'sockets': 7},
                            'phpBytes': value, 'native': {'nativeMemoryBytes': 0}}) + '\n')
                    stream.write('{"type":"complete","seconds":10000}\n')
                result = mixed_samples(path)
                self.assertEqual(result['samples'], count)
                self.assertEqual(result['completeRecords'], 1)
                self.assertEqual(result['parseErrorCount'], 0)
                steady = values[max(1, count // 5):] or values
                if not steady:
                    self.assertIsNone(result['steadyTrends']['rssKiB'])
                    continue
                width = min(5, max(1, len(steady) // 3))
                self.assertEqual(result['steadyTrends']['rssKiB']['endMinusStartMedian'],
                    statistics.median(steady[-width:]) - statistics.median(steady[:width]))

    def test_malformed_and_duplicate_completion_remain_visible(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'bad.jsonl'
            path.write_text('invalid\n[]\n' * 50 + '{"type":"complete"}\n' * 2)
            result = mixed_samples(path)
            self.assertEqual(result['parseErrorCount'], 100)
            self.assertEqual(len(result['parseErrors']), 20)
            self.assertEqual(result['completeRecords'], 2)


if __name__ == '__main__':
    unittest.main()
