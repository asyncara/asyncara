# Ecosystem integration

Composer provides PSR-4 loading. Native installation is separate. The HTTP adapter implements PSR-18 and consumes PSR-7 requests plus PSR-17 response/stream factories. Nyholm PSR-7 is a development-test dependency, not a required HTTP implementation for consumers.

```php
$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$client = new Asyncara\HTTP\Psr\Client($factory, $factory);
$response = $client->sendRequest($factory->createRequest('GET', $url));
```

Inside Async::run, multiple sendRequest calls in spawned Tasks overlap through curl multi. Outside a runtime, the adapter runs one scope around the request. Exception interfaces and HTTP error responses follow PSR-18; buffered request-body materialization limitations remain explicit.

Laravel and Symfony can bind this adapter wherever their application accepts a PSR-18 client. This is integration guidance, not a tested framework adapter. Preserve each framework's request lifecycle; do not share mutable containers, sessions, authenticated-user globals or ORM connections between Fibers without analysis. There is no tested WordPress bootstrap integration or global HTTP transport replacement. No ORM/PDO adapter is shipped.

AMP and Revolt informed the Future/watcher design and provide a benchmark baseline. Their loops remain independent. Do not nest another loop inside Asyncara or assume an AMP Future is directly awaitable by Asyncara. A proper interoperability adapter requires explicit driver ownership and cancellation mapping; it is not implemented. ReactPHP adapters likewise remain future work.

Context can carry trace/logging keys, but no PSR-3 logging adapter is shipped. Applications may read Context in their logger processors. Context inheritance does not clone loggers or shared dependency-container objects.
