<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventsRequest;
use App\Models\ApiKey;
use App\Services\EventIngestor;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function store(StoreEventsRequest $request, EventIngestor $ingestor): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        /** @var array<int, array<string, mixed>> $events */
        $events = $request->validated('events');

        $accepted = $ingestor->ingest($apiKey->project, $events);

        return response()->json(['accepted' => $accepted], 202);
    }
}
