<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';

use Asyncara\{Async,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;

// Diagnostic only: the original fpm_request.php acceptance fixture is unchanged.
$ticks = 0;
$tickTimes = [];
$result = Async::run(function () use (&$ticks, &$tickTimes): array {
    $http = (new HttpClient(1, 5))->get($_SERVER['PROGRESS_URL']);
    $ticker = Async::spawn(function () use ($http, &$ticks, &$tickTimes): void {
        while (!$http->isCompleted()) {
            Async::sleep(.005);
            if (!$http->isCompleted()) {
                $ticks++;
                $tickTimes[] = hrtime(true);
                if ($ticks === 2 && $_SERVER['PROGRESS_CASE'] === 'gated') {
                    Files::write($_SERVER['PROGRESS_GATE'], 'timer progressed')->await();
                }
            }
        }
    });
    $response = $http->await();
    $ticker->await();
    return ['status' => $response->status, 'body' => $response->body];
});
// Preserve the original acceptance threshold, including the pending-operation
// predicate. The injected process-stop case is an EXPECTED negative control.
$result += ['ticksWhilePending' => $ticks, 'acceptanceSatisfied' => $ticks >= 2,
    'tickTimesNs' => $tickTimes,
    'pid' => getmypid(), 'diagnostics' => Runtime::diagnostics(),
    'native' => Runtime::information()];
header('Content-Type: application/json');
echo json_encode($result, JSON_THROW_ON_ERROR);
