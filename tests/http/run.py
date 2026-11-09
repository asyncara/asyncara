#!/usr/bin/env python3
"""HTTP completion gate; isolated artifacts, unchanged production I/O paths."""
import argparse, hashlib, json, os, pathlib, resource, shlex, subprocess, time

ROOT = pathlib.Path(__file__).resolve().parents[2]
p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--extension', required=True)
p.add_argument('--output', required=True)
p.add_argument('--php-only', action='store_true')
a = p.parse_args()
out = pathlib.Path(a.output).resolve()
out.mkdir(parents=True, exist_ok=False)
module = pathlib.Path(a.extension).resolve()
sha = lambda f: hashlib.sha256(f.read_bytes()).hexdigest()
manifest = {str(f.relative_to(ROOT)): sha(f) for d in ['ext', 'src', 'tests/http']
            for f in (ROOT / d).rglob('*') if f.is_file() and '__pycache__' not in str(f)}
identity = {'source': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'status': subprocess.check_output(['git', 'status', '--short'], cwd=ROOT, text=True),
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

if not a.php_only:
    cc = shlex.split(os.environ.get('CC', 'cc'))
    flags = ['-std=c11', '-D_GNU_SOURCE', '-O2', '-g', '-Wall', '-Wextra', '-Werror',
             '-I.local/sysroot/usr/include', '-I.local/sysroot/usr/include/x86_64-linux-gnu']
    flags += shlex.split(os.environ.get('EXTRA_CFLAGS', ''))
    for name, files, wraps in [
        ('stage', ['tests/http/stage_native.c', 'ext/backend.c'], ['fsync', 'renameat']),
        ('completion', ['tests/http/native_completion.c'], ['curl_multi_socket_action'])]:
        run(name + '-build', cc + flags + files + ['ext/workers.c', 'ext/memory.c', '-pthread',
            '-l:libsqlite3.so.0', '-l:libcurl.so.4'] + ['-Wl,--wrap=' + w for w in wraps] + ['-o', out / name])
        run(name, [out / name])
php = [a.php, '-n', '-d', 'memory_limit=128M', '-d', 'extension=' + str(module)]
run('php-identity', php + ['-v'])
run('extension-identity', php + ['--ri', 'asyncara'])
for backend in ['epoll', 'poll']:
    env = {'ASYNCARA_BACKEND': backend, 'ASYNCARA_EXTENSION': str(module)}
    for test in ['completion', 'proxy', 'pooling', 'buffer_budget']:
        run(backend + '-' + test, php + ['tests/http/' + test + '.php'], env)
    for limit in [8, 16, 32]:
        run(backend + '-connections-' + str(limit), php + ['tests/http/connections.php', limit], env)
    run(backend + '-streaming', php + ['tests/http_streaming.php'], env)
    run(backend + '-shutdown', ['python3', 'tests/http/stage_shutdown.py', '--php', a.php,
        '--extension', module, '--output', out / (backend + '-shutdown')], env)
assert sha(module) == identity['moduleSha256']
assert all(sha(ROOT / name) == digest for name, digest in manifest.items())
(out / 'report.json').write_text(json.dumps({'status': 'PASS', 'wallSeconds': time.monotonic() - start,
    'cpuSeconds': sum(r['cpuSeconds'] for r in rows), 'commands': rows}, indent=2) + '\n')
