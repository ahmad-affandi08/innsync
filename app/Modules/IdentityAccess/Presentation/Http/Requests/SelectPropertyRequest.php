<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SelectPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'string', 'ulid'],
        ];
    }
}
