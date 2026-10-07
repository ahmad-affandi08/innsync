<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Access\AccessAdmin;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** People and roles of the property (owner instruction 2026-10-07; docs/OPERATIONS/ACCESS-ADMINISTRATION.md). Every write needs a recent password confirmation. */
final readonly class AccessAdminController
{
    public function __construct(private AccessAdmin $access, private PropertyContext $property) {}

    public function users(Request $request): Response
    {
        return Inertia::render('identity-access/pages/users', $this->access->peopleOverview($this->property->current(), $this->actor($request)));
    }

    public function roles(Request $request): Response
    {
        return Inertia::render('identity-access/pages/roles', $this->access->rolesOverview($this->property->current(), $this->actor($request)));
    }

    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role_id' => ['required', 'string', 'size:26'],
            'scope_type' => ['required', 'string', 'in:property,outlet'],
            'scope_id' => ['nullable', 'string', 'size:26', 'required_if:scope_type,outlet'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $password = $this->temporaryPassword();
        $id = $this->access->createAccount($this->property->current(), $this->actor($request), $data['name'], $data['email'], $password, [
            ['role_id' => $data['role_id'], 'scope_type' => $data['scope_type'], 'scope_id' => $data['scope_id'] ?? null],
        ], $data['reason']);

        return $this->once(['id' => $id, 'email' => mb_strtolower(trim($data['email'])), 'temporary_password' => $password], 201);
    }

    public function createUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'people' => ['required', 'array', 'min:1', 'max:'.AccessAdmin::MAX_BULK],
            'people.*.name' => ['required', 'string', 'max:255'],
            'people.*.email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role_id' => ['required', 'string', 'size:26'],
            'scope_type' => ['required', 'string', 'in:property,outlet'],
            'scope_id' => ['nullable', 'string', 'size:26', 'required_if:scope_type,outlet'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $people = array_map(fn (array $p): array => ['name' => $p['name'], 'email' => $p['email'], 'password' => $this->temporaryPassword()], array_values($data['people']));
        $ids = $this->access->createAccounts($this->property->current(), $this->actor($request), $people, $data['role_id'], $data['scope_type'], $data['scope_id'] ?? null, $data['reason']);

        return $this->once(['created' => array_map(static fn (array $p, string $id): array => ['id' => $id, 'name' => $p['name'], 'email' => mb_strtolower(trim($p['email'])), 'temporary_password' => $p['password']], $people, $ids)], 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'string', 'size:26'],
            'scope_type' => ['required', 'string', 'in:property,outlet'],
            'scope_id' => ['nullable', 'string', 'size:26', 'required_if:scope_type,outlet'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->access->assignRole($this->property->current(), $this->actor($request), $id, $data['role_id'], $data['scope_type'], $data['scope_id'] ?? null, $data['reason']);

        return $this->once(['ok' => true]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->access->revokeAssignment($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->once(['ok' => true]);
    }

    public function setActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500']]);
        $this->access->setActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], $data['reason']);

        return $this->once(['ok' => true]);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $password = $this->temporaryPassword();
        $this->access->resetPassword($this->property->current(), $this->actor($request), $id, $password, $data['reason']);

        return $this->once(['temporary_password' => $password]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $this->roleData($request);
        $id = $this->access->createRole($this->property->current(), $this->actor($request), $data['name'], (bool) $data['requires_mfa'], $data['permissions'], $data['reason']);

        return $this->once(['id' => $id], 201);
    }

    public function updateRole(Request $request, string $id): JsonResponse
    {
        $data = $this->roleData($request);
        $this->access->updateRole($this->property->current(), $this->actor($request), $id, $data['name'], (bool) $data['requires_mfa'], $data['permissions'], $data['reason']);

        return $this->once(['ok' => true]);
    }

    public function setRoleActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500']]);
        $this->access->setRoleActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], $data['reason']);

        return $this->once(['ok' => true]);
    }

    /** @return array{name: string, requires_mfa: bool, permissions: list<string>, reason: string} */
    private function roleData(Request $request): array
    {
        /** @var array{name: string, requires_mfa: bool, permissions: list<string>, reason: string} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'requires_mfa' => ['required', 'boolean'],
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['string', 'max:120'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
    }

    private function actor(Request $request): string
    {
        return strtolower((string) $request->user()->getAuthIdentifier());
    }

    /** A password that meets the rules (12+, mixed case, digit, symbol) and avoids look-alike characters. */
    private function temporaryPassword(): string
    {
        return Str::password(14, true, true, true, false).'aA1!';
    }

    /** @param array<string, mixed> $body */
    private function once(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
