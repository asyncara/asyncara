#!/usr/bin/env python3
"""Diagnostic comparison only; never trims allocators in the real soak fixture."""
import argparse,concurrent.futures,importlib.util,json,os,pathlib,signal,socket,subprocess,time
root=pathlib.Path(__file__).resolve().parents[2];p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--cycles',type=int,default=100);a=p.parse_args();out=pathlib.Path(a.output).resolve()
if out.exists():p.error('Use a new evidence directory')
os.environ['ASYNCARA_FPM_DIR']=str(out);spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
inc=root/'.local/sysroot/usr/include/php/20250925';module=out/'allocator-probe.so'
subprocess.run(['cc','-shared','-fPIC','-Wall','-Wextra','-Werror','-I'+str(inc),'-I'+str(inc/'main'),'-I'+str(inc/'Zend'),'-I'+str(inc/'TSRM'),str(root/'tests/audit/allocator_probe.c'),'-o',str(module)],check=True)
with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
cfg=out/'fpm.conf';cfg.write_text(f'[global]\ndaemonize=no\nerror_log={out}/error.log\n[probe]\nlisten=127.0.0.1:{port}\npm=static\npm.max_children=4\nclear_env=yes\ncatch_workers_output=yes\nphp_admin_value[error_log]={out}/php-errors.log\n')
log=(out/'startup.log').open('w');process=subprocess.Popen(['php-fpm8.5','-n','-F','-d','extension='+str(root/'build/asyncara.so'),'-d','extension='+str(module),'-d','display_errors=0','-y',str(cfg)],stdout=log,stderr=log)
records=[]
try:
    deadline=time.monotonic()+10
    while True:
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.1):break
        except OSError:
            if time.monotonic()>deadline or process.poll()is not None:raise
            time.sleep(.01)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4)as executor:
        for cycle in range(a.cycles):
            mode='fatal-burst'if cycle%5==0 else'stream'
            jobs=[executor.submit(audit.request,port,cycle*100+i,mode)for i in range(4)];[f.result()for f in jobs]
            jobs=[executor.submit(audit.request,port,cycle*100+10+i,'allocator')for i in range(4)]
            for f in jobs:
                record=f.result();record.update(audit.idle(record['pid']));record['cycle']=cycle;record['after']=mode;records.append(record)
            (out/'samples.json').write_text(json.dumps(records,indent=2)+'\n')
        before=[audit.idle(r['pid'])for r in records[-4:]]
        jobs=[executor.submit(audit.request,port,999000+i,'allocator-trim')for i in range(4)];trim=[]
        for f in jobs:
            record=f.result();record.update(audit.idle(record['pid']));trim.append(record)
        (out/'trim.json').write_text(json.dumps({'before':before,'after':trim},indent=2)+'\n')
    print('Diagnostic allocator/FPM workload completed; inspect in-use, free and trim evidence',flush=True)
finally:
    process.send_signal(signal.SIGQUIT);process.wait(timeout=10);log.close();audit.server.shutdown();audit.server.server_close()
