<?php

namespace App\Services\Worker;

use RuntimeException;

/** The worker answered with a 4xx. Retrying the same request will not help. */
class WorkerRejected extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}
