import { Head } from '@inertiajs/react';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { ChartCard } from '@/components/charts/chart-card';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import { ProjectHeader } from '@/components/projects/project-header';
import { RegressionAlert } from '@/components/projects/regression-alert';
import { formatDay, formatNumber, formatPercent } from '@/lib/format';
import { dashboard } from '@/routes';
import { show, versions as versionsRoute } from '@/routes/projects';
import type { Adoption, Regression, ReportProps, VersionRow } from '@/types';

type Props = ReportProps & {
    versions: VersionRow[];
    adoption: Adoption;
    regression: Regression | null;
    thresholds: { ratio: number; min_sessions: number };
};

/** Series colors in fixed order. A chart never shows more versions than there are slots. */
const COLORS = Array.from(
    { length: 8 },
    (_, index) => `var(--viz-${index + 1})`,
);

export default function Versions({
    project,
    days,
    periods,
    versions,
    adoption,
    regression,
    thresholds,
}: Props) {
    // The newest versions matter most: when there are more than 8, the oldest are left out of the lines.
    const shown = adoption.versions.slice(-COLORS.length);

    return (
        <>
            <Head title={`Versions · ${project.name}`} />

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
                    />
                )}

                <p className="text-sm text-muted-foreground">
                    A version is flagged when its crash rate (crashes per
                    session) is at least {formatNumber(thresholds.ratio)}× the
                    one before it, and both have at least{' '}
                    {formatNumber(thresholds.min_sessions)} sessions. You can
                    change these numbers in the settings.
                </p>

                {versions.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-sidebar-border/70 px-6 py-14 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        No sessions yet. Versions show up when your app sends
                        its first <code>session_start</code>.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-4 xl:grid-cols-2">
                            <ChartCard
                                title="Crash rate by version"
                                description="Over the whole life of the project."
                                table={{
                                    columns: [
                                        'Version',
                                        'Crash rate',
                                        'Crashes',
                                        'Sessions',
                                    ],
                                    rows: versions.map((row) => [
                                        row.version,
                                        formatPercent(row.crash_rate),
                                        formatNumber(row.crashes),
                                        formatNumber(row.sessions),
                                    ]),
                                }}
                            >
                                <BarList
                                    items={versions.map((row) => ({
                                        label: row.version,
                                        value: row.crash_rate,
                                        display: formatPercent(row.crash_rate),
                                        critical: row.regression !== null,
                                        detail: `${formatNumber(row.crashes)} crashes in ${formatNumber(row.sessions)} sessions`,
                                    }))}
                                />
                            </ChartCard>

                            <ChartCard
                                title="Adoption"
                                description="Sessions per day, by version."
                                table={{
                                    columns: ['Day', ...shown],
                                    rows: adoption.rows.map((row) => [
                                        formatDay(String(row.date)),
                                        ...shown.map((version) =>
                                            formatNumber(
                                                Number(row[version] ?? 0),
                                            ),
                                        ),
                                    ]),
                                }}
                            >
                                <TimeSeriesChart
                                    data={adoption.rows}
                                    series={shown.map((version, index) => ({
                                        key: version,
                                        label: version,
                                        color: COLORS[index],
                                    }))}
                                    ariaLabel={`Sessions per day by version over the last ${days} days`}
                                />
                            </ChartCard>
                        </div>

                        <VersionTable versions={versions} />
                    </>
                )}
            </div>
        </>
    );
}

Versions.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard() },
        { title: props.project.name, href: show(props.project.id) },
        { title: 'Versions', href: versionsRoute(props.project.id) },
    ],
});

function VersionTable({ versions }: { versions: VersionRow[] }) {
    return (
        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="text-left text-xs text-muted-foreground">
                    <tr className="border-b border-border">
                        <th className="px-4 py-2 font-medium">Version</th>
                        <th className="px-4 py-2 text-right font-medium">
                            Sessions
                        </th>
                        <th className="px-4 py-2 text-right font-medium">
                            Users
                        </th>
                        <th className="px-4 py-2 text-right font-medium">
                            Crashes
                        </th>
                        <th className="px-4 py-2 text-right font-medium">
                            Crash rate
                        </th>
                        <th className="px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    {[...versions].reverse().map((row) => (
                        <tr
                            key={row.version}
                            className="border-b border-border/60 last:border-0"
                        >
                            <td className="px-4 py-2.5 font-medium">
                                {row.version}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                                {formatNumber(row.sessions)}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                                {formatNumber(row.users)}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                                {formatNumber(row.crashes)}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                                {formatPercent(row.crash_rate)}
                            </td>
                            <td className="px-4 py-2.5">
                                {row.regression ? (
                                    <span className="flex items-center gap-1.5 font-medium text-(--viz-critical)">
                                        <TriangleAlert
                                            className="size-4"
                                            aria-hidden
                                        />
                                        {row.regression.ratio !== null
                                            ? `${row.regression.ratio.toFixed(1)}× ${row.regression.previous_version}`
                                            : `New crashes after ${row.regression.previous_version}`}
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1.5 text-muted-foreground">
                                        <CircleCheck
                                            className="size-4"
                                            aria-hidden
                                        />
                                        OK
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
