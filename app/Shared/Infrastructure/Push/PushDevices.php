<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Push;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Privacy\FieldCipher;
use Illuminate\Support\Facades\DB;
use Throwable;

/** The devices that allowed notices, and the notices waiting for them. */
final readonly class PushDevices
{
    public function __construct(private FieldCipher $cipher, private IdentifierGenerator $ids) {}

    /** Keeps the device for this person (the same device signing in as someone else moves to that person). False when the person has too many devices already. */
    public function add(string $userId, string $endpoint, ?string $label): bool
    {
        $hash = $this->cipher->blindIndex('push.endpoint', $endpoint);
        $userId = strtolower($userId);
        $known = DB::table('push_devices')->where('endpoint_hash', $hash)->first();

        if ($known === null && DB::table('push_devices')->where('user_id', $userId)->count() >= (int) config('push.max_devices')) {
            return false;
        }

        $now = now();

        if ($known === null) {
            DB::table('push_devices')->insert(['id' => $this->ids->next(), 'user_id' => $userId, 'endpoint_hash' => $hash, 'endpoint_sealed' => $this->cipher->seal($endpoint), 'label' => $label === null ? null : mb_substr($label, 0, 80), 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('push_devices')->where('endpoint_hash', $hash)->update(['user_id' => $userId, 'failures' => 0, 'updated_at' => $now]);
        }

        return true;
    }

    public function remove(string $userId, string $endpoint): void
    {
        DB::table('push_devices')->where('user_id', strtolower($userId))->where('endpoint_hash', $this->cipher->blindIndex('push.endpoint', $endpoint))->delete();
    }

    public function has(string $userId, string $endpoint): bool
    {
        return DB::table('push_devices')->where('user_id', strtolower($userId))->where('endpoint_hash', $this->cipher->blindIndex('push.endpoint', $endpoint))->exists();
    }

    /** @param list<string> $userIds @return list<array{id: string, endpoint: string}> */
    public function of(array $userIds): array
    {
        $out = [];

        foreach (DB::table('push_devices')->whereIn('user_id', array_map('strtolower', $userIds))->get(['id', 'endpoint_sealed']) as $row) {
            try {
                $out[] = ['id' => (string) $row->id, 'endpoint' => $this->cipher->open((string) $row->endpoint_sealed)];
            } catch (Throwable) {
                DB::table('push_devices')->where('id', $row->id)->delete();
            }
        }

        return $out;
    }

    public function pushed(string $id): void
    {
        DB::table('push_devices')->where('id', $id)->update(['last_pushed_at' => now(), 'failures' => 0]);
    }

    /** A device the push service no longer knows is dropped at once; one that fails again and again after a few tries. */
    public function failed(string $id, bool $gone): void
    {
        if ($gone) {
            DB::table('push_devices')->where('id', $id)->delete();

            return;
        }

        DB::table('push_devices')->where('id', $id)->increment('failures');
        DB::table('push_devices')->where('id', $id)->where('failures', '>=', 20)->delete();
    }

    public function addNotice(string $propertyId, string $deviceId, string $title, string $body, string $url): void
    {
        DB::table('push_notices')->where('device_id', $deviceId)->where('created_at', '<', now()->subDays(max(1, (int) config('push.notice_days'))))->delete();
        DB::table('push_notices')->insert(['id' => $this->ids->next(), 'property_id' => strtolower($propertyId), 'device_id' => $deviceId, 'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 300), 'url' => $url, 'created_at' => now()]);
    }

    /** The oldest notice this device of the person has not fetched yet, marked as fetched. @return array{title: string, body: string, url: string}|null */
    public function nextNotice(string $userId, string $endpoint): ?array
    {
        $device = DB::table('push_devices')->where('user_id', strtolower($userId))->where('endpoint_hash', $this->cipher->blindIndex('push.endpoint', $endpoint))->value('id');

        if ($device === null) {
            return null;
        }

        return DB::transaction(function () use ($device): ?array {
            $row = DB::table('push_notices')->where('device_id', $device)->whereNull('fetched_at')->where('created_at', '>=', now()->subDays(max(1, (int) config('push.notice_days'))))->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            DB::table('push_notices')->where('id', $row->id)->update(['fetched_at' => now()]);

            return ['title' => (string) $row->title, 'body' => (string) $row->body, 'url' => (string) $row->url];
        });
    }
}
