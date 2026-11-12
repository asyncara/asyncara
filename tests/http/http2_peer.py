#!/usr/bin/env python3
"""Independent hyper-h2 TLS peer; bounded metadata, controlled pending streams."""
import asyncio,gzip,hashlib,json,pathlib,ssl,sys,time,urllib.parse
import h2.config,h2.connection,h2.events,h2.errors,h2.settings
from hyperframe.frame import GoAwayFrame
certs=pathlib.Path(sys.argv[1]);connections=set();next_id=0
stats={'accepted':0,'rejectedAlpn':0,'requests':[],'resets':0,'peakHeld':0,'earlyResponses':0,'errors':[]}
class Peer:
    def __init__(self,writer,identity):
        self.writer=writer;self.id=identity;self.h2=h2.connection.H2Connection(config=h2.config.H2Configuration(client_side=False,header_encoding='utf-8'))
        self.requests={};self.pending={};self.held={};self.timers={};self.h2.initiate_connection()
    def flush(self):
        for sid,(body,offset,total) in list(self.pending.items()):
            try:
                while offset<total:
                    n=min(total-offset,self.h2.local_flow_control_window(sid),self.h2.max_outbound_frame_size)
                    if n<=0:break
                    self.h2.send_data(sid,b'x'*n if body is None else body[offset:offset+n],end_stream=offset+n==total);offset+=n
                if offset==total:del self.pending[sid]
                else:self.pending[sid]=(body,offset,total)
            except (h2.exceptions.StreamClosedError,h2.exceptions.NoSuchStreamError):self.pending.pop(sid,None)
        data=self.h2.data_to_send()
        if data:self.writer.write(data)
    def reply(self,sid,body=b'ok',status='200',headers=(),size=None,head=False):
        total=len(body)if size is None else size
        self.h2.send_headers(sid,[(':status',status),('content-length',str(total)),('x-test-protocol','h2'),('x-test-connection',str(self.id)),('x-test-stream',str(sid)),*headers],end_stream=head or total==0)
        if not head and total:self.pending[sid]=(body,0,total)
    def complete(self,sid):
        req=self.requests.pop(sid);url=urllib.parse.urlsplit(req['path']);path=url.path;group=path.rsplit('/',1)[-1]
        stats['requests'].append({'connection':self.id,'stream':sid,'method':req['method'],'path':path,'bytes':req['bytes']})
        if len(stats['requests'])>400:raise RuntimeError('Fixture request bound')
        if path=='/fpm':
            self.held[sid]='fpm';began=time.time()
            def finish():
                self.timers.pop(sid,None)
                if sid not in self.held:return
                self.held.pop(sid);self.reply(sid,json.dumps({'start':began,'end':time.time(),'connection':self.id,'stream':sid}).encode());self.flush()
            self.timers[sid]=asyncio.get_running_loop().call_later(.2,finish);return
        if path.startswith('/hold/'):
            self.held[sid]=group;stats['peakHeld']=max(stats['peakHeld'],sum(len(p.held)for p in connections));return
        if path.startswith('/state/'):
            self.reply(sid,json.dumps({'held':sum(sum(g==group for g in p.held.values())for p in connections)}).encode())
        elif path.startswith('/release/'):
            for peer in list(connections):
                for stream,g in list(peer.held.items()):
                    if g==group:del peer.held[stream];peer.reply(stream,b'released')
                peer.flush()
            self.reply(sid)
        elif path=='/zero-window':
            self.h2.update_settings({h2.settings.SettingCodes.INITIAL_WINDOW_SIZE:0,h2.settings.SettingCodes.MAX_CONCURRENT_STREAMS:1});self.reply(sid)
        elif path=='/reset-count':self.reply(sid,json.dumps({'resets':stats['resets'],'openUploads':sum(len(p.requests)for p in connections)}).encode())
        elif path=='/echo':self.reply(sid,json.dumps({'bytes':req['bytes'],'sha256':req['digest'].hexdigest(),'method':req['method']}).encode())
        elif path=='/head':self.reply(sid,size=123,head=True)
        elif path=='/trailers':
            self.h2.send_headers(sid,[(':status','200'),('content-length','2')]);self.h2.send_data(sid,b'ok');self.h2.send_headers(sid,[('x-trailer','done')],end_stream=True)
        elif path=='/informational':
            self.h2.send_headers(sid,[(':status','103'),('link','</fixture>')]);self.reply(sid)
        elif path=='/goaway-drain':
            # Keep admitted streams live. Inject a valid GOAWAY without moving
            # python-h2's server state to CLOSED, so the held stream can finish.
            self.reply(sid);self.flush();frame=GoAwayFrame(0);frame.last_stream_id=sid;frame.error_code=0;self.writer.write(frame.serialize())
        elif path=='/goaway':
            self.reply(sid);self.flush();self.h2.close_connection(error_code=h2.errors.ErrorCodes.NO_ERROR);self.flush();self.writer.close()
        elif path=='/disconnect':self.writer.transport.abort()
        elif path=='/gzip':self.reply(sid,gzip.compress(bytes(range(256))*256),headers=[('content-encoding','gzip')])
        elif path=='/oversize':self.reply(sid,body=None,size=16777217)
        elif path=='/large':self.reply(sid,body=None,size=2097152)
        elif path=='/header-limit':self.reply(sid,headers=[('x-padding-'+str(i),'a'*8192)for i in range(9)])
        elif path=='/reset':
            self.h2.send_headers(sid,[(':status','200'),('content-length','100')]);self.h2.send_data(sid,b'partial');self.h2.reset_stream(sid,error_code=h2.errors.ErrorCodes.INTERNAL_ERROR)
        elif path=='/short':self.reply(sid,b'short',size=100);self.pending.pop(sid,None);self.h2.send_data(sid,b'short',end_stream=True)
        elif path=='/redirect':self.reply(sid,b'',status='302',headers=[('location','/ok')])
        else:self.reply(sid,headers=[('x-repeat','one'),('x-repeat','two')])
    def receive(self,data):
        for event in self.h2.receive_data(data):
            if isinstance(event,h2.events.RequestReceived):
                headers=dict(event.headers);self.requests[event.stream_id]={'path':headers[':path'],'method':headers[':method'],'bytes':0,'digest':hashlib.sha256()}
                if len(self.requests)+len(self.held)>64:raise RuntimeError('Fixture active-stream bound')
                if headers[':path']=='/early':
                    stats['earlyResponses']+=1;self.reply(event.stream_id,b'rejected',status='413')
            elif isinstance(event,h2.events.DataReceived):
                req=self.requests[event.stream_id];req['bytes']+=len(event.data);req['digest'].update(event.data)
                if req['bytes']>1048576:raise RuntimeError('Fixture upload bound')
                if req['path']=='/early-started' and not req.get('early'):
                    req['early']=True;stats['earlyResponses']+=1;self.reply(event.stream_id,b'rejected',status='413')
                self.h2.acknowledge_received_data(event.flow_controlled_length,event.stream_id)
            elif isinstance(event,h2.events.StreamEnded):self.complete(event.stream_id)
            elif isinstance(event,h2.events.StreamReset):
                handle=self.timers.pop(event.stream_id,None)
                if handle:handle.cancel()
                self.held.pop(event.stream_id,None);self.pending.pop(event.stream_id,None);self.requests.pop(event.stream_id,None);stats['resets']+=1
        self.flush()
