<?php
declare(strict_types=1);
namespace Asyncara\HTTP\Psr;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private RequestInterface $request,\Throwable $previous){parent::__construct('Asyncara HTTP transport failed: '.$previous->getMessage(),0,$previous);}
    public function getRequest():RequestInterface{return $this->request;}
}
