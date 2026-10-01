<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PropertySettingsController
{
    public function __construct(private PropertySettingsService $settings, private PropertyContext $property) {}

    public function show(): Response
    {
        return Inertia::render('property/pages/settings', ['settings' => $this->settings->get($this->property->current())->toArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'check_in_time' => ['required', 'string', 'max:5'],
            'check_out_time' => ['required', 'string', 'max:5'],
            'night_audit_earliest_time' => ['required', 'string', 'max:5'],
            'rounding_increment_minor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'rounding_mode' => ['required', 'string', 'max:12'],
            'availability_horizon_days' => ['required', 'integer', 'min:30', 'max:1095'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $settings = $this->settings->update(
            $this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['check_in_time'], $data['check_out_time'], $data['night_audit_earliest_time'],
            (int) $data['rounding_increment_minor'], $data['rounding_mode'], (int) $data['availability_horizon_days'], (int) $data['lock_version'], $data['reason'],
        );

        return response()->json(['settings' => $settings->toArray()])->header('Cache-Control', 'no-store');
    }

    public function initializeBusinessDate(Request $request): JsonResponse
    {
        $data = $request->validate(['business_date' => ['required', 'string', 'size:10'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);

        $settings = $this->settings->initializeBusinessDate($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['business_date'], (int) $data['lock_version'], $data['reason']);

        return response()->json(['settings' => $settings->toArray()])->header('Cache-Control', 'no-store');
    }
}
