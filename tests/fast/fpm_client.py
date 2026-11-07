"""FastCGI transport identical to audit helper; explicit new fixture path."""
import socket,struct,json
def request(audit,port,index,mode,fixture):
    server=audit.server;large=audit.large;record=audit.record;exact=audit.exact
    parallel=4 if mode in ('io','stream') else 1
    params={'SCRIPT_FILENAME':str(fixture),'REQUEST_METHOD':'GET','QUERY_STRING':'mode='+mode,'SERVER_PROTOCOL':'HTTP/1.1','GATEWAY_INTERFACE':'CGI/1.1','REMOTE_ADDR':'127.0.0.1','CONTENT_LENGTH':'0','AUDIT_ID':str(index),'AUDIT_URL':f'http://127.0.0.1:{server.server_port}/slow?id={index}&parallel={parallel}','AUDIT_LARGE_FILE':str(large)}
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
    if mode in ('fatal','fatal-burst'):
        if b'Status: 500' not in output and b'Allowed memory size' not in errors:raise RuntimeError('Missing fatal error response')
        return {'fatalResponse':True}
    if errors or b'Status: 500' in output:raise RuntimeError(repr(output+errors))
    response=json.loads(output.split(b'\r\n\r\n',1)[1]);assert response['id']==str(index)
    return response
