<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The e-mail address of the people who work in a property, answered by the identity module, for a notice that something is waiting for them (a scheduled report is ready). The address is
 * personal data: it is used only to send that notice and is never shown, stored or logged by the caller.
 */
interface StaffContacts
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, string> user id to e-mail address, only for people with an active account who work in this property
     */
    public function emailsOf(PropertyId $property, array $userIds): array;
}
