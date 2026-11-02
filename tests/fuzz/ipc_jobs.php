<?php
declare(strict_types=1);
return [
    'echo'=>static fn($value)=>$value,
    'raw'=>static function(string $base64):never {
        $wire=base64_decode($base64,true);
        foreach(str_split($wire,3)as$chunk){fwrite(STDOUT,$chunk);fflush(STDOUT);usleep(100);}
        exit(23);
    },
];
