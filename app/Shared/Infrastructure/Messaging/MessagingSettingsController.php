<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Messaging\MessagingAdmin;
use App\Shared\Application\Messaging\MessagingCatalog;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** How the installation sends email and WhatsApp, set on screen. Keys are written here and never read back to the browser. */
final readonly class MessagingSettingsController
{
    public function __construct(private MessagingAdmin $admin, private PermissionChecker $permissions) {}

    public function show(Request $request): Response
    {
        $this->assertInstallationWide($request);

        return Inertia::render('foundation/pages/messaging', $this->admin->overview());
    }

    public function save(Request $request, string $channel): JsonResponse
    {
        $this->assertInstallationWide($request);
        $this->assertChannel($channel);
        $data = $request->validate(['provider' => ['required', 'string', 'max:24'], 'enabled' => ['required', 'boolean'], 'values' => ['nullable', 'array'], 'values.*' => ['nullable', 'string', 'max:300']]);
        $this->admin->save((string) $request->user()->getAuthIdentifier(), $channel, $data['provider'], $data['values'] ?? [], (bool) $data['enabled']);

        return response()->json($this->admin->overview()['channels'][$channel])->header('Cache-Control', 'no-store');
    }

    public function turnOff(Request $request, string $channel): JsonResponse
    {
        $this->assertInstallationWide($request);
        $this->assertChannel($channel);
        $this->admin->turnOff((string) $request->user()->getAuthIdentifier(), $channel);

        return response()->json($this->admin->overview()['channels'][$channel])->header('Cache-Control', 'no-store');
    }

    public function test(Request $request, string $channel): JsonResponse
    {
        $this->assertInstallationWide($request);
        $this->assertChannel($channel);
        $data = $request->validate(['destination' => ['required', 'string', 'max:120']]);

        return response()->json($this->admin->test($channel, $data['destination']) + ['channel' => $this->admin->overview()['channels'][$channel]])->header('Cache-Control', 'no-store');
    }

    /**
     * The channels are one set for the whole installation, so a manager of one property alone may not change what every property sends with: the right is needed in each property.
     * With one property this is the same right as before.
     */
    private function assertInstallationWide(Request $request): void
    {
        $actor = (string) $request->user()->getAuthIdentifier();

        foreach (DB::table('properties')->pluck('id') as $property) {
            if (! $this->permissions->allowsInProperty($actor, 'property.settings.manage', PropertyId::fromString((string) $property))) {
                throw Refusal::forbidden('These settings are shared by every property; changing them needs the right to manage settings in all of them.');
            }
        }
    }

    private function assertChannel(string $channel): void
    {
        if (! array_key_exists($channel, MessagingCatalog::all())) {
            throw Refusal::notFound('Unknown channel.');
        }
    }
}
