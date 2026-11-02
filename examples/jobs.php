<?php
declare(strict_types=1);
return [
    'digest' => static function(int $rounds):array {
        $value='Asyncara';
        for($i=0;$i<$rounds;$i++)$value=hash('sha256',$value,true);
        return ['pid'=>getmypid(),'digest'=>bin2hex($value)];
    },
];
