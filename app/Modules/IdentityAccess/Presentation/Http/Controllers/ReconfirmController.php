<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Screens that call a sensitive JSON endpoint send people here when the password confirmation lapsed (HTTP 423). The
 * `password.confirm` middleware on this route asks for the password and the person lands back on the page they were on.
 * Only same-site paths are accepted as the return target.
 */
final readonly class ReconfirmController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $return = (string) $request->query('return', '/');
        $safe = preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%\[\]]*$#D', $return) === 1 && ! str_contains($return, '..');

        return redirect($safe ? $return : '/');
    }
}
