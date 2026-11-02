import concurrent.futures,os,pathlib,socket,struct,subprocess,time

def run(root,url):
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    directory=root/'build/fpm-benchmark';directory.mkdir(exist_ok=True)
    config=directory/'pool.conf'
    config.write_text(f'''[global]
pid = {directory}/fpm.pid
error_log = {directory}/error.log
daemonize = no
[benchmark]
listen = 127.0.0.1:{port}
pm = static
pm.max_children = 4
clear_env = no
catch_workers_output = yes
security.limit_extensions = .php
''')
    environment={'PATH':os.environ['PATH'],'ASYNCARA_BENCH_URL':url}
    process=subprocess.Popen(['php-fpm8.5','-n','-F','-y',str(config)],env=environment,stdout=subprocess.DEVNULL,stderr=open(directory/'startup.log','w'))
    def record(kind,content):return struct.pack('!BBHHBB',1,kind,1,len(content),0,0)+content
    def length(n):return bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)
    def exact(s,n):
        data=b''
        while len(data)<n:
            part=s.recv(n-len(data))
            if not part:raise RuntimeError('FastCGI EOF')
            data+=part
        return data
    def request(_):
        start=time.monotonic()
        params={'SCRIPT_FILENAME':str(root/'benchmarks/fpm_target.php'),'REQUEST_METHOD':'GET','SERVER_PROTOCOL':'HTTP/1.1','GATEWAY_INTERFACE':'CGI/1.1','SERVER_NAME':'localhost','SERVER_PORT':str(port),'REMOTE_ADDR':'127.0.0.1','CONTENT_LENGTH':'0'}
        encoded=b''
        for key,value in params.items():
            k=key.encode();v=value.encode();encoded+=length(len(k))+length(len(v))+k+v
        with socket.create_connection(('127.0.0.1',port),timeout=10) as s:
            s.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,encoded)+record(4,b'')+record(5,b''))
            output=b''
            while True:
                version,kind,rid,size,padding,_=struct.unpack('!BBHHBB',exact(s,8));data=exact(s,size)
                if padding:exact(s,padding)
                if kind==6:output+=data
                if kind==3:break
        if not output.endswith(b'hello asyncara'):raise RuntimeError('FPM unexpected response: '+repr(output))
        return (time.monotonic()-start)*1000
    try:
        deadline=time.monotonic()+10
        while True:
            if process.poll() is not None:raise RuntimeError('FPM startup failed; see '+str(directory/'startup.log'))
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:
                if time.monotonic()>deadline:raise
                time.sleep(.02)
        samples=[]
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
            for _ in range(3):
                start=time.monotonic();times=sorted(executor.map(request,range(24)));elapsed=time.monotonic()-start
                samples.append({'seconds':elapsed,'operations':24,'operationsPerSecond':24/elapsed,'medianMs':times[11],'p95Ms':times[21],'p99Ms':times[22]})
        return {'mode':'php-fpm-4-workers','samples':samples}
    finally:process.terminate();process.wait(timeout=10)
