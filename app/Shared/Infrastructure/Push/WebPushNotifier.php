<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Push;

use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Web push without a payload: the notice is kept in `push_notices`, and each device of the person gets an empty push, which wakes the service worker; the worker fetches the notice from this server behind the
 * person's sign-in and shows it. So the push service (Google, Mozilla, Apple) never sees a word of it, and no encryption library is needed.
 */
final readonly class WebPushNotifier implements PushNotifier
{
    public function __construct(private PushDevices $devices) {}

    public function enabled(): bool
    {
        return Vapid::fromConfig()->enabled();
    }

    public function notify(PropertyId $property, array $userIds, string $title, string $body, string $url, string $kind = 'general'): int
    {
        if (! $this->enabled() || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return 0;
        }

        $told = 0;

        try {
            $vapid = Vapid::fromConfig();
            $userIds = array_values(array_diff(array_unique(array_map('strtolower', $userIds)), $this->devices->mutedBy($kind)));

            foreach ($this->devices->of($userIds) as $device) {
                if (! PushEndpoint::acceptable($device['endpoint'])) {
                    $this->devices->failed($device['id'], true);

                    continue;
                }

                $this->devices->addNotice($property->toString(), $device['id'], $title, $body, $url);

                try {
                    $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->withHeaders(['Authorization' => $vapid->authorization($device['endpoint'], time()), 'TTL' => (string) max(60, (int) config('push.ttl_seconds')), 'Urgency' => 'normal', 'Content-Length' => '0'])->withBody('', 'application/octet-stream')->post($device['endpoint']);
                } catch (Throwable $e) {
                    report($e);
                    $this->devices->failed($device['id'], false);

                    continue;
                }

                if ($response->successful()) {
                    $this->devices->pushed($device['id']);
                    $told++;
                } else {
                    $this->devices->failed($device['id'], in_array($response->status(), [404, 410], true));
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $told;
    }
}
