#!/usr/bin/env python3
import concurrent.futures,http.server,json,os,pathlib,signal,socket,struct,subprocess,tempfile,threading,time
root=pathlib.Path(__file__).resolve().parent.parent
marker=root/'build/audit-path'
if not marker.exists():
    marker.parent.mkdir(exist_ok=True);marker.write_text(tempfile.mkdtemp(prefix='asyncara-audit-')+'\n')
out=pathlib.Path(marker.read_text().strip())/'fpm';out.mkdir(exist_ok=True)
large=out/'large.bin'
with large.open('wb')as f:f.truncate(12*1024*1024)
class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def do_GET(self):
        start=time.time();time.sleep(.2);body=json.dumps({'start':start,'end':time.time()}).encode()
        self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
class Server(http.server.ThreadingHTTPServer):
    request_queue_size=128
server=Server(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
def record(kind,value):return struct.pack('!BBHHBB',1,kind,1,len(value),0,0)+value
def exact(s,n):
    out=b''
    while len(out)<n:
        value=s.recv(n-len(out))
        if not value:raise RuntimeError('Unexpected FastCGI EOF')
        out+=value
    return out
def request(port,index,mode):
    params={'SCRIPT_FILENAME':str(root/'tests/audit/fpm_request.php'),'REQUEST_METHOD':'GET','QUERY_STRING':'mode='+mode,'SERVER_PROTOCOL':'HTTP/1.1','GATEWAY_INTERFACE':'CGI/1.1','REMOTE_ADDR':'127.0.0.1','CONTENT_LENGTH':'0','AUDIT_ID':str(index),'AUDIT_URL':f'http://127.0.0.1:{server.server_port}/slow','AUDIT_LARGE_FILE':str(large)}
    def length(n):return bytes([n])if n<128 else struct.pack('!I',n|0x80000000)
    body=b''.join(length(len(k.encode()))+length(len(v.encode()))+k.encode()+v.encode()for k,v in params.items())
    with socket.create_connection(('127.0.0.1',port),timeout=15)as s:
        s.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,body)+record(4,b'')+record(5,b''));output=b'';errors=b''
        while True:
            v,t,rid,n,pad,_=struct.unpack('!BBHHBB',exact(s,8));data=exact(s,n);exact(s,pad)
            if v!=1 or rid!=1:raise RuntimeError('Invalid FastCGI record')
            if t==6:output+=data
            if t==7:errors+=data
            if t==3:break
    if mode=='fatal':
        if b'Status: 500' not in output and b'Allowed memory size' not in errors:raise RuntimeError('Missing fatal error response')
        return {'fatalResponse':True}
    if errors or b'Status: 500' in output:raise RuntimeError(repr(output+errors))
    response=json.loads(output.split(b'\r\n\r\n',1)[1]);assert response['id']==str(index)
    return response
def idle(pid):
    p=pathlib.Path('/proc')/str(pid);deadline=time.monotonic()+5
    while 'accept' not in (p/'wchan').read_text():
        if time.monotonic()>deadline:raise RuntimeError('Worker did not become idle')
        time.sleep(.005)
    status=dict(s.split(':',1)for s in(p/'status').read_text().splitlines())
    return {'pid':pid,'fds':len(list((p/'fd').iterdir())),'threads':int(status['Threads']),'rssKiB':int(status['VmRSS'].split()[0])}
def run(name,workers,count,mode,parallel=False):
    with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    folder=out/name;folder.mkdir(exist_ok=True);cfg=folder/'fpm.conf'
    cfg.write_text(f'[global]\ndaemonize=no\npid={folder}/pid\nerror_log={folder}/error.log\n[audit]\nlisten=127.0.0.1:{port}\npm=static\npm.max_children={workers}\npm.max_requests=0\nclear_env=yes\ncatch_workers_output=yes\nsecurity.limit_extensions=.php\nphp_admin_flag[log_errors]=on\nphp_admin_value[error_log]={folder}/php-errors.log\n')
    log=(folder/'startup.log').open('w')
    process=subprocess.Popen(['php-fpm8.5','-n','-F','-d','extension='+str(root/'build/asyncara.so'),'-d','display_errors=0','-y',str(cfg)],stdout=log,stderr=log)
    try:
        deadline=time.monotonic()+10
        while True:
            if process.poll()is not None:raise RuntimeError('FPM startup failed')
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:
                if time.monotonic()>deadline:raise
                time.sleep(.02)
        samples=[];responses=[]
        if parallel:
            with concurrent.futures.ThreadPoolExecutor(max_workers=workers)as pool:
                responses=list(pool.map(lambda i:request(port,i,mode),range(count)))
            pids={r['pid']for r in responses};assert len(pids)==workers
            first=[next(r for r in responses if r['pid']==pid)for pid in pids]
            assert max(r['start']for r in first)<min(r['end']for r in first),'FPM requests did not overlap'
            samples=[idle(pid)for pid in pids]
        else:
            for i in range(count):
                response=request(port,i,mode);responses.append(response)
                if i%10==9 or count<10:samples.append(idle(response['pid']))
            assert len({r['pid']for r in responses})==1,'FPM worker replacement'
            assert len({s['fds']for s in samples})==1,'FD growth'
            if len(samples)>3:assert max(s['rssKiB']for s in samples[3:])-min(s['rssKiB']for s in samples[3:])<4096,'RSS growth'
            if name=='A-lifecycle':
                request(port,1000,'fatal');after=request(port,1001,'lifecycle')
                assert after['pid']==responses[0]['pid'],'FPM worker did not survive request-level fatal teardown'
                post=idle(after['pid']);assert post['fds']==samples[-1]['fds'];samples.append(post)
        assert all(s['threads']==1 for s in samples),'Native threads escaped request'
        return {'name':name,'workers':workers,'requests':count,'samples':samples,'responses':responses,'status':'PASS'}
    finally:
        process.send_signal(signal.SIGQUIT)
        try:process.wait(timeout=10)
        except subprocess.TimeoutExpired:process.kill();process.wait()
        log.close()
try:
    results=[]
    for args in [('A-lifecycle',1,100,'lifecycle',False),('B-within-request',1,10,'io',False),('C-multiple-workers',4,12,'io',True)]:
        if os.environ.get('AUDIT_FPM_CASE') and os.environ['AUDIT_FPM_CASE']!=args[0]:continue
        result=run(*args);results.append(result);print(result['name'],result['status'],flush=True)
    (out/'results.json').write_text(json.dumps(results,indent=2)+'\n')
finally:server.shutdown();server.server_close()
