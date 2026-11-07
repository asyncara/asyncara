<?php
declare(strict_types=1);
namespace Asyncara\HTTP\Psr;
use Asyncara\{Async,Scheduler};
use Asyncara\HTTP\HttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface,ResponseInterface,ResponseFactoryInterface,StreamFactoryInterface};
final class Client implements ClientInterface
{
    use \Asyncara\Internal\NotSerializable;

    public function __construct(private ResponseFactoryInterface $responses,private StreamFactoryInterface $streams,private HttpClient $http=new HttpClient()){}
    public function sendRequest(RequestInterface $request):ResponseInterface
    {
        $send=function()use($request):ResponseInterface{
            try{
                $headers=[];foreach($request->getHeaders()as$name=>$values)foreach($values as$value)$headers[]="$name: $value";
                $size=$request->getBody()->getSize();if($size!==null&&$size>16777216)throw new \ValueError('Request body exceeds 16 MiB');
                // PSR-7 body materialization follows the supplied stream's semantics.
                $body=(string)$request->getBody();
                $future=$this->http->request($request->getMethod(),(string)$request->getUri(),$body===''?null:$body,$headers);
            }catch(\Throwable $e){throw new RequestException($request,$e);}
            try{$result=Async::await($future);}catch(\Throwable $e){throw new NetworkException($request,$e);}
            $response=$this->responses->createResponse($result->status)->withBody($this->streams->createStream($result->body));
            foreach($result->headers as$name=>$values)$response=$response->withHeader($name,$values);
            // libcurl has decoded these bodies; do not leave stale transfer metadata.
            return $response->withoutHeader('Transfer-Encoding')->withoutHeader('Content-Encoding')->withHeader('Content-Length',(string)strlen($result->body));
        };
        return Scheduler::tryCurrent()?$send():Async::run($send);
    }
}
