<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
