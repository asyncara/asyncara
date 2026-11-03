"""Bounded FPM aggregation; raw observations belong in append-only JSONL files."""
from dataclasses import dataclass
from collections import deque
import json
import statistics


@dataclass
class WorkerRss:
    count: int = 0
    peak: int = 0
    low: int | None = None
    high: int = 0
    rise: int = 0
    mean_time: float = 0.0
    mean_rss: float = 0.0
    time_variance: float = 0.0
    covariance: float = 0.0

    def add(self, seconds: float, rss: int) -> None:
        self.count += 1
        self.peak = max(self.peak, rss)
        # The historical range/rise calculation excludes the first three samples.
        if self.count > 3:
            self.low = rss if self.low is None else min(self.low, rss)
            self.high = max(self.high, rss)
            self.rise = max(self.rise, rss - self.low)
        dt = seconds - self.mean_time
        dy = rss - self.mean_rss
        self.mean_time += dt / self.count
        self.mean_rss += dy / self.count
        self.time_variance += dt * (seconds - self.mean_time)
        self.covariance += dt * (rss - self.mean_rss)


class FpmGrowth:
    """Preserve the original RSS/fd/thread criterion using O(worker count) space."""

    def __init__(self, warmup: list[dict]):
        self.warm_peaks: dict[int, int] = {}
        for sample in warmup:
            pid = sample['pid']
            self.warm_peaks[pid] = max(self.warm_peaks.get(pid, 0), sample['rssKiB'])
        if not self.warm_peaks:
            raise ValueError('Warmup samples are required')
        self.workers: dict[int, WorkerRss] = {}
        self.count = 0

    def add(self, sample: dict) -> None:
        pid = sample['pid']
        if pid not in self.warm_peaks:
            raise AssertionError('Unexpected worker replacement after warmup')
        if sample['threads'] != 1 or sample['fds'] != 6:
            raise AssertionError('Idle resource escape')
        self.workers.setdefault(pid, WorkerRss()).add(sample['seconds'], sample['rssKiB'])
        self.count += 1

    def summary(self) -> dict:
        return {
            'sampleCount': self.count,
            'rssRangeKiBAfterWarmup': {
                pid: s.high - s.low for pid, s in self.workers.items() if s.count > 4
            },
            'maxRssIncreaseKiB': {
                pid: s.rise for pid, s in self.workers.items() if s.count > 4
            },
            'warmupPeakKiB': self.warm_peaks,
            'growthBeyondWarmupPeakKiB': {
                pid: max(0, s.peak - self.warm_peaks[pid]) for pid, s in self.workers.items()
            },
            'rssSlopeKiBPerHour': {
                pid: 3600 * s.covariance / s.time_variance if s.time_variance else 0.0
                for pid, s in self.workers.items()
            },
        }

    def check(self) -> None:
        if not self.count:
            raise AssertionError('No steady-state samples')
        # Deliberately unchanged: equality is a failure, not an accepted boundary.
        if any(s.peak - self.warm_peaks[pid] >= 8192 for pid, s in self.workers.items()):
            raise AssertionError('Persistent worker RSS growth')


class Trend:
    """Online least-squares slope plus the historical bounded endpoint windows."""

    def __init__(self, samples: int):
        self.width = min(5, max(1, samples // 3))
        self.first = []
        self.last = deque(maxlen=self.width)
        self.count = 0
        self.low = self.high = None
        self.mean_x = self.mean_y = self.variance = self.covariance = 0.0

    def add(self, x: float, y: float) -> None:
        self.count += 1
        self.low = y if self.low is None else min(self.low, y)
        self.high = y if self.high is None else max(self.high, y)
        if len(self.first) < self.width:
            self.first.append(y)
        self.last.append(y)
        dx, dy = x - self.mean_x, y - self.mean_y
        self.mean_x += dx / self.count
        self.mean_y += dy / self.count
        self.variance += dx * (x - self.mean_x)
        self.covariance += dx * (y - self.mean_y)

    def summary(self) -> dict | None:
        if not self.count:
            return None
        return {'min': self.low, 'max': self.high, 'mean': self.mean_y,
                'endMinusStartMedian': statistics.median(self.last) - statistics.median(self.first),
                'slopePerHour': 3600 * self.covariance / self.variance if self.variance else 0.0}


def mixed_samples(path):
    """Two sequential passes; never retain the campaign's complete sample list.

    The all-sample median is deliberately no longer reported: exact medians would
    need retained observations or an external sort. Raw JSONL retains every value.
    Admission thresholds still use exactly the historical endpoint medians.
    """
    count = complete_count = error_count = 0
    complete, errors = [], []
    with path.open() as stream:
        for line in stream:
            try:
                record = json.loads(line)
                if not isinstance(record, dict):
                    raise ValueError('Expected a JSON object')
            except ValueError:
                error_count += 1
                if len(errors) < 20:
                    errors.append(line[:4096])
                continue
            count += record.get('type') == 'sample'
            if record.get('type') == 'complete':
                complete_count += 1
                complete = [record]
    skip = max(1, count // 5)
    if skip >= count:
        skip = 0
    paths = {'rssKiB': ['os', 'rssKiB'], 'phpBytes': ['phpBytes'],
             'nativeBytes': ['native', 'nativeMemoryBytes'], 'fds': ['os', 'fds'],
             'threads': ['os', 'threads'], 'sockets': ['os', 'sockets']}
    trends = {name: Trend(count - skip) for name in paths}
    index = 0
    with path.open() as stream:
        for line in stream:
            try:
                record = json.loads(line)
            except ValueError:
                continue
            if not isinstance(record, dict) or record.get('type') != 'sample':
                continue
            index += 1
            if index <= skip:
                continue
            for name, keys in paths.items():
                value = record
                for key in keys:
                    value = value[key]
                trends[name].add(record['seconds'], value)
    return {'samples': count, 'complete': complete, 'completeRecords': complete_count,
            'parseErrors': errors, 'parseErrorCount': error_count,
            'steadyTrends': {name: trend.summary() for name, trend in trends.items()}}
