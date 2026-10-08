<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Middleware;

use App\Modules\IdentityAccess\Application\Ports\GrantMemory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Turns on the one-request memory of permission answers for the length of a web request, and throws it away afterwards, whatever happens. */
final readonly class MemoizeGrants
{
    public function __construct(private GrantMemory $memo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->memo->start();

        try {
            return $next($request);
        } finally {
            $this->memo->stop();
        }
    }
}
