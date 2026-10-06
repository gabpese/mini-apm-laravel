import { Head } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { ProjectHeader } from '@/components/projects/project-header';
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import { dashboard } from '@/routes';
import { errors, show } from '@/routes/projects';
import type { ErrorGroupRow, ReportProps } from '@/types';

type Props = ReportProps & {
    groups: ErrorGroupRow[];
};

export default function Errors({ project, days, periods, groups }: Props) {
    return (
        <>
            <Head title={`Errors · ${project.name}`} />

            <div className="flex flex-col gap-4 p-4">
                <ProjectHeader
                    project={project}
                    days={days}
                    periods={periods}
                />

                <p className="text-sm text-muted-foreground">
                    Equal errors are grouped by their message and the first line
                    of the stack. Counts cover the last {days} days.
                </p>

                {groups.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 px-6 py-14 text-center dark:border-sidebar-border">
                        <CircleCheck
                            className="size-7 text-muted-foreground"
                            aria-hidden
                        />
                        <p className="font-medium">No errors in this period</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="px-4 py-2 font-medium">
                                        Error
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Occurrences
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Crashes
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        First seen
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Last seen
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((group) => (
                                    <tr
                                        key={group.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="max-w-md px-4 py-2.5 font-mono text-xs break-words">
                                            {group.message}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {formatNumber(group.occurrences)}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {formatNumber(group.crashes)}
                                        </td>
                                        <td className="px-4 py-2.5 whitespace-nowrap text-muted-foreground">
                                            {formatDateTime(
                                                group.first_seen_at,
                                            )}
                                        </td>
                                        <td
                                            className="px-4 py-2.5 whitespace-nowrap text-muted-foreground"
                                            title={formatDateTime(
                                                group.last_seen_at,
                                            )}
                                        >
                                            {formatRelative(group.last_seen_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

Errors.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard() },
        { title: props.project.name, href: show(props.project.id) },
        { title: 'Errors', href: errors(props.project.id) },
    ],
});
