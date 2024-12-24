<?php

namespace MuckiFacilityPlugin\Exception;

use Exception;

class InvalidBackupTypeException extends Exception
{
    public function __construct($message = "Invalid backup type provided", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
