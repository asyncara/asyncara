<?php
declare(strict_types=1);
// Read-only ABI/capability report, deliberately no environment or application data.
echo json_encode([
    'schema' => 1,
    'classification' => 'CAPABILITY_REPORT_NOT_A_TEST',
    'php' => PHP_VERSION,
    'osFamily' => PHP_OS_FAMILY,
    'machine' => php_uname('m'),
    'bits' => PHP_INT_SIZE * 8,
    'zts' => (bool) PHP_ZTS,
    'debug' => (bool) PHP_DEBUG,
    'extensionVersion' => phpversion('asyncara') ?: null,
    'runtime' => function_exists('asyncara_info') ? asyncara_info() : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
