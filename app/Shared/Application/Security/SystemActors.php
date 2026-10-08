<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The account an automatic or guest-driven action is recorded under, answered by the identity module. It is a real account of the property that nobody can sign in to (it is inactive and has a random
 * password), holding only the permissions the caller asks for, so every record still names who acted and every permission check still runs. It never appears in a list of staff.
 */
interface SystemActors
{
    /**
     * The guest self-service account of the property, created on first use and given the permissions named (those it holds already are kept; none is ever taken away).
     *
     * @param  list<string>  $permissions
     * @return string the user id
     */
    public function guestSelfService(PropertyId $property, array $permissions): string;

    /**
     * The account bookings made on the hotel's own web page are recorded under, so a reservation names "Online booking" as its maker and every rule and permission check still runs.
     *
     * @param  list<string>  $permissions
     * @return string the user id
     */
    public function onlineBooking(PropertyId $property, array $permissions): string;
}
