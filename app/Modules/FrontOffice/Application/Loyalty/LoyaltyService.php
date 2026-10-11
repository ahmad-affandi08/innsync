<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Loyalty;

use App\Modules\FrontOffice\Application\GiftCards\GiftCardService;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Loyalty points (docs/OPERATIONS/LOYALTY.md). Points are earned when a stay ends, on the room revenue charged to it (see LoyaltyEarningConsumer), and spent by turning them into a voucher, which is then used
 * like any gift card. The balance is on the account and every movement is an entry that is never changed. A programme is off until the hotel switches it on; the rates are baselines in `config/frontdesk.php`.
 */
final readonly class LoyaltyService
{
    public const VIEW_PERMISSION = 'front-office.loyalty.view';

    public const REDEEM_PERMISSION = 'front-office.loyalty.redeem';

    public const ADJUST_PERMISSION = 'front-office.loyalty.adjust';

    public function __construct(
        private LoyaltyStore $store,
        private GiftCardService $cards,
        private PermissionChecker $permissions,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $accountId): array
    {
        if (! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, self::REDEEM_PERMISSION)) {
            throw Refusal::forbidden('This person may not see loyalty points.');
        }

        $rules = self::rules();
        $open = $accountId === null ? null : $this->store->account($property, strtolower($accountId));

        return [
            'currency' => $this->currency->currencyOf($property), 'enabled' => $rules['enabled'], 'earn_per_point_minor' => $rules['earn_per_point_minor'], 'point_value_minor' => $rules['point_value_minor'], 'min_redeem_points' => $rules['min_redeem_points'],
            'accounts' => array_map(self::shape(...), $this->store->accounts($property, 300)),
            'open' => $open === null ? null : ['account' => self::shape($open), 'entries' => array_map(static fn (array $e): array => ['id' => $e['id'], 'kind' => $e['kind'], 'points' => (int) $e['points'], 'balance_after' => (int) $e['balance_after'], 'source_type' => $e['source_type'], 'note' => $e['note'], 'business_date' => substr((string) $e['business_date'], 0, 10)], $this->store->entries($property, $open['id'], 60))],
            'may' => ['redeem' => $this->may($property, $actorId, self::REDEEM_PERMISSION) && $this->may($property, $actorId, GiftCardService::ISSUE_PERMISSION), 'adjust' => $this->may($property, $actorId, self::ADJUST_PERMISSION)],
        ];
    }

    /**
     * Turns points into a voucher. A request sent again with the same key takes the points once and answers with the same voucher (without its code, which is shown only the first time).
     *
     * @return array{card: array<string, mixed>, code: string|null, replayed: bool, balance_points: int}
     */
    public function redeem(PropertyId $property, string $actorId, IdempotencyKey $key, string $accountId, int $points): array
    {
        $rules = self::rules();

        if (! $this->may($property, $actorId, self::REDEEM_PERMISSION) || ! $this->may($property, $actorId, GiftCardService::ISSUE_PERMISSION)) {
            throw Refusal::forbidden('This person may not turn loyalty points into vouchers.');
        }

        if (! $rules['enabled']) {
            throw Refusal::stateConflict('The loyalty programme is not switched on.');
        }

        if ($points < $rules['min_redeem_points']) {
            throw Refusal::invalid("Turn in at least {$rules['min_redeem_points']} points at a time.", ['points']);
        }

        $account = $this->store->account($property, strtolower($accountId)) ?? throw Refusal::notFound('Loyalty account not found.');
        $actor = strtolower($actorId);
        $hash = hash('sha256', $key->toString());
        $ref = substr($hash, 0, 26);
        $outcome = [];

        $this->transactions->run(function () use ($property, $actor, $account, $points, $rules, $ref, $hash, &$outcome): void {
            $again = $this->store->entryBySource($property, 'redeem', 'redeem', $ref);

            if ($again === null) {
                if (! $this->store->move($property, $account['id'], -$points, $this->clock->nowUtc())) {
                    throw Refusal::invalid('The account holds fewer points than that.', ['points']);
                }

                $this->store->addEntry($property, ['id' => $this->ids->next(), 'account_id' => $account['id'], 'kind' => 'redeem', 'points' => -$points, 'balance_after' => $this->store->balance($property, $account['id']), 'source_type' => 'redeem', 'source_ref' => $ref, 'note' => null, 'business_date' => $this->businessDate->current($property)->toString(), 'actor_id' => $actor], $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'loyalty.redeemed', 'loyalty_account', $account['id'], ['points' => (int) $account['balance_points']], ['points' => (int) $account['balance_points'] - $points, 'redeemed' => $points, 'value_minor' => $points * $rules['point_value_minor']]));
            } else {
                $points = -(int) $again['points'];
            }

            $made = $this->cards->issue($property, $actor, IdempotencyKey::fromString('loyalty.'.substr($hash, 0, 40)), $points * $rules['point_value_minor'], 'voucher', 'complimentary', null, null, mb_substr('Loyalty '.$account['guest_name'], 0, 120));
            $outcome = ['card' => $made['card'], 'code' => $made['code'], 'replayed' => $again !== null, 'points' => $points];
        });

        return ['card' => $outcome['card'], 'code' => $outcome['code'], 'replayed' => $outcome['replayed'], 'balance_points' => $this->store->balance($property, $account['id'])];
    }

    /** A correction, up or down, with the reason. */
    public function adjust(PropertyId $property, string $actorId, string $accountId, int $points, string $reason): void
    {
        if (! $this->may($property, $actorId, self::ADJUST_PERMISSION)) {
            throw Refusal::forbidden('This person may not correct loyalty points.');
        }

        $reason = trim($reason);

        if ($points === 0 || abs($points) > 100_000) {
            throw Refusal::invalid('Give the points to add or take, up to 100000.', ['points']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $account = $this->store->account($property, strtolower($accountId)) ?? throw Refusal::notFound('Loyalty account not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $account, $points, $reason): void {
            if (! $this->store->move($property, $account['id'], $points, $this->clock->nowUtc())) {
                throw Refusal::invalid('The account holds fewer points than that.', ['points']);
            }

            $this->store->addEntry($property, ['id' => $this->ids->next(), 'account_id' => $account['id'], 'kind' => 'adjust', 'points' => $points, 'balance_after' => $this->store->balance($property, $account['id']), 'source_type' => 'adjust', 'source_ref' => $this->ids->next(), 'note' => $reason, 'business_date' => $this->businessDate->current($property)->toString(), 'actor_id' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'loyalty.adjusted', 'loyalty_account', $account['id'], ['points' => (int) $account['balance_points']], ['points' => (int) $account['balance_points'] + $points], $reason));
        });
    }

    /** @return array{enabled: bool, earn_per_point_minor: int, point_value_minor: int, min_redeem_points: int} */
    public static function rules(): array
    {
        return [
            'enabled' => (bool) config('frontdesk.loyalty.enabled'), 'earn_per_point_minor' => max(1, (int) config('frontdesk.loyalty.earn_per_point_minor')),
            'point_value_minor' => max(1, (int) config('frontdesk.loyalty.point_value_minor')), 'min_redeem_points' => max(1, (int) config('frontdesk.loyalty.min_redeem_points')),
        ];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function shape(array $a): array
    {
        return ['id' => $a['id'], 'name' => $a['guest_name'], 'phone_last4' => $a['phone_last4'], 'balance_points' => (int) $a['balance_points'], 'earned_points' => (int) $a['earned_points']];
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }
}
