<?php

namespace App\Enums;

/**
 * What kind of document a file is. Chosen by the uploader, editable by admins,
 * and usable as a filter in the catalogue, the admin table and semantic search.
 *
 * The repository holds two kinds only. The stored values stay "project" and
 * "research" so existing documents and their Qdrant payloads keep working.
 */
enum DocumentType: string
{
    case Project = 'project';
    case Research = 'research';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Senior Design Project',
            self::Research => 'Senior Research Project',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
