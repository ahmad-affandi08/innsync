<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Transactions;

use App\Shared\Application\Transactions\TransactionRunner;
use Closure;
use Illuminate\Support\Facades\DB;

final class MySqlTransactionRunner implements TransactionRunner
{
    public function run(Closure $operation): mixed
    {
        return DB::transaction($operation, 3);
    }
}
