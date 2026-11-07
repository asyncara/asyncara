<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\Async;
use Asyncara\SQLite\Connection;
try {
    foreach (['UTF-8', 'UTF-16le', 'UTF-16be'] as $encoding) {
        Async::run(static function () use ($encoding): void {
            $db = Connection::open(':memory:')->await();
            try {
                $db->query("PRAGMA encoding='$encoding'")->await();
                $db->query('CREATE TABLE t(s TEXT, b BLOB)')->await();
                foreach (["caf\xc3\xa9", "\xf0\x9f\x8c\x8d", "a\0b", ''] as $text) {
                    $db->query("INSERT INTO t VALUES (?, x'00feff80')", [$text])->await();
                }
                $rows = $db->query('SELECT s, b FROM t ORDER BY rowid')->await();
                foreach (["caf\xc3\xa9", "\xf0\x9f\x8c\x8d", "a\0b", ''] as $i => $text) {
                    if ($rows[$i]['s'] !== $text || $rows[$i]['b'] !== "\0\xfe\xff\x80") {
                        throw new RuntimeException($encoding.' row '.$i.' text='.bin2hex($rows[$i]['s']).' blob='.bin2hex($rows[$i]['b']));
                    }
                }
            } finally { $db->close()->await(); }
        });
    }
    echo "SQLite UTF-8/UTF-16 text and unchanged binary values: PASS\n";
} catch (Throwable $e) { fwrite(STDERR, (string)$e."\n"); exit(1); }
