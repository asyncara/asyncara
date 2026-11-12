#!/usr/bin/env python3
"""Peer shutdown control without PHP: unread TLS upload and a held connection."""
import argparse, json, pathlib, selectors, socket, ssl, subprocess, sys, time
p=argparse.ArgumentParser();p.add_argument('--peer',default=str(pathlib.Path(__file__).with_name('multipart_peer.py')));p.add_argument('--certs',required=True);a=p.parse_args()
def line(pipe):
    with selectors.DefaultSelector() as selector:
        selector.register(pipe,selectors.EVENT_READ)
        assert selector.select(2),'Peer notification deadline'
        return json.loads(pipe.readline())
rows=[]
for scenario in ['hold','reject','cancel']:
    child=subprocess.Popen([sys.executable,a.peer,a.certs],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    sock=None;started=time.monotonic();error=None
    try:
        ports=line(child.stdout);context=ssl.create_default_context(cafile=str(pathlib.Path(a.certs)/'ca.pem'))
        sock=context.wrap_socket(socket.create_connection(('127.0.0.1',ports['https']),2),server_hostname='localhost');sock.settimeout(2)
        path='/reject/test'if scenario=='reject'else'/hold/test'
        sock.sendall(('POST '+path+' HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\nContent-Type: multipart/form-data; boundary=x\r\n\r\n').encode()+b'10000\r\n'+b'x'*65536+b'\r\n')
        assert line(child.stdout)['pending']=='test'
        if scenario=='reject':
            data=b''
            while b'\r\n\r\n'not in data:data+=sock.recv(4096)
            assert data.startswith(b'HTTP/1.1 413')
            child.stdin.write(b'release test\n');child.stdin.flush()
        elif scenario=='cancel':
            sock.close();sock=None;child.stdin.write(b'release test\n');child.stdin.flush()
        child.stdin.close();child.stdin=None
        output,stderr=child.communicate(timeout=2)
        assert child.returncode==0 and not stderr,(child.returncode,stderr)
    except Exception as e:error=repr(e)
    finally:
        if sock:sock.close()
        if child.poll()is None:child.terminate()
        output,stderr=child.communicate(timeout=2)
    rows.append({'scenario':scenario,'status':'FAIL'if error else'PASS','error':error,'seconds':time.monotonic()-started,'stderr':stderr.decode(),'exit':child.returncode})
print(json.dumps({'status':'PASS'if all(r['status']=='PASS'for r in rows)else'FAIL','rows':rows},indent=2))
raise SystemExit(any(r['status']!='PASS'for r in rows))
