<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
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
use App\Shared\Application\Files\StoredFileUnreadable;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Infrastructure\Files\StoredFileResponse;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

final class PrivateFileStorageTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    private const OWNER_ID = '01arz3ndektsv4rrffq69g5fax';

    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fat';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private UserRecord $user;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_files');
        $this->createProperty(self::PROPERTY_A, 'Property A');
        $this->createProperty(self::PROPERTY_B, 'Property B');
        $this->user = UserRecord::factory()->create();
        Context::add('correlation_id', self::CORRELATION_ID);
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    public function test_stored_blob_is_encrypted_randomly_named_and_metadata_is_recorded(): void
    {
        $png = base64_decode(self::PNG);
        $file = $this->store($png, displayName: '../../Passport Scan?.png');

        $files = Storage::disk('private_files')->allFiles();
        self::assertCount(1, $files);
        self::assertStringContainsString($file->storageKey, $files[0]);
        self::assertStringNotContainsString($file->id, $files[0]);
        self::assertNotSame($png, Storage::disk('private_files')->get($files[0]));
        self::assertStringNotContainsString("\x89PNG", Storage::disk('private_files')->get($files[0]));
        self::assertSame('image/png', $file->mimeType);
        self::assertSame('Passport Scan_.png', $file->displayName);
        self::assertSame(hash('sha256', $png), $file->checksumSha256);
        $this->assertDatabaseHas('stored_files', ['id' => $file->id, 'property_id' => self::PROPERTY_A]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'file.stored', 'aggregate_id' => $file->id]);
    }

    public function test_content_type_is_detected_from_bytes_not_from_the_claimed_name(): void
    {
        try {
            $this->store('<?php echo "pwn";', displayName: 'photo.png');
            self::fail('Executable content was accepted as an image.');
        } catch (FileRejected) {
            self::assertSame(0, DB::table('stored_files')->count());
            self::assertSame([], Storage::disk('private_files')->allFiles());
        }
    }

    public function test_empty_oversized_and_expired_uploads_are_rejected(): void
    {
        foreach ([
            fn () => $this->store(''),
            fn () => $this->store(base64_decode(self::PNG), maxBytes: 10),
            fn () => $this->store(base64_decode(self::PNG), expiresAt: new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Invalid upload was accepted.');
            } catch (FileRejected) {
                self::assertSame(0, DB::table('stored_files')->count());
            }
        }
    }

    public function test_sensitive_export_policy_requires_an_explicit_expiry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->upload(base64_decode(self::PNG), requiresExpiry: true);
    }

    public function test_blob_is_discarded_when_the_metadata_transaction_fails(): void
    {
        $this->store(base64_decode(self::PNG));

        $this->expectException(QueryException::class);

        try {
            // Foreign key to a non-existent actor makes the insert fail after the blob was written.
            app(StoreFile::class)->execute($this->upload(
                base64_decode(self::PNG),
                actorId: '01arz3ndektsv4rrffq69g5fb0',
            ));
        } finally {
            self::assertCount(1, Storage::disk('private_files')->allFiles());
        }
    }

    public function test_authorized_download_returns_exact_bytes_and_is_audited(): void
    {
        $png = base64_decode(self::PNG);
        $file = $this->store($png);

        $content = $this->download($file->id, $this->allow(true));

        self::assertSame($png, $content->contents);
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'file.downloaded',
            'aggregate_id' => $file->id,
            'actor_id' => $this->user->id,
        ]);

        $response = StoredFileResponse::attachment($content);
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_denied_download_is_a_security_event_and_returns_no_content(): void
    {
        $file = $this->store(base64_decode(self::PNG));

        try {
            $this->download($file->id, $this->allow(false));
            self::fail('Download was allowed against policy.');
        } catch (FileAccessDenied) {
            $this->assertDatabaseHas('security_events', ['event_type' => 'file.download-denied']);
            $this->assertDatabaseMissing('audit_entries', ['action' => 'file.downloaded']);
        }
    }

    public function test_expired_file_is_not_downloadable(): void
    {
        $file = $this->store(
            base64_decode(self::PNG),
            expiresAt: new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
        $this->travel(2)->hours();

        $this->expectException(StoredFileNotFound::class);
        $this->download($file->id, $this->allow(true));
    }

    public function test_other_property_cannot_discover_or_read_a_file(): void
    {
        $file = $this->store(base64_decode(self::PNG));

        app(PropertyContext::class)->activateFromString(self::PROPERTY_B);

        try {
            app(DownloadFile::class)->execute(
                PropertyId::fromString(self::PROPERTY_A),
                $file->id,
                $this->user->id,
                $this->allow(true),
            );
            self::fail('Cross-property read succeeded.');
        } catch (PropertyScopeViolation) {
            // Expected: mismatched context fails closed.
        }

        $this->expectException(StoredFileNotFound::class);
        app(DownloadFile::class)->execute(
            PropertyId::fromString(self::PROPERTY_B),
            $file->id,
            $this->user->id,
            $this->allow(true),
        );
    }

    public function test_tampered_or_missing_blob_fails_integrity_check(): void
    {
        $file = $this->store(base64_decode(self::PNG));
        $path = Storage::disk('private_files')->allFiles()[0];
        Storage::disk('private_files')->put($path, 'not-encrypted');

        try {
            $this->download($file->id, $this->allow(true));
            self::fail('Tampered blob was served.');
        } catch (StoredFileUnreadable) {
            $this->assertDatabaseMissing('audit_entries', ['action' => 'file.downloaded']);
        }

        Storage::disk('private_files')->delete($path);
        $this->expectException(StoredFileUnreadable::class);
        $this->download($file->id, $this->allow(true));
    }

    public function test_file_metadata_is_append_only(): void
    {
        $file = $this->store(base64_decode(self::PNG));

        foreach ([
            fn () => DB::table('stored_files')->where('id', $file->id)->update(['purpose' => 'changed']),
            fn () => DB::table('stored_files')->where('id', $file->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('File metadata was mutated.');
            } catch (QueryException) {
                self::assertSame(1, DB::table('stored_files')->count());
            }
        }
    }

    public function test_private_disk_is_not_web_reachable(): void
    {
        $config = config('filesystems.disks.private_files');

        self::assertSame('local', $config['driver']);
        self::assertFalse($config['serve']);
        self::assertArrayNotHasKey('url', $config);
        self::assertStringStartsNotWith(public_path(), $config['root']);
        self::assertNotContains($config['root'], array_values(config('filesystems.links')));
    }

    private function upload(
        string $contents,
        ?string $displayName = null,
        int $maxBytes = 1_000_000,
        ?DateTimeImmutable $expiresAt = null,
        bool $requiresExpiry = false,
        ?string $actorId = null,
    ): FileUpload {
        return new FileUpload(
            PropertyId::fromString(self::PROPERTY_A),
            $actorId ?? $this->user->id,
            'guest-identity-photo',
            'guest',
            self::OWNER_ID,
            $contents,
            new FilePolicy(['image/png', 'image/jpeg'], $maxBytes, FileSensitivity::Sensitive, $requiresExpiry),
            $displayName,
            $expiresAt,
        );
    }

    private function store(
        string $contents,
        ?string $displayName = null,
        int $maxBytes = 1_000_000,
        ?DateTimeImmutable $expiresAt = null,
    ): StoredFile {
        return app(StoreFile::class)->execute(
            $this->upload($contents, $displayName, $maxBytes, $expiresAt),
        );
    }

    private function download(string $fileId, FileAccessPolicy $policy): FileContent
    {
        return app(DownloadFile::class)->execute(
            PropertyId::fromString(self::PROPERTY_A),
            $fileId,
            $this->user->id,
            $policy,
        );
    }

    private function allow(bool $allowed): FileAccessPolicy
    {
        return new class($allowed) implements FileAccessPolicy
        {
            public function __construct(private bool $allowed) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->allowed;
            }
        };
    }

    private function createProperty(string $id, string $name): void
    {
        $property = new PropertyRecord([
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
        ]);
        $property->id = $id;
        $property->save();
    }
}
