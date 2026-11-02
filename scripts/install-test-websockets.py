#!/usr/bin/env python3
"""Pinned independent test peer, extracted locally; no global Python changes."""
import hashlib,io,pathlib,urllib.request,zipfile
root=pathlib.Path(__file__).resolve().parents[1]
target=root/'.local/ws-peer-17.4'
url='https://files.pythonhosted.org/packages/eb/27/9db924cb0dbf22eade78d98fbae77790f9bf6bc729e6ab29aef9d68e4156/websockets-17.4-py3-none-any.whl'
expected='c6bd2c9781915592b209160d0f017d4c7c82ad53075e6b0c24d2a89299e7f9e9'
marker=target/'.verified-sha256'
if marker.exists():
    if marker.read_text().strip()!=expected:raise SystemExit('Existing peer marker differs; inspect before replacing')
    print('Pinned WebSocket peer already available:',target);raise SystemExit(0)
if target.exists():raise SystemExit('Existing unverified test directory; inspect it before installation')
data=urllib.request.urlopen(url,timeout=30).read()
if hashlib.sha256(data).hexdigest()!=expected:raise SystemExit('WebSocket peer SHA-256 mismatch')
with zipfile.ZipFile(io.BytesIO(data))as archive:
    for entry in archive.infolist():
        if pathlib.PurePosixPath(entry.filename).is_absolute()or'..'in pathlib.PurePosixPath(entry.filename).parts:raise SystemExit('Unsafe test wheel path')
    target.mkdir(parents=True);archive.extractall(target)
marker.write_text(expected+'\n')
print('Installed local BSD-licensed websockets17.4 test peer:',target)
