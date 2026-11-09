#!/usr/bin/env python3
"""A failed client can leave a held-body fixture before sending release.
Verify EOF cleanup independently of PHP, without weakening its body barrier.
"""
import json,pathlib,socket,subprocess,sys,time
peer=pathlib.Path(sys.argv[1]).resolve()
p=subprocess.Popen([sys.executable,str(peer)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
start=time.monotonic();status='FAIL';error=None
try:
    port=json.loads(p.stdout.readline())['port']
    with socket.create_connection(('127.0.0.1',port),timeout=2)as sock:
        sock.sendall(b'GET / HTTP/1.1\r\nHost: localhost\r\n\r\n')
        first=sock.recv(4096)
        assert first.startswith(b'HTTP/1.1 200'),first
        p.stdin.close();p.stdin=None
        # Drain data already accepted by the peer's transport before joining it.
        while sock.recv(65536):pass
    stdout,stderr=p.communicate(timeout=2)
    assert p.returncode==0,stderr
    status='PASS'
except Exception as e:
    error=repr(e)
finally:
    if p.poll() is None:p.terminate()
    stdout,stderr=p.communicate(timeout=2)
    print(json.dumps({'status':status,'peer':str(peer),'pid':p.pid,'wallSeconds':time.monotonic()-start,'exit':p.returncode,'error':error,'stderr':stderr}))
sys.exit(0 if status=='PASS'else 1)
