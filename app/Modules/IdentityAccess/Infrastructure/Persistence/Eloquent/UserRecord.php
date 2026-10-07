<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent;

use App\Shared\Infrastructure\Persistence\Eloquent\UsesOptimisticLocking;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
final class UserRecord extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, UsesOptimisticLocking;

    protected $table = 'users';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'failed_login_attempts' => 0,
        'lock_version' => 0,
    ];

    /** The reset link by the installation's mailer, in the language of the request. The broker hands the token; the link is the only secret in it. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = url(route('password.reset', ['token' => $token, 'email' => $this->email], false));
        app(\App\Shared\Application\Notifications\EmailNotifier::class)->notify(
            (string) $this->email,
            __('identity.reset_subject'),
            __('identity.reset_body', ['url' => $url, 'minutes' => (int) config('auth.passwords.users.expire', 60)]),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
