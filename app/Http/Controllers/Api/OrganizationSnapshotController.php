<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationSnapshotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $organization = $request->user()->selectedOrganization();
        abort_unless($organization, 404, 'Организация не найдена.');
        $snapshots = $organization->snapshots()->latest('id')->paginate(20);

        return response()->json([
            'data' => $snapshots->map(fn ($snapshot) => [
                'id' => $snapshot->id, 'parsing_run_id' => $snapshot->parsing_run_id,
                'title' => $snapshot->title, 'rating' => $snapshot->rating,
                'ratings_count' => $snapshot->ratings_count, 'reviews_count' => $snapshot->reviews_count,
                'changes' => $snapshot->payload['changes'] ?? [], 'created_at' => $snapshot->created_at->toISOString(),
            ]),
            'meta' => ['current_page' => $snapshots->currentPage(), 'per_page' => 20,
                'total' => $snapshots->total(), 'last_page' => $snapshots->lastPage()],
        ]);
    }
}
