import { Form, Head, Link } from '@inertiajs/react';
import { FolderPlus, TriangleAlert } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber, formatPercent } from '@/lib/format';
import { dashboard } from '@/routes';
import { show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

export default function Projects({
    projects,
    period,
}: {
    projects: ProjectSummary[];
    period: number;
}) {
    return (
        <>
            <Head title="Projects" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Projects
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            One project per monitored application. Numbers cover
                            the last {period} days.
                        </p>
                    </div>
                    <NewProject />
                </div>

                {projects.length === 0 ? (
                    <EmptyState />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <ProjectCard project={project} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Projects.layout = {
    breadcrumbs: [{ title: 'Projects', href: dashboard() }],
};

function ProjectCard({ project }: { project: ProjectSummary }) {
    return (
        <Link
            href={show(project.id)}
            prefetch
            className="block rounded-xl border border-sidebar-border/70 bg-card p-4 transition-colors hover:bg-accent dark:border-sidebar-border"
        >
            <div className="flex items-start justify-between gap-2">
                <h2 className="truncate font-medium">{project.name}</h2>
                {project.regression && (
                    <span className="flex shrink-0 items-center gap-1 rounded-md bg-(--viz-critical)/10 px-1.5 py-0.5 text-xs font-medium text-(--viz-critical)">
                        <TriangleAlert className="size-3.5" aria-hidden />
                        Regression
                    </span>
                )}
            </div>

            <p className="mt-0.5 text-xs text-muted-foreground">
                {project.latest_version
                    ? `Latest version ${project.latest_version}`
                    : 'No data yet'}
            </p>

            <dl className="mt-4 grid grid-cols-3 gap-2 text-sm">
                <Figure
                    label="Sessions"
                    value={formatNumber(project.sessions)}
                />
                <Figure label="Crashes" value={formatNumber(project.crashes)} />
                <Figure
                    label="Crash rate"
                    value={formatPercent(project.crash_rate)}
                />
            </dl>
        </Link>
    );
}

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold">{value}</dd>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 px-6 py-16 text-center dark:border-sidebar-border">
            <FolderPlus className="size-8 text-muted-foreground" aria-hidden />
            <div>
                <h2 className="font-medium">No projects yet</h2>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Create a project to get an API key, then send events from
                    your app. To see the dashboard filled right away, run{' '}
                    <code className="rounded bg-muted px-1 py-0.5">
                        php artisan apm:simulate
                    </code>
                    .
                </p>
            </div>
            <NewProject />
        </div>
    );
}

function NewProject() {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button>
                    <FolderPlus />
                    New project
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New project</DialogTitle>
                    <DialogDescription>
                        You get a first API key right after creating it. The
                        requirements are optional and show how many users run
                        below them.
                    </DialogDescription>
                </DialogHeader>

                <Form {...ProjectController.store.form()} className="space-y-4">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="My desktop app"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="min_ram_mb">
                                        Minimum RAM (MB)
                                    </Label>
                                    <Input
                                        id="min_ram_mb"
                                        name="min_ram_mb"
                                        type="number"
                                        min={0}
                                        placeholder="8192"
                                    />
                                    <InputError message={errors.min_ram_mb} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="min_os">Minimum OS</Label>
                                    <Input
                                        id="min_os"
                                        name="min_os"
                                        placeholder="Windows 10"
                                    />
                                    <InputError message={errors.min_os} />
                                </div>
                            </div>

                            <Button
                                type="submit"
                                disabled={processing}
                                className="w-full"
                            >
                                Create project
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
