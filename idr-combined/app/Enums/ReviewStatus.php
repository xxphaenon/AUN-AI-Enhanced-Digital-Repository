<?php

namespace App\Enums;

/**
 * Moderation state of a submission (MongoDB field `status`, API field
 * `reviewStatus`). Separate from ProcessingStatus, which tracks the AI
 * embedding pipeline: a file can be EMBEDDED but still pending review.
 *
 * Only APPROVED files are visible to students and guests (search, chat,
 * listing, preview, download). Admins see everything.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
