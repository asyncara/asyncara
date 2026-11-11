#!/usr/bin/env python3
"""HTTP completion gate; isolated artifacts, unchanged production I/O paths."""
import argparse, hashlib, json, os, pathlib, resource, shlex, subprocess, time

ROOT = pathlib.Path(__file__).resolve().parents[2]
p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--extension', required=True)
p.add_argument('--output', required=True)
p.add_argument('--php-only', action='store_true')
p.add_argument('--hyper', action='store_true', help='Also require and test the optional Hyper prototype')
a = p.parse_args()
out = pathlib.Path(a.output).resolve()
out.mkdir(parents=True, exist_ok=False)
module = pathlib.Path(a.extension).resolve()
sha = lambda f: hashlib.sha256(f.read_bytes()).hexdigest()
manifest = {str(f.relative_to(ROOT)): sha(f) for d in ['ext', 'src', 'tests/http']
            for f in (ROOT / d).rglob('*') if f.is_file() and '__pycache__' not in str(f)}
def git_identity(*args):
    # Source distributions have no Git metadata. Their file manifest and module
    # hash remain the identity; do not accidentally report an enclosing checkout.
    if not (ROOT / '.git').exists():
        return None
    return subprocess.check_output(['git', *args], cwd=ROOT, text=True).strip()

identity = {'source': git_identity('rev-parse', 'HEAD'),
            'status': git_identity('status', '--short'),
            'module': str(module), 'moduleSha256': sha(module), 'manifest': manifest, 'arguments': vars(a)}
(out / 'identity.json').write_text(json.dumps(identity, indent=2) + '\n')
rows = []
start = time.monotonic()

def run(name, cmd, env=None, timeout=120):
    before = resource.getrusage(resource.RUSAGE_CHILDREN)
    began = time.monotonic()
    log = out / (name + '.log')
    with log.open('xb') as f:
        child = subprocess.Popen(list(map(str, cmd)), cwd=ROOT, env=os.environ | (env or {}), stdout=f, stderr=subprocess.STDOUT)
        (out / 'active.json').write_text(json.dumps({'pid': child.pid, 'command': list(map(str, cmd)), 'status': 'RUNNING'}))
        try:
            code = child.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            child.terminate()
            child.wait(timeout=15)
            code = 124
    after = resource.getrusage(resource.RUSAGE_CHILDREN)
    rows.append({'name': name, 'command': list(map(str, cmd)), 'environment': env or {}, 'exit': code,
                 'wallSeconds': time.monotonic() - began,
                 'cpuSeconds': after.ru_utime + after.ru_stime - before.ru_utime - before.ru_stime,
                 'logSha256': sha(log)})
    (out / 'active.json').write_text(json.dumps({'pid': child.pid, 'status': 'TERMINAL', 'exit': code}))
    (out / 'report.json').write_text(json.dumps({'status': 'FAIL' if code else 'RUNNING', 'commands': rows}, indent=2) + '\n')
    print(name, code, flush=True)
    if code:
        raise SystemExit(1)

run('https-proxy-buffered-readiness', ['python3', 'tests/http/test_https_proxy_io.py'])
run('capacity-peer-shutdown', ['python3', 'tests/http/peer_shutdown.py', 'tests/http/capacity_server.py'])

if not a.php_only:
    cc = shlex.split(os.environ.get('CC', 'cc'))
    flags = ['-std=c11', '-D_GNU_SOURCE', '-O2', '-g', '-Wall', '-Wextra', '-Werror',
             '-I.local/sysroot/usr/include', '-I.local/sysroot/usr/include/x86_64-linux-gnu']
    flags += shlex.split(os.environ.get('EXTRA_CFLAGS', ''))
    for name, files, wraps in [
        ('capacity', ['tests/http/buffer_capacity.c'], []),
        ('curl-cache', ['tests/http/curl_cache.c', 'ext/backend.c'], ['curl_easy_setopt', 'curl_easy_cleanup']),
        ('method', ['tests/http/method_native.c', 'ext/backend.c'], ['curl_multi_add_handle']),
        ('dispatch', ['tests/http/dispatch_native.c', 'ext/backend.c'], ['curl_multi_timeout', 'curl_multi_socket_action', 'write']),
        ('stage', ['tests/http/stage_native.c', 'ext/backend.c'], ['fsync', 'renameat']),
        ('completion', ['tests/http/native_completion.c'], ['curl_multi_socket_action']),
        ('start-wait', ['tests/http/start_wait.c', 'ext/backend.c'], ['epoll_wait', 'poll', '__poll_chk', 'clock_gettime'])]:
        run(name + '-build', cc + flags + files + ['ext/workers.c', 'ext/memory.c', '-pthread',
            '-l:libsqlite3.so.0', '-l:libcurl.so.4'] + ['-Wl,--wrap=' + w for w in wraps] + ['-o', out / name])
        run(name, [out / name] + (['--compact'] if name == 'capacity' else []))
        if name == 'start-wait':
            run(name + '-logical-clock', [out / name, '--logical-clock'])
    if a.hyper:
        run('hyper-codec-build', cc + flags + ['-DHAVE_ASYNCARA_HYPER=1',
            'tests/http/hyper_decode.c', 'ext/hyper_decode.c', 'ext/memory.c',
            '-pthread', '-lz', '-Wl,--wrap=malloc', '-o', out / 'hyper-codec'])
        run('hyper-codec', [out / 'hyper-codec'])
