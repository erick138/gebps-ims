<?php

namespace App\Libraries;

/** Thrown by services; controllers turn it into a JSON error with the given HTTP status. */
class ApiException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422, public array $details = [])
    {
        parent::__construct($message, $status);
    }
}
