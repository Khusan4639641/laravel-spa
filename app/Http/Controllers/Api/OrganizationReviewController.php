<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationReviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $organization = $request->user()->selectedOrganization();

        if (! $organization) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        $reviews = $organization->reviews()
            ->orderByDesc('review_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => OrganizationReviewResource::collection($reviews->getCollection()),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ],
        ]);
    }
}
