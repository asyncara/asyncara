#!/usr/bin/env python3
"""Local HTTP/1.1 keepalive fixture. No external service or artificial client sleeps."""
import asyncio,json,sys,urllib.parse,time,resource
stats={'connections':0,'requests':0,'active':0,'peak':0};writers=set()
async def client(reader,writer):
 writers.add(writer);stats['connections']+=1
 try:
  while True:
   raw=await reader.readuntil(b'\r\n\r\n')
   line=raw.split(b'\r\n')[0].decode('ascii');method,target,_=line.split(' ')
   q=urllib.parse.parse_qs(urllib.parse.urlsplit(target).query);size=int(q.get('size',['1024'])[0]);delay=float(q.get('delay',['0'])[0])
   assert method=='GET' and 0<=size<=4194304 and 0<=delay<=1
   stats['requests']+=1;stats['active']+=1;stats['peak']=max(stats['peak'],stats['active'])
   if delay:await asyncio.sleep(delay)
   body=b'x'*size;writer.write(b'HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: '+str(size).encode()+b'\r\nConnection: keep-alive\r\n\r\n'+body);await writer.drain();stats['active']-=1
 except (asyncio.IncompleteReadError,ConnectionResetError,BrokenPipeError):pass
 finally:
  writers.discard(writer);writer.close()
  try:await writer.wait_closed()
  except (ConnectionResetError,BrokenPipeError):pass
async def main():
 server=await asyncio.start_server(client,'127.0.0.1',0,limit=65536)
 print(json.dumps({'port':server.sockets[0].getsockname()[1]}),flush=True)
 loop=asyncio.get_running_loop();await loop.run_in_executor(None,sys.stdin.read)
 server.close()
 for writer in list(writers):writer.close()
 await server.wait_closed();print(json.dumps(stats|{'cpuSeconds':resource.getrusage(resource.RUSAGE_SELF).ru_utime+resource.getrusage(resource.RUSAGE_SELF).ru_stime}),flush=True)
asyncio.run(main())
