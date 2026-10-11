<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Push;

use App\Shared\Application\Errors\Refusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The browser side of web push: whether it is set up, keeping or dropping this device, and the notice the device fetches after an empty push. */
final readonly class PushController
{
    public function __construct(private PushDevices $devices) {}

    public function config(Request $request): JsonResponse
    {
        $vapid = Vapid::fromConfig();
        $endpoint = (string) $request->query('endpoint', '');

        return $this->json(['enabled' => $vapid->enabled(), 'public_key' => $vapid->enabled() ? $vapid->publicKey() : null, 'subscribed' => $endpoint !== '' && PushEndpoint::acceptable($endpoint) && $this->devices->has($this->user($request), $endpoint)]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        if (! Vapid::fromConfig()->enabled()) {
            throw Refusal::stateConflict('Notices on devices are not set up on this server.');
        }

        $data = $request->validate(['endpoint' => ['required', 'string', 'max:700'], 'label' => ['nullable', 'string', 'max:80']]);

        if (! PushEndpoint::acceptable($data['endpoint'])) {
            throw Refusal::invalid('This device address is not one of a known push service.', ['endpoint']);
        }

        if (! $this->devices->add($this->user($request), $data['endpoint'], $data['label'] ?? null)) {
            throw Refusal::stateConflict('This person has too many devices. Turn notices off on one first.');
        }

        return $this->json(['ok' => true], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:700']]);
        $this->devices->remove($this->user($request), $data['endpoint']);

        return $this->json(['ok' => true]);
    }

    /** What the device shows after an empty push. It marks the notice as taken, which is the one thing a GET does here; see the header note below. */
    public function next(Request $request): JsonResponse
    {
        // A header, not the address or the body: the service worker cannot send the page's CSRF token, and a header the browser sends only from this site cannot be forged from another one.
        $endpoint = (string) $request->header('X-Push-Endpoint', '');

        return $this->json(['notice' => $endpoint === '' || strlen($endpoint) > 700 ? null : $this->devices->nextNotice($this->user($request), $endpoint)]);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function user(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
