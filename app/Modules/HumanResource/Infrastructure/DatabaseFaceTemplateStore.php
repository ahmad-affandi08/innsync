<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\FaceTemplateStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** The face templates are encrypted with the application key before they reach the database, so a copy of the database alone shows no face data. */
final class DatabaseFaceTemplateStore implements FaceTemplateStore
{
    public function find(PropertyId $property, string $employeeId): ?array
    {
        $r = DB::table('hr_face_templates')->where('property_id', $property->toString())->where('employee_id', $employeeId)->first();

        if ($r === null) {
            return null;
        }

        /** @var list<list<float>> $templates */
        $templates = json_decode(Crypt::decryptString((string) $r->template), true, 8, JSON_THROW_ON_ERROR);

        return ['templates' => $templates, 'samples' => (int) $r->samples, 'enrolled_at' => (string) $r->enrolled_at];
    }

    public function save(PropertyId $property, string $employeeId, array $templates, string $by, DateTimeImmutable $at): void
    {
        $row = ['property_id' => $property->toString(), 'template' => Crypt::encryptString(json_encode($templates, JSON_THROW_ON_ERROR)), 'samples' => count($templates), 'enrolled_by' => $by, 'enrolled_at' => $at->format('Y-m-d H:i:s.u')];

        DB::table('hr_face_templates')->updateOrInsert(['employee_id' => $employeeId], $row);
    }

    public function delete(PropertyId $property, string $employeeId): bool
    {
        return DB::table('hr_face_templates')->where('property_id', $property->toString())->where('employee_id', $employeeId)->delete() > 0;
    }

    public function enrolled(PropertyId $property): array
    {
        return DB::table('hr_face_templates')->where('property_id', $property->toString())->pluck('enrolled_at', 'employee_id')->map(static fn ($v): string => (string) $v)->all();
    }
}
