<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Presentation\Http\Requests\RejectApprovalRequest;
use App\Shared\Application\Approval\ApprovalView;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approver's inbox and decisions. Every action re-authorizes on the server in
 * `ApprovalService` (permission at the request's scope, no self-approval, one
 * decision per person); hiding a button in the UI is never the control.
 */
final readonly class ApprovalController
{
    public function __construct(private ApprovalService $approvals, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $user = (string) $request->user()->getAuthIdentifier();

        return Inertia::render('identity-access/pages/approvals', [
            'pending' => array_map(static fn (ApprovalView $v): array => $v->toArray(), $this->approvals->pendingFor($property, $user)),
            'mine' => array_map(static fn (ApprovalView $v): array => $v->toArray(), $this->approvals->requestedBy($property, $user)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $view = $this->approvals->visibleTo($this->property->current(), $id, (string) $request->user()->getAuthIdentifier());

        abort_if($view === null, 404);

        return response()->json(['approval' => $view->toArray()])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $id): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $this->approvals->approve($this->property->current(), $id, (string) $request->user()->getAuthIdentifier()));
    }

    public function reject(RejectApprovalRequest $request, string $id): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $this->approvals->reject(
            $this->property->current(),
            $id,
            (string) $request->user()->getAuthIdentifier(),
            $request->string('reason')->toString(),
        ));
    }

    public function cancel(Request $request, string $id): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $this->approvals->cancel($this->property->current(), $id, (string) $request->user()->getAuthIdentifier()));
    }

    private function respond(Request $request, ApprovalView $view): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['approval' => $view->toArray()])->header('Cache-Control', 'no-store')
            : back();
    }
}
