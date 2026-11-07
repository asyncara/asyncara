#!/usr/bin/env python3
"""Read-only count-gate review. Recompute resources from raw observations.
Never infer worker chronology from request IDs or JSONL emission order.
"""
import argparse,collections,hashlib,json,math,pathlib
p=argparse.ArgumentParser();p.add_argument('evidence');p.add_argument('--output',required=True);a=p.parse_args()
root=pathlib.Path(a.evidence).resolve();out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
def sha(p):
 h=hashlib.sha256()
 with p.open('rb')as f:
  for b in iter(lambda:f.read(1024*1024),b''):h.update(b)
 return h.hexdigest()
def lines(name):
 with(root/name).open()as f:
  for number,line in enumerate(f,1):
   if not line.endswith('\n'):raise ValueError(f'{name}:{number}: incomplete record')
   yield json.loads(line)
try:
 gate=json.loads((root/'gate.json').read_text());report=json.loads((root/'report.json').read_text());provenance=json.loads((root/'provenance.json').read_text())
 assert sha(root/'asyncara.so')==provenance['extension']['sha256'],'module identity'
 source=pathlib.Path(__file__).resolve().parents[2]
 for name,digest in provenance['files'].items():assert sha(source/name)==digest,'changed fixture/tool '+name
 assert sha(source/'tests/fast/fpm_count.py')==provenance['countRunnerSha256'],'count runner changed'
 ids=set();workers=collections.Counter();ticks=[];zero_fields=['pendingOperations','nativeMemoryBytes','openFileHandles','sqliteConnections','httpStreamingTransfers','httpStreamingBufferedBytes','httpUploadBufferedBytes']
 for r in lines('mixed-responses.jsonl'):
  i=int(r['id']);assert i not in ids,'duplicate request';ids.add(i);assert r['pid']>1,'invalid worker';workers[r['pid']]+=1
  assert all(r['before'][key]==0 for key in zero_fields),'request-state escape'
  if i%4!=3:
   assert r['result']['ticks']>=2,'two-tick requirement';ticks.append(r['result']['ticks']);http=r['result']['http'];assert len(http)==4 and max(v['start']for v in http)<min(v['end']for v in http),'HTTP overlap'
   assert all(r['after'][key]==0 for key in zero_fields),'native result escape'
   s=r['afterState']['scheduler'];assert all(s[key]==0 for key in ['activeTasks','pendingFutures','activeTimers','ioWatchers','pendingNative']),'scheduler result escape'
  else:assert r['pending']['pendingOperations']>0,'missing pending shutdown workload'
 assert len(ids)==gate['mainResponses'] and ids==set(range(len(ids))),'missing main responses'
 warm=json.loads((root/'fatal-warmup.json').read_text());peaks={}
 for s in warm:peaks[s['pid']]=max(peaks.get(s['pid'],0),s['rssKiB'])
 samples=collections.defaultdict(list)
 for s in lines('mixed-samples.jsonl'):
  assert s['threads']==1 and s['fds']==6,'idle resources';assert s['pid']in peaks,'unplanned worker replacement';samples[s['pid']].append(s)
 resources={};stored=json.loads((root/'mixed-growth.json').read_text())
 for pid,ss in samples.items():
  assert len(ss)>4,'insufficient steady samples'
  times=[s['seconds']for s in ss];rss=[s['rssKiB']for s in ss];assert all(b>=a for a,b in zip(times,times[1:])),'monotonic sample order'
  mx=math.fsum(times)/len(times);my=math.fsum(rss)/len(rss);v=math.fsum((x-mx)**2 for x in times)
  slope=3600*math.fsum((x-mx)*(y-my)for x,y in zip(times,rss))/v if v else 0
  beyond=max(0,max(rss)-peaks[pid]);assert beyond<8192,'RSS threshold'
  values={'samples':len(ss),'rssMinKiB':min(rss),'rssMaxKiB':max(rss),'warmupPeakKiB':peaks[pid],'beyondWarmupKiB':beyond,'rangeAfterFirstThreeKiB':max(rss[3:])-min(rss[3:]),'slopeKiBPerHour':slope,'idleFds':6,'idleThreads':1}
  assert beyond==stored['growthBeyondWarmupPeakKiB'][str(pid)],'growth summary mismatch'
  assert values['rangeAfterFirstThreeKiB']==stored['rssRangeKiBAfterWarmup'][str(pid)],'range summary mismatch'
  assert math.isclose(slope,stored['rssSlopeKiBPerHour'][str(pid)],rel_tol=1e-7,abs_tol=1e-6),'slope summary mismatch'
  resources[pid]=values
 assert all(x['status']=='PASS'for x in report),'lifecycle check'
 assert all(x['exit']==0 for x in gate['fpmExitRecords']),'FPM exit'
 assert len(samples)==4,'four steady workers'
 result={'status':'PASS'if len(ids)>=gate['targetResponses']else'INCOMPLETE','workloadAndResourceChecks':'PASS','mainResponses':len(ids),'twoTickRequests':len(ticks),'minTicks':min(ticks),'maxTicks':max(ticks),'workerResponseCounts':dict(workers),'resources':resources,'lifecycleCases':[{k:r[k]for k in ['name','status']}for r in report],'gate':gate,'limitations':['Within-batch independent worker chronology was not recorded; no clock-rollback certification.','Helper A/B/C/D master exit codes are not separately recorded; their original functional assertions and shutdown logic are reused.','Short observation is not endurance certification.']}
except BaseException as error:
 result={'status':'FAIL','error':repr(error)}
result['artifacts']={str(p.relative_to(root)):sha(p)for p in root.rglob('*')if p.is_file()}
(out/'report.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({k:v for k,v in result.items()if k not in ['artifacts','resources']}))
raise SystemExit(0 if result['status']=='PASS'else 2 if result['status']=='INCOMPLETE'else 1)
