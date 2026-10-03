<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Who owes the property money (FR-FIN-014). A company or a travel agent becomes a customer when its first folio is billed; an online channel or anyone
 * else is added by finance. The payment terms are the days from the invoice to the due date and apply to receivables made afterwards. The code, the
 * kind and the company never change; a customer that was used is deactivated, not removed.
 */
final readonly class CustomerService
{
    public const MANUAL_KINDS = ['ota', 'other'];

    public function __construct(
        private ReceivableStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requireReceivableView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $owed = [];

        foreach ($this->store->receivables($property, null) as $r) {
            $balance = (int) $r['amount_minor'] - (int) $r['received_minor'];

            if ($balance > 0) {
                $o = $owed[$r['customer_id']] ?? ['owed' => 0, 'overdue' => 0, 'open' => 0];
                $o['owed'] += $balance;
                $o['overdue'] += substr((string) $r['due_date'], 0, 10) < $today ? $balance : 0;
                $o['open']++;
                $owed[$r['customer_id']] = $o;
            }
        }

        return [
            'customers' => array_map(fn (array $c): array => [...$this->shape($c), 'owed_minor' => $owed[$c['id']]['owed'] ?? 0, 'overdue_minor' => $owed[$c['id']]['overdue'] ?? 0, 'open_receivables' => $owed[$c['id']]['open'] ?? 0], $this->store->customers($property)),
            'kinds' => self::MANUAL_KINDS, 'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, string $kind, int $termsDays): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE, 'This person may not manage customers.');
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/D', $code) !== 1) {
            throw Refusal::invalid('A code is up to 20 letters, digits, dots, dashes or underscores.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw Refusal::invalid('Give a name of at most 120 characters.', ['name']);
        }

        if (! in_array($kind, self::MANUAL_KINDS, true)) {
            throw Refusal::invalid('Choose an online channel or other. Companies and agents are made from the front office.', ['kind']);
        }

        $this->assertTerms($termsDays);
        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $code, $name, $kind, $termsDays): void {
            if (! $this->store->addCustomer($property, ['id' => $id, 'code' => $code, 'name' => $name, 'kind' => $kind, 'terms_days' => $termsDays, 'company_id' => null], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A customer with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'customer.created', 'customer', $id, null, ['code' => $code, 'name' => $name, 'kind' => $kind, 'terms_days' => $termsDays]));
        });

        return $this->shape($this->store->customer($property, $id) ?? throw Refusal::notFound('Customer not found.'));
    }

    /** @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, int $termsDays, bool $active, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE, 'This person may not manage customers.');
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw Refusal::invalid('Give a name of at most 120 characters.', ['name']);
        }

        $this->assertTerms($termsDays);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $termsDays, $active, $expectedLockVersion): void {
            $before = $this->store->customer($property, strtolower($id)) ?? throw Refusal::notFound('Customer not found.');

            if (! $this->store->updateCustomer($property, $before['id'], $expectedLockVersion, ['name' => $name, 'terms_days' => $termsDays, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This customer changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'customer.updated', 'customer', $before['id'], ['name' => $before['name'], 'terms_days' => (int) $before['terms_days'], 'is_active' => (bool) $before['is_active']], ['name' => $name, 'terms_days' => $termsDays, 'is_active' => $active]));
        });

        return $this->shape($this->store->customer($property, strtolower($id)) ?? throw Refusal::notFound('Customer not found.'));
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function shape(array $c): array
    {
        return ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'terms_days' => (int) $c['terms_days'], 'from_company' => $c['company_id'] !== null, 'active' => (bool) $c['is_active'], 'lock_version' => (int) $c['lock_version']];
    }

    private function assertTerms(int $days): void
    {
        if ($days < 0 || $days > 180) {
            throw Refusal::invalid('The payment terms are 0 to 180 days.', ['terms_days']);
        }
    }
}
