<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if ($class === 'Asyncara') { require __DIR__ . '/src/Asyncara.php'; return; }
    if (str_starts_with($class, 'Asyncara\\')) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) require $path;
    }
});
