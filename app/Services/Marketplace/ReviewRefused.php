<?php

namespace App\Services\Marketplace;

/**
 * A rating that will not be written, with a reason the person can act on.
 *
 * ⛔ Never says "invalid". A reviewer told their rating is invalid learns
 * nothing; one told "You have already rated this. Edit your review instead."
 * knows what to do next.
 */
class ReviewRefused extends \RuntimeException
{
}
