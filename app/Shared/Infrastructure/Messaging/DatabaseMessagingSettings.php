<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\ChannelConfig;
use App\Shared\Application\Messaging\MessagingSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** The channel choices, with the provider's keys encrypted by the application key. A row that cannot be decrypted (the key changed) reads as not set up, never as an error. */
final class DatabaseMessagingSettings implements MessagingSettings
{
    public function get(string $channel): ?ChannelConfig
    {
        $row = DB::table('messaging_channels')->where('channel', $channel)->first();

        if ($row === null) {
            return null;
        }

        try {
            $values = json_decode(Crypt::decryptString((string) $row->settings), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        return new ChannelConfig(
            $channel,
            (string) $row->provider,
            array_map('strval', is_array($values) ? $values : []),
            (bool) $row->enabled,
            $row->last_test_at === null ? null : (string) $row->last_test_at,
            $row->last_test_ok === null ? null : (bool) $row->last_test_ok,
            $row->last_test_error === null ? null : (string) $row->last_test_error,
        );
    }

    public function save(string $channel, string $provider, array $values, bool $enabled, string $actorId): void
    {
        $now = now();
        DB::table('messaging_channels')->updateOrInsert(['channel' => $channel], [
            'provider' => $provider,
            'settings' => Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR)),
            'enabled' => $enabled,
            'last_test_at' => null,
            'last_test_ok' => null,
            'last_test_error' => null,
            'updated_by' => strtolower($actorId),
            'updated_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function disable(string $channel, string $actorId): void
    {
        DB::table('messaging_channels')->where('channel', $channel)->update(['enabled' => false, 'updated_by' => strtolower($actorId), 'updated_at' => now()]);
    }

    public function recordTest(string $channel, bool $ok, ?string $error): void
    {
        DB::table('messaging_channels')->where('channel', $channel)->update(['last_test_at' => now(), 'last_test_ok' => $ok, 'last_test_error' => $error === null ? null : mb_substr($error, 0, 200)]);
    }
}
