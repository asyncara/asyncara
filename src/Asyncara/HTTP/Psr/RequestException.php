<?php
declare(strict_types=1);
namespace Asyncara\HTTP\Psr;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
final class RequestException extends \RuntimeException implements RequestExceptionInterface
{
    public function __construct(private RequestInterface $request,\Throwable $previous){parent::__construct('Invalid Asyncara HTTP request: '.$previous->getMessage(),0,$previous);}
    public function getRequest():RequestInterface{return $this->request;}
}
