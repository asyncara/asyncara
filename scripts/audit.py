#!/usr/bin/env python3
"""Run independent regression/slow-peer tests against the selected PHP binary."""
import json,os,pathlib,subprocess,tempfile,time
root=pathlib.Path(__file__).resolve().parent.parent
os.chdir(root)
marker=root/'build/audit-path'
output=pathlib.Path(os.environ.get('ASYNCARA_AUDIT_DIR',marker.read_text().strip() if marker.exists() else tempfile.mkdtemp(prefix='asyncara-audit-'))).resolve()
output.mkdir(parents=True,exist_ok=True);marker.parent.mkdir(exist_ok=True);marker.write_text(str(output.resolve())+'\n')
php=os.environ.get('PHP_BIN','php');extension=(root/os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so')).resolve()
subprocess.run(['python3','scripts/check-build.py',str(extension)],check=True)
subprocess.run(['bash','scripts/prepare-test-certs.sh'],check=True)
base=[php,'-n','-d','memory_limit=1G','-d','extension='+str(extension)]
results=[]
def run(name,args,extra=None):
    start=time.monotonic()
    result=subprocess.run(base+args,env=os.environ|(extra or {}),text=True,capture_output=True,timeout=120)
    (output/(name+'.log')).write_text(result.stdout+result.stderr)
    results.append({'name':name,'exit':result.returncode,'wallSeconds':time.monotonic()-start,'output':result.stdout+result.stderr})
    (output/'independent-results.json').write_text(json.dumps(results,indent=2)+'\n')
    if result.returncode:raise RuntimeError(name+' failed; inspect '+str(output/(name+'.log')))
    print(name+': PASS',flush=True)
for case in ['root_failure_timer','foreign_fiber','root_failure_shielded_wait','cleanup_delayed_callback','sqlite_parallel_transaction','pool_pending_close','pool_acquire_cancel','pool_close_failure','pool_close_queued_borrower','waiter_ready_cancel']:
    run('regression-'+case,['tests/audit/regressions.php',case])
run('hooks',['tests/audit/hooks.php'])
run('process-cpu',['tests/audit/process_cpu.php'])
run('idle-epoll',['tests/audit/regressions.php','idle'],{'ASYNCARA_BACKEND':'epoll'})
run('idle-poll',['tests/audit/regressions.php','idle'],{'ASYNCARA_BACKEND':'poll'})
run('latency',['tests/audit/regressions.php','latency'])
for count in (1000,10000):
    for sample in range(3):run(f'tasks-{count}-{sample}',['tests/audit/regressions.php','tasks',str(count)])
library=output/'slow_io.so'
subprocess.run(['cc','-Wall','-Wextra','-Werror','-fPIC','-shared','tests/audit/slow_io.c','-o',str(library),'-ldl'],check=True)
with tempfile.TemporaryDirectory(prefix='slow-path-',dir=output)as temporary:
    run('slow-path',['tests/audit/slow_files.php'],{'LD_PRELOAD':str(library),'ASYNCARA_AUDIT_EXTENSION':str(extension),'ASYNCARA_AUDIT_MARKER':str(pathlib.Path(temporary)/'started')})
run('slow-io',['tests/audit/io.php'],{'LD_PRELOAD':str(library),'ASYNCARA_AUDIT_EXTENSION':str(extension),'ASYNCARA_AUDIT_DNS':'1'})
with tempfile.NamedTemporaryFile(prefix='fatal-batch-',dir=output)as large:
    large.truncate(12*1024*1024);large.flush()
    result=subprocess.run(base+['tests/audit/fatal_batch.php',large.name],text=True,capture_output=True,timeout=30)
    (output/'fatal-batch.log').write_text(result.stdout+result.stderr)
    if result.returncode!=255 or 'Allowed memory size'not in result.stdout+result.stderr:
        raise RuntimeError('Fatal batch must end the PHP request, not exit137/segfault; inspect fatal-batch.log')
    print('fatal-batch: PASS',flush=True)
print('Evidence: '+str(output))
