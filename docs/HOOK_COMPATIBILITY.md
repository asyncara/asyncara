# Transparent hook compatibility

Only **sleep** and **usleep** are intercepted. Asyncara suspension requires a registered Task Fiber; a manually nested foreign Fiber is rejected. Both are classified **PARTIALLY_SUPPORTED**, although their supported waiting path is an asynchronous scheduler timer. Neither is a filesystem worker or kernel file I/O operation. No other hook is advertised as implemented.

| Original function | Implementation / PHP versions tested | Actual backend / status | Fallback, errors, resources, cancellation |
|---|---|---|---|
| sleep | execute_hook → hooked_sleep → Async::sleep; 8.3.36 / 8.4.27 / 8.5.11 | monotonic timer + epoll/poll wait; **PARTIALLY_SUPPORTED** | Ordinary nonnegative bounded integers, named argument, return 0, zero/negative/strict type cases tested. Outside enabled runtime original PHP handler runs. No PHP resource produced. Cancellation throws CancelledException. POSIX signal/remaining-time behavior and durations beyond scheduler’s one-year cap differ |
| usleep | same dispatch and microsecond conversion; same versions | same timer; **PARTIALLY_SUPPORTED** | Null return, named argument, negative/strict type errors, progress during 200 ms wait and cancellation tested. Outside runtime original handler. No resource. Signal semantics and full weak-coercion/deprecation matrix not certified |

The following function-by-function matrix prevents “HOOK_ALL” from being mistaken for universal coverage. **UNSUPPORTED** means no Asyncara interception; the fallback is the ordinary PHP implementation and can be blocking. A **BLOCKING_FALLBACK** is a behavior, not an asynchronous guarantee. Error/resource behavior therefore remains PHP’s own; there is no Asyncara cancellation or responsiveness certification for these original calls.

| Original target | Hook status | Original-call fallback |
|---|---|---|
| fopen | UNSUPPORTED | BLOCKING_FALLBACK; stream wrapper dependent |
| fclose | UNSUPPORTED | Original close/flush may block |
| fread | UNSUPPORTED | BLOCKING_FALLBACK unless caller configured nonblocking stream |
| fwrite | UNSUPPORTED | BLOCKING_FALLBACK unless caller configured nonblocking stream |
| file_get_contents | UNSUPPORTED | BLOCKING_FALLBACK |
| file_put_contents | UNSUPPORTED | BLOCKING_FALLBACK |
| stream_get_contents | UNSUPPORTED | BLOCKING_FALLBACK / stream dependent |
| stream_socket_client | UNSUPPORTED | Original flags determine blocking behavior |
| stream_socket_server | UNSUPPORTED | Original bind/listen setup |
| stream_select | UNSUPPORTED | Original timeout/readiness wait |
| fsockopen | UNSUPPORTED | BLOCKING_FALLBACK |
| stream_set_timeout | UNSUPPORTED | Original stream setting; no scheduler deadline |
| unlink | UNSUPPORTED | BLOCKING_FALLBACK |
| rename | UNSUPPORTED | BLOCKING_FALLBACK |
| mkdir | UNSUPPORTED | BLOCKING_FALLBACK |
| rmdir | UNSUPPORTED | BLOCKING_FALLBACK |
| copy | UNSUPPORTED | BLOCKING_FALLBACK |
| stat | UNSUPPORTED | Original metadata operation; may block |
| lstat | UNSUPPORTED | Original metadata operation; may block |
| file_exists | UNSUPPORTED | Original metadata/cache behavior |
| is_file | UNSUPPORTED | Original metadata/cache behavior |
| filesize | UNSUPPORTED | Original metadata/cache behavior |
| filemtime | UNSUPPORTED | Original metadata/cache behavior |
| curl_init | UNSUPPORTED | Original CurlHandle creation; no async interception claimed |
| curl_setopt | UNSUPPORTED | Original option handling |
| curl_exec | UNSUPPORTED | BLOCKING_FALLBACK |
| curl_multi_exec | UNSUPPORTED | Original caller-driven curl multi behavior |
| curl_multi_select | UNSUPPORTED | Original readiness wait |
| PDO::__construct | UNSUPPORTED | Original driver connection behavior |
| PDO::query | UNSUPPORTED | BLOCKING_FALLBACK for ordinary synchronous drivers |
| PDOStatement::execute | UNSUPPORTED | Original driver behavior |
| mysqli_query | UNSUPPORTED | Original flags/driver behavior |
| Redis::command / extension methods | UNSUPPORTED | Original ext-redis behavior |
| pg_query | UNSUPPORTED | Original ext-pgsql behavior |
| SoapClient::__soapCall | UNSUPPORTED | Original SOAP behavior |
| socket_connect | UNSUPPORTED | Original socket mode determines blocking behavior |
| socket_recv | UNSUPPORTED | Original socket mode determines blocking behavior |
| socket_send | UNSUPPORTED | Original socket mode determines blocking behavior |
| proc_open | UNSUPPORTED | Original process spawning behavior |
| proc_close | UNSUPPORTED | Original child reap behavior |
| exec | UNSUPPORTED | Original synchronous process behavior |

Explicit Files/SQLite/DNS APIs are **WORKER_BACKED_ASYNC** in their execution phase. Explicit readiness TCP/UDP/HTTP/SQL drivers have genuinely asynchronous waiting paths; their setup and buffered/unsupported limitations are in section 5. No untested transparent hook receives FULLY_ASYNC or WORKER_BACKED_ASYNC status.

The dispatch chains the previous Zend execute-internal handler and restores it only while still owned. Request flags reset at RINIT/RSHUTDOWN. Hook::ALL is TIME only. STRICT rejects unavailable requested groups; it cannot discover/intercept every blocking call. Long-step diagnostics measure elapsed time after return, not the identity of an unknown native function. Coexistence with the host extension set is tested separately; arbitrary profiler/debugger/hook combinations remain unverified.

Phase 3 preserves this limited hook scope. The full core/audit suites continue to test these paths on PHP 8.3/8.4/8.5. The new PHP-buffered readiness fix serves explicit asynchronous streams; it does not intercept fread, stream_select or PDO. Userland alternatives remain TcpStream, HttpClient, Files and explicit database drivers. No new hook group is certified.
