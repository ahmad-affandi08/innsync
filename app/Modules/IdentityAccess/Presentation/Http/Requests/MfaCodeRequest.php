<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
