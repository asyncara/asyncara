<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\App;
use Asyncara\Server\Response;
$app=new App(); $input=getenv('ASYNCARA_TEST_REQUEST_INPUT')==='1';
$app->post('/echo',function($request)use($input){if(!$input)return Response::text($request->body);$body='';foreach($request->stream()as$chunk)$body.=$chunk;return Response::text($body);});
$app->get('/stream',fn()=>Response::stream((function(){ for($i=0;$i<16;$i++) yield str_repeat('x',4096); })()));
$app->get('/stop',function()use($app){$app->stop();return Response::text('ok');});
// Default production deadline, not an endurance/progress acceptance fixture.
$app->listen('127.0.0.1:0',['streamRequests'=>$input,'tls'=>['certificate'=>$argv[1].'/server.pem','privateKey'=>$argv[1].'/server.key']],function($address){echo "READY $address\n";flush();});
echo json_encode(Asyncara\Runtime::diagnostics()),"\n";
