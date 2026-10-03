<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\GuestHelpStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGuestHelpStore implements GuestHelpStore
{
    public function addRequest(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('ge_requests')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function requestByKey(PropertyId $property, string $sessionId, string $clientKey): ?array
    {
        $r = DB::table('ge_requests')->where('property_id', $property->toString())->where('session_id', $sessionId)->where('client_key', $clientKey)->first();

        return $r === null ? null : (array) $r;
    }

    public function requestsOfStay(PropertyId $property, string $stayId, int $limit): array
    {
        return DB::table('ge_requests')->where('property_id', $property->toString())->where('stay_id', $stayId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function requestsSince(PropertyId $property, string $sessionId, DateTimeImmutable $since): int
    {
        return DB::table('ge_requests')->where('property_id', $property->toString())->where('session_id', $sessionId)->where('created_at', '>=', $since)->count();
    }

    public function addSurvey(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('ge_surveys')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function surveyOfStay(PropertyId $property, string $stayId): ?array
    {
        $r = DB::table('ge_surveys')->where('property_id', $property->toString())->where('stay_id', $stayId)->first();

        return $r === null ? null : (array) $r;
    }

    public function surveysSince(PropertyId $property, DateTimeImmutable $since, int $limit): array
    {
        return DB::table('ge_surveys')->where('property_id', $property->toString())->where('created_at', '>=', $since)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }
}
