<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\StockLotService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The batches that hold stock and when they expire. Every rule and permission lives in `StockLotService`. */
final readonly class StockLotController
{
    public function __construct(private StockLotService $lots, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['department' => ['nullable', 'string', 'max:16'], 'status' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('inventory-purchasing/pages/lots', ['overview' => $this->lots->overview($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['department'] ?? null, $data['status'] ?? null), 'status' => $data['status'] ?? '']);
    }
}
