<?php

namespace App\Services\Marketplace;

/**
 * A refusal a provider can act on: what was wrong, in their words.
 *
 * Never thrown for "you have not paid yet" alone as a dead end - that message
 * says what is missing, because there is currently no way for them to pay and
 * they deserve to know that rather than retry.
 */
class AdvertisingRefused extends \RuntimeException
{
}
