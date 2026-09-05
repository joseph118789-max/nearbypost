<?php

namespace App\Services\Media;

/**
 * An upload that will not be stored, with a reason the uploader can act on.
 *
 * ⛔ The message is shown to the person, so it says what to do about it - "That
 * file is 12.4 MB. The limit is 8 MB." - and never leaks a path, a disk name or
 * an internal id. Spec: "Errors explain what went wrong and how to fix it."
 */
class MediaRejected extends \RuntimeException
{
}
