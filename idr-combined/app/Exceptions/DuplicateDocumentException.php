<?php

namespace App\Exceptions;

use App\Models\Artifact;
use RuntimeException;

/**
 * The exact same file (same SHA-256) is already pending or approved.
 * Rendered by the upload controller as 409 Conflict.
 */
class DuplicateDocumentException extends RuntimeException
{
    public function __construct(public readonly Artifact $existing)
    {
        parent::__construct('This exact file has already been submitted.');
    }
}
