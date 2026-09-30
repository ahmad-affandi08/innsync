<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Support\PhpArchitectureInspector;

final class ArchitectureBoundariesTest extends TestCase
{
    private PhpArchitectureInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inspector = new PhpArchitectureInspector;
    }

    public function test_current_application_respects_namespace_and_dependency_boundaries(): void
    {
        self::assertSame([], $this->inspector->inspectApplication(dirname(__DIR__, 2).'/app'));
    }

    #[DataProvider('validSourceProvider')]
    public function test_valid_layer_dependencies_are_accepted(string $path, string $source): void
    {
        self::assertSame([], $this->inspector->inspectSource($path, $source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validSourceProvider(): iterable
    {
        yield 'domain to own and shared domain' => [
            'Modules/FrontOffice/Domain/Reservations/Reservation.php',
            <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App\Modules\FrontOffice\Domain\Reservations;
                use App\Modules\FrontOffice\Domain\ValueObjects\ReservationId;
                use App\Shared\Domain\Clock;
                final class Reservation {}
                PHP,
        ];

        yield 'application to own domain' => [
            'Modules/FrontOffice/Application/Commands/CreateReservation.php',
            <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App\Modules\FrontOffice\Application\Commands;
                use App\Modules\FrontOffice\Domain\Reservations\Reservation;
                use App\Shared\Application\Transaction;
                final class CreateReservation {}
                PHP,
        ];

        yield 'infrastructure to framework and own application' => [
            'Modules/FrontOffice/Infrastructure/Persistence/ReservationRecord.php',
            <<<'PHP'
                <?php
                namespace App\Modules\FrontOffice\Infrastructure\Persistence;
                use App\Modules\FrontOffice\Application\Ports\ReservationStore;
                use Illuminate\Database\Eloquent\Model;
                final class ReservationRecord extends Model {}
                PHP,
        ];

        yield 'presentation to own application and framework' => [
            'Modules/FrontOffice/Presentation/Http/Controllers/ReservationController.php',
            <<<'PHP'
                <?php
                namespace App\Modules\FrontOffice\Presentation\Http\Controllers;
                use App\Modules\FrontOffice\Application\Commands\CreateReservation;
                use Illuminate\Http\Request;
                final class ReservationController {}
                PHP,
        ];
    }

    #[DataProvider('invalidSourceProvider')]
    public function test_forbidden_namespaces_and_dependencies_are_rejected(
        string $path,
        string $source,
        string $expectedViolation,
    ): void {
        $violations = $this->inspector->inspectSource($path, $source);

        self::assertNotEmpty($violations);
        self::assertStringContainsString($expectedViolation, implode("\n", $violations));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidSourceProvider(): iterable
    {
        yield 'domain framework dependency' => [
            'Modules/FrontOffice/Domain/Reservations/Reservation.php',
            '<?php namespace App\Modules\FrontOffice\Domain\Reservations; use Illuminate\Support\Str; final class Reservation {}',
            'Domain cannot depend on framework namespace Illuminate\\Support\\Str.',
        ];

        yield 'domain infrastructure dependency' => [
            'Modules/FrontOffice/Domain/Reservations/Reservation.php',
            '<?php namespace App\Modules\FrontOffice\Domain\Reservations; use App\Modules\FrontOffice\Infrastructure\Persistence\ReservationRecord; final class Reservation {}',
            'Domain cannot depend on App\\Modules\\FrontOffice\\Infrastructure',
        ];

        yield 'application framework dependency' => [
            'Modules/FrontOffice/Application/Commands/CreateReservation.php',
            '<?php namespace App\Modules\FrontOffice\Application\Commands; use Illuminate\Support\Facades\DB; final class CreateReservation {}',
            'Application cannot depend on framework namespace Illuminate\\Support\\Facades\\DB.',
        ];

        yield 'presentation domain dependency' => [
            'Modules/FrontOffice/Presentation/Http/Controllers/ReservationController.php',
            '<?php namespace App\Modules\FrontOffice\Presentation\Http\Controllers; use App\Modules\FrontOffice\Domain\Reservations\Reservation; final class ReservationController {}',
            'Presentation cannot depend on App\\Modules\\FrontOffice\\Domain',
        ];

        yield 'cross-context infrastructure dependency' => [
            'Modules/Finance/Infrastructure/Posting/FolioPoster.php',
            '<?php namespace App\Modules\Finance\Infrastructure\Posting; use App\Modules\FrontOffice\Infrastructure\Persistence\FolioRecord; final class FolioPoster {}',
            'Infrastructure may use only another context\'s Application contracts',
        ];

        yield 'shared domain module dependency' => [
            'Shared/Domain/Money.php',
            '<?php namespace App\Shared\Domain; use App\Modules\Finance\Domain\Ledger\Account; final class Money {}',
            'Shared\\Domain cannot depend on module namespace',
        ];

        yield 'namespace does not match path' => [
            'Modules/Finance/Domain/Ledger/Account.php',
            '<?php namespace App\Modules\Finance\Application; final class Account {}',
            'expected namespace App\\Modules\\Finance\\Domain\\Ledger',
        ];

        yield 'unknown bounded context' => [
            'Modules/Generic/Domain/Thing.php',
            '<?php namespace App\Modules\Generic\Domain; final class Thing {}',
            'Generic is not an approved bounded context',
        ];

        yield 'generic root services directory' => [
            'Services/GenericManager.php',
            '<?php namespace App\Services; final class GenericManager {}',
            'application PHP must live under Http, Modules, Providers, or Shared',
        ];

        yield 'domain requires strict types' => [
            'Modules/Finance/Domain/Ledger/Account.php',
            '<?php namespace App\Modules\Finance\Domain\Ledger; final class Account {}',
            'Domain source must declare strict_types=1',
        ];

        yield 'grouped framework import is detected' => [
            'Modules/Finance/Domain/Ledger/Account.php',
            '<?php declare(strict_types=1); namespace App\Modules\Finance\Domain\Ledger; use Illuminate\Support\{Arr, Str}; final class Account {}',
            'Domain cannot depend on framework namespace Illuminate\\Support\\Arr',
        ];

        yield 'fully qualified framework reference is detected' => [
            'Modules/Finance/Domain/Ledger/Account.php',
            '<?php declare(strict_types=1); namespace App\Modules\Finance\Domain\Ledger; final class Account { public function values(): \\Illuminate\\Support\\Collection {} }',
            'Domain cannot depend on framework namespace Illuminate\\Support\\Collection',
        ];
    }
}
