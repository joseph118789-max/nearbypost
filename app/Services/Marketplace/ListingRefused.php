<?php

namespace App\Services\Marketplace;

/**
 * A listing that will not be written, with a reason the person can act on.
 *
 * ⛔ The message is shown to the provider, so it says what to do next - "You
 * already have 3 listings in this category, which is the limit here. Remove one
 * first." - and never names a table, a column or an internal id.
 */
class ListingRefused extends \RuntimeException
{
}
