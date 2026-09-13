<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\Yandex\OrganizationSyncScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $request->user()->selectedOrganization();

        return response()->json(['data' => $organization ? new OrganizationResource($organization->load('latestRun')) : null]);
    }

    public function store(SaveOrganizationRequest $request, OrganizationSyncScheduler $scheduler): JsonResponse
    {
        $run = $scheduler->schedule($request->user(), $request->validated('source_url'));

        return response()->json(['data' => [
            'organization_id' => $run->organization_id, 'parsing_run_id' => $run->id, 'status' => $run->status->value,
        ]], 202);
    }

    public function sync(Request $request, OrganizationSyncScheduler $scheduler): JsonResponse
    {
        $run = $scheduler->schedule($request->user());

        return response()->json(['data' => [
            'organization_id' => $run->organization_id, 'parsing_run_id' => $run->id, 'status' => $run->status->value,
        ]], 202);
    }
}
