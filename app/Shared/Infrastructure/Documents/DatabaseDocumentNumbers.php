<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Documents;

use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseDocumentNumbers implements DocumentNumbers
{
    public function next(PropertyId $property, string $prefix): string
    {
        if (preg_match('/^[A-Z]{2,6}$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('A document prefix is 2 to 6 uppercase letters.');
        }

        // The row is created on first use. Another request may create it at the same moment; the update below serializes them.
        try {
            DB::table('document_sequences')->insert(['property_id' => $property->toString(), 'doc_type' => $prefix, 'last_number' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Already there.
        }

        $updated = DB::update('UPDATE document_sequences SET last_number = LAST_INSERT_ID(last_number + 1) WHERE property_id = ? AND doc_type = ?', [$property->toString(), $prefix]);

        if ($updated !== 1) {
            throw new RuntimeException('The document sequence could not be advanced.');
        }

        $number = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('%s-%06d', $prefix, $number);
    }
}