php = [a.php, '-n', '-d', 'memory_limit=128M', '-d', 'extension=' + str(module)]
run('php-identity', php + ['-v'])
run('extension-identity', php + ['--ri', 'asyncara'])
for backend in ['epoll', 'poll']:
    env = {'ASYNCARA_BACKEND': backend, 'ASYNCARA_EXTENSION': str(module)}
    run(backend + '-https-proxy', ['python3', 'tests/http/https_proxy.py',
        '--php', a.php, '--module', module, '--backend', backend, '--out', out / (backend + '-https-proxy')], env)
    for transport in (['curl-multi', 'hyper-prototype'] if a.hyper else ['curl-multi']):
        run(backend + '-file-paths-' + transport, ['python3', 'tests/http/file_paths.py',
            '--php', a.php, '--module', module, '--backend', backend, '--transport', transport,
            '--out', out / (backend + '-file-paths-' + transport)], env)
        run(backend + '-ca-paths-' + transport, ['python3', 'tests/http/ca_paths.py',
            '--php', a.php, '--module', module, '--backend', backend, '--transport', transport,
            '--out', out / (backend + '-ca-paths-' + transport)], env)
    for test in ['cancelled_upload_reuse', 'transfer_geometry', 'hyper_wakeups', 'capacity', 'gate_protocol', 'completion', 'methods', 'acquire_cancel', 'buffered_owner', 'options_lifetime', 'headers', 'wait_observer', 'proxy', 'pooling', 'buffer_budget']:
        run(backend + '-' + test, php + ['tests/http/' + test + '.php'], env)
    for limit in [8, 16, 32]:
        run(backend + '-connections-' + str(limit), php + ['tests/http/connections.php', limit], env)
    run(backend + '-streaming', php + ['tests/http_streaming.php'], env)
    run(backend + '-shutdown', ['python3', 'tests/http/stage_shutdown.py', '--php', a.php,
        '--extension', module, '--output', out / (backend + '-shutdown')], env)
    if a.hyper:
        run(backend + '-prototype-compression', ['python3', 'tests/http/hyper_compression.py',
            '--php', a.php, '--module', module, '--backend', backend,
            '--out', out / (backend + '-prototype-compression')], env)
        run(backend + '-prototype-compression-bounds', ['python3', 'tests/http/hyper_compression.py',
            '--php', a.php, '--module', module, '--backend', backend, '--bounds',
            '--out', out / (backend + '-prototype-compression-bounds')], env)
        run(backend + '-prototype-streaming', ['python3', 'tests/http/hyper_streaming.py',
            '--php', a.php, '--module', module, '--backend', backend,
            '--out', out / (backend + '-prototype-streaming')], env, timeout=150)
        run(backend + '-prototype-stream-bounds', ['python3', 'tests/http/hyper_streaming.py',
            '--php', a.php, '--module', module, '--backend', backend, '--bounds',
            '--out', out / (backend + '-prototype-stream-bounds')], env, timeout=150)
        for test in ['transfer_geometry', 'hyper_wakeups', 'hyper_encoding', 'hyper_dns', 'capacity', 'gate_protocol', 'hyper_uri', 'hyper', 'buffered_owner']:
            run(backend + '-prototype-' + test, php + ['tests/http/' + test + '.php'],
                env | {'ASYNCARA_HTTP_BACKEND': 'hyper-prototype'})
assert sha(module) == identity['moduleSha256']
assert all(sha(ROOT / name) == digest for name, digest in manifest.items())
(out / 'report.json').write_text(json.dumps({'status': 'PASS', 'wallSeconds': time.monotonic() - start,
    'cpuSeconds': sum(r['cpuSeconds'] for r in rows), 'commands': rows}, indent=2) + '\n')
