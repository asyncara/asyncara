<?php
declare(strict_types=1);
namespace Asyncara\Exception;
/** Admission or buffering was rejected before exceeding a configured limit. */
class CapacityException extends \OverflowException {}
