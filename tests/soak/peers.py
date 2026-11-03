"""Reuse independent TLS/WebSocket fixtures for the optional mixed network scope."""
import http.server
import json
import os
from pathlib import Path
import select
import ssl
import subprocess
import threading


class ExtendedPeers:
    def __init__(self, source: Path, output: Path, handler):
        self.source, self.output, self.handler = source, output, handler
        self.children = []
        self.https = None
        self.https_started = False

    def spawn(self, name, arguments, env):
        log = (self.output / (name + '.log')).open('x')
        process = subprocess.Popen(arguments, cwd=self.source, env=env,
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=log, text=True)
        self.children.append((process, log))
        if not select.select([process.stdout], [], [], 10)[0]:
            raise RuntimeError(name + ' readiness timeout')
        line = process.stdout.readline(65537)
        if not line or len(line) > 65536:
            raise RuntimeError(name + ' readiness failed; inspect peer log')
        return json.loads(line)

    def __enter__(self):
        try:
            subprocess.run(['bash', 'scripts/prepare-test-certs.sh'], cwd=self.source, check=True)
            cert = self.source / 'build/test-certs/cert.pem'
            key = self.source / 'build/test-certs/key.pem'
            network = self.spawn('network-peer', ['python3', 'tests/fixtures/network_edges.py',
                str(cert), str(key)], os.environ.copy())
            websocket = self.spawn('websocket-peer', ['python3', 'tests/fixtures/websocket_peer.py',
                str(cert), str(key)], os.environ | {'PYTHONPATH': str(self.source / '.local/ws-peer-17.4')})
            self.https = http.server.ThreadingHTTPServer(('127.0.0.1', 0), self.handler)
            self.https.daemon_threads = True
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            context.load_cert_chain(cert, key)
            self.https.socket = context.wrap_socket(self.https.socket, server_side=True)
            threading.Thread(target=self.https.serve_forever, daemon=True).start()
            self.https_started = True
            config = {'network': network, 'websocket': websocket, 'ca': str(cert),
                      'https': f'https://localhost:{self.https.server_port}/slow'}
            (self.output / 'network-peers.json').write_text(json.dumps(config, indent=2) + '\n')
            return config
        except BaseException:
            self.__exit__(None, None, None)
            raise

    def __exit__(self, kind, value, traceback):
        if self.https is not None:
            if self.https_started:
                self.https.shutdown()
            self.https.server_close()
        failures = []
        for process, log in reversed(self.children):
            process.stdin.close()
            try:
                status = process.wait(timeout=10)
                if status:
                    failures.append(f'Peer exit {status}')
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
                failures.append('Peer graceful shutdown timed out')
            finally:
                process.stdout.close()
                log.close()
        if failures and kind is None:
            raise RuntimeError('; '.join(failures))
