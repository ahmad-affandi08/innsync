<?php

declare(strict_types=1);

namespace Tests\Feature\Property;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** The property's own logo (owner request 2026-10-08): kept in the database, checked by content, shown in the header and on printed pages. */
final class PropertyLogoTest extends TestCase
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

    private function png(int $w = 200, int $h = 80): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 15, 76, 129));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/property/branding/logo', ['logo' => $file], ['Accept' => 'application/json']);
    }

    public function test_a_manager_uploads_a_logo_that_every_signed_in_person_sees_and_the_header_points_to(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);

        $this->upload($this->png())->assertOk()->assertJsonPath('has_logo', true);
        $this->assertSame(1, DB::table('property_logos')->count());

        $this->get('/brand/'.self::A.'/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/property/branding')->assertOk()->assertInertia(fn ($page) => $page->where('has_logo', true));
        $this->get('/property/system')->assertInertia(fn ($page) => $page->where('brand.logoUrl', fn ($u) => str_starts_with((string) $u, '/brand/'.self::A.'/logo?v='))->where('brand.poweredBy', true));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'property.logo.replaced')->count());
    }

    public function test_a_person_without_the_permission_sees_the_logo_but_cannot_change_it(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $this->upload($this->png())->assertOk();
        $this->post('/logout');
        $this->signIn(self::A, ['housekeeping.view']);

        $this->get('/brand/'.self::A.'/logo')->assertOk();
        $this->upload($this->png())->assertForbidden();
        $this->delete('/property/branding/logo')->assertForbidden();
        $this->get('/property/branding')->assertForbidden();
    }

    public function test_the_logo_is_public_by_its_address_only_and_a_wrong_address_gives_nothing(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $this->upload($this->png())->assertOk();
        $this->post('/logout');

        $first = $this->get('/brand/'.self::A.'/logo')->assertOk();
        $this->get('/brand/'.self::A.'/logo', ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304);
        $this->get('/brand/01arz3ndektsv4rrffq69g5fax/logo')->assertNotFound();
        $this->get('/brand/not-an-id/logo')->assertNotFound();
    }

    public function test_the_sign_in_page_shows_the_logo_of_the_only_property_and_none_when_there_are_several(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $this->upload($this->png())->assertOk();
        $this->post('/logout');

        $this->get('/login')->assertInertia(fn ($page) => $page->where('brand.logoUrl', fn ($u) => str_contains((string) $u, self::A)));

        $this->createProperty('01arz3ndektsv4rrffq69g5fb0', 'B');
        $this->get('/login')->assertInertia(fn ($page) => $page->where('brand.logoUrl', null));
    }

    public function test_the_powered_by_line_can_be_switched_off_by_the_property_and_is_audited(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $this->upload($this->png())->assertOk();

        $this->putJson('/property/branding/powered-by', ['show' => false])->assertOk()->assertJsonPath('show_powered_by', false);
        $this->get('/property/system')->assertInertia(fn ($page) => $page->where('brand.poweredBy', false));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'property.powered_by.changed')->count());

        $this->putJson('/property/branding/powered-by', ['show' => true])->assertOk();
        $this->get('/property/system')->assertInertia(fn ($page) => $page->where('brand.poweredBy', true));

        $this->post('/logout');
        $this->signIn(self::A, ['housekeeping.view']);
        $this->putJson('/property/branding/powered-by', ['show' => false])->assertForbidden();
    }

    public function test_bad_pictures_are_refused(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);

        $this->upload($this->png(20, 20))->assertStatus(422);
        $this->upload(UploadedFile::fake()->createWithContent('logo.png', 'not a picture at all'))->assertStatus(422);
        $this->upload(UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'))->assertStatus(422);
        $this->assertSame(0, DB::table('property_logos')->count());
    }

    public function test_a_vector_is_kept_only_when_it_holds_shapes(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $safe = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40"><rect width="100" height="40" fill="#0f4c81"/></svg>';

        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><rect width="1" height="1"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10"><image xlink:href="https://evil.example/x.png"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><foreignObject><div/></foreignObject></svg>',
            '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&x;</text></svg>',
        ] as $bad) {
            $this->upload(UploadedFile::fake()->createWithContent('logo.svg', $bad))->assertStatus(422);
        }

        $this->assertSame(0, DB::table('property_logos')->count());
        $this->upload(UploadedFile::fake()->createWithContent('logo.svg', $safe))->assertOk();
        $this->get('/brand/'.self::A.'/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    public function test_removing_the_logo_returns_to_the_product_logo(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $this->upload($this->png())->assertOk();

        $this->delete('/property/branding/logo', [], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('has_logo', false);
        $this->get('/brand/'.self::A.'/logo')->assertNotFound();
        $this->get('/property/system')->assertInertia(fn ($page) => $page->where('brand.logoUrl', null));
    }
}
