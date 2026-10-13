<?php

namespace App\Services\Worker;

use RuntimeException;

/** The GPU worker (or the SSH tunnel to it) is not answering. Always worth retrying later. */
class WorkerUnreachable extends RuntimeException
{
}
