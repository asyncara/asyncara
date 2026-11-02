<?php
declare(strict_types=1);
try {
    asyncara_mysql_open(['host'=>'127.0.0.1','port'=>1,'tls'=>true]);
    throw new RuntimeException('Unsupported TLS request was admitted');
} catch (ValueError $error) {
    if (!str_contains($error->getMessage(),'callback/status')) throw $error;
}
echo "Unsupported Connector/C TLS fails closed before network: PASS\n";
