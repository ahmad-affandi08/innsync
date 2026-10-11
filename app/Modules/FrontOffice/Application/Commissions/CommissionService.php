<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Commissions;

use App\Modules\FrontOffice\Application\Companies\CompanyRepository;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Export\CsvWriter;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Commissions owed to travel agents and booking channels (docs/OPERATIONS/COMMISSIONS.md). A partner is a company profile or a channel named in an imported booking file, with a rate (up to 50 percent) on the
 * room revenue. At check-out the commission on the room revenue actually charged (base only: before service charge and tax) is accrued once for the reservation. A statement pays the accrued commissions of a
 * partner by cash or transfer; an accrual that is not owed is voided with a reason. Accruals and statements are never changed or deleted.
 */
final readonly class CommissionService
{
    public const VIEW_PERMISSION = 'front-office.commission.view';

    public const MANAGE_PERMISSION = 'front-office.commission.manage';

    public const METHODS = ['transfer', 'cash'];

    public function __construct(
        private CommissionStore $store,
        private CompanyRepository $companies,
        private PermissionChecker $permissions,
        private PropertyContext $context,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $partnerId, ?string $status): array
    {
        $this->read($property, $actorId);
        $owed = $this->store->owedByPartner($property);
        $status = in_array($status, ['accrued', 'paid', 'void'], true) ? $status : null;

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $this->businessDate->current($property)->toString(), 'methods' => self::METHODS,
            'partners' => array_map(static fn (array $p): array => ['id' => $p['id'], 'kind' => $p['partner_kind'], 'key' => $p['partner_key'], 'name' => $p['name'], 'rate_bp' => (int) $p['rate_bp'], 'is_active' => (bool) $p['is_active'], 'lock_version' => (int) $p['lock_version'], 'owed_minor' => $owed[$p['id']] ?? 0], $this->store->partners($property)),
            'companies' => array_map(static fn (array $c): array => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind']], array_values(array_filter($this->companies->list($property, true), static fn (array $c): bool => (bool) $c['is_active']))),
            'accruals' => array_map(self::shape(...), $this->store->accruals($property, $partnerId, $status, 300)),
            'filter' => ['partner' => $partnerId, 'status' => $status], 'may_manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property),
        ];
    }

    /** @return array<string, mixed> */
    public function addPartner(PropertyId $property, string $actorId, string $kind, string $key, int $rateBp): array
    {
        $this->manage($property, $actorId);
        $rateBp = $this->rate($rateBp);

        if ($kind === 'company') {
            $company = $this->companies->find($property, strtolower($key)) ?? throw Refusal::invalid('Choose a company or agent profile.', ['key']);
            $name = (string) $company['name'];
            $key = (string) $company['id'];
        } elseif ($kind === 'channel') {
            $key = strtolower(trim($key));

            if (preg_match('/^[a-z0-9_]{2,30}$/D', $key) !== 1) {
                throw Refusal::invalid('Name the channel as the imported file does: 2 to 30 lower-case letters, digits or underscores (for example booking_com).', ['key']);
            }

            $name = ucfirst(str_replace('_', ' ', $key));
        } else {
            throw Refusal::invalid('Choose a company or a channel.', ['kind']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $kind, $key, $name, $rateBp): void {
            if (! $this->store->addPartner($property, ['id' => $id, 'partner_kind' => $kind, 'partner_key' => $key, 'name' => $name, 'rate_bp' => $rateBp], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This partner has a rate already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'commission.partner_added', 'commission_partner', $id, null, ['kind' => $kind, 'key' => $key, 'rate_bp' => $rateBp]));
        });

        return $this->overview($property, $actorId, null, null);
    }

    /** A new rate counts from the next check-out; what was accrued keeps the rate it was accrued with. @return array<string, mixed> */
    public function changePartner(PropertyId $property, string $actorId, string $id, int $rateBp, bool $active, int $lock): array
    {
        $this->manage($property, $actorId);
        $rateBp = $this->rate($rateBp);
        $before = $this->store->partner($property, strtolower($id)) ?? throw Refusal::notFound('Partner not found.');

        $this->transactions->run(function () use ($property, $actorId, $before, $rateBp, $active, $lock): void {
            if (! $this->store->updatePartner($property, $before['id'], $lock, $rateBp, $active, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This partner changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'commission.partner_changed', 'commission_partner', $before['id'], ['rate_bp' => (int) $before['rate_bp'], 'is_active' => (bool) $before['is_active']], ['rate_bp' => $rateBp, 'is_active' => $active]));
        });

        return $this->overview($property, $actorId, null, null);
    }

    /** Pays the accrued commissions of a partner (all of them, or the ones named). @return array{number: string, total_minor: int, accruals: int} */
    public function pay(PropertyId $property, string $actorId, string $partnerId, array $accrualIds, string $method, ?string $reference, ?string $paidOn): array
    {
        $this->manage($property, $actorId);
        $partner = $this->store->partner($property, strtolower($partnerId)) ?? throw Refusal::notFound('Partner not found.');
        $today = $this->businessDate->current($property)->toString();
        $paidOn = $paidOn === null || $paidOn === '' ? $today : $paidOn;
        $reference = $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 60);

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose cash or transfer.', ['method']);
        }

        if ($method === 'transfer' && ($reference === null || mb_strlen($reference) < 4)) {
            throw Refusal::invalid('Give the reference of the transfer.', ['reference']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) !== 1 || $paidOn > $today) {
            throw Refusal::invalid('The day paid is today or earlier.', ['paid_on']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $partner, $accrualIds, $method, $reference, $paidOn): array {
            $rows = $this->store->accruedOf($property, $partner['id'], array_map('strtolower', $accrualIds));

            if ($rows === [] || ($accrualIds !== [] && count($rows) !== count(array_unique($accrualIds)))) {
                throw Refusal::stateConflict('There is nothing accrued to pay, or some of it was paid or voided meanwhile.');
            }

            $total = array_sum(array_map(static fn (array $r): int => (int) $r['commission_minor'], $rows));
            $id = $this->ids->next();
            $number = $this->numbers->next($property, 'KOM');
            $this->store->addStatement($property, ['id' => $id, 'partner_id' => $partner['id'], 'number' => $number, 'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference, 'total_minor' => $total, 'accruals' => count($rows), 'created_by' => strtolower($actorId)], array_column($rows, 'id'), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'commission.paid', 'commission_statement', $id, null, ['number' => $number, 'partner' => $partner['name'], 'total_minor' => $total, 'accruals' => count($rows), 'method' => $method, 'paid_on' => $paidOn]));

            return ['number' => $number, 'total_minor' => $total, 'accruals' => count($rows)];
        });
    }

    public function void(PropertyId $property, string $actorId, string $accrualId, string $reason): void
    {
        $this->manage($property, $actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $a = $this->store->accrual($property, strtolower($accrualId)) ?? throw Refusal::notFound('Commission not found.');

        $this->transactions->run(function () use ($property, $actorId, $a, $reason): void {
            if (! $this->store->voidAccrual($property, $a['id'], $reason, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This commission was paid or voided already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'commission.voided', 'commission_accrual', $a['id'], ['status' => 'accrued', 'commission_minor' => (int) $a['commission_minor']], ['status' => 'void'], $reason));
        });
    }

    /** The accruals as a spreadsheet-safe CSV: what the partner is sent with the payment. @return array{filename: string, contents: string} */
    public function export(PropertyId $property, string $actorId, ?string $partnerId, ?string $status): array
    {
        $this->read($property, $actorId);
        $status = in_array($status, ['accrued', 'paid', 'void'], true) ? $status : null;
        $rows = array_map(static fn (array $a): array => [
            $a['stay_out_on'] === null ? '' : substr((string) $a['stay_out_on'], 0, 10), $a['partner_name'], $a['reservation_number'], self::money((int) $a['base_minor']), number_format((int) $a['rate_bp'] / 100, 2, '.', ''), self::money((int) $a['commission_minor']), $a['status'],
        ], $this->store->accruals($property, $partnerId, $status, 5000));
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'commission.exported', 'commission_export', $partnerId ?? 'all', null, ['rows' => count($rows), 'status' => $status]));

        return ['filename' => 'commissions-'.$this->businessDate->current($property)->toString().'.csv', 'contents' => CsvWriter::build(['Check-out', 'Partner', 'Reservation', 'Room revenue', 'Rate %', 'Commission', 'Status'], $rows)];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function shape(array $a): array
    {
        return [
            'id' => $a['id'], 'partner_id' => $a['partner_id'], 'partner_name' => $a['partner_name'], 'reservation_id' => $a['reservation_id'], 'reservation_number' => $a['reservation_number'], 'stay_out_on' => substr((string) $a['stay_out_on'], 0, 10),
            'base_minor' => (int) $a['base_minor'], 'rate_bp' => (int) $a['rate_bp'], 'commission_minor' => (int) $a['commission_minor'], 'status' => $a['status'], 'void_reason' => $a['void_reason'],
        ];
    }

    private static function money(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    private function rate(int $rateBp): int
    {
        if ($rateBp < 1 || $rateBp > 5000) {
            throw Refusal::invalid('The rate is more than 0 and at most 50 percent.', ['rate']);
        }

        return $rateBp;
    }

    private function read(PropertyId $property, string $actorId): void
    {
        $this->scope($property);

        if (! $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see commissions.');
        }
    }

    private function manage(PropertyId $property, string $actorId): void
    {
        $this->scope($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage commissions.');
        }
    }

    private function scope(PropertyId $property): void
    {
        $current = $this->context->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
