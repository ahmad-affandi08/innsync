<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Approver chains per property (BR-004): who must approve which sensitive action, from which amount. */
final readonly class ApprovalPolicyController
{
    public function __construct(private ApprovalPolicyAdmin $policies, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('identity-access/pages/approval-policies', [
            'subjects' => $this->policies->overview($this->property->current(), (string) $request->user()->getAuthIdentifier()),
        ]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:120'],
            'band_min_amount_minor' => ['required', 'integer', 'min:0'],
            'steps' => ['required', 'array', 'min:1', 'max:5'],
            'steps.*.permission' => ['required', 'string', 'max:120'],
            'steps.*.approvals_required' => ['nullable', 'integer', 'min:1', 'max:5'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $policy = $this->policies->define($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['subject_type'], (int) $data['band_min_amount_minor'], $data['steps'], $data['reason']);

        return response()->json(['policy' => ['id' => $policy->id, 'version' => $policy->version]], 201)->header('Cache-Control', 'no-store');
    }
}
