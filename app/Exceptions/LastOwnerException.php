<?php

namespace App\Exceptions;

use RuntimeException;

/** Every workspace keeps at least one owner (docs/architecture.md, invariant 6). */
class LastOwnerException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A workspace needs at least one owner. Make someone else an owner first.');
    }
}
