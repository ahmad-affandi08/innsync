<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\ChannelConfig;
use App\Shared\Application\Messaging\MessageTransport;
use App\Shared\Application\Messaging\PhoneNumber;
use App\Shared\Application\Messaging\PublicUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one message through the provider the installation chose, using Laravel's own HTTP client and mailer (no extra package). It answers null when the
 * provider accepted the message and a short reason otherwise. The reason is the provider's HTTP status and never its answer body, so a key can never leak into a screen or a log.
 */
final class HttpMessageTransport implements MessageTransport
{
    public function deliver(ChannelConfig $config, string $to, string $subject, string $message): ?string
    {
        try {
            return $config->channel === 'email' ? $this->email($config, $to, $subject, $message) : $this->whatsapp($config, $to, $message);
        } catch (Throwable $e) {
            report($e);

            return 'unreachable';
        }
    }

    private function email(ChannelConfig $config, string $to, string $subject, string $body): ?string
    {
        $v = $config->values;
        $from = $v['from_address'] ?? '';
        $name = $v['from_name'] ?? (string) config('app.name');

        switch ($config->provider) {
            case 'smtp':
                config(['mail.mailers.configured' => [
                    'transport' => 'smtp',
                    'host' => $v['host'] ?? '',
                    'port' => (int) ($v['port'] ?? 587),
                    'scheme' => ($v['security'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                    'username' => $v['username'] ?? null,
                    'password' => $v['password'] ?? null,
                    'timeout' => 15,
                    'auto_tls' => ($v['security'] ?? 'tls') !== 'none',
                ]]);
                Mail::mailer('configured')->raw($body, static function ($m) use ($to, $subject, $from, $name): void {
                    $m->from($from, $name)->to($to)->subject($subject);
                });

                return null;
            case 'resend':
                $response = $this->http()->withToken($v['api_key'])->post('https://api.resend.com/emails', ['from' => "{$name} <{$from}>", 'to' => [$to], 'subject' => $subject, 'text' => $body]);
                break;
            case 'brevo':
                $response = $this->http()->withHeaders(['api-key' => $v['api_key']])->post('https://api.brevo.com/v3/smtp/email', ['sender' => ['email' => $from, 'name' => $name], 'to' => [['email' => $to]], 'subject' => $subject, 'textContent' => $body]);
                break;
            case 'mailgun':
                $host = ($v['region'] ?? 'us') === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
                $response = $this->http()->withBasicAuth('api', $v['api_key'])->asForm()->post("https://{$host}/v3/".rawurlencode($v['domain']).'/messages', ['from' => "{$name} <{$from}>", 'to' => $to, 'subject' => $subject, 'text' => $body]);
                break;
            case 'postmark':
                $response = $this->http()->withHeaders(['X-Postmark-Server-Token' => $v['server_token'], 'Accept' => 'application/json'])->post('https://api.postmarkapp.com/email', ['From' => "{$name} <{$from}>", 'To' => $to, 'Subject' => $subject, 'TextBody' => $body]);
                break;
            case 'sendgrid':
                $response = $this->http()->withToken($v['api_key'])->post('https://api.sendgrid.com/v3/mail/send', ['personalizations' => [['to' => [['email' => $to]]]], 'from' => ['email' => $from, 'name' => $name], 'subject' => $subject, 'content' => [['type' => 'text/plain', 'value' => $body]]]);
                break;
            default:
                return 'unknown_provider';
        }

        return $this->verdict($response);
    }

    private function whatsapp(ChannelConfig $config, string $phone, string $text): ?string
    {
        $v = $config->values;
        $to = PhoneNumber::international($phone);

        if ($to === null) {
            return 'bad_number';
        }

        switch ($config->provider) {
            case 'meta_cloud':
                $payload = ($v['template_name'] ?? '') !== ''
                    ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $v['template_name'], 'language' => ['code' => $v['template_language'] ?? 'id'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]]]]
                    : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text]];
                $response = $this->http()->withToken($v['access_token'])->post('https://graph.facebook.com/v21.0/'.rawurlencode($v['phone_number_id']).'/messages', $payload);
                break;
            case 'twilio':
                $from = 'whatsapp:+'.ltrim(preg_replace('/\D/', '', $v['from_number']) ?? '', '+');
                $response = $this->http()->withBasicAuth($v['account_sid'], $v['auth_token'])->asForm()->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($v['account_sid']).'/Messages.json', ['From' => $from, 'To' => "whatsapp:+{$to}", 'Body' => $text]);
                break;
            case 'fonnte':
                $response = $this->http()->withHeaders(['Authorization' => $v['token']])->asForm()->post('https://api.fonnte.com/send', ['target' => $to, 'message' => $text, 'countryCode' => '62']);

                return $this->verdict($response) ?? ($response->json('status') === false ? 'rejected' : null);
            case 'wablas':
                if (! PublicUrl::acceptable($v['server_url'])) {
                    return 'bad_address';
                }

                $response = $this->http()->withHeaders(['Authorization' => $v['token']])->asForm()->post(rtrim($v['server_url'], '/').'/api/send-message', ['phone' => $to, 'message' => $text]);

                return $this->verdict($response) ?? ($response->json('status') === false ? 'rejected' : null);
            case 'webhook':
                if (! PublicUrl::acceptable($v['url'])) {
                    return 'bad_address';
                }

                $request = $this->http();
                $response = ($v['token'] ?? '') !== '' ? $request->withToken($v['token'])->post($v['url'], ['phone' => $to, 'message' => $text]) : $request->post($v['url'], ['phone' => $to, 'message' => $text]);
                break;
            default:
                return 'unknown_provider';
        }

        return $this->verdict($response);
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->connectTimeout(8)->withoutRedirecting()->acceptJson();
    }

    private function verdict(Response $response): ?string
    {
        return $response->successful() ? null : 'http_'.$response->status();
    }
}
