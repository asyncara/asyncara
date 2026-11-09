<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
function reference(string $raw): array {
    $headers = [];
    foreach (explode("\r\n", $raw) as $line) {
        if (str_starts_with($line, 'HTTP/')) { $headers = []; continue; }
        if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))][] = trim($value); }
    }
    return $headers;
}
$cases = ['', "HTTP/1.1 100 Continue\r\nA: discard\r\n\r\nHTTP/1.1 200 OK\r\nA: yes\r\na: again\r\n", ": zero\r\n0001: padded\r\n1: number\r\n-1: negative\r\n", " A\0 :\0v\v\t\r\n", "a:b:c\r\nx:"];
mt_srand(20261109);
for ($i=0; $i<10000; $i++) {
    $raw='';
    for ($line=0,$lines=mt_rand(1,12); $line<$lines; $line++) {
        $raw .= $line%5 === 0 ? 'HTTP/1.1 200 OK' : (string)mt_rand(-2,2).chr(mt_rand(0,255)).chr(mt_rand(0,255)).": \t".chr(mt_rand(0,255)).' : '.chr(mt_rand(0,255));
        $raw .= "\r\n";
    }
    $cases[]=$raw;
}
foreach ($cases as $i=>$raw) {
    $expected=reference($raw);
    if (asyncara_http_headers($raw)!==$expected || (new Asyncara\HTTP\Response(200,'',$raw))->headers!==$expected) throw new RuntimeException('Header metadata mismatch '.$i.' '.bin2hex($raw));
}
echo 'PASS '.count($cases)." header metadata cases, seed 20261109\n";
