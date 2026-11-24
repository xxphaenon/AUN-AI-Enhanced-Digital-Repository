<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class Controller
{
    /**
     * Run a paginated query and return the list envelope used everywhere:
     *   { status, data: [...], meta: { page, perPage, total, lastPage } }
     *
     * @param  Builder  $query
     */
    protected function paginated($query, int $page, int $perPage, callable $map, array $extraMeta = []): array
    {
        $total = (clone $query)->count();

        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'status' => 'success',
            'data' => $items->map($map)->values()->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ] + $extraMeta,
        ];
    }

    /**
     * Stream a stored PDF straight from private storage.
     *
     *   inline     → the browser displays it (preview)
     *   attachment → the browser saves it (download)
     *
     * The file is never copied to a public folder or given a public URL.
     */
    protected function streamFile(Artifact $artifact, string $disposition): StreamedResponse
    {
        if (! $artifact->fileExists()) {
            Log::error('Stored file missing.', ['artifactId' => (string) $artifact->id, 'path' => $artifact->file_path]);
            abort(404, 'The file for this document is missing from storage.');
        }

        return Storage::disk($artifact->storage_disk)->response(
            $artifact->file_path,
            $artifact->downloadFilename(),
            [
                'Content-Type' => 'application/pdf',
                // Not stored by browsers or shared caches/proxies.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition,
        );
    }
}
