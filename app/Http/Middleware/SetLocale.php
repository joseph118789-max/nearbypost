<?php

namespace App\Http\Middleware;

use App\Support\Loc;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the reading language from the URL prefix.
 *
 * The locale lives in the path, so it is shareable and separately indexable.
 * Anything unrecognised falls back to English rather than erroring: a bad
 * language code should show the news, not a 404.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $locale = $locale ?? $request->route('locale');

        app()->setLocale(Loc::isValid($locale) ? $locale : Loc::DEFAULT);

        return $next($request);
    }
}
