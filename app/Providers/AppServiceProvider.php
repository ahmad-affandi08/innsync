<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Finance\Application\BudgetStore;
use App\Modules\Finance\Application\CorrectionStore;
use App\Modules\Finance\Application\ExceptionStore;
use App\Modules\Finance\Application\FinanceAuditQueries;
use App\Modules\Finance\Application\FinanceExportQueries;
use App\Modules\Finance\Application\ManagementReportQueries;
use App\Modules\Finance\Application\PayableStore;
use App\Modules\Finance\Application\PayrollDisbursements;
use App\Modules\Finance\Application\PayrollDisbursementService;
use App\Modules\Finance\Application\PayrollDisbursementStore;
use App\Modules\Finance\Application\PettyCashStore;
use App\Modules\Finance\Application\ReceivableStore;
use App\Modules\Finance\Application\RecurringExpenseStore;
use App\Modules\Finance\Application\RevenueStore;
use App\Modules\Finance\Application\ServiceChargeCollected;
use App\Modules\Finance\Application\SettlementStore;
use App\Modules\Finance\Application\StockReportQueries;
use App\Modules\Finance\Application\TaxQueries;
use App\Modules\Finance\Application\TaxStore;
use App\Modules\Finance\Infrastructure\DatabaseBudgetStore;
use App\Modules\Finance\Infrastructure\DatabaseCorrectionStore;
use App\Modules\Finance\Infrastructure\DatabaseExceptionStore;
use App\Modules\Finance\Infrastructure\DatabaseFinanceAuditQueries;
use App\Modules\Finance\Infrastructure\DatabaseFinanceExportQueries;
use App\Modules\Finance\Infrastructure\DatabaseManagementReportQueries;
use App\Modules\Finance\Infrastructure\DatabasePayableStore;
use App\Modules\Finance\Infrastructure\DatabasePayrollDisbursementStore;
use App\Modules\Finance\Infrastructure\DatabasePettyCashStore;
use App\Modules\Finance\Infrastructure\DatabaseReceivableStore;
use App\Modules\Finance\Infrastructure\DatabaseRecurringExpenseStore;
use App\Modules\Finance\Infrastructure\DatabaseRevenueStore;
use App\Modules\Finance\Infrastructure\DatabaseServiceChargeCollected;
use App\Modules\Finance\Infrastructure\DatabaseSettlementStore;
use App\Modules\Finance\Infrastructure\DatabaseStockReportQueries;
use App\Modules\Finance\Infrastructure\DatabaseTaxQueries;
use App\Modules\Finance\Infrastructure\DatabaseTaxStore;
use App\Modules\FnbSales\Application\BillStore;
use App\Modules\FnbSales\Application\GuestOrdering;
use App\Modules\FnbSales\Application\GuestOrderingService;
use App\Modules\FnbSales\Application\MenuAvailability;
use App\Modules\FnbSales\Application\MenuAvailabilityService;
use App\Modules\FnbSales\Application\MenuSales;
use App\Modules\FnbSales\Application\MinibarStore;
use App\Modules\FnbSales\Application\PaymentStore;
use App\Modules\FnbSales\Application\PriceRuleStore;
use App\Modules\FnbSales\Application\SetupStore;
use App\Modules\FnbSales\Infrastructure\DatabaseBillStore;
use App\Modules\FnbSales\Infrastructure\DatabaseMenuSales;
use App\Modules\FnbSales\Infrastructure\DatabaseMinibarStore;
use App\Modules\FnbSales\Infrastructure\DatabasePaymentStore;
use App\Modules\FnbSales\Infrastructure\DatabasePriceRuleStore;
use App\Modules\FnbSales\Infrastructure\DatabaseSetupStore;
use App\Modules\FrontOffice\Application\Cashier\CashierRepository;
use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Cashier\ShiftAttribution;
use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\FrontOffice\Application\Charging\GuestChargingService;
use App\Modules\FrontOffice\Application\Companies\CompanyRepository;
use App\Modules\FrontOffice\Application\Companies\CompanyRouting;
use App\Modules\FrontOffice\Application\Feedback\ComplaintWorkload;
use App\Modules\FrontOffice\Application\Feedback\FeedbackRepository;
use App\Modules\FrontOffice\Application\Folios\FolioPenaltyPoster;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\ForeignPayments\ForeignPaymentRepository;
use App\Modules\FrontOffice\Application\Groups\GroupRepository;
use App\Modules\FrontOffice\Application\GuestDesk\GuestStayDesk;
use App\Modules\FrontOffice\Application\GuestDesk\GuestStayDeskService;
use App\Modules\FrontOffice\Application\GuestDesk\SelfCheckInDesk;
use App\Modules\FrontOffice\Application\GuestDesk\SelfCheckInDeskService;
use App\Modules\FrontOffice\Application\Inventory\InventoryHoldRepository;
use App\Modules\FrontOffice\Application\Inventory\InventoryRepository;
use App\Modules\FrontOffice\Application\Inventory\RoomBlocking;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockingService;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditRepository;
use App\Modules\FrontOffice\Application\Requests\GuestRequestRepository;
use App\Modules\FrontOffice\Application\Requests\GuestRequestsForHousekeeping;
use App\Modules\FrontOffice\Application\Reservations\DepositLedger;
use App\Modules\FrontOffice\Application\Reservations\PenaltyPoster;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Routine\RoutineRepository;
use App\Modules\FrontOffice\Application\Routing\ChargeRoutingChain;
use App\Modules\FrontOffice\Application\Stays\GuestRepository;
use App\Modules\FrontOffice\Application\Stays\LaundryExceptionStore;
use App\Modules\FrontOffice\Application\Stays\RegistrationCardRepository;
use App\Modules\FrontOffice\Application\Stays\StayOccupancyReader;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\FrontOffice\Application\Stays\StayTimeFeeRepository;
use App\Modules\FrontOffice\Infrastructure\Cashier\DatabaseCashierRepository;
use App\Modules\FrontOffice\Infrastructure\Companies\DatabaseCompanyRepository;
use App\Modules\FrontOffice\Infrastructure\DatabaseLaundryExceptionStore;
use App\Modules\FrontOffice\Infrastructure\Feedback\DatabaseComplaintWorkload;
use App\Modules\FrontOffice\Infrastructure\Feedback\DatabaseFeedbackRepository;
use App\Modules\FrontOffice\Infrastructure\Folios\DatabaseFolioRepository;
use App\Modules\FrontOffice\Infrastructure\ForeignPayments\DatabaseForeignPaymentRepository;
use App\Modules\FrontOffice\Infrastructure\Groups\DatabaseGroupRepository;
use App\Modules\FrontOffice\Infrastructure\Inventory\DatabaseInventoryHoldRepository;
use App\Modules\FrontOffice\Infrastructure\Inventory\DatabaseInventoryRepository;
use App\Modules\FrontOffice\Infrastructure\Inventory\DatabaseRoomBlockRepository;
use App\Modules\FrontOffice\Infrastructure\NightAudit\DatabaseNightAuditRepository;
use App\Modules\FrontOffice\Infrastructure\Requests\DatabaseGuestRequestRepository;
use App\Modules\FrontOffice\Infrastructure\Reservations\DatabaseReservationRepository;
use App\Modules\FrontOffice\Infrastructure\Routine\DatabaseRoutineRepository;
use App\Modules\FrontOffice\Infrastructure\Stays\DatabaseGuestRepository;
use App\Modules\FrontOffice\Infrastructure\Stays\DatabaseRegistrationCardRepository;
use App\Modules\FrontOffice\Infrastructure\Stays\DatabaseStayRepository;
use App\Modules\FrontOffice\Infrastructure\Stays\DatabaseStayTimeFeeRepository;
use App\Modules\GuestExperience\Application\GuestHelpStore;
use App\Modules\GuestExperience\Application\GuestOrderStore;
use App\Modules\GuestExperience\Application\GuestRoomCharges;
use App\Modules\GuestExperience\Application\GuestRoomChargeVerdict;
use App\Modules\GuestExperience\Application\GuestSessionStore;
use App\Modules\GuestExperience\Application\GuestTokens;
use App\Modules\GuestExperience\Application\QrPointStore;
use App\Modules\GuestExperience\Application\SelfCheckInStore;
use App\Modules\GuestExperience\Infrastructure\DatabaseGuestHelpStore;
use App\Modules\GuestExperience\Infrastructure\DatabaseGuestOrderStore;
use App\Modules\GuestExperience\Infrastructure\DatabaseGuestSessionStore;
use App\Modules\GuestExperience\Infrastructure\DatabaseQrPointStore;
use App\Modules\GuestExperience\Infrastructure\DatabaseSelfCheckInStore;
use App\Modules\GuestExperience\Infrastructure\RandomGuestTokens;
use App\Modules\Housekeeping\Application\ChecklistRepository;
use App\Modules\Housekeeping\Application\GuestServiceRequests;
use App\Modules\Housekeeping\Application\HousekeepingRepository;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\LinenRepository;
use App\Modules\Housekeeping\Application\LostFoundRepository;
use App\Modules\Housekeeping\Application\OccupancyReader;
use App\Modules\Housekeeping\Application\ParLevelRepository;
use App\Modules\Housekeeping\Application\RoomGuestRequests;
use App\Modules\Housekeeping\Application\RoomHandover;
use App\Modules\Housekeeping\Application\RoomReadiness;
use App\Modules\Housekeeping\Infrastructure\DatabaseChecklistRepository;
use App\Modules\Housekeeping\Infrastructure\DatabaseHousekeepingRepository;
use App\Modules\Housekeeping\Infrastructure\DatabaseLinenRepository;
use App\Modules\Housekeeping\Infrastructure\DatabaseLostFoundRepository;
use App\Modules\Housekeeping\Infrastructure\DatabaseParLevelRepository;
use App\Modules\HumanResource\Application\AnnouncementStore;
use App\Modules\HumanResource\Application\AppraisalStore;
use App\Modules\HumanResource\Application\AttendanceCorrectionStore;
use App\Modules\HumanResource\Application\AttendanceStore;
use App\Modules\HumanResource\Application\ConductStore;
use App\Modules\HumanResource\Application\EmployeeStore;
use App\Modules\HumanResource\Application\LeaveStore;
use App\Modules\HumanResource\Application\OvertimeStore;
use App\Modules\HumanResource\Application\PayrollRunStore;
use App\Modules\HumanResource\Application\PayrollStore;
use App\Modules\HumanResource\Application\PerformanceStore;
use App\Modules\HumanResource\Application\RosterStore;
use App\Modules\HumanResource\Application\ServiceChargeStore;
use App\Modules\HumanResource\Application\ShiftSwapStore;
use App\Modules\HumanResource\Application\StaffOnDuty;
use App\Modules\HumanResource\Application\StaffOnDutyService;
use App\Modules\HumanResource\Infrastructure\DatabaseAnnouncementStore;
use App\Modules\HumanResource\Infrastructure\DatabaseAppraisalStore;
use App\Modules\HumanResource\Infrastructure\DatabaseAttendanceCorrectionStore;
use App\Modules\HumanResource\Infrastructure\DatabaseAttendanceStore;
use App\Modules\HumanResource\Infrastructure\DatabaseConductStore;
use App\Modules\HumanResource\Infrastructure\DatabaseEmployeeStore;
use App\Modules\HumanResource\Infrastructure\DatabaseLeaveStore;
use App\Modules\HumanResource\Infrastructure\DatabaseOvertimeStore;
use App\Modules\HumanResource\Infrastructure\DatabasePayrollRunStore;
use App\Modules\HumanResource\Infrastructure\DatabasePayrollStore;
use App\Modules\HumanResource\Infrastructure\DatabasePerformanceStore;
use App\Modules\HumanResource\Infrastructure\DatabaseRosterStore;
use App\Modules\HumanResource\Infrastructure\DatabaseServiceChargeStore;
use App\Modules\HumanResource\Infrastructure\DatabaseShiftSwapStore;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyRepository;
use App\Modules\IdentityAccess\Application\Approval\ApprovalRepository;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Application\Ports\CredentialAuthenticator;
use App\Modules\IdentityAccess\Application\Ports\MfaStore;
use App\Modules\IdentityAccess\Application\Ports\OneTimePassword;
use App\Modules\IdentityAccess\Application\Ports\PermissionGrantReader;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Modules\IdentityAccess\Application\Ports\UserPasswordUpdater;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use App\Modules\IdentityAccess\Infrastructure\Approval\ConfiguredApprovalSubjects;
use App\Modules\IdentityAccess\Infrastructure\Approval\DatabaseApprovalPolicyRepository;
use App\Modules\IdentityAccess\Infrastructure\Approval\DatabaseApprovalRepository;
use App\Modules\IdentityAccess\Infrastructure\Authentication\EloquentCredentialAuthenticator;
use App\Modules\IdentityAccess\Infrastructure\Authentication\EloquentUserPasswordUpdater;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseStaffAccess;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseStaffContacts;
use App\Modules\IdentityAccess\Application\Ports\AccessDirectory;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseProfileRoles;
use App\Modules\Property\Application\Profile\PropertyProfileRepository;
use App\Modules\Property\Infrastructure\Persistence\DatabasePropertyProfileRepository;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseModuleAccess;
use App\Shared\Application\Setup\ModuleAccess;
use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Application\Setup\ProfileRoles;
use App\Shared\Application\Setup\SetupFacts;
use App\Shared\Infrastructure\Setup\DatabaseModuleSettings;
use App\Shared\Infrastructure\Setup\DatabaseSetupFacts;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseAccessDirectory;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseStaffDirectory;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseSystemActors;
use App\Modules\IdentityAccess\Infrastructure\Authorization\EloquentPermissionGrantReader;
use App\Modules\IdentityAccess\Infrastructure\Authorization\EloquentUserAccessReader;
use App\Modules\IdentityAccess\Infrastructure\Authorization\ScopedPermissionChecker;
use App\Modules\IdentityAccess\Infrastructure\Mfa\EloquentMfaStore;
use App\Modules\IdentityAccess\Infrastructure\Mfa\TotpOneTimePassword;
use App\Modules\IdentityAccess\Infrastructure\Sessions\DatabaseUserSessionRepository;
use App\Modules\InventoryPurchasing\Application\DepartmentSupplyUse;
use App\Modules\InventoryPurchasing\Application\DepartmentSupplyUseService;
use App\Modules\InventoryPurchasing\Application\IngredientCatalog;
use App\Modules\InventoryPurchasing\Application\IngredientCatalogService;
use App\Modules\InventoryPurchasing\Application\InventoryStore;
use App\Modules\InventoryPurchasing\Application\PurchaseRequesting;
use App\Modules\InventoryPurchasing\Application\PurchaseRequestingService;
use App\Modules\InventoryPurchasing\Application\PurchasingStore;
use App\Modules\InventoryPurchasing\Application\RequisitionStore;
use App\Modules\InventoryPurchasing\Application\StockCountStore;
use App\Modules\InventoryPurchasing\Application\SupplierDirectory;
use App\Modules\InventoryPurchasing\Application\SupplierDirectoryService;
use App\Modules\InventoryPurchasing\Infrastructure\DatabaseInventoryStore;
use App\Modules\InventoryPurchasing\Infrastructure\DatabasePurchasingStore;
use App\Modules\InventoryPurchasing\Infrastructure\DatabaseRequisitionStore;
use App\Modules\InventoryPurchasing\Infrastructure\DatabaseStockCountStore;
use App\Modules\Kitchen\Application\ProductionStore;
use App\Modules\Kitchen\Application\RecipeStore;
use App\Modules\Kitchen\Application\TicketStore;
use App\Modules\Kitchen\Application\WasteStore;
use App\Modules\Kitchen\Infrastructure\DatabaseProductionStore;
use App\Modules\Kitchen\Infrastructure\DatabaseRecipeStore;
use App\Modules\Kitchen\Infrastructure\DatabaseTicketStore;
use App\Modules\Kitchen\Infrastructure\DatabaseWasteStore;
use App\Modules\Laundry\Application\ClaimRepository;
use App\Modules\Laundry\Application\LaundryEscalations;
use App\Modules\Laundry\Application\LaundryLiability;
use App\Modules\Laundry\Application\LaundryRepository;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Laundry\Infrastructure\DatabaseClaimRepository;
use App\Modules\Laundry\Infrastructure\DatabaseLaundryEscalations;
use App\Modules\Laundry\Infrastructure\DatabaseLaundryRepository;
use App\Modules\Maintenance\Application\AssetStore;
use App\Modules\Maintenance\Application\DamageReporting;
use App\Modules\Maintenance\Application\DamageReportService as MaintenanceDamageReportService;
use App\Modules\Maintenance\Application\DutyStore;
use App\Modules\Maintenance\Application\GuestMaintenanceRequests;
use App\Modules\Maintenance\Application\GuestMaintenanceRequestService;
use App\Modules\Maintenance\Application\PartsStore;
use App\Modules\Maintenance\Application\VendorJobStore;
use App\Modules\Maintenance\Application\WorkOrderStore;
use App\Modules\Maintenance\Infrastructure\DatabaseAssetStore;
use App\Modules\Maintenance\Infrastructure\DatabaseDutyStore;
use App\Modules\Maintenance\Infrastructure\DatabasePartsStore;
use App\Modules\Maintenance\Infrastructure\DatabaseVendorJobStore;
use App\Modules\Maintenance\Infrastructure\DatabaseWorkOrderStore;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Catalog\RoomCatalogRepository;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Migration\ImportBatchRepository;
use App\Modules\Property\Application\Policies\BookingPolicyReader;
use App\Modules\Property\Application\Policies\BookingPolicyRepository;
use App\Modules\Property\Application\Policies\BookingPolicyService;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Ports\StandardTimesReader;
use App\Modules\Property\Application\Rates\ChargeCalculator;
use App\Modules\Property\Application\Rates\ChargeSchemeRepository;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Modules\Property\Application\Rates\RatePlanRepository;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Rates\RateQuoter;
use App\Modules\Property\Application\Rates\RateQuoteService;
use App\Modules\Property\Application\Rates\RestrictionCalendar;
use App\Modules\Property\Application\Settings\BusinessDateAdvancer;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\GoLiveBusinessDateInitializer;
use App\Modules\Property\Application\Settings\PropertySettingsRepository;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Property\Infrastructure\Catalog\DatabaseRoomCatalogRepository;
use App\Modules\Property\Infrastructure\Migration\DatabaseImportBatchRepository;
use App\Modules\Property\Infrastructure\Policies\DatabaseBookingPolicyRepository;
use App\Modules\Property\Infrastructure\Profile\EloquentPropertyProfileReader;
use App\Modules\Property\Infrastructure\Rates\DatabaseChargeSchemeRepository;
use App\Modules\Property\Infrastructure\Rates\DatabasePropertyCurrencyReader;
use App\Modules\Property\Infrastructure\Rates\DatabaseRatePlanRepository;
use App\Modules\Property\Infrastructure\Settings\DatabasePropertySettingsRepository;
use App\Modules\Property\Infrastructure\Settings\EloquentStandardTimesReader;
use App\Modules\Property\Infrastructure\Time\EloquentPropertyTimeZoneReader;
use App\Modules\Reporting\Application\DashboardPreferenceRepository;
use App\Modules\Reporting\Application\DrillQueries;
use App\Modules\Reporting\Application\ExportJobRepository;
use App\Modules\Reporting\Application\ObligationRepository;
use App\Modules\Reporting\Application\OutletRepository;
use App\Modules\Reporting\Application\ReportBuilderQueries;
use App\Modules\Reporting\Application\ReportNotifier;
use App\Modules\Reporting\Application\ReportQueries;
use App\Modules\Reporting\Application\ReportScheduleRepository;
use App\Modules\Reporting\Infrastructure\DatabaseDashboardPreferenceRepository;
use App\Modules\Reporting\Infrastructure\DatabaseDrillQueries;
use App\Modules\Reporting\Infrastructure\DatabaseExportJobRepository;
use App\Modules\Reporting\Infrastructure\DatabaseObligationRepository;
use App\Modules\Reporting\Infrastructure\DatabaseOutletRepository;
use App\Modules\Reporting\Infrastructure\DatabaseReportBuilderQueries;
use App\Modules\Reporting\Infrastructure\DatabaseReportQueries;
use App\Modules\Reporting\Infrastructure\DatabaseReportScheduleRepository;
use App\Modules\Reporting\Infrastructure\MailReportNotifier;
use App\Modules\Routines\Application\RoutineStore;
use App\Modules\Routines\Infrastructure\DatabaseRoutineStore;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalSubjects;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Audit\AuditWriter;
use App\Shared\Application\Deployment\GoLiveBusinessDate;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Files\ContentInspector;
use App\Shared\Application\Files\PrivateFileStorage;
use App\Shared\Application\Files\StoredFileRepository;
use App\Shared\Application\Idempotency\IdempotencyContext;
use App\Shared\Application\Idempotency\IdempotencyStore;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Integration\CircuitStore;
use App\Shared\Application\Integration\InnSyncWebhookProtocol;
use App\Shared\Application\Integration\ProviderRegistry;
use App\Shared\Application\Integration\UnknownOutcomeRepository;
use App\Shared\Application\Integration\WebhookProtocol;
use App\Shared\Application\Integration\WebhookReceiptStore;
use App\Shared\Application\Integration\WebhookReceiver;
use App\Shared\Application\Localization\LocaleNegotiator;
use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Observability\Health\AlertNotifier;
use App\Shared\Application\Observability\Health\AlertStore;
use App\Shared\Application\Observability\Health\HealthCheckRegistry;
use App\Shared\Application\Offline\DeviceStatusRepository;
use App\Shared\Application\Offline\OfflineHandlerRegistry;
use App\Shared\Application\Offline\OfflineSyncProcessor;
use App\Shared\Application\Offline\SyncExceptionRepository;
use App\Shared\Application\Offline\UnexpectedFailureReporter;
use App\Shared\Application\Outbox\OutboxConsumerRegistry;
use App\Shared\Application\Outbox\OutboxMessageStore;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Outbox\OutboxQueue;
use App\Shared\Application\Outbox\ProcessedOutboxMessageStore;
use App\Shared\Application\Privacy\ConsentRepository;
use App\Shared\Application\Privacy\DataSubjectRequestRepository;
use App\Shared\Application\Privacy\DataSubjectRequests;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Application\Retention\ErasableFileRepository;
use App\Shared\Application\Retention\LegalHoldRepository;
use App\Shared\Application\Retention\RetentionCatalog;
use App\Shared\Application\Retention\RetentionOverrides;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\SecurityEventWriter;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Security\StaffAccess;
use App\Shared\Application\Security\StaffContacts;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Infrastructure\Audit\DatabaseAuditWriter;
use App\Shared\Infrastructure\Documents\DatabaseDocumentNumbers;
use App\Shared\Infrastructure\Files\DatabaseStoredFileRepository;
use App\Shared\Infrastructure\Files\EncryptedDiskFileStorage;
use App\Shared\Infrastructure\Files\FinfoContentInspector;
use App\Shared\Infrastructure\Idempotency\DatabaseIdempotencyStore;
use App\Shared\Infrastructure\Identifiers\UlidIdentifierGenerator;
use App\Shared\Infrastructure\Integration\ConfiguredProviderRegistry;
use App\Shared\Infrastructure\Integration\DatabaseCircuitStore;
use App\Shared\Infrastructure\Integration\DatabaseUnknownOutcomeRepository;
use App\Shared\Infrastructure\Integration\DatabaseWebhookReceiptStore;
use App\Shared\Infrastructure\Notifications\MailEmailNotifier;
use App\Shared\Infrastructure\Observability\Health\ConfiguredHealthCheckRegistry;
use App\Shared\Infrastructure\Observability\Health\DatabaseAlertStore;
use App\Shared\Infrastructure\Observability\Health\LogAlertNotifier;
use App\Shared\Infrastructure\Observability\LaravelCorrelationId;
use App\Shared\Infrastructure\Offline\ConfiguredHandlerRegistry;
use App\Shared\Infrastructure\Offline\DatabaseDeviceStatusRepository;
use App\Shared\Infrastructure\Offline\DatabaseSyncExceptionRepository;
use App\Shared\Infrastructure\Offline\ReportingFailureReporter;
use App\Shared\Infrastructure\Outbox\ConfiguredOutboxConsumerRegistry;
use App\Shared\Infrastructure\Outbox\DatabaseOutboxMessageStore;
use App\Shared\Infrastructure\Outbox\DatabaseOutboxPublisher;
use App\Shared\Infrastructure\Outbox\DatabaseOutboxQueue;
use App\Shared\Infrastructure\Outbox\DatabaseProcessedOutboxMessageStore;
use App\Shared\Infrastructure\Privacy\DatabaseConsentRepository;
use App\Shared\Infrastructure\Privacy\DatabaseDataSubjectRequestRepository;
use App\Shared\Infrastructure\Privacy\LaravelFieldCipher;
use App\Shared\Infrastructure\Retention\ConfiguredRetentionCatalog;
use App\Shared\Infrastructure\Retention\DatabaseErasableFileRepository;
use App\Shared\Infrastructure\Retention\DatabaseLegalHoldRepository;
use App\Shared\Infrastructure\Retention\DatabaseRetentionOverrides;
use App\Shared\Infrastructure\Security\DatabaseSecurityEventWriter;
use App\Shared\Infrastructure\Time\SystemClock;
use App\Shared\Infrastructure\Transactions\MySqlTransactionRunner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PropertyContext::class, static fn (): PropertyContext => new PropertyContext);
        $this->app->scoped(CorrelationId::class, LaravelCorrelationId::class);
        $this->app->scoped(IdempotencyContext::class, static fn (): IdempotencyContext => new IdempotencyContext);
        $this->app->singleton(LocaleNegotiator::class, static fn (): LocaleNegotiator => new LocaleNegotiator(
            array_values(array_map('strval', (array) config('localization.supported'))),
            (string) config('localization.default'),
        ));
        $this->app->bind(AuditWriter::class, DatabaseAuditWriter::class);
        $this->app->bind(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->bind(PrivateFileStorage::class, EncryptedDiskFileStorage::class);
        $this->app->bind(StoredFileRepository::class, DatabaseStoredFileRepository::class);
        $this->app->singleton(FieldCipher::class, static fn ($app): FieldCipher => new LaravelFieldCipher($app->make(Encrypter::class), (string) config('privacy.blind_index_key')));
        $this->app->bind(ContentInspector::class, FinfoContentInspector::class);
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(IdentifierGenerator::class, UlidIdentifierGenerator::class);
        $this->app->bind(HealthCheckRegistry::class, ConfiguredHealthCheckRegistry::class);
        $this->app->bind(AlertStore::class, DatabaseAlertStore::class);
        $this->app->bind(AlertNotifier::class, LogAlertNotifier::class);
        $this->app->bind(OutboxPublisher::class, DatabaseOutboxPublisher::class);
        $this->app->bind(OutboxMessageStore::class, DatabaseOutboxMessageStore::class);
        $this->app->bind(OutboxQueue::class, DatabaseOutboxQueue::class);
        $this->app->bind(ProcessedOutboxMessageStore::class, DatabaseProcessedOutboxMessageStore::class);
        $this->app->singleton(OutboxConsumerRegistry::class, function ($app): OutboxConsumerRegistry {
            $consumerClasses = config('outbox.consumers');
            $consumers = [];

            if (is_array($consumerClasses)) {
                foreach ($consumerClasses as $consumerClass) {
                    if (is_string($consumerClass)) {
                        $consumers[] = $app->make($consumerClass);
                    }
                }
            }

            return new ConfiguredOutboxConsumerRegistry($consumers);
        });
        $this->app->bind(SecurityEventWriter::class, DatabaseSecurityEventWriter::class);
        $this->app->bind(TransactionRunner::class, MySqlTransactionRunner::class);
        $this->app->bind(CredentialAuthenticator::class, EloquentCredentialAuthenticator::class);
        $this->app->bind(UserAccessReader::class, EloquentUserAccessReader::class);
        $this->app->bind(UserPasswordUpdater::class, EloquentUserPasswordUpdater::class);
        $this->app->bind(PermissionGrantReader::class, EloquentPermissionGrantReader::class);
        $this->app->bind(OneTimePassword::class, TotpOneTimePassword::class);
        $this->app->bind(MfaStore::class, EloquentMfaStore::class);
        $this->app->bind(UserSessionRepository::class, DatabaseUserSessionRepository::class);
        $this->app->bind(PropertyTimeZoneReader::class, EloquentPropertyTimeZoneReader::class);
        $this->app->bind(StandardTimesReader::class, EloquentStandardTimesReader::class);
        $this->app->bind(StayTimeFeeRepository::class, DatabaseStayTimeFeeRepository::class);
        $this->app->bind(PropertyProfileReader::class, EloquentPropertyProfileReader::class);
        $this->app->bind(CashierRepository::class, DatabaseCashierRepository::class);
        $this->app->bind(GuestRequestRepository::class, DatabaseGuestRequestRepository::class);
        $this->app->bind(FeedbackRepository::class, DatabaseFeedbackRepository::class);
        $this->app->bind(ComplaintWorkload::class, DatabaseComplaintWorkload::class);
        $this->app->bind(RoutineRepository::class, DatabaseRoutineRepository::class);
        $this->app->bind(ShiftAttribution::class, CashierService::class);
        $this->app->bind(StaffDirectory::class, DatabaseStaffDirectory::class);
        $this->app->bind(AccessDirectory::class, DatabaseAccessDirectory::class);
        $this->app->bind(SetupFacts::class, DatabaseSetupFacts::class);
        $this->app->bind(ProfileRoles::class, DatabaseProfileRoles::class);
        $this->app->bind(ModuleSettings::class, DatabaseModuleSettings::class);
        $this->app->singleton(ModuleAccess::class, DatabaseModuleAccess::class);
        $this->app->bind(PropertyProfileRepository::class, DatabasePropertyProfileRepository::class);
        $this->app->bind(StaffContacts::class, DatabaseStaffContacts::class);
        $this->app->bind(ReportScheduleRepository::class, DatabaseReportScheduleRepository::class);
        $this->app->bind(ReportNotifier::class, MailReportNotifier::class);
        $this->app->bind(StaffAccess::class, DatabaseStaffAccess::class);
        $this->app->bind(EmployeeStore::class, DatabaseEmployeeStore::class);
        $this->app->bind(RosterStore::class, DatabaseRosterStore::class);
        $this->app->bind(AttendanceStore::class, DatabaseAttendanceStore::class);
        $this->app->bind(OvertimeStore::class, DatabaseOvertimeStore::class);
        $this->app->bind(LeaveStore::class, DatabaseLeaveStore::class);
        $this->app->bind(PerformanceStore::class, DatabasePerformanceStore::class);
        $this->app->bind(PayrollStore::class, DatabasePayrollStore::class);
        $this->app->bind(PayrollRunStore::class, DatabasePayrollRunStore::class);
        $this->app->bind(ServiceChargeStore::class, DatabaseServiceChargeStore::class);
        $this->app->bind(ShiftSwapStore::class, DatabaseShiftSwapStore::class);
        $this->app->bind(ConductStore::class, DatabaseConductStore::class);
        $this->app->bind(AppraisalStore::class, DatabaseAppraisalStore::class);
        $this->app->bind(AnnouncementStore::class, DatabaseAnnouncementStore::class);
        $this->app->bind(ServiceChargeCollected::class, DatabaseServiceChargeCollected::class);
        $this->app->bind(TaxStore::class, DatabaseTaxStore::class);
        $this->app->bind(TaxQueries::class, DatabaseTaxQueries::class);
        $this->app->bind(PayrollDisbursementStore::class, DatabasePayrollDisbursementStore::class);
        $this->app->bind(PayrollDisbursements::class, PayrollDisbursementService::class);
        $this->app->bind(RequisitionStore::class, DatabaseRequisitionStore::class);
        $this->app->bind(RoutineStore::class, DatabaseRoutineStore::class);
        $this->app->bind(DepartmentSupplyUse::class, DepartmentSupplyUseService::class);
        $this->app->bind(AttendanceCorrectionStore::class, DatabaseAttendanceCorrectionStore::class);
        $this->app->bind(StaffOnDuty::class, StaffOnDutyService::class);
        $this->app->bind(PermissionChecker::class, ScopedPermissionChecker::class);
        $this->app->bind(ApprovalRepository::class, DatabaseApprovalRepository::class);
        $this->app->bind(ApprovalPolicyRepository::class, DatabaseApprovalPolicyRepository::class);
        $this->app->bind(ApprovalSubjects::class, ConfiguredApprovalSubjects::class);
        $this->app->bind(ApprovalGate::class, ApprovalService::class);
        $this->app->bind(RetentionCatalog::class, ConfiguredRetentionCatalog::class);
        $this->app->bind(RetentionOverrides::class, DatabaseRetentionOverrides::class);
        $this->app->bind(LegalHoldRepository::class, DatabaseLegalHoldRepository::class);
        $this->app->bind(ErasableFileRepository::class, DatabaseErasableFileRepository::class);
        $this->app->bind(ConsentRepository::class, DatabaseConsentRepository::class);
        $this->app->bind(DataSubjectRequestRepository::class, DatabaseDataSubjectRequestRepository::class);
        $this->app->bind(DataSubjectRequests::class, static fn ($app): DataSubjectRequests => new DataSubjectRequests(
            $app->make(DataSubjectRequestRepository::class),
            $app->make(PermissionChecker::class),
            $app->make(TransactionRunner::class),
            $app->make(AuditTrail::class),
            $app->make(IdentifierGenerator::class),
            $app->make(Clock::class),
            $app->make(PropertyContext::class),
            array_map('intval', (array) config('retention.request_due_hours')),
        ));
        $this->app->bind(RoomCatalogRepository::class, DatabaseRoomCatalogRepository::class);
        $this->app->bind(RoomCatalogReader::class, RoomCatalogService::class);
        $this->app->bind(LaundryExceptionStore::class, DatabaseLaundryExceptionStore::class);
        $this->app->bind(PropertySettingsRepository::class, DatabasePropertySettingsRepository::class);
        $this->app->bind(BusinessDateProvider::class, PropertySettingsService::class);
        $this->app->bind(BusinessDateAdvancer::class, PropertySettingsService::class);
        $this->app->bind(GoLiveBusinessDate::class, GoLiveBusinessDateInitializer::class);
        $this->app->bind(RatePlanRepository::class, DatabaseRatePlanRepository::class);
        $this->app->bind(ChargeSchemeRepository::class, DatabaseChargeSchemeRepository::class);
        $this->app->bind(PropertyCurrencyReader::class, DatabasePropertyCurrencyReader::class);
        $this->app->bind(RateQuoter::class, RateQuoteService::class);
        $this->app->bind(ChargeCalculator::class, ChargeSchemeService::class);
        $this->app->bind(RatePlanReader::class, RatePlanService::class);
        $this->app->bind(RestrictionCalendar::class, RateQuoteService::class);
        $this->app->bind(InventoryRepository::class, DatabaseInventoryRepository::class);
        $this->app->bind(RoomBlockRepository::class, DatabaseRoomBlockRepository::class);
        $this->app->bind(InventoryHoldRepository::class, DatabaseInventoryHoldRepository::class);
        $this->app->bind(ReservationRepository::class, DatabaseReservationRepository::class);
        $this->app->bind(FolioRepository::class, DatabaseFolioRepository::class);
        $this->app->bind(GuestRepository::class, DatabaseGuestRepository::class);
        $this->app->bind(HousekeepingRepository::class, DatabaseHousekeepingRepository::class);
        $this->app->bind(OccupancyReader::class, StayOccupancyReader::class);
        $this->app->bind(RoomReadiness::class, HousekeepingService::class);
        $this->app->bind(RoomHandover::class, HousekeepingService::class);
        $this->app->bind(GuestServiceRequests::class, HousekeepingService::class);
        $this->app->bind(LinenRepository::class, DatabaseLinenRepository::class);
        $this->app->bind(LostFoundRepository::class, DatabaseLostFoundRepository::class);
        $this->app->bind(ChecklistRepository::class, DatabaseChecklistRepository::class);
        $this->app->bind(RoomGuestRequests::class, GuestRequestsForHousekeeping::class);
        $this->app->bind(GuestCharging::class, GuestChargingService::class);
        $this->app->bind(PenaltyPoster::class, FolioPenaltyPoster::class);
        $this->app->bind(DepositLedger::class, FolioPenaltyPoster::class);
        $this->app->bind(LaundryRepository::class, DatabaseLaundryRepository::class);
        $this->app->bind(LaundryEscalations::class, DatabaseLaundryEscalations::class);
        $this->app->bind(ReportQueries::class, DatabaseReportQueries::class);
        $this->app->bind(DrillQueries::class, DatabaseDrillQueries::class);
        $this->app->bind(ObligationRepository::class, DatabaseObligationRepository::class);
        $this->app->bind(OutletRepository::class, DatabaseOutletRepository::class);
        $this->app->bind(ExportJobRepository::class, DatabaseExportJobRepository::class);
        $this->app->bind(ReportBuilderQueries::class, DatabaseReportBuilderQueries::class);
        $this->app->bind(DashboardPreferenceRepository::class, DatabaseDashboardPreferenceRepository::class);
        $this->app->bind(ImportBatchRepository::class, DatabaseImportBatchRepository::class);
        $this->app->bind(BookingPolicyRepository::class, DatabaseBookingPolicyRepository::class);
        $this->app->bind(BookingPolicyReader::class, BookingPolicyService::class);
        $this->app->bind(LaundryLiability::class, LaundryService::class);
        $this->app->bind(NightAuditRepository::class, DatabaseNightAuditRepository::class);
        $this->app->bind(StayRepository::class, DatabaseStayRepository::class);
        $this->app->bind(RegistrationCardRepository::class, DatabaseRegistrationCardRepository::class);
        $this->app->bind(CompanyRepository::class, DatabaseCompanyRepository::class);
        $this->app->bind(ForeignPaymentRepository::class, DatabaseForeignPaymentRepository::class);
        $this->app->bind(GroupRepository::class, DatabaseGroupRepository::class);
        $this->app->bind(ClaimRepository::class, DatabaseClaimRepository::class);
        $this->app->bind(InventoryStore::class, DatabaseInventoryStore::class);
        $this->app->bind(StockCountStore::class, DatabaseStockCountStore::class);
        $this->app->bind(PurchasingStore::class, DatabasePurchasingStore::class);
        $this->app->bind(PayableStore::class, DatabasePayableStore::class);
        $this->app->bind(RevenueStore::class, DatabaseRevenueStore::class);
        $this->app->bind(ReceivableStore::class, DatabaseReceivableStore::class);
        $this->app->bind(PettyCashStore::class, DatabasePettyCashStore::class);
        $this->app->bind(ManagementReportQueries::class, DatabaseManagementReportQueries::class);
        $this->app->bind(StockReportQueries::class, DatabaseStockReportQueries::class);
        $this->app->bind(SetupStore::class, DatabaseSetupStore::class);
        $this->app->bind(BillStore::class, DatabaseBillStore::class);
        $this->app->bind(MinibarStore::class, DatabaseMinibarStore::class);
        $this->app->bind(PriceRuleStore::class, DatabasePriceRuleStore::class);
        $this->app->bind(MenuAvailability::class, MenuAvailabilityService::class);
        $this->app->bind(MenuSales::class, DatabaseMenuSales::class);
        $this->app->bind(GuestOrdering::class, GuestOrderingService::class);
        $this->app->bind(GuestTokens::class, RandomGuestTokens::class);
        $this->app->bind(QrPointStore::class, DatabaseQrPointStore::class);
        $this->app->bind(GuestSessionStore::class, DatabaseGuestSessionStore::class);
        $this->app->bind(GuestOrderStore::class, DatabaseGuestOrderStore::class);
        $this->app->bind(GuestRoomCharges::class, GuestRoomChargeVerdict::class);
        $this->app->bind(GuestHelpStore::class, DatabaseGuestHelpStore::class);
        $this->app->bind(GuestStayDesk::class, GuestStayDeskService::class);
        $this->app->bind(SelfCheckInStore::class, DatabaseSelfCheckInStore::class);
        $this->app->bind(SelfCheckInDesk::class, SelfCheckInDeskService::class);
        $this->app->bind(SystemActors::class, DatabaseSystemActors::class);
        $this->app->bind(TicketStore::class, DatabaseTicketStore::class);
        $this->app->bind(RecipeStore::class, DatabaseRecipeStore::class);
        $this->app->bind(WasteStore::class, DatabaseWasteStore::class);
        $this->app->bind(ProductionStore::class, DatabaseProductionStore::class);
        $this->app->bind(WorkOrderStore::class, DatabaseWorkOrderStore::class);
        $this->app->bind(AssetStore::class, DatabaseAssetStore::class);
        $this->app->bind(RoomBlocking::class, RoomBlockingService::class);
        $this->app->bind(IngredientCatalog::class, IngredientCatalogService::class);
        $this->app->bind(PurchaseRequesting::class, PurchaseRequestingService::class);
        $this->app->bind(PartsStore::class, DatabasePartsStore::class);
        $this->app->bind(DutyStore::class, DatabaseDutyStore::class);
        $this->app->bind(GuestMaintenanceRequests::class, GuestMaintenanceRequestService::class);
        $this->app->bind(DamageReporting::class, MaintenanceDamageReportService::class);
        $this->app->bind(VendorJobStore::class, DatabaseVendorJobStore::class);
        $this->app->bind(SupplierDirectory::class, SupplierDirectoryService::class);
        $this->app->bind(PaymentStore::class, DatabasePaymentStore::class);
        $this->app->bind(FinanceExportQueries::class, DatabaseFinanceExportQueries::class);
        $this->app->bind(RecurringExpenseStore::class, DatabaseRecurringExpenseStore::class);
        $this->app->bind(BudgetStore::class, DatabaseBudgetStore::class);
        $this->app->bind(CorrectionStore::class, DatabaseCorrectionStore::class);
        $this->app->bind(ExceptionStore::class, DatabaseExceptionStore::class);
        $this->app->bind(SettlementStore::class, DatabaseSettlementStore::class);
        $this->app->bind(FinanceAuditQueries::class, DatabaseFinanceAuditQueries::class);
        $this->app->bind(ParLevelRepository::class, DatabaseParLevelRepository::class);
        $this->app->bind(CompanyRouting::class, ChargeRoutingChain::class);
        $this->app->bind(DocumentNumbers::class, DatabaseDocumentNumbers::class);
        $this->app->bind(ProviderRegistry::class, ConfiguredProviderRegistry::class);
        $this->app->bind(CircuitStore::class, DatabaseCircuitStore::class);
        $this->app->bind(UnknownOutcomeRepository::class, DatabaseUnknownOutcomeRepository::class);
        $this->app->bind(WebhookReceiptStore::class, DatabaseWebhookReceiptStore::class);
        $this->app->bind(WebhookProtocol::class, static fn (): WebhookProtocol => new InnSyncWebhookProtocol((int) config('integrations.webhooks.tolerance_seconds')));
        $this->app->bind(WebhookReceiver::class, static fn ($app): WebhookReceiver => new WebhookReceiver(
            $app->make(ProviderRegistry::class),
            $app->make(WebhookProtocol::class),
            static fn (string $class): WebhookProtocol => $app->make($class),
            $app->make(WebhookReceiptStore::class),
            $app->make(OutboxPublisher::class),
            $app->make(TransactionRunner::class),
            $app->make(SecurityLog::class),
            $app->make(IdentifierGenerator::class),
            $app->make(Clock::class),
            $app->make(PropertyContext::class),
            (int) config('integrations.webhooks.max_body_bytes'),
        ));
        $this->app->bind(SyncExceptionRepository::class, DatabaseSyncExceptionRepository::class);
        $this->app->bind(EmailNotifier::class, MailEmailNotifier::class);
        $this->app->bind(DeviceStatusRepository::class, DatabaseDeviceStatusRepository::class);
        $this->app->bind(UnexpectedFailureReporter::class, ReportingFailureReporter::class);
        $this->app->singleton(OfflineHandlerRegistry::class, static fn ($app): OfflineHandlerRegistry => new ConfiguredHandlerRegistry(
            $app,
            array_values((array) config('offline.handlers')),
        ));
        $this->app->bind(OfflineSyncProcessor::class, static fn ($app): OfflineSyncProcessor => new OfflineSyncProcessor(
            $app->make(OfflineHandlerRegistry::class),
            $app->make(IdempotentExecutor::class),
            $app->make(PermissionChecker::class),
            $app->make(SyncExceptionRepository::class),
            $app->make(DeviceStatusRepository::class),
            $app->make(PropertyContext::class),
            $app->make(SecurityLog::class),
            $app->make(UnexpectedFailureReporter::class),
            $app->make(Clock::class),
            (int) config('offline.max_payload_bytes'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(static fn (): Password => Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());

        RateLimiter::for('guest', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) $request->ip()));

        // The lobby code asks for a reservation number and a name: slower than the rest, so a name cannot be tried against many numbers.
        RateLimiter::for('guest-lookup', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->ip()));

        RateLimiter::for('guest-write', static fn (Request $request): Limit => Limit::perMinute(20)
            ->by(hash('sha256', (string) $request->cookie('ge_session').'|'.$request->ip())));

        RateLimiter::for('approvals', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) $request->user()?->getAuthIdentifier().'|'.$request->ip()));

        RateLimiter::for('sync', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->user()?->getAuthIdentifier().'|'.$request->ip()));

        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) $request->route('provider').'|'.$request->ip()));

        RateLimiter::for('bookings', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->user()?->getAuthIdentifier().'|'.$request->ip()));

        RateLimiter::for('health', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->ip()));

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('identity_access.login_rate_limit_per_minute'),
        )->by(hash(
            'sha256',
            strtolower((string) $request->input('email')).'|'.$request->ip(),
        )));

        RateLimiter::for('mfa', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash(
                'sha256',
                (string) $request->user()?->getAuthIdentifier().'|'.$request->ip(),
            )));

        RateLimiter::for('sensitive', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash(
                'sha256',
                (string) $request->user()?->getAuthIdentifier().'|'.$request->ip(),
            )));

        // Administration of people and roles: a manager sets up several accounts in a sitting, so the limit is higher than for a password change.
        RateLimiter::for('access-admin', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by(hash(
                'sha256',
                'access-admin|'.(string) $request->user()?->getAuthIdentifier().'|'.$request->ip(),
            )));
    }
}
