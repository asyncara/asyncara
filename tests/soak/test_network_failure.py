"""Deliberate TLS stall must retain bounded diagnostics and the original error."""
import argparse
import http.server
import json
import os
from pathlib import Path
import subprocess
from peers import ExtendedPeers

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
out = args.output.resolve()
out.mkdir(mode=0o700)

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *arguments):
        pass

    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Length', '8192')
        self.end_headers()
        self.wfile.write(b'x' * 8192)

with ExtendedPeers(root, out, Handler) as config:
    # network_edges.py's ordinary stall closes after 300 ms. Keep its TCP
    # connection open through the complete existing three-second TLS deadline.
    import socketserver
    import threading
    import time

    class Stall(socketserver.BaseRequestHandler):
        def handle(self):
            self.request.recv(65536)
            time.sleep(4)

    with socketserver.ThreadingTCPServer(('127.0.0.1', 0), Stall) as server:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            config['network']['stall'] = server.server_address[1]
            env = os.environ | {'ASYNCARA_SOAK_NETWORK': json.dumps(config)}
            command = [env.get('PHP_BIN', 'php'), '-n', '-d',
                'extension=' + str(root / env.get('ASYNCARA_EXTENSION', 'build/asyncara.so')),
                str(root / 'tests/soak/network_failure.php')]
            result = subprocess.run(command, cwd=root, env=env, capture_output=True, text=True, timeout=30)
        finally:
            server.shutdown()
            thread.join()
(out / 'stdout.log').write_text(result.stdout)
(out / 'stderr.log').write_text(result.stderr)
assert result.returncode == 0, result.stdout + result.stderr
assert len(result.stderr) < 8192, 'Unbounded diagnostic'
diagnostic = json.loads(result.stderr)
assert diagnostic['type'] == 'network-failure'
assert diagnostic['error'] == 'Asyncara\\Exception\\TimeoutException'
assert diagnostic['operations']['tls12']['stage'] == 'connect'
assert diagnostic['elapsedNs'] >= 3_000_000_000, 'Original connection deadline was shortened'
assert len(diagnostic['operations']) <= 8
print(result.stdout, end='')
