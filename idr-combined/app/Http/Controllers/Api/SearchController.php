<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    /**
     * GET /api/search?q=...&limit=5  (anyone)
     *
     * Semantic search over APPROVED documents only.
     */
    public function __invoke(SearchRequest $request): JsonResponse
    {
        return $this->run($request, adminMode: false);
    }

    /**
     * GET /api/admin/search?q=...&limit=5  (admins)
     *
     * Same search with Member 2's admin_mode on: pending, approved and
     * rejected documents are all included, each tagged with reviewStatus.
     */
    public function admin(SearchRequest $request): JsonResponse
    {
        return $this->run($request, adminMode: true);
    }

    private function run(SearchRequest $request, bool $adminMode): JsonResponse
    {
        set_time_limit((int) config('idr.ai_request_seconds', 120));

        try {
            $results = $this->search->search($request->searchQuery(), $request->limit(), $adminMode, $request->documentType());
        } catch (Throwable $e) {
            Log::error('Search failed.', ['query' => $request->searchQuery(), 'error' => $e->getMessage()]);

            throw AiServiceUnavailableException::forFailure($e, 'Search');
        }

        return response()->json([
            'status' => 'success',
            'query' => $request->searchQuery(),
            'results' => $results,
        ]);
    }
}
