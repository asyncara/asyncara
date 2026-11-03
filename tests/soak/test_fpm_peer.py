"""The deliberate slow HTTP peer must release bookkeeping on barrier failure."""
import importlib.util
import os
from pathlib import Path
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from unittest.mock import patch


class PeerTests(unittest.TestCase):
    def test_incomplete_concurrent_request_releases_barrier(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {'ASYNCARA_FPM_DIR': directory}):
            source = Path(__file__).resolve().parents[2] / 'scripts/audit-fpm.py'
            spec = importlib.util.spec_from_file_location('audit_peer_test', source)
            peer = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(peer)
            try:
                url = f'http://127.0.0.1:{peer.server.server_port}/slow?id=incomplete&parallel=2'
                with self.assertRaises(urllib.error.HTTPError) as caught:
                    urllib.request.urlopen(url, timeout=10)
                self.assertEqual(caught.exception.code, 503)
                caught.exception.close()
                deadline = time.monotonic() + 1
                while True:
                    with peer.active_lock:
                        remaining = dict(peer.active_requests)
                        barriers = dict(peer.barriers)
                    if not remaining and not barriers:
                        break
                    if time.monotonic() > deadline:
                        self.fail(f'Peer retained failed barrier: {remaining}')
                    time.sleep(.01)
            finally:
                peer.server.shutdown()
                peer.server.server_close()


if __name__ == '__main__':
    unittest.main()
