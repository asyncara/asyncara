<?php
declare(strict_types=1);
// Conversion-only diagnostic. These operations are not HTTP requests/sec.
$count = (int)($argv[1] ?? 20000);
if ($count < 1) throw new ValueError('Positive operation count required');
function cpuSeconds(): float {
    $r = getrusage();
    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 +
        $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
}
function expected(string $raw): array {
    $headers = [];
    foreach (explode("\r\n", $raw) as $line) {
        if (str_starts_with($line, 'HTTP/')) { $headers = []; continue; }
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }
    }
    return $headers;
}
$common = "HTTP/1.1 200 OK\r\nServer: nginx\r\nDate: Mon, 09 Nov 2026 12:00:00 GMT\r\nContent-Type: application/octet-stream\r\nContent-Length: 1024\r\nConnection: keep-alive\r\nETag: \"test\"\r\nAccept-Ranges: bytes\r\n\r\n";
$cases = ['common' => $common, 'long-value' => "X: ".str_repeat('a', 8192)."\r\n",
    'dense-cr' => "X: ".str_repeat("\r", 8192)."\r\n",
    'dense-lf' => "X: ".str_repeat("\n", 8192)."\r\n"];
$results = [];
foreach ($cases as $name => $raw) {
    $want = expected($raw);
    for ($i = 0; $i < 500; $i++) if (asyncara_http_headers($raw) !== $want) throw new RuntimeException('Warmup mismatch');
    $start = hrtime(true); $cpu = cpuSeconds();
    for ($i = 0; $i < $count; $i++) if (asyncara_http_headers($raw) !== $want) throw new RuntimeException('Conversion mismatch');
    $results[$name] = ['operations' => $count, 'bytes' => strlen($raw),
        'wallSeconds' => (hrtime(true) - $start) / 1e9, 'cpuSeconds' => cpuSeconds() - $cpu];
}
echo json_encode(['status' => 'PASS', 'scope' => 'checked header conversions, not HTTP throughput',
    'php' => PHP_VERSION, 'results' => $results], JSON_THROW_ON_ERROR), "\n";
