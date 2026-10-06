import { Link, router, usePage } from '@inertiajs/react';
import { errors, settings, show, versions } from '@/routes/projects';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import type { ProjectRef } from '@/types';

type Tab = {
    title: string;
    route: typeof show;
    /** Whether the page depends on the selected period. */
    period: boolean;
};

const tabs: Tab[] = [
    { title: 'Overview', route: show, period: true },
    { title: 'Errors', route: errors, period: true },
    { title: 'Versions', route: versions, period: true },
    { title: 'Settings', route: settings, period: false },
];

/**
 * Title, tabs and period selector shared by the project pages. Switching tabs
 * keeps the chosen period.
 */
export function ProjectHeader({
    project,
    days,
    periods,
}: {
    project: ProjectRef;
    /** The selected period. Leave out on pages that do not use one. */
    days?: number;
    periods?: number[];
}) {
    const { url } = usePage();
    const path = new URL(url, 'http://localhost').pathname;

    return (
        <header className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    {project.name}
                </h1>

                {days !== undefined && periods && (
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={String(days)}
                        aria-label="Period"
                        onValueChange={(value) => {
                            // An empty value means the active item was clicked again: keep it.
                            if (value) {
                                router.get(
                                    path,
                                    { days: value },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                );
                            }
                        }}
                    >
                        {periods.map((period) => (
                            <ToggleGroupItem
                                key={period}
                                value={String(period)}
                            >
                                {period} days
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                )}
            </div>

            <nav
                aria-label="Project"
                className="flex gap-1 border-b border-border"
            >
                {tabs.map((tab) => {
                    const href = tab.route(
                        project.id,
                        tab.period && days ? { query: { days } } : undefined,
                    );
                    const active =
                        path ===
                        new URL(tab.route.url(project.id), 'http://localhost')
                            .pathname;

                    return (
                        <Link
                            key={tab.title}
                            href={href}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                                active
                                    ? 'border-foreground font-medium text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.title}
                        </Link>
                    );
                })}
            </nav>
        </header>
    );
}
