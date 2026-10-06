<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Builds weeks of realistic, fictional usage for an invented desktop app.
 *
 * The newest version has a deliberate crash regression, so the dashboard
 * always has something to show.
 */
class DemoDataGenerator
{
    /** @var list<string> */
    public const VERSIONS = ['1.0.0', '1.1.0', '1.2.0'];

    /** Share of sessions that end in a crash, per version. */
    public const CRASH_RATES = ['1.0.0' => 0.015, '1.1.0' => 0.018, '1.2.0' => 0.07];

    private const FEATURES = [
        'exportar_pdf' => 30,
        'importar_csv' => 22,
        'compartilhar' => 15,
        'modo_escuro' => 10,
        'busca_avancada' => 14,
        'sincronizar' => 9,
    ];

    private const ERRORS = [
        ['Timeout ao sincronizar com o servidor', 'sync.rb:41:in `push`'],
        ['Arquivo CSV com formato inválido', 'importer.rb:18:in `parse`'],
        ['Falha ao gerar o PDF', 'exporter.rb:77:in `render`'],
    ];

    private const COMMON_CRASHES = [
        ['Undefined method for nil', 'cache.rb:12:in `fetch`'],
        ['Memória insuficiente', 'loader.rb:9:in `load_all`'],
    ];

    private const REGRESSION_CRASH = ['NoMethodError: undefined method `render_chart` for nil', 'dashboard.rb:56:in `draw`'];

    private const MACHINES = [
        ['Windows 11', 16384, 'RTX 3060'],
        ['Windows 11', 8192, 'GTX 1660'],
        ['Windows 10', 8192, 'Intel UHD'],
        ['Windows 10', 4096, 'Intel UHD'],
        ['Windows 10', 2048, 'Intel HD'],
        ['Windows 7', 4096, 'Intel HD'],
        ['macOS 14', 16384, 'Apple M2'],
        ['Ubuntu 24.04', 8192, 'Intel UHD'],
    ];

    /**
     * @return array<int, array<string, mixed>> events in the API batch format, oldest first
     */
    public function generate(int $days = 30, int $users = 150, int $seed = 42, ?CarbonInterface $until = null): array
    {
        mt_srand($seed);

        $end = ($until ?? now())->toImmutable();
        $start = $end->startOfDay()->subDays($days - 1);

        // Each user has a fixed machine and updates some days after a release.
        $pool = [];
        for ($i = 0; $i < $users; $i++) {
            $pool[] = [
                'ref' => 'u_'.substr(md5($seed.'-'.$i), 0, 6),
                'machine' => self::MACHINES[mt_rand(0, count(self::MACHINES) - 1)],
                'delay' => mt_rand(0, 5),
            ];
        }

        $events = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $start->addDays($day);

            foreach ($pool as $user) {
                if (mt_rand(1, 100) > 35) {
                    continue;
                }

                $version = $this->versionFor($day, $days, $user['delay']);
                array_push($events, ...$this->session($user, $version, $date, $end));
            }
        }

        usort($events, fn (array $a, array $b) => strcmp($a['occurred_at'], $b['occurred_at']));

        return $events;
    }

    /**
     * 1.0.0 is out from day one, 1.1.0 two weeks and 1.2.0 one week before the end,
     * so the regression is recent but already has enough sessions to show.
     * Each user adopts a release a few days after it ships.
     */
    private function versionFor(int $day, int $days, int $delay): string
    {
        $releases = [
            '1.0.0' => 0,
            '1.1.0' => max(1, $days - 14),
            '1.2.0' => max(2, $days - 7),
        ];

        $version = '1.0.0';
        foreach ($releases as $name => $releaseDay) {
            if ($day >= $releaseDay + $delay) {
                $version = $name;
            }
        }

        return $version;
    }

    /**
     * @param  array{ref: string, machine: array{0: string, 1: int, 2: string}, delay: int}  $user
     * @return array<int, array<string, mixed>>
     */
    private function session(array $user, string $version, CarbonImmutable $date, CarbonImmutable $end): array
    {
        $at = $date->addSeconds(mt_rand(8 * 3600, 22 * 3600));
        $base = ['app_version' => $version, 'user_ref' => $user['ref']];

        $events = [$base + [
            'type' => Event::TYPE_SESSION_START,
            'occurred_at' => $at->toIso8601ZuluString(),
            'env' => ['os' => $user['machine'][0], 'ram_mb' => $user['machine'][1], 'gpu' => $user['machine'][2]],
        ]];

        for ($i = mt_rand(2, 6); $i > 0; $i--) {
            $at = $at->addSeconds(mt_rand(20, 600));
            $events[] = $base + [
                'type' => Event::TYPE_FEATURE_USED,
                'name' => $this->weighted(self::FEATURES),
                'occurred_at' => $at->toIso8601ZuluString(),
            ];
        }

        if (mt_rand(1, 100) <= 8) {
            [$message, $stack] = self::ERRORS[mt_rand(0, count(self::ERRORS) - 1)];
            $events[] = $base + [
                'type' => Event::TYPE_ERROR,
                'message' => $message,
                'stack' => $stack,
                'occurred_at' => $at->addSeconds(mt_rand(5, 60))->toIso8601ZuluString(),
            ];
        }

        if (mt_rand(1, 10000) <= self::CRASH_RATES[$version] * 10000) {
            [$message, $stack] = $version === '1.2.0' && mt_rand(1, 100) <= 80
                ? self::REGRESSION_CRASH
                : self::COMMON_CRASHES[mt_rand(0, count(self::COMMON_CRASHES) - 1)];

            $events[] = $base + [
                'type' => Event::TYPE_CRASH,
                'message' => $message,
                'stack' => $stack,
                'occurred_at' => $at->addSeconds(mt_rand(60, 300))->toIso8601ZuluString(),
            ];
        }

        // Today is only partly over: drop what would happen after "now". Done after the random
        // draws, so the same seed keeps producing the same history up to this moment.
        return array_values(array_filter(
            $events,
            fn (array $event) => CarbonImmutable::parse($event['occurred_at'])->lte($end),
        ));
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $pick = mt_rand(1, array_sum($weights));

        foreach ($weights as $name => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return $name;
            }
        }

        return array_key_first($weights);
    }
}
