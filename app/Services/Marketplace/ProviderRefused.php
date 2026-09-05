<?php

namespace App\Services\Marketplace;

/**
 * Setting up a provider did not go ahead, with a reason the person can act on.
 *
 * ⛔ Worded as the next step, not as a rejection. "You already have a neighbour
 * profile. Add another category to it instead of starting again." tells someone
 * what to do; "duplicate provider" tells them they have done something wrong,
 * which they have not.
 */
class ProviderRefused extends \RuntimeException
{
}
