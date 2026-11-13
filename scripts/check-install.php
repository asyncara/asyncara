<?php
declare(strict_types=1);
// Read-only installation check. Use the same PHP executable/configuration as the application.
require dirname(__DIR__) . '/autoload.php';
$errors = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, ['--require-hyper', '--require-sql'], true)) $errors[] = 'Unknown option: ' . $arg;
}
$info = [];
if (!extension_loaded('asyncara')) {
    $errors[] = 'Load asyncara.so with -d extension=/absolute/path/asyncara.so';
} else {
    $info = asyncara_info();
    preg_match('/^#define PHP_ASYNCARA_VERSION "([^"]+)"/m', file_get_contents(dirname(__DIR__) . '/ext/php_asyncara.h'), $match);
    if (($info['version'] ?? null) !== ($match[1] ?? 'missing')) $errors[] = 'Extension and source versions differ; rebuild this source';
    if (($info['buildPhpVersion'] ?? null) !== PHP_VERSION) $errors[] = 'PHP interpreter and extension SDK differ';
    if (in_array('--require-hyper', $argv, true) && !($info['hyperPrototypeBuilt'] ?? false)) $errors[] = 'Build with --with-asyncara-hyper for App/Hyper';
    if (in_array('--require-sql', $argv, true) && (!($info['postgresql'] ?? false) || !($info['mysql'] ?? false))) $errors[] = 'Build with --with-asyncara-pq --with-asyncara-mysql';
}
if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE !== 8 || PHP_ZTS) $errors[] = '0.10 validation scope is Linux 64-bit NTS';
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600) $errors[] = '0.10 supports PHP 8.3 through 8.5';
foreach (['Fiber', 'JsonException'] as $class) if (!class_exists($class)) $errors[] = 'Missing PHP capability: ' . $class;
foreach (['proc_open', 'stream_socket_client', 'json_decode'] as $function) if (!is_callable($function)) $errors[] = 'Missing PHP function: ' . $function;
echo json_encode(['status' => $errors ? 'FAIL' : 'PASS', 'classification' => 'EXPERIMENTAL',
    'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'runtime' => $info, 'errors' => $errors,
    'scope' => 'Installation capabilities only; run the test suites for behavioral validation'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($errors ? 1 : 0);
