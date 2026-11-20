<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Documents;

use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class DatabaseDocumentNumbers implements DocumentNumbers
{
    public function next(PropertyId $property, string $prefix): string
    {
        if (preg_match('/^[A-Z]{2,6}$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('A document prefix is 2 to 6 uppercase letters.');
        }

        // One statement takes the exclusive lock at once, so two requests cannot hold a shared lock each and then wait on each other. The row is created on first use
        // (number 1); after that the counter is advanced. LAST_INSERT_ID(expr) hands the new value back on both paths.
        DB::statement('INSERT INTO document_sequences (property_id, doc_type, last_number) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)', [$property->toString(), $prefix]);

        $number = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('%s-%06d', $prefix, $number);
    }
}
