"""Independent local peers measure active HTTP requests, not API declarations."""
import http.server
import json
import sys
import threading
import time

lock = threading.Lock()
completed = threading.Condition(lock)
cases = {name: {'active': 0, 'peak': 0, 'completed': 0, 'gate': threading.Event(), 'target': target}
         for name, target in [('host', 8), ('global', 64)]}


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def do_GET(self):
        case = cases[self.path[1:]]
        with lock:
            case['active'] += 1
            case['peak'] = max(case['peak'], case['active'])
            if case['active'] >= case['target']:
                case['gate'].set()
        counted = True
        try:
            ready = case['gate'].wait(10)
            time.sleep(.03)
            # Stop counting before a response can release/reuse the connection.
            # Otherwise client receipt can race this handler's finally block,
            # falsely counting a completed request alongside its replacement.
            with lock:
                case['active'] -= 1
                counted = False
            self.send_response(200 if ready else 503)
            self.send_header('Content-Length', '2')
            self.end_headers()
            self.wfile.write(b'ok')
        finally:
            with lock:
                if counted:
                    case['active'] -= 1
                case['completed'] += 1
                completed.notify_all()


class Server(http.server.ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 128


servers = []
try:
    for _ in range(9):
        server = Server(('127.0.0.1', 0), Handler)
        servers.append(server)
        threading.Thread(target=server.serve_forever, daemon=True).start()
    print(json.dumps([server.server_port for server in servers]), flush=True)
    for line in sys.stdin:
        if line != 'stats\n':
            raise RuntimeError('Invalid test control command')
        with completed:
            completed.wait_for(lambda: cases['host']['completed'] == 16 and cases['global']['completed'] == 72, timeout=5)
            print(json.dumps({name: {key: value[key] for key in ['active', 'peak', 'completed']}
                              for name, value in cases.items()}), flush=True)
finally:
    for server in servers:
        server.shutdown()
        server.server_close()
