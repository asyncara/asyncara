<?php
declare(strict_types=1);
namespace Asyncara\Internal;
use Asyncara\Exception\CapacityException;
/** Shared admission check for private IPC. Does not run object serialization. */
final class JsonData
{
    public static function validate(mixed $value): void
    {
        $bytes=0;$nodes=0;self::visit($value,$bytes,$nodes,0);
    }
    private static function visit(mixed $value,int &$bytes,int &$nodes,int $depth): void
    {
        if($depth>32 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) throw new \ValueError('IPC requires bounded JSON-compatible data');
        $bytes+=is_string($value)?strlen($value):16;
        if(++$nodes>100000 || $bytes>16777216) throw new CapacityException('IPC data size limit reached');
        if(is_array($value)) foreach($value as$key=>$item){$bytes+=is_string($key)?strlen($key):8;self::visit($item,$bytes,$nodes,$depth+1);}
    }
}
