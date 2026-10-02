<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Modules\Laundry\Domain\LaundryLine;
use App\Modules\Laundry\Domain\LaundryOrder;
use App\Modules\Laundry\Domain\LaundryStatus;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoredFileRepository;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Claims for damaged or lost guest laundry (FR-LDY-006). Someone at the laundry or housekeeping records what happened to which
 * order (and which item, how many pieces), with a photo and the value the guest claims. A Manager on Duty, never the person who
 * recorded it, approves an amount up to the claim, or rejects it with a reason. When the claim names an item, the baseline caps the
 * compensation at ten times the laundry price of those pieces (a common term of hotel laundry in Indonesia; the owner may change the
 * multiple or switch the cap off). Nothing is posted to the guest's folio: how the compensation is paid or credited is the hotel's
 * decision, and the approved amount is announced for Finance. The photo is private and erased a year after the decision.
 */
final readonly class ClaimService
{
    public const RECORD_PERMISSION = 'laundry.claim.record';

    public const APPROVE_PERMISSION = 'laundry.claim.approve';

    public const VIEW_PERMISSION = 'laundry.claim.view';

    public const PHOTO_PURPOSE = 'laundry.claim';

    public const PHOTO_MAX_BYTES = 5_242_880;

    public const DEFAULT_CAP_MULTIPLE = 10;

    public const MAX_CLAIM_MINOR = 1_000_000_000_000;

    private const RETENTION_CATEGORY = 'laundry_claim_photo';

    public function __construct(
        private ClaimRepository $claims,
        private LaundryRepository $orders,
        private RoomCatalogReader $rooms,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private StoredFileRepository $files,
        private RetentionPolicies $retention,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{claims: list<array<string, mixed>>, orders: list<array<string, mixed>>, currency: string, cap_multiple: int, settings_lock: int|null, may: array{record: bool, approve: bool, settings: bool}}
     */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->authorizeAny($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['open', 'approved', 'rejected'], true)) {
            throw Refusal::invalid('Choose open, approved or rejected.', ['status']);
        }

        $claims = $this->claims->list($property, $status === '' ? null : $status, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_merge(array_column($claims, 'recorded_by'), array_filter(array_column($claims, 'decided_by'))))));
        $may = $this->abilities($property, $actorId);
        $settings = $this->claims->settings($property);

        return [
            'claims' => array_map(fn (array $c): array => [...$c, 'recorded_by_name' => $names[$c['recorded_by']] ?? null, 'decided_by_name' => $c['decided_by'] === null ? null : ($names[$c['decided_by']] ?? null),
                'has_photo' => $c['photo_file_id'] !== null, 'cap_minor' => $c['status'] === 'open' ? $this->capOf($property, $c) : null, 'may_decide' => $may['approve'] && $c['status'] === 'open' && $c['recorded_by'] !== strtolower($actorId)], $claims),
            'orders' => $may['record'] ? $this->orderChoices($property) : [],
            'currency' => $this->currencies->currencyOf($property), 'cap_multiple' => $settings['cap_multiple'] ?? self::DEFAULT_CAP_MULTIPLE, 'settings_lock' => $settings['lock_version'] ?? null, 'may' => $may,
        ];
    }

    /** The claims on one order, for its page. @return list<array<string, mixed>> */
    public function ofOrder(PropertyId $property, string $actorId, string $orderId): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, self::RECORD_PERMISSION) && ! $this->may($property, $actorId, self::APPROVE_PERMISSION)) {
            return [];
        }

        return array_map(static fn (array $c): array => [...$c, 'has_photo' => $c['photo_file_id'] !== null], $this->claims->ofOrder($property, strtolower($orderId)));
    }

    /**
     * Records a claim. `$photo` is the content of an image (JPEG or PNG), optional.
     *
     * @return array<string, mixed>
     */
    public function record(PropertyId $property, string $actorId, string $orderId, ?string $lineId, int $pieces, string $kind, string $description, int $claimedMinor, ?string $photo, ?string $photoName): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::RECORD_PERMISSION)) {
            throw Refusal::forbidden('This person may not record laundry claims.');
        }

        $description = trim($description);
        $lineId = $lineId === null || $lineId === '' ? null : strtolower($lineId);

        if (! in_array($kind, ['damage', 'loss'], true)) {
            throw Refusal::invalid('Choose damage or loss.', ['kind']);
        }

        if ($description === '' || mb_strlen($description) > 300) {
            throw Refusal::invalid('Say what happened, in at most 300 characters.', ['description']);
        }

        if ($claimedMinor < 1 || $claimedMinor > self::MAX_CLAIM_MINOR) {
            throw Refusal::invalid('Give the value the guest claims, more than zero.', ['claimed_minor']);
        }

        $order = $this->orders->findOrder($property, strtolower($orderId)) ?? throw Refusal::notFound('Order not found.');

        if ($order->status === LaundryStatus::Cancelled) {
            throw Refusal::stateConflict('A cancelled order took no laundry, so there is nothing to claim.');
        }

        $line = $lineId === null ? null : $this->lineOf($order, $lineId);

        if ($line === null && $lineId !== null) {
            throw Refusal::invalid('Choose an item of this order.', ['line_id']);
        }

        if ($pieces < 1 || ($line !== null && $pieces > $line->quantity) || ($line === null && $pieces !== 1)) {
            throw Refusal::invalid('Say how many pieces of the item are concerned (not more than were handed in).', ['pieces']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $file = null;

        if ($photo !== null && $photo !== '') {
            try {
                $file = $this->storeFile->execute(new FileUpload($property, $actor, self::PHOTO_PURPOSE, 'laundry-claim', $id, $photo, new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Sensitive, false), $photoName));
            } catch (FileRejected $e) {
                throw Refusal::invalid($e->getMessage(), ['photo']);
            }
        }

        try {
            $this->transactions->run(function () use ($property, $actor, $id, $order, $line, $pieces, $kind, $description, $claimedMinor, $file): void {
                $number = $this->numbers->next($property, 'CLM');
                $currency = $this->currencies->currencyOf($property);
                $this->claims->add($property, [
                    'id' => $id, 'number' => $number, 'order_id' => $order->id, 'line_id' => $line?->id, 'pieces' => $pieces, 'kind' => $kind, 'description' => $description, 'claimed_minor' => $claimedMinor,
                    'currency_code' => $currency, 'photo_file_id' => $file?->id, 'recorded_by' => $actor,
                ], $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'laundry_claim.recorded', 'laundry_claim', $id, null, ['number' => $number, 'order' => $order->number, 'kind' => $kind, 'claimed_minor' => $claimedMinor, 'item' => $line?->itemName, 'pieces' => $pieces, 'has_photo' => $file !== null]));
                $this->outbox->publish(new OutboxEvent($property, 'laundry.claim.recorded', $id, 1, ['claim_id' => $id, 'number' => $number, 'order_id' => $order->id, 'actor_id' => $actor]));
            });
        } catch (Throwable $e) {
            if ($file !== null) {
                $this->files->setExpiryOnce($property, $file->id, $this->clock->nowUtc());
            }

            throw $e;
        }

        return $this->claims->find($property, $id) ?? throw Refusal::notFound('Claim not found.');
    }

    /** @return array<string, mixed> */
    public function approve(PropertyId $property, string $actorId, string $claimId, int $approvedMinor, ?string $note, int $lock): array
    {
        return $this->decide($property, $actorId, $claimId, 'approved', $approvedMinor, $note, $lock);
    }

    /** @return array<string, mixed> */
    public function reject(PropertyId $property, string $actorId, string $claimId, string $note, int $lock): array
    {
        return $this->decide($property, $actorId, $claimId, 'rejected', null, $note, $lock);
    }

    public function photo(PropertyId $property, string $actorId, string $claimId): FileContent
    {
        $this->authorizeAny($property, $actorId);
        $claim = $this->claims->find($property, strtolower($claimId)) ?? throw Refusal::notFound('Claim not found.');

        if ($claim['photo_file_id'] === null) {
            throw Refusal::notFound('This claim has no photo.');
        }

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                foreach ([ClaimService::VIEW_PERMISSION, ClaimService::RECORD_PERMISSION, ClaimService::APPROVE_PERMISSION] as $permission) {
                    if ($this->permissions->allowsInProperty($actorId, $permission, $this->property)) {
                        return true;
                    }
                }

                return false;
            }
        };

        try {
            return $this->downloadFile->execute($property, $claim['photo_file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The photo is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see laundry claims.');
        }
    }

    /** Sets the multiple of the laundry price a compensation may reach (0 for no cap). @return array{cap_multiple: int, lock_version: int} */
    public function saveCap(PropertyId $property, string $actorId, int $multiple, ?int $lock, string $reason): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, LaundryService::PRICES_PERMISSION)) {
            throw Refusal::forbidden('This person may not change how far a compensation can go.');
        }

        if ($multiple < 0 || $multiple > 100) {
            throw Refusal::invalid('Give a multiple from 0 (no cap) to 100.', ['cap_multiple']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $before = $this->claims->settings($property);

        $this->transactions->run(function () use ($property, $actorId, $multiple, $lock, $reason, $before): void {
            if (($before === null) !== ($lock === null) || ! $this->claims->saveSettings($property, $multiple, $lock, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This setting changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'laundry_claim.cap_set', 'laundry_claim_settings', $property->toString(), ['cap_multiple' => $before['cap_multiple'] ?? self::DEFAULT_CAP_MULTIPLE], ['cap_multiple' => $multiple], trim($reason)));
        });

        return $this->claims->settings($property) ?? throw Refusal::notFound('Setting not found.');
    }

    // ---- internals ----

    /** @return array<string, mixed> */
    private function decide(PropertyId $property, string $actorId, string $claimId, string $status, ?int $approvedMinor, ?string $note, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::APPROVE_PERMISSION)) {
            throw Refusal::forbidden('Only a Manager on Duty may decide a laundry claim.');
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($note !== null && mb_strlen($note) > 300) || ($status === 'rejected' && $note === null)) {
            throw Refusal::invalid($status === 'rejected' ? 'Say why the claim is rejected, in at most 300 characters.' : 'A note is at most 300 characters.', ['note']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $claimId, $status, $approvedMinor, $note, $lock): void {
            $claim = $this->claims->find($property, strtolower($claimId)) ?? throw Refusal::notFound('Claim not found.');

            if ($claim['status'] !== 'open') {
                throw Refusal::stateConflict('This claim was already '.$claim['status'].'.');
            }

            if ($claim['recorded_by'] === $actor) {
                throw Refusal::forbidden('A claim is decided by someone other than the person who recorded it.');
            }

            if ($status === 'approved') {
                if ($approvedMinor === null || $approvedMinor < 1 || $approvedMinor > $claim['claimed_minor']) {
                    throw Refusal::invalid('Approve an amount of more than zero and at most what was claimed.', ['approved_minor']);
                }

                $cap = $this->capOf($property, $claim);

                if ($cap !== null && $approvedMinor > $cap) {
                    throw Refusal::invalid(sprintf('The compensation is at most %d times the laundry price of the pieces (%d).', $this->claims->settings($property)['cap_multiple'] ?? self::DEFAULT_CAP_MULTIPLE, $cap), ['approved_minor']);
                }
            }

            $now = $this->clock->nowUtc();

            if (! $this->claims->decide($property, $claim['id'], $lock, $status, $status === 'approved' ? $approvedMinor : null, $note, $actor, $now)) {
                throw Refusal::stateConflict('This claim changed after you opened it.');
            }

            if ($claim['photo_file_id'] !== null) {
                $anchor = new DateTimeImmutable($this->businessDate->current($property)->toString().' 00:00:00', new DateTimeZone('UTC'));
                $this->files->setExpiryOnce($property, $claim['photo_file_id'], $this->retention->expiryFor($property, self::RETENTION_CATEGORY, $anchor));
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'laundry_claim.'.$status, 'laundry_claim', $claim['id'], ['status' => 'open'], ['status' => $status, 'number' => $claim['number'], 'approved_minor' => $status === 'approved' ? $approvedMinor : null, 'claimed_minor' => $claim['claimed_minor']], $note));
            $this->outbox->publish(new OutboxEvent($property, 'laundry.claim.'.$status, $claim['id'], 1, ['claim_id' => $claim['id'], 'number' => $claim['number'], 'order_id' => $claim['order_id'], 'approved_minor' => $status === 'approved' ? $approvedMinor : null, 'currency' => $claim['currency'], 'actor_id' => $actor]));
        });

        return $this->claims->find($property, strtolower($claimId)) ?? throw Refusal::notFound('Claim not found.');
    }

    /** @param array<string, mixed> $claim @return int|null the most that may be approved for a claim that names an item, or null when nothing caps it */
    private function capOf(PropertyId $property, array $claim): ?int
    {
        $multiple = $this->claims->settings($property)['cap_multiple'] ?? self::DEFAULT_CAP_MULTIPLE;

        if ($multiple === 0 || $claim['line_id'] === null) {
            return null;
        }

        $order = $this->orders->findOrder($property, $claim['order_id']);
        $line = $order === null ? null : $this->lineOf($order, $claim['line_id']);

        return $line === null ? null : $multiple * $line->pieceMinor() * $claim['pieces'];
    }

    private function lineOf(LaundryOrder $order, string $lineId): ?LaundryLine
    {
        foreach ($order->lines as $line) {
            if ($line->id === $lineId) {
                return $line;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> the orders a claim can be made on, newest first */
    private function orderChoices(PropertyId $property): array
    {
        $choices = [];
        $statuses = array_values(array_filter(LaundryStatus::cases(), static fn (LaundryStatus $s): bool => $s !== LaundryStatus::Cancelled));

        foreach ($this->orders->orders($property, $statuses, 100) as $order) {
            $choices[] = [
                'id' => $order->id, 'number' => $order->number, 'room' => $this->rooms->room($property, $order->roomId)?->number, 'status' => $order->status->value,
                'lines' => array_map(static fn (LaundryLine $l): array => ['id' => $l->id, 'item_name' => $l->itemName, 'quantity' => $l->quantity, 'piece_minor' => $l->pieceMinor()], $order->lines),
            ];
        }

        return $choices;
    }

    /** @return array{record: bool, approve: bool, settings: bool} */
    private function abilities(PropertyId $property, string $actorId): array
    {
        return [
            'record' => $this->may($property, $actorId, self::RECORD_PERMISSION), 'approve' => $this->may($property, $actorId, self::APPROVE_PERMISSION),
            'settings' => $this->may($property, $actorId, LaundryService::PRICES_PERMISSION),
        ];
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    private function authorizeAny(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        foreach ([self::VIEW_PERMISSION, self::RECORD_PERMISSION, self::APPROVE_PERMISSION] as $permission) {
            if ($this->may($property, $actorId, $permission)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see laundry claims.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
