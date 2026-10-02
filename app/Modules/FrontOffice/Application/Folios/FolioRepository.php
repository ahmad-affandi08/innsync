<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Folios;

use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface FolioRepository
{
    /** @return bool false when the reservation already has a folio in this window */
    public function create(PropertyId $property, Folio $folio, string $actorId, DateTimeImmutable $at): bool;

    public function find(PropertyId $property, string $id): ?Folio;

    /** Folios opened for late charges against this folio, oldest first. @return list<Folio> */
    public function lateFoliosOf(PropertyId $property, string $originFolioId): array;

    /** Reads the folio with a row lock (`FOR UPDATE`). Every posting happens under it. Call inside a transaction. */
    public function lock(PropertyId $property, string $id): ?Folio;

    /**
     * Open folios of guests who are in the house now, for moving a charge to another room (FR-FO-023).
     *
     * @return list<array{folio_id: string, number: string, window: int, label: string, reservation_id: string, room: string, guest: string}>
     */
    public function openInHouseFolios(PropertyId $property): array;

    /** @return list<Folio> */
    public function byReservation(PropertyId $property, string $reservationId): array;

    /** Appends a posting under the held lock, gives it the next sequence number and moves the balance. */
    public function append(PropertyId $property, Folio $locked, Posting $posting): Posting;

    /** @return list<Posting> in ledger order */
    public function postings(PropertyId $property, string $folioId): array;

    /** @return array{0: string, 1: Posting}|null the folio id and the posting */
    public function findPosting(PropertyId $property, string $postingId): ?array;

    /** @return array{0: string, 1: Posting}|null */
    public function findBySource(PropertyId $property, string $source, string $sourceRef): ?array;

    public function isReversed(PropertyId $property, string $postingId): bool;

    public function hasPayments(PropertyId $property, string $folioId): bool;

    /** Deposits held for a reservation: deposit payments that were not reversed, less money paid back. */
    public function depositHeldMinor(PropertyId $property, string $reservationId): int;

    /** @return bool false when the lock version no longer matches */
    public function close(PropertyId $property, Folio $folio, string $actorId, DateTimeImmutable $at): bool;

    /** The balance recomputed from the ledger, to check the stored one. */
    public function recomputeBalance(PropertyId $property, string $folioId): int;
}
