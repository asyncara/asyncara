<?php
declare(strict_types=1);
// The kernel socket is empty while PHP already owns unread bytes. TLS stream
// casting can itself fill this buffer from SSL_pending(), before epoll runs.
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (!$pair) throw new RuntimeException('socket pair failed');
try {
    fwrite($pair[0], 'buffered');
    if (fread($pair[1], 1) !== 'b') throw new RuntimeException('fixture read failed');
    if (stream_get_meta_data($pair[1])['unread_bytes'] !== 7) throw new RuntimeException('fixture did not buffer');
    $ready = asyncara_poll([42 => $pair[1]], [], 20);
    if ($ready['read'] !== [42]) throw new RuntimeException('Lost PHP-buffered stream readiness');
    if (fread($pair[1], 7) !== 'uffered') throw new RuntimeException('Buffered bytes were lost');
    $empty = asyncara_poll([42 => $pair[1]], [], 0);
    if ($empty['read'] !== []) throw new RuntimeException('Stale buffered readiness');
    fwrite($pair[0], 'x');
    $ready = asyncara_poll([42 => $pair[1]], [43 => $pair[1]], 20);
    if ($ready['read'] !== [42] || $ready['write'] !== [43]) throw new RuntimeException('Mixed descriptor interests lost');
} finally {
    fclose($pair[0]);
    fclose($pair[1]);
}
echo "Buffered stream readiness and mixed interests: PASS\n";
