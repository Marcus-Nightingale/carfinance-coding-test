<?php

namespace App\Exceptions;

use RuntimeException;

class ImportAlreadyActiveException extends RuntimeException
{
    public function __construct(public readonly ?int $existingJobId)
    {
        parent::__construct('An import for this file is already pending or processing.');
    }
}
