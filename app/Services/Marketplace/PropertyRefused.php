<?php

namespace App\Services\Marketplace;

/**
 * A property payload or action that was refused, and why.
 *
 * Most of these are aimed at whoever is wiring the ListingMine feed rather than
 * at a reader, so they name the field or the host that was wrong.
 */
class PropertyRefused extends \RuntimeException
{
}
