<?php

namespace App\Services;

use App\Models\AppSession;
use App\Models\ErrorGroup;
use App\Models\Event;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventIngestor
{
    /**
     * Store a validated batch of events for the project.
     *
     * A session_start event opens an AppSession. Other events are linked to the
     * latest session of the same user and version, and errors and crashes are
     * grouped by fingerprint.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return int how many events were stored
     */
    public function ingest(Project $project, array $events): int
    {
        // Process in the order events happened, so a session exists before its events.
        usort($events, fn (array $a, array $b) => Carbon::parse($a['occurred_at'])->getTimestamp() <=> Carbon::parse($b['occurred_at'])->getTimestamp());

        DB::transaction(function () use ($project, $events): void {
            foreach ($events as $data) {
                $this->store($project, $data);
            }
        });

        return count($events);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(Project $project, array $data): void
    {
        $type = $data['type'];
        $occurredAt = Carbon::parse($data['occurred_at']);

        $session = $type === Event::TYPE_SESSION_START
            ? $this->openSession($project, $data, $occurredAt)
            : $this->findSession($project, $data, $occurredAt);

        $group = in_array($type, [Event::TYPE_ERROR, Event::TYPE_CRASH], true)
            ? $this->recordError($project, $data, $occurredAt)
            : null;

        $project->events()->create([
            'session_id' => $session?->id,
            'error_group_id' => $group?->id,
            'type' => $type,
            'name' => $data['name'] ?? null,
            'app_version' => $data['app_version'],
            'user_ref' => $data['user_ref'] ?? null,
            'occurred_at' => $occurredAt,
            'payload' => $this->payload($data),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function openSession(Project $project, array $data, Carbon $occurredAt): AppSession
    {
        /** @var array<string, mixed> $env */
        $env = $data['env'] ?? [];

        return $project->appSessions()->create([
            'user_ref' => $data['user_ref'] ?? null,
            'app_version' => $data['app_version'],
            'os' => $env['os'] ?? null,
            'ram_mb' => $env['ram_mb'] ?? null,
            'gpu' => $env['gpu'] ?? null,
            'started_at' => $occurredAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findSession(Project $project, array $data, Carbon $occurredAt): ?AppSession
    {
        if (blank($data['user_ref'] ?? null)) {
            return null;
        }

        return $project->appSessions()
            ->where('user_ref', $data['user_ref'])
            ->where('app_version', $data['app_version'])
            ->where('started_at', '<=', $occurredAt)
            ->latest('started_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordError(Project $project, array $data, Carbon $occurredAt): ErrorGroup
    {
        $fingerprint = ErrorGroup::fingerprintFor($data['message'], $data['stack'] ?? null);

        $group = $project->errorGroups()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'message' => $data['message'],
                'first_seen_at' => $occurredAt,
                'last_seen_at' => $occurredAt,
                'occurrences' => 0,
            ],
        );

        $group->increment('occurrences', 1, [
            'first_seen_at' => $group->first_seen_at->min($occurredAt),
            'last_seen_at' => $group->last_seen_at->max($occurredAt),
        ]);

        return $group;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function payload(array $data): ?array
    {
        $payload = array_filter([
            'message' => $data['message'] ?? null,
            'stack' => $data['stack'] ?? null,
            'properties' => $data['properties'] ?? null,
        ], fn ($value) => $value !== null);

        return $payload === [] ? null : $payload;
    }
}
