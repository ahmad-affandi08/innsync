<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Companies;

use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Companies and travel agents the hotel bills (FR-FO-035). A profile holds who to bill, a billing instruction, a credit limit and
 * which charges go to the company: the room charges (with their tax) and, if agreed, everything else. A reservation is billed to
 * one company, set once; its charges of those kinds then go to a company folio kept apart from the guest's own, and that folio may
 * stay open after the guest leaves as money the company owes, to be paid and closed like any folio. The credit limit warns, it does
 * not refuse: a room night is charged by night audit whatever the limit says.
 */
final readonly class CompanyService implements CompanyRouting
{
    public const MANAGE_PERMISSION = 'front-office.company.manage';

    public const VIEW_PERMISSION = 'front-office.company.view';

    public const LINK_PERMISSION = 'front-office.company.link';

    public function __construct(
        private CompanyRepository $companies,
        private FolioRepository $folios,
        private FolioLedger $ledger,
        private ReservationRepository $reservations,
        private DocumentNumbers $numbers,
        private PropertyCurrencyReader $currencies,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function currency(PropertyId $property): string
    {
        $this->assertProperty($property);

        return $this->currencies->currencyOf($property);
    }

    // ---- profiles ----

    /** @return array{companies: list<array<string, mixed>>, may: array{manage: bool}} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $used = $this->usedByCompany($property);

        return [
            'companies' => array_map(static fn (array $c): array => [...$c, 'used_minor' => $used[$c['id']] ?? 0, 'over_limit' => $c['credit_limit_minor'] !== null && ($used[$c['id']] ?? 0) > $c['credit_limit_minor']], $this->companies->list($property, false)),
            'may' => ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, array $data): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $row = $this->clean($data) + ['code' => strtoupper(trim((string) ($data['code'] ?? '')))];

        if (preg_match('/^[A-Z0-9_-]{2,20}$/D', $row['code']) !== 1) {
            throw Refusal::invalid('Give a code of 2 to 20 letters, digits, hyphens or underscores.', ['code']);
        }

        $id = $this->ids();

        $this->transactions->run(function () use ($property, $actorId, $id, $row): void {
            if (! $this->companies->add($property, ['id' => $id, ...$row], $this->clock->nowUtc())) {
                throw Refusal::invalid('This code is already used.', ['code']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'company.created', 'company_profile', $id, null, ['code' => $row['code'], 'name' => $row['name'], 'kind' => $row['kind'], 'credit_limit_minor' => $row['credit_limit_minor']]));
        });

        return $this->companies->find($property, $id) ?? throw Refusal::notFound('Company not found.');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, array $data, bool $active, int $lock, string $reason): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $values = $this->clean($data) + ['is_active' => $active];

        $this->transactions->run(function () use ($property, $actorId, $id, $values, $lock, $reason): void {
            $before = $this->companies->find($property, strtolower($id)) ?? throw Refusal::notFound('Company not found.');

            if (! $this->companies->update($property, $before['id'], $values, $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This company changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'company.updated', 'company_profile', $before['id'], ['credit_limit_minor' => $before['credit_limit_minor'], 'route_rooms' => $before['route_rooms'], 'route_extras' => $before['route_extras'], 'is_active' => $before['is_active']], ['credit_limit_minor' => $values['credit_limit_minor'], 'route_rooms' => $values['route_rooms'], 'route_extras' => $values['route_extras'], 'is_active' => $values['is_active']], trim($reason)));
        });

        return $this->companies->find($property, strtolower($id)) ?? throw Refusal::notFound('Company not found.');
    }

    // ---- a reservation billed to a company ----

    /** @return array{company: array<string, mixed>|null, folio_id: string|null, options: list<array<string, mixed>>, may_link: bool} */
    public function forReservation(PropertyId $property, string $actorId, string $reservationId): array
    {
        $this->assertProperty($property);

        $viewer = $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property) || $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property) || $this->permissions->allowsInProperty($actorId, self::LINK_PERMISSION, $property);

        if (! $viewer) {
            return ['company' => null, 'folio_id' => null, 'options' => [], 'may_link' => false];
        }

        $company = $this->companies->companyOf($property, strtolower($reservationId));

        return [
            'company' => $company, 'folio_id' => $this->companies->companyFolioId($property, strtolower($reservationId)),
            'options' => $company === null ? array_values(array_map(static fn (array $c): array => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name']], $this->companies->list($property, true))) : [],
            'may_link' => $company === null && $this->permissions->allowsInProperty($actorId, self::LINK_PERMISSION, $property),
        ];
    }

    /**
     * Bills a reservation to a company, once. The company's folio is opened when the company takes any charges.
     *
     * @return array{company: array<string, mixed>, folio_id: string|null}
     */
    public function link(PropertyId $property, string $actorId, string $reservationId, string $companyId): array
    {
        $this->authorize($property, $actorId, [self::LINK_PERMISSION]);
        $reservation = $this->reservations->find($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
        $company = $this->companies->find($property, strtolower($companyId));

        if ($company === null || ! $company['is_active']) {
            throw Refusal::invalid('Choose an active company or agent.', ['company_id']);
        }

        if (! $reservation->status->holdsInventory()) {
            throw Refusal::stateConflict('Only a booking that is still to come or in the house can be billed to a company.');
        }

        $folioId = null;

        $this->transactions->run(function () use ($property, $actorId, $reservation, $company, &$folioId): void {
            if (! $this->companies->link($property, $reservation->id, $company['id'], strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This reservation is already billed to a company.');
            }

            if ($company['route_rooms'] || $company['route_extras']) {
                $window = max(1, $this->companies->highestWindow($property, $reservation->id)) + 1;

                if ($window > 20) {
                    throw Refusal::stateConflict('This reservation has no free folio window for the company.');
                }

                $folio = new Folio($this->ledger->newId(), $this->numbers->next($property, 'FOL'), $reservation->id, $window, $company['code'], $reservation->total->currency, false, Money::zero($reservation->total->currency), 0, 0);
                $this->folios->create($property, $folio, strtolower($actorId), $this->clock->nowUtc());
                $this->companies->markCompanyFolio($property, $folio->id, $reservation->id, $company['id'], $this->clock->nowUtc());
                $folioId = $folio->id;
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'company.linked', 'reservation', $reservation->id, null, ['company' => $company['code'], 'folio_id' => $folioId]));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.company.linked', $reservation->id, 1, ['reservation_id' => $reservation->id, 'company_id' => $company['id'], 'actor_id' => strtolower($actorId)]));
        });

        return ['company' => $company, 'folio_id' => $folioId];
    }

    // ---- what the companies owe ----

    /**
     * Open company folios by company, with what is owed and the limit.
     *
     * @return array{companies: list<array<string, mixed>>, total_minor: int}
     */
    public function accounts(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $byCompany = [];

        foreach ($this->companies->openFolios($property) as $folio) {
            $byCompany[$folio['company_id']][] = $folio;
        }

        $result = [];
        $total = 0;

        foreach ($this->companies->list($property, false) as $c) {
            $folios = $byCompany[$c['id']] ?? [];

            if ($folios === []) {
                continue;
            }

            $used = array_sum(array_column($folios, 'balance_minor'));
            $total += $used;
            $result[] = [...$c, 'used_minor' => $used, 'over_limit' => $c['credit_limit_minor'] !== null && $used > $c['credit_limit_minor'], 'folios' => $folios];
        }

        return ['companies' => $result, 'total_minor' => $total];
    }

    // ---- routing ----

    public function routeTo(PropertyId $property, string $reservationId, string $category): ?string
    {
        $company = $this->companies->companyOf($property, $reservationId);

        if ($company === null || ! ($category === 'room' ? $company['route_rooms'] : $company['route_extras'])) {
            return null;
        }

        $folioId = $this->companies->companyFolioId($property, $reservationId);

        if ($folioId === null) {
            return null;
        }

        $folio = $this->folios->find($property, $folioId);

        return $folio === null || $folio->isClosed ? null : $folio->id;
    }

    public function isCompanyFolio(PropertyId $property, string $folioId): bool
    {
        return $this->companies->isCompanyFolio($property, $folioId);
    }

    // ---- internals ----

    /** @return array<string, int> */
    private function usedByCompany(PropertyId $property): array
    {
        $used = [];

        foreach ($this->companies->openFolios($property) as $folio) {
            $used[$folio['company_id']] = ($used[$folio['company_id']] ?? 0) + $folio['balance_minor'];
        }

        return $used;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $text = static fn (mixed $v, int $max): ?string => $v === null || trim((string) $v) === '' ? null : mb_substr(trim((string) $v), 0, $max);
        $name = trim((string) ($data['name'] ?? ''));
        $kind = (string) ($data['kind'] ?? '');
        $limit = $data['credit_limit_minor'] ?? null;

        if ($name === '' || mb_strlen($name) > 120 || ! in_array($kind, ['company', 'agent'], true)) {
            throw Refusal::invalid('Give a name (at most 120 characters) and choose company or agent.', ['name', 'kind']);
        }

        if ($limit !== null && (! is_int($limit) || $limit < 0 || $limit > 1_000_000_000_000)) {
            throw Refusal::invalid('The credit limit is an amount of zero or more, or empty for none.', ['credit_limit_minor']);
        }

        foreach (['contact_name' => 100, 'contact_phone' => 30, 'contact_email' => 190, 'tax_id' => 30, 'billing_instruction' => 500] as $field => $max) {
            if (isset($data[$field]) && mb_strlen(trim((string) $data[$field])) > $max) {
                throw Refusal::invalid("This field is at most {$max} characters.", [$field]);
            }
        }

        if (isset($data['contact_email']) && trim((string) $data['contact_email']) !== '' && filter_var(trim((string) $data['contact_email']), FILTER_VALIDATE_EMAIL) === false) {
            throw Refusal::invalid('Give a valid e-mail address.', ['contact_email']);
        }

        return [
            'name' => $name, 'kind' => $kind, 'contact_name' => $text($data['contact_name'] ?? null, 100), 'contact_phone' => $text($data['contact_phone'] ?? null, 30), 'contact_email' => $text($data['contact_email'] ?? null, 190),
            'tax_id' => $text($data['tax_id'] ?? null, 30), 'billing_instruction' => $text($data['billing_instruction'] ?? null, 500), 'credit_limit_minor' => $limit,
            'route_rooms' => (bool) ($data['route_rooms'] ?? true), 'route_extras' => (bool) ($data['route_extras'] ?? false),
        ];
    }

    private function ids(): string
    {
        return $this->ledger->newId();
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use company accounts.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
