<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Stays;

use App\Modules\FrontOffice\Application\Stays\GuestRepository;
use App\Modules\FrontOffice\Domain\Stays\GuestProfile;
use App\Modules\FrontOffice\Domain\Stays\IdType;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGuestRepository implements GuestRepository
{
    private const INDEX_SCOPE = 'frontoffice.guest.id_number';

    public function __construct(private FieldCipher $cipher) {}

    public function add(PropertyId $property, GuestProfile $guest, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('guests')->insert([
            'id' => $guest->id,
            'property_id' => $property->toString(),
            'full_name' => $guest->fullName,
            'nationality' => $guest->nationality,
            'id_type' => $guest->idType->value,
            'id_number_enc' => $this->cipher->seal($guest->idNumber),
            'id_number_index' => $this->index($guest->idType->value, $guest->normalizedNumber()),
            'id_valid_until' => $guest->idValidUntil?->toString(),
            'visa_number_enc' => $guest->visaNumber === null ? null : $this->cipher->seal($guest->visaNumber),
            'address_enc' => $this->cipher->seal($guest->address),
            'created_by' => $actorId,
            'lock_version' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function find(PropertyId $property, string $id): ?GuestProfile
    {
        $row = DB::table('guests')->where('property_id', $property->toString())->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return new GuestProfile(
            $row->id,
            $row->full_name,
            $row->nationality,
            IdType::from($row->id_type),
            $this->cipher->open($row->id_number_enc),
            $row->id_valid_until === null ? null : BusinessDate::fromString(substr((string) $row->id_valid_until, 0, 10)),
            $row->visa_number_enc === null ? null : $this->cipher->open($row->visa_number_enc),
            $this->cipher->open($row->address_enc),
        );
    }

    public function previousWithDocument(PropertyId $property, string $idType, string $idNumber, int $limit = 5): array
    {
        $rows = DB::table('guests as g')
            ->leftJoin('stays as s', 's.guest_id', '=', 'g.id')
            ->where('g.property_id', $property->toString())
            ->where('g.id_number_index', $this->index($idType, GuestProfile::normalizeNumber($idNumber)))
            ->groupBy('g.id', 'g.full_name', 'g.created_at')
            ->orderByDesc('g.created_at')
            ->limit(max(1, $limit))
            ->get(['g.id', 'g.full_name', DB::raw('COUNT(s.id) as stays'), DB::raw('MAX(s.checked_in_business_date) as last_stay')]);

        return $rows->map(static fn ($r): array => [
            'guest_id' => $r->id,
            'full_name' => $r->full_name,
            'stays' => (int) $r->stays,
            'last_stay' => $r->last_stay === null ? null : substr((string) $r->last_stay, 0, 10),
        ])->all();
    }

    private function index(string $idType, string $normalized): string
    {
        return $this->cipher->blindIndex(self::INDEX_SCOPE.'.'.$idType, $normalized);
    }
}
