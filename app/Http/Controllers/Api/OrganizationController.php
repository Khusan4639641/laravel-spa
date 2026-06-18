<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\YandexParserException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Yandex\OrganizationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        return response()->json([
            'data' => $organization ? new OrganizationResource($organization) : null,
        ]);
    }

    public function store(SaveOrganizationRequest $request, OrganizationSyncService $syncService): JsonResponse
    {
        try {
            $organization = $syncService->saveAndSync($request->user(), $request->validated('source_url'));
        } catch (YandexParserException $exception) {
            return $this->parserErrorResponse($request, $exception->getMessage());
        }

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    public function sync(Request $request, OrganizationSyncService $syncService): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return response()->json([
                'message' => 'Сначала сохраните ссылку на организацию.',
            ], 404);
        }

        try {
            $organization = $syncService->sync($organization);
        } catch (YandexParserException $exception) {
            return $this->parserErrorResponse($request, $exception->getMessage());
        }

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    private function currentOrganization(Request $request): ?Organization
    {
        return Organization::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();
    }

    private function parserErrorResponse(Request $request, string $message): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        return response()->json([
            'message' => $message,
            'data' => $organization ? new OrganizationResource($organization) : null,
        ], 502);
    }
}
