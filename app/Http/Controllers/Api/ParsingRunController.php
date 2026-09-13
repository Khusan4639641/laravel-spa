<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParsingRunResource;
use App\Models\ParsingRun;
use Illuminate\Http\Request;

class ParsingRunController extends Controller
{
    public function show(Request $request, int $parsingRun): ParsingRunResource
    {
        return new ParsingRunResource(ParsingRun::query()
            ->whereHas('organization', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($parsingRun));
    }
}
