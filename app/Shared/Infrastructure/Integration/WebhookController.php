<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration;

use App\Shared\Application\Integration\WebhookReceiver;
use App\Shared\Application\Integration\WebhookRefused;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stateless endpoint for provider callbacks. It reads the RAW body (the signature covers the exact bytes), answers
 * 202 quickly, and gives a sender that fails verification nothing but a uniform 401, whether or not the provider exists.
 */
final readonly class WebhookController
{
    public function __construct(private WebhookReceiver $receiver) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        try {
            $status = $this->receiver->receive($provider, $headers, $request->getContent());
        } catch (WebhookRefused $refused) {
            abort_if($refused->reason === WebhookRefused::TOO_LARGE, 413);

            throw new AuthenticationException;
        }

        return response()->json(['status' => $status], 202)->header('Cache-Control', 'no-store');
    }
}
