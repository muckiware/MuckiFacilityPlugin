<?php

namespace MuckiFacilityPlugin\Exception;

use Exception;

class InvalidTableCleanupException extends Exception
{
    public function __construct($message = "Invalid database table provided for to cleanup", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
