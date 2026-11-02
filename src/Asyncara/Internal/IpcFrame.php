<?php
declare(strict_types=1);
namespace Asyncara\Internal;
use Asyncara\Exception\{CapacityException,WorkerException};
/** The same bounded private-pipe framing codec is used at both endpoints. */
final class IpcFrame
{
    public const MAX_BYTES=16777216;
    public static function length(string $header): int
    {
        if(strlen($header)!==4)throw new WorkerException('Truncated IPC header');
        $bytes=unpack('N',$header)[1];
        if($bytes<1 || $bytes>self::MAX_BYTES)throw new WorkerException('Invalid IPC frame length');
        return $bytes;
    }
    public static function encode(array $value): string
    {
        JsonData::validate($value);
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if(strlen($json)>self::MAX_BYTES)throw new CapacityException('IPC encoded frame limit reached');
        self::structure($json);
        return $json;
    }
    public static function decode(string $json): array
    {
        if(strlen($json)>self::MAX_BYTES)throw new CapacityException('IPC encoded frame limit reached');
        self::structure($json);
        $value=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new WorkerException('Invalid IPC payload');
        JsonData::validate($value);
        return $value;
    }
    private static function structure(string $json): void
    {
        // Bound metadata amplification before PHP's JSON decoder allocates arrays.
        // This is only an admission scan; json_decode remains the grammar parser.
        $quoted=false;$escaped=false;$nodes=1;$length=strlen($json);
        for($i=0;$i<$length;$i++){
            $c=$json[$i];
            if($quoted){if($escaped)$escaped=false;elseif($c==='\\')$escaped=true;elseif($c==='"')$quoted=false;}
            elseif($c==='"')$quoted=true;
            elseif(($c==='[' || $c==='{' || $c===',' || $c===':') && ++$nodes>100000)throw new CapacityException('IPC structure limit reached');
        }
    }
}
