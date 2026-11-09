#!/usr/bin/env python3
"""Negative control for the HTTP feature post-fatal peer barrier mismatch.
Keep the generic Phase 4 fixture unchanged; exercise its real independent peer.
"""
import concurrent.futures,importlib.util,json,os,pathlib,sys,time,urllib.request
root=pathlib.Path(__file__).resolve().parents[2]
if len(sys.argv)!=2:raise SystemExit('Usage: fpm_barrier.py NEW_OUTPUT')
out=pathlib.Path(sys.argv[1]).resolve();out.mkdir(parents=True,exist_ok=False);os.environ['ASYNCARA_FPM_DIR']=str(out)
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
def get(case,parallel):
 with urllib.request.urlopen(f'http://127.0.0.1:{audit.server.server_port}/slow?id={case}&parallel={parallel}',timeout=10)as r:return json.load(r)
def overlap(rows):return max(x['start']for x in rows)<min(x['end']for x in rows)
try:
 # A barrier of one admits a completion before the fourth operation is started.
 with concurrent.futures.ThreadPoolExecutor(max_workers=4)as pool:
  old=[pool.submit(get,'old',1)for _ in range(3)];old_rows=[f.result()for f in old];old_rows.append(pool.submit(get,'old',1).result())
  assert not overlap(old_rows)
  corrected=[pool.submit(get,'new',4)for _ in range(3)];deadline=time.monotonic()+3
  while True:
   with audit.active_lock:waiting=audit.barriers.get('new');ready=waiting is not None and waiting.n_waiting==3
   if ready:break
   if time.monotonic()>deadline:raise RuntimeError('Peer barrier registration missing')
   time.sleep(.001) # Observe independent state, not the primary I/O-release mechanism.
  assert not any(f.done()for f in corrected),'Peer did not hold incomplete batch'
  corrected.append(pool.submit(get,'new',4));new_rows=[f.result()for f in corrected];assert overlap(new_rows)
 (out/'report.json').write_text(json.dumps({'status':'PASS','oldBarrierOneAllowsNonoverlap':old_rows,'correctedBarrierFourRequiresOverlap':new_rows},indent=2)+'\n')
 print('PASS old one-operation barrier permits nonoverlap; four-operation barrier holds the pending batch')
finally:audit.server.shutdown();audit.server.server_close()
