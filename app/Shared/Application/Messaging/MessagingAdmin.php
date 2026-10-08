<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;

/**
 * Sets up how the installation sends email and WhatsApp, on screen instead of in a server file. The keys are stored encrypted and are never sent back to the
 * browser: a saved secret shows only as "saved", and leaving it empty on a later save keeps it. Every change is audited by what changed, never by the secret.
 */
final readonly class MessagingAdmin
{
    public function __construct(private MessagingSettings $settings, private MessageTransport $transport, private AuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $channels = [];

        foreach (MessagingCatalog::all() as $channel => $providers) {
            $config = $this->settings->get($channel);
            $channels[$channel] = [
                'provider' => $config?->provider,
                'enabled' => $config?->enabled ?? false,
                'values' => $config === null ? new \stdClass : $this->visible($channel, $config),
                'saved_secrets' => $config === null ? [] : $this->savedSecrets($channel, $config),
                'last_test' => $config?->lastTestAt === null ? null : ['at' => $config->lastTestAt, 'ok' => $config->lastTestOk, 'error' => $config->lastTestError],
                'providers' => array_map(static fn (array $p): array => ['official' => $p['official'], 'fields' => $p['fields']], $providers),
            ];
        }

        return ['channels' => $channels];
    }

    /** @param array<string, mixed> $input */
    public function save(string $actorId, string $channel, string $provider, array $input, bool $enabled): void
    {
        $spec = MessagingCatalog::provider($channel, $provider) ?? throw Refusal::invalid('Choose a provider from the list.', ['provider']);
        $previous = $this->settings->get($channel);
        $keep = $previous !== null && $previous->provider === $provider ? $previous->values : [];
        $values = [];

        foreach ($spec['fields'] as $field) {
            $name = $field['name'];
            $text = trim((string) ($input[$name] ?? ''));

            if ($text === '' && $field['secret']) {
                $text = $keep[$name] ?? '';
            }

            if ($text === '' && isset($field['default']) && $field['required']) {
                $text = $field['default'];
            }

            if ($text === '') {
                if ($field['required']) {
                    throw Refusal::invalid('Fill in this field.', [$name]);
                }

                continue;
            }

            $values[$name] = $this->clean($field, $text);
        }

        $this->settings->save($channel, $provider, $values, $enabled, $actorId);
        $this->audit->record(new AuditEntry(null, strtolower($actorId), 'messaging.channel.saved', 'messaging_channel', $channel, $previous === null ? null : ['provider' => $previous->provider, 'enabled' => $previous->enabled], ['provider' => $provider, 'enabled' => $enabled]));
    }

    public function turnOff(string $actorId, string $channel): void
    {
        $previous = $this->settings->get($channel);

        if ($previous === null || ! $previous->enabled) {
            return;
        }

        $this->settings->disable($channel, $actorId);
        $this->audit->record(new AuditEntry(null, strtolower($actorId), 'messaging.channel.disabled', 'messaging_channel', $channel, ['provider' => $previous->provider, 'enabled' => true], ['provider' => $previous->provider, 'enabled' => false]));
    }

    /** @return array{ok: bool, error: string|null} */
    public function test(string $channel, string $destination): array
    {
        $config = $this->settings->get($channel) ?? throw Refusal::invalid('Save the settings first.', ['provider']);
        $to = trim($destination);

        if ($channel === MessagingCatalog::EMAIL) {
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw Refusal::invalid('Give an email address to send the test to.', ['destination']);
            }
        } else {
            $to = PhoneNumber::international($to) ?? throw Refusal::invalid('Give a mobile number to send the test to, for example 0812 3456 7890.', ['destination']);
        }

        $error = $this->transport->deliver($config, $to, 'InnSYnc test', 'InnSYnc: this is a test message. If you read it, sending works.');
        $this->settings->recordTest($channel, $error === null, $error);

        return ['ok' => $error === null, 'error' => $error];
    }

    /** @param array<string, mixed> $field */
    private function clean(array $field, string $text): string
    {
        $name = (string) $field['name'];

        if (mb_strlen($text) > 300) {
            throw Refusal::invalid('This is too long.', [$name]);
        }

        return match ($field['kind']) {
            'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? $text : throw Refusal::invalid('Give a valid email address.', [$name]),
            'number' => preg_match('/^\d{1,5}$/', $text) === 1 && (int) $text >= 1 && (int) $text <= 65535 ? $text : throw Refusal::invalid('Give a number from 1 to 65535.', [$name]),
            'select' => in_array($text, $field['options'] ?? [], true) ? $text : throw Refusal::invalid('Choose one of the options.', [$name]),
            'host' => PublicUrl::acceptableHost($text) ? $text : throw Refusal::invalid('Give the name of a mail server on the public internet, without https:// or a port.', [$name]),
            'url' => PublicUrl::acceptable($text) ? $text : throw Refusal::invalid('Give a secure (https) address on the public internet.', [$name]),
            default => $text,
        };
    }

    /** @return array<string, string> the fields that are safe to show */
    private function visible(string $channel, ChannelConfig $config): array
    {
        $secret = array_column(array_filter(MessagingCatalog::provider($channel, $config->provider)['fields'] ?? [], static fn (array $f): bool => $f['secret']), 'name');

        return array_diff_key($config->values, array_flip($secret));
    }

    /** @return list<string> */
    private function savedSecrets(string $channel, ChannelConfig $config): array
    {
        $secret = array_column(array_filter(MessagingCatalog::provider($channel, $config->provider)['fields'] ?? [], static fn (array $f): bool => $f['secret']), 'name');

        return array_values(array_filter($secret, static fn (string $n): bool => ($config->values[$n] ?? '') !== ''));
    }
}
