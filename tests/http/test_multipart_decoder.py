#!/usr/bin/env python3
"""Compare binary boundary scanning with stdlib's whole-message MIME parser."""
import email.parser, email.policy, hashlib, json, time
from multipart_peer import decode_parts
boundary=b'independent-fixture';kind=b'multipart/form-data; boundary='+boundary
def reference(body):
    msg=email.parser.BytesParser(policy=email.policy.default).parsebytes(b'Content-Type: '+kind+b'\r\n\r\n'+body)
    assert not msg.defects
    return [{'field':p.get_param('name',header='content-disposition'),'filename':p.get_filename(),'type':p.get_content_type(),
             'bytes':len(p.get_payload(decode=True)),'sha256':hashlib.sha256(p.get_payload(decode=True)).hexdigest()}for p in msg.iter_parts()]
began=time.monotonic();cases=0
for payload in [b'',b'\x00\xff',b'\r\n',bytes(range(256))*256,b'\r\n--independent-fixture-suffix\r\n',b'ends--',b'prefix--independent-fixture\r\nnot-at-a-boundary']:
    body=b'--'+boundary+b'\r\nContent-Disposition: form-data; name="a\\\"b"; filename="binary.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'+payload+b'\r\n--'+boundary+b'\r\nContent-Disposition: form-data; name="empty"\r\n\r\n\r\n--'+boundary+b'--\r\n'
    assert decode_parts(kind,body)==reference(body);cases+=1
    for malformed in [body[:-2],body.replace(b'Content-Disposition:',b'Broken-Disposition:',1)]:
        try:decode_parts(kind,malformed)
        except AssertionError:pass
        else:raise AssertionError('Malformed MIME accepted')
print(json.dumps({'status':'PASS','cases':cases,'rejections':cases*2,'seconds':time.monotonic()-began}))
