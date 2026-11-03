#!/usr/bin/env python3
"""Exhaust descriptors only in an isolated child, then verify UDP admission recovery."""
import os
from pathlib import Path
import resource
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
extension = (root / os.environ.get('ASYNCARA_EXTENSION', 'build/asyncara.so')).resolve()
fixture = r'''<?php
require $argv[1] . '/autoload.php';
$before = count(glob('/proc/self/fd/*'));
$held = [];
$failure = null;
try {
    for ($i = 0; $i < 256; $i++) {
        try { $held[] = new Asyncara\IO\UdpSocket(); }
        catch (Asyncara\Exception\OperationException $error) { $failure = $error; break; }
    }
    if (!$failure || $failure->getCode() !== 24 || count($held) < 16) {
        throw new RuntimeException('Expected Linux EMFILE within child descriptor limit');
    }
    array_pop($held)->close();
    $recovered = new Asyncara\IO\UdpSocket();
    $recovered->close();
} finally { foreach ($held as $socket) $socket->close(); }
if (count(glob('/proc/self/fd/*')) !== $before) throw new RuntimeException('Descriptor leak after EMFILE');
echo "UDP descriptor exhaustion, errno, recovery and cleanup: PASS\n";
'''


def constrain_child():
    resource.setrlimit(resource.RLIMIT_NOFILE, (128, 128))


with tempfile.NamedTemporaryFile(mode='w', suffix='.php', prefix='asyncara-udp-limit-') as file:
    file.write(fixture)
    file.flush()
    subprocess.run([os.environ.get('PHP_BIN', 'php'), '-n', '-d', 'extension=' + str(extension),
                    file.name, str(root)], preexec_fn=constrain_child, check=True, timeout=30)
