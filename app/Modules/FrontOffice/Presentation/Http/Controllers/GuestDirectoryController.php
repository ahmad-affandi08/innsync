<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\GuestDirectory\GuestDirectoryService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The guests the property has hosted, newest first, so a returning guest is recognised at the desk. */
final readonly class GuestDirectoryController
{
    public function __construct(private GuestDirectoryService $guests, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['query' => ['nullable', 'string', 'max:100']]);
        $query = (string) ($data['query'] ?? '');

        return Inertia::render('front-office/pages/guests', [
            'guests' => $this->guests->list($this->property->current(), (string) $request->user()->getAuthIdentifier(), $query),
            'query' => $query,
        ]);
    }
}