async def handle(reader,writer):
    global next_id
    peer=None
    try:
        if writer.get_extra_info('ssl_object').selected_alpn_protocol()!='h2':stats['rejectedAlpn']+=1;return
        next_id+=1;peer=Peer(writer,next_id);connections.add(peer);stats['accepted']+=1;peer.flush()
        while data:=await asyncio.wait_for(reader.read(65536),20):
            peer.receive(data);await writer.drain()
    except (ConnectionError,ssl.SSLError):pass
    except BaseException as error:stats['errors'].append(repr(error))
    finally:
        if peer:
            connections.discard(peer)
            for timer in peer.timers.values():timer.cancel()
        writer.close()
        try:await writer.wait_closed()
        except (ConnectionError,ssl.SSLError):pass
async def main():
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.minimum_version=ssl.TLSVersion.TLSv1_2;ctx.set_alpn_protocols(['h2']);ctx.load_cert_chain(certs/'server.pem',certs/'server.key')
    server=await asyncio.start_server(handle,'127.0.0.1',0,ssl=ctx,ssl_handshake_timeout=3)
    print(json.dumps({'https':server.sockets[0].getsockname()[1]}),flush=True)
    await asyncio.get_running_loop().run_in_executor(None,sys.stdin.read)
    server.close();await server.wait_closed()
    for peer in list(connections):peer.writer.close()
    print(json.dumps(stats),flush=True)
asyncio.run(main())
