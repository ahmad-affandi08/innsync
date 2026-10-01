<?php

declare(strict_types=1);

namespace App\Shared\Application\Transactions;

use Closure;

interface TransactionRunner
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed;
}
