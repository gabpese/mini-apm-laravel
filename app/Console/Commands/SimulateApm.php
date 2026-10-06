<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use App\Services\DemoDataGenerator;
use App\Services\EventIngestor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apm:simulate
    {--user= : E-mail of the user who owns the demo project (default: the first user)}
    {--project=Demo App : Name of the demo project}
    {--days=30 : How many days of data to generate}
    {--users=150 : How many fictional end users to simulate}
    {--seed=42 : Seed, so the same numbers give the same data}
    {--api-key= : Use this exact API key (apm_ followed by 20+ characters), for a public demo with a known key}
    {--fresh : Delete the demo project data first}')]
#[Description('Fill a demo project with realistic fictional usage, errors and a crash regression')]
class SimulateApm extends Command
{
    public function handle(DemoDataGenerator $generator, EventIngestor $ingestor): int
    {
        $user = $this->option('user')
            ? User::where('email', $this->option('user'))->first()
            : User::first();

        if ($user === null) {
            $this->components->error('No user found. Create an account first, or pass --user=email.');

            return self::FAILURE;
        }

        $project = $user->projects()->firstOrCreate(
            ['name' => $this->option('project')],
            ['min_ram_mb' => 8192, 'min_os' => 'Windows 10'],
        );

        if (! $this->registerFixedKey($project)) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->clear($project);
        }

        $events = $generator->generate(
            days: (int) $this->option('days'),
            users: (int) $this->option('users'),
            seed: (int) $this->option('seed'),
        );

        $this->components->info(sprintf('Sending %d events to "%s"...', count($events), $project->name));

        $this->output->progressStart(count($events));
        foreach (array_chunk($events, 100) as $batch) {
            $ingestor->ingest($project, $batch);
            $this->output->progressAdvance(count($batch));
        }
        $this->output->progressFinish();

        $this->summary($project);
        $this->apiKey($project);

        return self::SUCCESS;
    }

    /**
     * Make sure the key given with --api-key belongs to this project. Returns false when it cannot.
     */
    private function registerFixedKey(Project $project): bool
    {
        $plain = $this->option('api-key');

        if ($plain === null) {
            return true;
        }

        if (! preg_match('/^apm_[A-Za-z0-9]{20,}$/', $plain)) {
            $this->components->error('--api-key must be apm_ followed by at least 20 letters or digits.');

            return false;
        }

        $existing = ApiKey::query()->where('key_hash', ApiKey::hash($plain))->first();

        if ($existing !== null && $existing->project_id !== $project->id) {
            $this->components->error('That API key already belongs to another project.');

            return false;
        }

        if ($existing === null) {
            ApiKey::fromPlain($project, $plain, 'demo');
        }

        return true;
    }

    private function clear(Project $project): void
    {
        $project->events()->delete();
        $project->appSessions()->delete();
        $project->errorGroups()->delete();
    }

    private function summary(Project $project): void
    {
        $this->newLine();
        $this->table(['Version', 'Sessions', 'Crashes', 'Crash rate'], collect(DemoDataGenerator::VERSIONS)
            ->map(function (string $version) use ($project): array {
                $sessions = $project->appSessions()->where('app_version', $version)->count();
                $crashes = $project->events()->where('type', 'crash')->where('app_version', $version)->count();

                return [$version, $sessions, $crashes, $sessions > 0 ? round($crashes / $sessions * 100, 1).'%' : '-'];
            })->all());
    }

    /**
     * The plain text key only exists when it is created, so a key is made
     * the first time and never shown again.
     */
    private function apiKey(Project $project): void
    {
        // A key given with --api-key was already registered, and a project that has one keeps it.
        if ($project->apiKeys()->exists()) {
            return;
        }

        [, $plain] = ApiKey::generate($project, 'demo');

        $this->components->info('API key created. Save it now, it will not be shown again:');
        $this->line("  $plain");
    }
}
