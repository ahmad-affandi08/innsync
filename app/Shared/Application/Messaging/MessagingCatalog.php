<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

/**
 * The providers an installation can send email and WhatsApp through, and the fields each one needs. The screen and the check of what was typed both read this
 * one list. A provider marked `official` is run by the messaging platform itself (Meta, Twilio); the others are gateways that are not WhatsApp's own service and can lose the number.
 */
final class MessagingCatalog
{
    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    /** @return array<string, array<string, array{official: bool, fields: list<array{name: string, kind: string, secret: bool, required: bool, options?: list<string>, default?: string}>}>> */
    public static function all(): array
    {
        $from = [
            ['name' => 'from_address', 'kind' => 'email', 'secret' => false, 'required' => true],
            ['name' => 'from_name', 'kind' => 'text', 'secret' => false, 'required' => false],
        ];

        return [
            self::EMAIL => [
                'smtp' => ['official' => true, 'fields' => [
                    ['name' => 'host', 'kind' => 'host', 'secret' => false, 'required' => true],
                    ['name' => 'port', 'kind' => 'number', 'secret' => false, 'required' => true, 'default' => '587'],
                    ['name' => 'security', 'kind' => 'select', 'secret' => false, 'required' => true, 'options' => ['tls', 'ssl', 'none'], 'default' => 'tls'],
                    ['name' => 'username', 'kind' => 'text', 'secret' => false, 'required' => false],
                    ['name' => 'password', 'kind' => 'text', 'secret' => true, 'required' => false],
                    ...$from,
                ]],
                'resend' => ['official' => true, 'fields' => [['name' => 'api_key', 'kind' => 'text', 'secret' => true, 'required' => true], ...$from]],
                'brevo' => ['official' => true, 'fields' => [['name' => 'api_key', 'kind' => 'text', 'secret' => true, 'required' => true], ...$from]],
                'mailgun' => ['official' => true, 'fields' => [
                    ['name' => 'api_key', 'kind' => 'text', 'secret' => true, 'required' => true],
                    ['name' => 'domain', 'kind' => 'text', 'secret' => false, 'required' => true],
                    ['name' => 'region', 'kind' => 'select', 'secret' => false, 'required' => true, 'options' => ['us', 'eu'], 'default' => 'us'],
                    ...$from,
                ]],
                'postmark' => ['official' => true, 'fields' => [['name' => 'server_token', 'kind' => 'text', 'secret' => true, 'required' => true], ...$from]],
                'sendgrid' => ['official' => true, 'fields' => [['name' => 'api_key', 'kind' => 'text', 'secret' => true, 'required' => true], ...$from]],
            ],
            self::WHATSAPP => [
                'meta_cloud' => ['official' => true, 'fields' => [
                    ['name' => 'phone_number_id', 'kind' => 'text', 'secret' => false, 'required' => true],
                    ['name' => 'access_token', 'kind' => 'text', 'secret' => true, 'required' => true],
                    ['name' => 'template_name', 'kind' => 'text', 'secret' => false, 'required' => false],
                    ['name' => 'template_language', 'kind' => 'text', 'secret' => false, 'required' => false, 'default' => 'id'],
                ]],
                'twilio' => ['official' => true, 'fields' => [
                    ['name' => 'account_sid', 'kind' => 'text', 'secret' => false, 'required' => true],
                    ['name' => 'auth_token', 'kind' => 'text', 'secret' => true, 'required' => true],
                    ['name' => 'from_number', 'kind' => 'text', 'secret' => false, 'required' => true],
                ]],
                'fonnte' => ['official' => false, 'fields' => [['name' => 'token', 'kind' => 'text', 'secret' => true, 'required' => true]]],
                'wablas' => ['official' => false, 'fields' => [
                    ['name' => 'server_url', 'kind' => 'url', 'secret' => false, 'required' => true],
                    ['name' => 'token', 'kind' => 'text', 'secret' => true, 'required' => true],
                ]],
                'webhook' => ['official' => false, 'fields' => [
                    ['name' => 'url', 'kind' => 'url', 'secret' => false, 'required' => true],
                    ['name' => 'token', 'kind' => 'text', 'secret' => true, 'required' => false],
                ]],
            ],
        ];
    }

    /** @return array{official: bool, fields: list<array<string, mixed>>}|null */
    public static function provider(string $channel, string $provider): ?array
    {
        return self::all()[$channel][$provider] ?? null;
    }
}
