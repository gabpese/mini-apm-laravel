<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ApiKeyController extends Controller
{
    /**
     * Create a key. The plain text is shown once, since only its hash is stored.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);

        [$apiKey, $plain] = ApiKey::generate($project, $data['name'] ?? null);

        Inertia::flash('new_key', ['name' => $apiKey->name, 'key' => $plain]);

        return to_route('projects.settings', $project);
    }

    /**
     * Revoke a key. It stays listed as revoked, and stops working at once.
     */
    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        Gate::authorize('update', $apiKey->project);

        $apiKey->revoke();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('projects.settings', $apiKey->project_id);
    }
}
