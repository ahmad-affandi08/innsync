<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Staff, suppliers and menu from a CSV file (owner request 2026-10-07): a check changes nothing, a bad row refuses the whole file, a good file goes through the same rules as typing. */
final class CsvImportsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity_access.login_rate_limit_per_minute' => 1000]);
        $this->createProperty(self::A, 'A');
    }

    private function upload(string $url, string $csv, bool $dryRun)
    {
        return $this->post($url, ['file' => UploadedFile::fake()->createWithContent('rows.csv', $csv), 'dry_run' => $dryRun ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_suppliers_are_checked_without_a_trace_then_imported(): void
    {
        $this->signIn(self::A, ['purchasing.supplier.manage']);
        $csv = "code,name,payment_terms_days,email\nS1,Toko Jaya,14,jaya@example.com\nS2,Kebun Segar,7,\n";

        $check = $this->upload('/inventory/suppliers/import', $csv, true)->assertOk()->assertJsonPath('status', 'validated')->assertJsonPath('count', 2);
        $this->assertSame(0, DB::table('suppliers')->count());

        $this->upload('/inventory/suppliers/import', $csv, false)->assertOk()->assertJsonPath('status', 'applied');
        $this->assertSame(2, DB::table('suppliers')->count());
    }

    public function test_one_bad_row_refuses_the_whole_file_and_every_bad_row_is_listed(): void
    {
        $this->signIn(self::A, ['purchasing.supplier.manage']);
        $csv = "code,name,payment_terms_days\nS1,Good,14\nS2,Bad terms,999\nS3,Good too,7\nS1,Same code,3\n";

        $answer = $this->upload('/inventory/suppliers/import', $csv, false)->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame([3, 5], array_column($answer->json('errors'), 'line'));
        $this->assertSame(0, DB::table('suppliers')->count());
    }

    public function test_a_person_who_may_not_manage_suppliers_is_refused(): void
    {
        $this->signIn(self::A, ['housekeeping.view']);

        $this->upload('/inventory/suppliers/import', "code,name,payment_terms_days\nS1,A,1\n", false)->assertForbidden();
        $this->assertSame(0, DB::table('suppliers')->count());
    }

    public function test_staff_are_imported_with_the_rules_of_the_staff_form(): void
    {
        $this->signIn(self::A, ['hr.employee.manage', 'property.settings.manage']);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        $bad = "full_name,department,position,joined_on,contract_type,contract_end_on\nRina,front_office,Receptionist,2026-01-05,permanent,\nBudi,nowhere,Cook,2026-01-05,permanent,\n";
        $this->upload('/hr/employees/import', $bad, false)->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('errors.0.line', 3);
        $this->assertSame(0, DB::table('hr_employees')->count());

        $good = "full_name,department,position,joined_on,contract_type,contract_end_on\nRina,front_office,Receptionist,2026-01-05,permanent,\nBudi,kitchen,Cook,2026-02-01,contract,2027-01-31\n";
        $this->upload('/hr/employees/import', $good, false)->assertOk()->assertJsonPath('status', 'applied');
        $this->assertSame(2, DB::table('hr_employees')->count());
    }

    public function test_the_menu_import_names_an_outlet_that_does_not_exist(): void
    {
        $this->signIn(self::A, ['fnb.setup.manage']);

        $answer = $this->upload('/fnb/menu/import', "outlet,category,code,name,price\nNOPE,MAIN,X1,Soup,25000\n", true)->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(2, $answer->json('errors.0.line'));
    }

    public function test_the_example_files_download(): void
    {
        $this->signIn(self::A, ['purchasing.supplier.manage', 'hr.employee.manage', 'fnb.setup.manage']);

        foreach (['/inventory/suppliers/import/template', '/hr/employees/import/template', '/fnb/menu/import/template'] as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        }
    }
}
