<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\App;
use Asyncara\Server\Response;
$app=new App();
$app->post('/echo',fn($request)=>Response::text($request->body));
$app->get('/stream',fn()=>Response::stream((function(){ for($i=0;$i<16;$i++) yield str_repeat('x',4096); })()));
$app->get('/stop',function()use($app){$app->stop();return Response::text('ok');});
// Default production deadline, not an endurance/progress acceptance fixture.
$app->listen('127.0.0.1:0',['tls'=>['certificate'=>$argv[1].'/server.pem','privateKey'=>$argv[1].'/server.key']],function($address){echo "READY $address\n";flush();});
echo json_encode(Asyncara\Runtime::diagnostics()),"\n";
