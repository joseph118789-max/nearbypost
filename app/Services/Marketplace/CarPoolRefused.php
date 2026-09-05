<?php

namespace App\Services\Marketplace;

/**
 * A car-pool action that will not go ahead.
 *
 * ⛔ The commercial-positioning refusal names the phrase that caused it, so the
 * person can edit their own words. Spec §18.3 forbids the system rewriting a
 * user's text, and quietly deleting "airport transfer" would be exactly that.
 */
class CarPoolRefused extends \RuntimeException
{
}
