<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class SyncActivityCancelledException extends Exception
{
    public function __construct(string $message = 'İşlem iptal edildi.')
    {
        parent::__construct($message);
    }
}
