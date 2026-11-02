#!/usr/bin/env python3
import asyncio,json,math,resource,sys,time
from websockets.asyncio.client import connect
async def main():
    count=int(sys.argv[1]);url=sys.argv[2];rounds=50;latencies=[];begin=time.perf_counter();clients=[]
    try:
        clients=await asyncio.gather(*(connect(url,compression=None,proxy=None,ping_interval=None)for _ in range(count)))
        async def run(ws):
            for _ in range(rounds):
                t=time.perf_counter();await ws.send('w'*1024);assert await ws.recv()=='w'*1024;latencies.append((time.perf_counter()-t)*1000)
        start=time.perf_counter();await asyncio.gather(*(run(ws)for ws in clients));seconds=time.perf_counter()-start
    finally:await asyncio.gather(*(ws.close()for ws in clients))
    latencies.sort();q=lambda p:latencies[math.ceil(len(latencies)*p)-1]
    print(json.dumps(dict(implementation='Python websockets17.4',connections=count,messages=count*rounds,payloadBytes=1024,seconds=seconds,totalSeconds=time.perf_counter()-begin,messagesPerSecond=count*rounds/seconds,medianMs=q(.5),p95Ms=q(.95),p99Ms=q(.99),allLatenciesMs=latencies,rssPeakKiB=resource.getrusage(resource.RUSAGE_SELF).ru_maxrss)))
asyncio.run(main())
