<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\DashboardPreferenceRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDashboardPreferenceRepository implements DashboardPreferenceRepository
{
    public function find(PropertyId $property, string $userId): ?array
    {
        $row = DB::table('dashboard_preferences')->where('property_id', $property->toString())->where('user_id', $userId)->first();

        return $row === null ? null : ['order' => json_decode((string) $row->card_order, true, 512, JSON_THROW_ON_ERROR), 'hidden' => json_decode((string) $row->hidden_cards, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function save(PropertyId $property, string $userId, array $order, array $hidden, DateTimeImmutable $at): void
    {
        DB::table('dashboard_preferences')->updateOrInsert(
            ['property_id' => $property->toString(), 'user_id' => $userId],
            ['card_order' => json_encode($order, JSON_THROW_ON_ERROR), 'hidden_cards' => json_encode($hidden, JSON_THROW_ON_ERROR), 'updated_at' => $at],
        );
    }

    public function clear(PropertyId $property, string $userId): void
    {
        DB::table('dashboard_preferences')->where('property_id', $property->toString())->where('user_id', $userId)->delete();
    }
}
