import { Head, Link } from '@inertiajs/react';
import { BarList } from '@/components/charts/bar-list';
import { ChartCard } from '@/components/charts/chart-card';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import { ProjectHeader } from '@/components/projects/project-header';
import { RegressionAlert } from '@/components/projects/regression-alert';
import { formatDay, formatNumber, formatPercent } from '@/lib/format';
import { dashboard } from '@/routes';
import { settings, show } from '@/routes/projects';
import type {
    DailyRow,
    DistributionRow,
    Environment,
    FeatureRow,
    Regression,
    ReportProps,
    Totals,
} from '@/types';

type Props = ReportProps & {
    totals: Totals;
    daily: DailyRow[];
    features: FeatureRow[];
    environment: Environment;
    regression: Regression | null;
    min_ram_mb: number | null;
    min_os: string | null;
};

export default function Overview({
    project,
    days,
    periods,
    totals,
    daily,
    features,
    environment,
    regression,
    min_ram_mb,
    min_os,
}: Props) {
    return (
        <>
            <Head title={project.name} />

            <div className="flex flex-col gap-4 p-4">
                <ProjectHeader
                    project={project}
                    days={days}
                    periods={periods}
                />

                {regression && (
                    <RegressionAlert
                        projectId={project.id}
                        regression={regression}
                        link
                    />
                )}

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <StatTile
                        label="Sessions"
                        value={formatNumber(totals.sessions)}
                    />
                    <StatTile
                        label="Active users"
                        value={formatNumber(totals.users)}
                    />
                    <StatTile
                        label="Errors"
                        value={formatNumber(totals.errors)}
                    />
                    <StatTile
                        label="Crashes"
                        value={formatNumber(totals.crashes)}
                    />
                    <StatTile
                        label="Crash rate"
                        value={formatPercent(totals.crash_rate)}
                        hint="crashes per session"
                    />
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    <ChartCard
                        title="Sessions per day"
                        table={{
                            columns: ['Day', 'Sessions'],
                            rows: daily.map((row) => [
                                formatDay(row.date),
                                formatNumber(row.sessions),
                            ]),
                        }}
                    >
                        <TimeSeriesChart
                            data={daily}
                            series={[
                                {
                                    key: 'sessions',
                                    label: 'Sessions',
                                    color: 'var(--viz-1)',
                                },
                            ]}
                            ariaLabel={`Sessions per day over the last ${days} days`}
                        />
                    </ChartCard>

                    <ChartCard
                        title="Errors and crashes per day"
                        description="Shown apart from sessions because the scales differ."
                        table={{
                            columns: ['Day', 'Errors', 'Crashes'],
                            rows: daily.map((row) => [
                                formatDay(row.date),
                                formatNumber(row.errors),
                                formatNumber(row.crashes),
                            ]),
                        }}
                    >
                        <TimeSeriesChart
                            data={daily}
                            series={[
                                {
                                    key: 'errors',
                                    label: 'Errors',
                                    color: 'var(--viz-2)',
                                },
                                {
                                    key: 'crashes',
                                    label: 'Crashes',
                                    color: 'var(--viz-3)',
                                },
                            ]}
                            ariaLabel={`Errors and crashes per day over the last ${days} days`}
                        />
                    </ChartCard>
                </div>

                <ChartCard
                    title="Most used features"
                    table={{
                        columns: ['Feature', 'Uses'],
                        rows: features.map((feature) => [
                            feature.name,
                            formatNumber(feature.uses),
                        ]),
                    }}
                >
                    <BarList
                        items={features.map((feature) => ({
                            label: feature.name,
                            value: feature.uses,
                            display: formatNumber(feature.uses),
                        }))}
                    />
                </ChartCard>

                <h2 className="mt-2 text-base font-medium">Machines</h2>

                <Requirements
                    projectId={project.id}
                    environment={environment}
                    minRamMb={min_ram_mb}
                    minOs={min_os}
                />

                <div className="grid gap-4 xl:grid-cols-3">
                    <Distribution
                        title="Operating system"
                        rows={environment.os}
                    />
                    <Distribution title="Memory" rows={environment.ram} />
                    <Distribution
                        title="Graphics card"
                        rows={environment.gpu}
                    />
                </div>
            </div>
        </>
    );
}

Overview.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard() },
        { title: props.project.name, href: show(props.project.id) },
    ],
});

function Distribution({
    title,
    rows,
}: {
    title: string;
    rows: DistributionRow[];
}) {
    return (
        <ChartCard
            title={title}
            description="Distinct users in the period."
            table={{
                columns: [title, 'Users'],
                rows: rows.map((row) => [row.label, formatNumber(row.users)]),
            }}
        >
            <BarList
                items={rows.map((row) => ({
                    label: row.label,
                    value: row.users,
                    display: formatNumber(row.users),
                }))}
            />
        </ChartCard>
    );
}

function Requirements({
    projectId,
    environment,
    minRamMb,
    minOs,
}: {
    projectId: number;
    environment: Environment;
    minRamMb: number | null;
    minOs: string | null;
}) {
    const hasRequirements = minRamMb !== null || minOs !== null;
    const share =
        environment.users > 0
            ? environment.below_minimum / environment.users
            : 0;

    return (
        <section className="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h3 className="text-sm font-medium">Minimum requirements</h3>

            {!hasRequirements ? (
                <p className="mt-1 text-sm text-muted-foreground">
                    No minimum RAM or operating system is set for this project.{' '}
                    <Link
                        href={settings(projectId)}
                        className="underline underline-offset-4"
                    >
                        Set them in the settings
                    </Link>{' '}
                    to see who runs below them.
                </p>
            ) : (
                <div className="mt-2 flex flex-wrap items-end gap-x-8 gap-y-2">
                    <div>
                        <p className="text-3xl font-semibold tracking-tight">
                            {formatNumber(environment.below_minimum)}{' '}
                            <span className="text-base font-normal text-muted-foreground">
                                of {formatNumber(environment.users)} users (
                                {formatPercent(share, 0)})
                            </span>
                        </p>
                        <p className="text-xs text-muted-foreground">
                            run below the minimum requirements
                        </p>
                    </div>

                    <ul className="space-y-0.5 text-sm text-muted-foreground">
                        {minRamMb !== null && (
                            <li>
                                <span className="text-foreground tabular-nums">
                                    {formatNumber(environment.below_ram)}
                                </span>{' '}
                                below {formatNumber(minRamMb)} MB of RAM
                            </li>
                        )}
                        {minOs !== null && (
                            <li>
                                <span className="text-foreground tabular-nums">
                                    {formatNumber(environment.below_os)}
                                </span>{' '}
                                on a system older than {minOs}
                            </li>
                        )}
                    </ul>
                </div>
            )}
        </section>
    );
}
