<?php
declare(strict_types=1);
foreach ([
    ['host'=>'127.0.0.1','port'=>1,'tls'=>true],
    ['host'=>'127.0.0.1','port'=>1],
    ['socket'=>'/nonexistent-asyncara-socket','tls'=>true],
    ['host'=>'127.0.0.1','port'=>1,'tls'=>false,'caFile'=>'/nonexistent-asyncara-ca'],
] as $options) {
    try {
        asyncara_mysql_open($options);
        throw new RuntimeException('Unsupported TLS request was admitted');
    } catch (ValueError $error) {
        if (!str_contains($error->getMessage(),'callback/status')) throw $error;
    }
}
echo "Unsupported Connector/C TLS/default TCP/CA fails closed before network: PASS\n";
