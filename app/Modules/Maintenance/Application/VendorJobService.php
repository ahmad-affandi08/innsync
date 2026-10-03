<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\InventoryPurchasing\Application\SupplierDirectory;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
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
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Work done by an outside vendor (FR-MTC-010, -015). A manager with the vendor privilege opens a job on a work order, records the quotations of suppliers of purchasing, and chooses
 * one (with a reason when it is not the lowest). The agreed amount goes through the approval chain the owner configured for that amount (subject `maintenance.vendor-job`); with no
 * policy for the amount it is approved at once. The person who chose takes the decision, the job is scheduled, and when the vendor has done it the manager records what it really
 * cost, an invoice reference, what was done and a photo of the work; a cost above the agreed amount is flagged. A work order is not closed while a vendor job of it is still open.
 * The payment of the vendor is made through purchasing and finance, not here.
 */
final readonly class VendorJobService
{
    public const SUBJECT = 'maintenance.vendor-job';

    public const MAX_QUOTES = 5;

    public const MAX_AMOUNT_MINOR = 10_000_000_000_000;

    public const PHOTO_MAX_BYTES = 5_242_880;

    private const PHOTO_PURPOSE = 'maintenance_photo';

    private const OPEN = ['quoting', 'pending_approval', 'approved', 'scheduled'];

    private const WORK_OPEN = ['open', 'assigned', 'in_progress', 'on_hold'];

    public function __construct(
        private VendorJobStore $jobs,
        private WorkOrderStore $orders,
        private MaintenanceAccess $access,
        private SupplierDirectory $suppliers,
        private ApprovalGate $approvals,
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
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $act = $this->view($property, $actorId);
        $rows = $this->jobs->list($property, $status === '' ? null : $status, null, 200);
        $open = $act ? array_values(array_map(static fn (array $w): array => ['id' => $w['id'], 'number' => $w['number'], 'title' => $w['title']], $this->orders->list($property, self::WORK_OPEN, null, 300))) : [];

        return [
            'currency' => $this->currencies->currencyOf($property),
            'jobs' => array_map(fn (array $j): array => $this->head($j), $rows),
            'work_orders' => $open, 'suppliers' => $act ? $this->suppliers->active($property) : [],
            'may' => ['act' => $act],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $act = $this->view($property, $actorId);
        $job = $this->job($property, $id);
        $quotes = $this->jobs->quotes($property, $job['id']);
        $work = $this->orders->find($property, $job['work_order_id']);
        $approval = $job['approval_id'] === null ? null : $this->approvals->find($property, (string) $job['approval_id']);
        $names = $this->staff->namesOf($property, array_values(array_unique([$job['created_by'], ...array_column($quotes, 'added_by')])));
        $status = $job['status'];
        $lowest = $quotes === [] ? null : (int) $quotes[0]['amount_minor'];

        return [
            ...$this->head([...$job, 'work_order_number' => $work['number'] ?? '', 'work_order_title' => $work['title'] ?? '', 'work_order_status' => $work['status'] ?? '']),
            'currency' => $this->currencies->currencyOf($property), 'created_by' => $names[$job['created_by']] ?? null,
            'quotes' => array_map(fn (array $q): array => [
                'id' => $q['id'], 'supplier_id' => $q['supplier_id'], 'supplier' => $q['supplier_name'], 'amount_minor' => (int) $q['amount_minor'], 'valid_until' => $q['valid_until'] === null ? null : substr((string) $q['valid_until'], 0, 10),
                'note' => $q['note'], 'by' => $names[$q['added_by']] ?? null, 'at' => $this->utc($q['created_at']), 'chosen' => $q['id'] === $job['quote_id'], 'lowest' => (int) $q['amount_minor'] === $lowest,
            ], $quotes),
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed, 'steps' => $approval->steps, 'decisions' => $approval->decisions],
            'suppliers' => $act && $status === 'quoting' ? array_values(array_filter($this->suppliers->active($property), static fn (array $s): bool => ! in_array($s['id'], array_column($quotes, 'supplier_id'), true))) : [],
            'business_date' => $this->businessDate->current($property)->toString(),
            'may' => [
                'quote' => $act && $status === 'quoting' && count($quotes) < self::MAX_QUOTES, 'choose' => $act && $status === 'quoting' && $quotes !== [],
                'release' => $act && $status === 'pending_approval', 'schedule' => $act && in_array($status, ['approved', 'scheduled'], true),
                'complete' => $act && in_array($status, ['approved', 'scheduled'], true), 'cancel' => $act && in_array($status, self::OPEN, true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $workOrderId, string $scope): array
    {
        $this->require($property, $actorId);
        $work = $this->orders->find($property, strtolower($workOrderId)) ?? throw Refusal::invalid('Choose a work order.', ['work_order_id']);
        $scope = trim($scope);

        if (! in_array($work['status'], self::WORK_OPEN, true)) {
            throw Refusal::stateConflict('This work order is closed.');
        }

        if ($scope === '' || mb_strlen($scope) > 300) {
            throw Refusal::invalid('Say what the vendor is to do, in at most 300 characters.', ['scope']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $work, $scope): void {
            $this->orders->lock($property, $work['id']);
            $now = $this->clock->nowUtc();
            $number = $this->numbers->next($property, 'VJ');

            if (! $this->jobs->add($property, ['id' => $id, 'work_order_id' => $work['id'], 'number' => $number, 'scope' => $scope, 'status' => 'quoting', 'created_by' => $actor], $now)) {
                throw Refusal::stateConflict('A job with this number already exists. Try again.');
            }

            $this->event($property, $work['id'], "{$number}: opened", $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.created', 'vendor_job', $id, null, ['number' => $number, 'work_order' => $work['number'], 'scope' => $scope]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function addQuote(PropertyId $property, string $actorId, string $id, string $supplierId, int $amountMinor, ?string $validUntil, ?string $note): array
    {
        $this->require($property, $actorId);
        $supplier = $this->suppliers->find($property, $supplierId);

        if ($supplier === null || ! $supplier['is_active']) {
            throw Refusal::invalid('Choose a supplier that is in use.', ['supplier_id']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_AMOUNT_MINOR) {
            throw Refusal::invalid('Give the quoted amount above zero.', ['amount_minor']);
        }

        $today = $this->businessDate->current($property)->toString();

        if ($validUntil !== null && ($this->date($validUntil) === null || $validUntil < $today)) {
            throw Refusal::invalid('Give the date the quotation is valid until, from today.', ['valid_until']);
        }

        $note = $this->text($note, 200, 'note');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $supplier, $amountMinor, $validUntil, $note): void {
            $this->jobs->lock($property, strtolower($id));
            $job = $this->job($property, $id);

            if ($job['status'] !== 'quoting') {
                throw Refusal::stateConflict('Quotations are taken while the job is being quoted.');
            }

            $quotes = $this->jobs->quotes($property, $job['id']);

            if (count($quotes) >= self::MAX_QUOTES) {
                throw Refusal::stateConflict('A job takes at most '.self::MAX_QUOTES.' quotations.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->jobs->addQuote($property, ['id' => $this->ids->next(), 'job_id' => $job['id'], 'supplier_id' => $supplier['id'], 'supplier_name' => $supplier['name'], 'amount_minor' => $amountMinor, 'valid_until' => $validUntil, 'note' => $note, 'added_by' => $actor], $now)) {
                throw Refusal::stateConflict($supplier['name'].' has given a quotation for this job already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.quoted', 'vendor_job', $job['id'], null, ['number' => $job['number'], 'supplier' => $supplier['code'], 'amount_minor' => $amountMinor], $note));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function choose(PropertyId $property, string $actorId, string $id, string $quoteId, ?string $reason, int $lock): array
    {
        $this->require($property, $actorId);
        $reason = $this->text($reason, 200, 'reason');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $quoteId, $reason, $lock): void {
            $this->jobs->lock($property, strtolower($id));
            $job = $this->current($property, $id, $lock, ['quoting']);
            $quotes = $this->jobs->quotes($property, $job['id']);
            $chosen = null;

            foreach ($quotes as $q) {
                if ($q['id'] === strtolower($quoteId)) {
                    $chosen = $q;
                }
            }

            $chosen ?? throw Refusal::invalid('Choose one of the quotations of this job.', ['quote_id']);
            $amount = (int) $chosen['amount_minor'];

            if (count($quotes) > 1 && $amount > (int) $quotes[0]['amount_minor'] && $reason === null) {
                throw Refusal::invalid('Say why this is not the lowest quotation.', ['reason']);
            }

            $now = $this->clock->nowUtc();
            $required = $this->approvals->requirementFor($property, self::SUBJECT, $amount)->required;
            $approvalId = null;

            if ($required) {
                $view = $this->approvals->request(new ApprovalRequestInput(
                    $property, self::SUBJECT, $job['id'], $actor, $job['scope'], $this->payload($job, $chosen),
                    ['number' => $job['number'], 'supplier' => $chosen['supplier_name']], $amount, $this->currencies->currencyOf($property),
                ), IdempotencyKey::fromString('vj-choose-'.$job['id'].'-'.$lock));
                $approvalId = $view->id;
            }

            $status = $required ? 'pending_approval' : 'approved';

            if (! $this->jobs->update($property, $job['id'], $lock, ['status' => $status, 'quote_id' => $chosen['id'], 'supplier_id' => $chosen['supplier_id'], 'supplier_name' => $chosen['supplier_name'], 'agreed_minor' => $amount, 'choice_reason' => $reason, 'approval_id' => $approvalId], $now)) {
                throw Refusal::stateConflict('This job changed after you opened it. Reload it.');
            }

            $this->event($property, $job['work_order_id'], "{$job['number']}: {$chosen['supplier_name']} chosen", $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.chosen', 'vendor_job', $job['id'], ['status' => 'quoting'], ['status' => $status, 'number' => $job['number'], 'supplier' => $chosen['supplier_name'], 'agreed_minor' => $amount], $reason, $approvalId));
        });

        return $this->show($property, $actorId, $id);
    }

    /** Takes the decision of the approvers: approved, the job may go on; rejected, it is closed. While they have not decided, nothing changes. @return array<string, mixed> */
    public function release(PropertyId $property, string $actorId, string $id): array
    {
        $this->require($property, $actorId);
        $actor = strtolower($actorId);
        $job = $this->job($property, $id);

        if ($job['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This job is not waiting for approval.');
        }

        $view = $this->approvals->find($property, (string) $job['approval_id']) ?? throw Refusal::notFound('Approval not found.');

        if ($view->makerId !== $actor) {
            throw Refusal::forbidden('The person who chose the quotation takes the decision.');
        }

        if (in_array($view->status, ['rejected', 'cancelled', 'expired'], true)) {
            $this->transactions->run(function () use ($property, $actor, $job): void {
                $this->jobs->lock($property, $job['id']);
                $now = $this->clock->nowUtc();

                if (! $this->jobs->update($property, $job['id'], (int) $job['lock_version'], ['status' => 'rejected'], $now)) {
                    throw Refusal::stateConflict('This job changed meanwhile.');
                }

                $this->event($property, $job['work_order_id'], "{$job['number']}: not approved", $actor, $now);
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.rejected', 'vendor_job', $job['id'], ['status' => 'pending_approval'], ['status' => 'rejected', 'number' => $job['number']], null, $job['approval_id']));
            });
        } elseif ($view->isApproved() && ! $view->consumed) {
            $quote = null;

            foreach ($this->jobs->quotes($property, $job['id']) as $q) {
                if ($q['id'] === $job['quote_id']) {
                    $quote = $q;
                }
            }

            $this->transactions->run(function () use ($property, $actor, $job, $quote): void {
                $this->jobs->lock($property, $job['id']);
                $this->approvals->consume($property, (string) $job['approval_id'], self::SUBJECT, $job['id'], $this->payload($job, (array) $quote), $actor);
                $now = $this->clock->nowUtc();

                if (! $this->jobs->update($property, $job['id'], (int) $job['lock_version'], ['status' => 'approved'], $now)) {
                    throw Refusal::stateConflict('This job changed meanwhile.');
                }

                $this->event($property, $job['work_order_id'], "{$job['number']}: approved", $actor, $now);
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.approved', 'vendor_job', $job['id'], ['status' => 'pending_approval'], ['status' => 'approved', 'number' => $job['number']], null, $job['approval_id']));
            });
        }

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function schedule(PropertyId $property, string $actorId, string $id, string $on, ?string $note, int $lock): array
    {
        $this->require($property, $actorId);

        if ($this->date($on) === null || $on < $this->businessDate->current($property)->toString()) {
            throw Refusal::invalid('Give the date the vendor comes, from today.', ['scheduled_on']);
        }

        $note = $this->text($note, 200, 'note');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $on, $note, $lock): void {
            $this->jobs->lock($property, strtolower($id));
            $job = $this->current($property, $id, $lock, ['approved', 'scheduled']);
            $now = $this->clock->nowUtc();

            if (! $this->jobs->update($property, $job['id'], $lock, ['status' => 'scheduled', 'scheduled_on' => $on, 'schedule_note' => $note], $now)) {
                throw Refusal::stateConflict('This job changed after you opened it. Reload it.');
            }

            $this->event($property, $job['work_order_id'], "{$job['number']}: scheduled for {$on}", $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.scheduled', 'vendor_job', $job['id'], ['status' => $job['status'], 'scheduled_on' => $job['scheduled_on']], ['status' => 'scheduled', 'number' => $job['number'], 'scheduled_on' => $on], $note));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function complete(PropertyId $property, string $actorId, string $id, int $actualMinor, ?string $invoiceRef, string $note, ?string $photo, ?string $photoName, int $lock): array
    {
        $this->require($property, $actorId);
        $note = trim($note);

        if ($actualMinor < 0 || $actualMinor > self::MAX_AMOUNT_MINOR) {
            throw Refusal::invalid('Give what the work cost, from zero.', ['actual_minor']);
        }

        if ($note === '' || mb_strlen($note) > 300) {
            throw Refusal::invalid('Say what was done, in at most 300 characters.', ['note']);
        }

        if ($photo === null || $photo === '') {
            throw Refusal::invalid('Attach a photo of the finished work. A job is not closed without one.', ['photo']);
        }

        $invoiceRef = $this->text($invoiceRef, 40, 'invoice_ref');
        $job = $this->job($property, $id);
        $file = $this->storePhoto($property, strtolower($actorId), $job['id'], $photo, $photoName);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $actualMinor, $invoiceRef, $note, $file, $lock): void {
            $this->jobs->lock($property, strtolower($id));
            $job = $this->current($property, $id, $lock, ['approved', 'scheduled']);
            $now = $this->clock->nowUtc();
            $today = $this->businessDate->current($property)->toString();
            $over = $actualMinor > (int) $job['agreed_minor'];

            if (! $this->jobs->update($property, $job['id'], $lock, ['status' => 'done', 'actual_minor' => $actualMinor, 'invoice_ref' => $invoiceRef, 'done_note' => $note, 'over_quote' => $over, 'proof_file_id' => $file->id, 'done_on' => $today, 'done_at' => $now], $now)) {
                throw Refusal::stateConflict('This job changed after you opened it. Reload it.');
            }

            $this->files->setExpiryOnce($property, $file->id, $this->retention->expiryFor($property, self::PHOTO_PURPOSE, new DateTimeImmutable($today.' 00:00:00', new DateTimeZone('UTC'))));
            $this->event($property, $job['work_order_id'], "{$job['number']}: done".($over ? ' (above the quotation)' : ''), $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.done', 'vendor_job', $job['id'], ['status' => $job['status']], ['status' => 'done', 'number' => $job['number'], 'agreed_minor' => (int) $job['agreed_minor'], 'actual_minor' => $actualMinor, 'over_quote' => $over, 'invoice_ref' => $invoiceRef], $note));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->require($property, $actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give a reason of at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $reason, $lock): void {
            $this->jobs->lock($property, strtolower($id));
            $job = $this->current($property, $id, $lock, self::OPEN);
            $now = $this->clock->nowUtc();

            if (! $this->jobs->update($property, $job['id'], $lock, ['status' => 'cancelled', 'cancel_reason' => $reason], $now)) {
                throw Refusal::stateConflict('This job changed after you opened it. Reload it.');
            }

            $this->event($property, $job['work_order_id'], "{$job['number']}: cancelled", $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'vendor_job.cancelled', 'vendor_job', $job['id'], ['status' => $job['status']], ['status' => 'cancelled', 'number' => $job['number']], $reason, $job['approval_id']));
        });

        return $this->show($property, $actorId, $id);
    }

    public function proof(PropertyId $property, string $actorId, string $id): FileContent
    {
        $this->view($property, $actorId);
        $job = $this->job($property, $id);

        if ($job['proof_file_id'] === null) {
            throw Refusal::notFound('This job has no photo.');
        }

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->permissions->allowsInProperty($actorId, MaintenanceAccess::VENDOR, $this->property) || $this->permissions->allowsInProperty($actorId, MaintenanceAccess::MANAGE, $this->property);
            }
        };

        try {
            return $this->downloadFile->execute($property, (string) $job['proof_file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The photo is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see vendor jobs.');
        }
    }

    /** Who sees vendor jobs: managers of work orders and those who handle vendors; only the latter act. @return bool whether the person may act */
    private function view(PropertyId $property, string $actorId): bool
    {
        $this->access->assertProperty($property);
        $act = $this->access->may($property, $actorId, MaintenanceAccess::VENDOR);

        if (! $act && ! $this->access->may($property, $actorId, MaintenanceAccess::MANAGE)) {
            throw Refusal::forbidden('This person may not see vendor jobs.');
        }

        return $act;
    }

    private function require(PropertyId $property, string $actorId): void
    {
        $this->access->require($property, $actorId, MaintenanceAccess::VENDOR, 'This person may not handle vendor jobs.');
    }

    /** @return array<string, mixed> */
    private function job(PropertyId $property, string $id): array
    {
        return $this->jobs->find($property, strtolower($id)) ?? throw Refusal::notFound('Vendor job not found.');
    }

    /**
     * @param  list<string>  $from
     * @return array<string, mixed>
     */
    private function current(PropertyId $property, string $id, int $lock, array $from): array
    {
        $job = $this->job($property, $id);

        if (! in_array($job['status'], $from, true)) {
            throw Refusal::stateConflict(in_array($job['status'], self::OPEN, true) ? 'This job is not at the step you chose. Reload it.' : 'This job is closed.');
        }

        if ((int) $job['lock_version'] !== $lock) {
            throw Refusal::stateConflict('This job changed after you opened it. Reload it.');
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function payload(array $job, array $quote): array
    {
        return ['number' => $job['number'], 'work_order_id' => $job['work_order_id'], 'scope' => $job['scope'], 'quote_id' => $quote['id'], 'supplier_id' => $quote['supplier_id'], 'amount_minor' => (int) $quote['amount_minor']];
    }

    /**
     * @param  array<string, mixed>  $j
     * @return array<string, mixed>
     */
    private function head(array $j): array
    {
        return [
            'id' => $j['id'], 'number' => $j['number'], 'work_order_id' => $j['work_order_id'], 'work_order_number' => $j['work_order_number'], 'work_order_title' => $j['work_order_title'], 'scope' => $j['scope'], 'status' => $j['status'],
            'supplier' => $j['supplier_name'], 'agreed_minor' => $j['agreed_minor'] === null ? null : (int) $j['agreed_minor'], 'choice_reason' => $j['choice_reason'], 'scheduled_on' => $j['scheduled_on'] === null ? null : substr((string) $j['scheduled_on'], 0, 10),
            'schedule_note' => $j['schedule_note'], 'actual_minor' => $j['actual_minor'] === null ? null : (int) $j['actual_minor'], 'invoice_ref' => $j['invoice_ref'], 'done_note' => $j['done_note'], 'over_quote' => (bool) $j['over_quote'],
            'has_proof' => $j['proof_file_id'] !== null, 'done_on' => $j['done_on'] === null ? null : substr((string) $j['done_on'], 0, 10), 'cancel_reason' => $j['cancel_reason'], 'lock_version' => (int) $j['lock_version'], 'created_at' => $this->utc($j['created_at']),
        ];
    }

    private function event(PropertyId $property, string $workOrderId, string $note, string $actor, DateTimeImmutable $at): void
    {
        $this->orders->addEvent($property, ['id' => $this->ids->next(), 'work_order_id' => $workOrderId, 'kind' => 'vendor_job', 'note' => mb_substr($note, 0, 300), 'actor_id' => $actor], $at);
    }

    private function storePhoto(PropertyId $property, string $actor, string $ownerId, string $photo, ?string $name): StoredFile
    {
        try {
            return $this->storeFile->execute(new FileUpload($property, $actor, self::PHOTO_PURPOSE, 'vendor-job', $ownerId, $photo, new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Standard, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['photo']);
        }
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $value ? $d : null;
    }

    private function text(?string $text, int $max, string $field): ?string
    {
        $text = $text === null ? null : trim($text);
        $text = $text === '' ? null : $text;

        if ($text !== null && mb_strlen($text) > $max) {
            throw Refusal::invalid("This is at most {$max} characters.", [$field]);
        }

        return $text;
    }

    private function utc(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
