<?php

namespace App\Services\Marketplace;

/**
 * A credential that will not be accepted or a decision that cannot be recorded,
 * with a reason.
 *
 * ⛔ Never implies the person is lying. "Confirm you are currently authorised to
 * provide these services." asks for a declaration; "invalid credential" accuses
 * somebody of something the site has not established.
 */
class CredentialRefused extends \RuntimeException
{
}
