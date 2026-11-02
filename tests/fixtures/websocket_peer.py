#!/usr/bin/env python3
"""Independent websockets package peer plus a deliberately malformed raw peer."""
import asyncio,base64,hashlib,json,ssl,sys,urllib.parse
import websockets
from websockets.asyncio.server import serve
from websockets.asyncio.client import connect
from websockets.exceptions import ConnectionClosed

async def echo(ws):
    try:
        path=ws.request.path
        if path=='/fragments':
            async def chunks():
                yield 'snowman ☃ '
                pong=await ws.ping(b'interleaved');await asyncio.wait_for(pong,2)
                yield 'text'
            await ws.send(chunks());await ws.close();return
        if path=='/flood':
            for i in range(32):await ws.send('message-'+str(i))
            await ws.wait_closed();return
        if path=='/idle':await asyncio.sleep(.5);return
        async for message in ws:await ws.send(message)
    except ConnectionClosed:pass

async def raw(reader,writer):
    try:
        request=await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'),3)
        lines=request.decode('ascii').split('\r\n');path=urllib.parse.urlparse(lines[0].split()[1]);headers={}
        for line in lines[1:]:
            if ':'in line:k,v=line.split(':',1);headers[k.lower()]=v.strip()
        accept=base64.b64encode(hashlib.sha1((headers['sec-websocket-key']+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode()
        if path.path=='/bad-accept':accept='invalid'
        response='HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: '+accept+'\r\n'
        if path.path=='/duplicate-accept':response+='Sec-WebSocket-Accept: '+accept+'\r\n'
        if path.path=='/extension':response+='Sec-WebSocket-Extensions: permessage-deflate\r\n'
        writer.write((response+'\r\n').encode());await writer.drain()
        if path.path=='/ping-during-upload':
            async def frame():
                header=await asyncio.wait_for(reader.readexactly(2),10);length=header[1]&127
                if length==126:length=int.from_bytes(await reader.readexactly(2),'big')
                elif length==127:length=int.from_bytes(await reader.readexactly(8),'big')
                assert header[1]&128 and length<=65536
                key=await reader.readexactly(4);data=await reader.readexactly(length)
                return header[0]&15,bool(header[0]&128),bytes(b^key[i%4]for i,b in enumerate(data))
            opcode,final,data=await frame();assert opcode==2 and not final
            writer.write(b'\x89\x05probe');await writer.drain();pong=False
            while not final:
                opcode,fin,data=await frame()
                if opcode==10:assert data==b'probe';pong=True
                else:
                    assert opcode==0
                    if fin:assert pong,'Pong was blocked behind the entire fragmented message'
                    final=fin
            writer.write(b'\x81\x13control-interleaved');await writer.drain()
            opcode,_,data=await frame();assert opcode==8
            writer.write(bytes([0x88,len(data)])+data);await writer.drain()
        elif path.path=='/frame':
            packet=bytes.fromhex(urllib.parse.parse_qs(path.query)['hex'][0]);writer.write(packet);await writer.drain()
            close=await asyncio.wait_for(reader.read(1024),3)
            if len(close)>=6 and close[0]==0x88 and close[1]&128 and (close[1]&127)<=125:
                length=close[1]&127;key=close[2:6];payload=close[6:6+length]
                if len(payload)==length:writer.write(bytes([0x88,length])+bytes(b^key[i%4]for i,b in enumerate(payload)));await writer.drain()
        elif path.path=='/truncated':writer.write(b'\x82\x05ab');await writer.drain()
        elif path.path=='/no-close':await asyncio.wait_for(reader.read(1024),3);await asyncio.sleep(1)
    except (ConnectionError,asyncio.IncompleteReadError,asyncio.TimeoutError):pass
    finally:writer.close();await writer.wait_closed()

async def client(uri):
    async with connect(uri,origin='https://allowed.test',subprotocols=['asyncara'],compression='deflate',proxy=None)as ws:
        assert ws.subprotocol=='asyncara'
        await ws.send('client ☃');assert await ws.recv()=='client ☃'
        data=bytes(range(256))*2048
        await ws.send([data[:12345],data[12345:]]);assert await ws.recv()==data
        pong=await ws.ping(b'client-ping');await asyncio.wait_for(pong,2)
    print('Independent Python client to Asyncara server: PASS',flush=True)

async def main():
    if sys.argv[1]=='client':await client(sys.argv[2]);return
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(sys.argv[1],sys.argv[2])
    options=dict(compression=None,subprotocols=['asyncara'],select_subprotocol=lambda ws,ps:'asyncara'if'asyncara'in ps else None,ping_interval=None,close_timeout=1)
    plain=await serve(echo,'127.0.0.1',0,**options)
    secure=await serve(echo,'127.0.0.1',0,ssl=context,**options)
    malformed=await asyncio.start_server(raw,'127.0.0.1',0)
    print(json.dumps({'ws':plain.sockets[0].getsockname()[1],'wss':secure.sockets[0].getsockname()[1],'raw':malformed.sockets[0].getsockname()[1],'peerVersion':websockets.__version__}),flush=True)
    await asyncio.to_thread(sys.stdin.read)
    plain.close();secure.close();malformed.close()
    await plain.wait_closed();await secure.wait_closed();await malformed.wait_closed()
asyncio.run(main())
