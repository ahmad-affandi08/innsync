<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Catalog\RoomTypeView;
use App\Modules\Property\Application\Catalog\RoomView;
use App\Shared\Domain\Tenancy\PropertyId;

/** The outlet, tables and menu of an F&B test, a room with a guest in it, and the little helpers of ordering through HTTP. */
trait BuildsFnb
{
    private const ROOM = '01arz3ndektsv4rrffq69g5fc1';

    private int $keys = 0;

    /** @var array<string, string> */
    private array $id = [];

    /** @var list<array<string, mixed>> what the fake front office was asked to charge */
    private array $charged = [];

    private function fakeGuests(): void
    {
        $test = $this;
        $this->app->instance(GuestCharging::class, new class($test) implements GuestCharging
        {
            public function __construct(private object $test) {}

            public function inHouseStayOfRoom(PropertyId $property, string $roomId): ?array
            {
                return $roomId === '01arz3ndektsv4rrffq69g5fc1' ? ['stay_id' => '01arz3ndektsv4rrffq69g5fc2', 'reservation_id' => '01arz3ndektsv4rrffq69g5fc3', 'guest_name' => 'Budi Santoso'] : null;
            }

            public function charge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array
            {
                $this->test->recordCharge(['scope' => $scope, 'quoted' => $quotedMinor, 'source' => $source, 'ref' => $sourceRef, 'reservation' => $reservationId]);

                return ['posting_id' => '01arz3ndektsv4rrffq69g5fc4', 'total_minor' => intdiv($quotedMinor * 121, 100), 'currency' => 'IDR', 'replayed' => false];
            }
        });
        $this->app->instance(RoomCatalogReader::class, new class implements RoomCatalogReader
        {
            public function activeTypes(PropertyId $property): array
            {
                return [];
            }

            public function activeRooms(PropertyId $property): array
            {
                return [new RoomView('01arz3ndektsv4rrffq69g5fc1', '101', '01arz3ndektsv4rrffq69g5fc5', null, true)];
            }

            public function type(PropertyId $property, string $id): ?RoomTypeView
            {
                return null;
            }

            public function room(PropertyId $property, string $id): ?RoomView
            {
                return $id === '01arz3ndektsv4rrffq69g5fc1' ? new RoomView($id, '101', '01arz3ndektsv4rrffq69g5fc5', null, true) : null;
            }
        });
    }

    /** @param array<string, mixed> $charge */
    public function recordCharge(array $charge): void
    {
        $this->charged[] = $charge;
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'fb-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function menu(): void
    {
        $outlet = ['code' => 'REST', 'name' => 'Restaurant', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => false];
        $this->id['rest'] = (string) $this->postJson('/fnb/outlets', $outlet)->assertCreated()->json('outlet.id');
        $this->id['t1'] = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/tables", ['code' => 'T1', 'seats' => 4])->assertCreated()->json('table.id');
        $this->id['t2'] = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/tables", ['code' => 'T2', 'seats' => 2])->assertCreated()->json('table.id');
        $main = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/categories", ['code' => 'MAIN', 'name' => 'Main', 'station' => 'kitchen', 'sort_order' => 0])->assertCreated()->json('category.id');
        $item = fn (string $code, int $price): string => (string) $this->postJson('/fnb/items', ['code' => $code, 'category_id' => $main, 'name' => $code, 'description' => null, 'price_minor' => $price, 'station' => null, 'sort_order' => 0, 'variants' => [], 'group_ids' => []])->assertCreated()->json('item.id');
        $this->id['nasi'] = $item('NASI', 4_500_000);
        $this->id['tea'] = $item('TEA', 2_000_000);
    }

    /** A bill with nasi ×2 (9 000 000; with 10% service and 11% tax 10 890 000), sent, ready to be paid. */
    private function sentBill(string $table = 't1', array $extra = []): string
    {
        $bill = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $table === '' ? null : $this->id[$table], 'covers' => 2, ...$extra], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 2], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 1], $this->key())->assertOk();

        return $bill;
    }
}
