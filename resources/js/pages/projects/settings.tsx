import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import ApiKeyController from '@/actions/App/Http/Controllers/ApiKeyController';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { ProjectHeader } from '@/components/projects/project-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { formatRelative } from '@/lib/format';
import { dashboard } from '@/routes';
import { settings as settingsRoute, show } from '@/routes/projects';
import type { ApiKeyRow, NewKey, ProjectSettings } from '@/types';

type Props = {
    project: ProjectSettings;
    keys: ApiKeyRow[];
};

export default function Settings({ project, keys }: Props) {
    const { flash } = usePage();

    return (
        <>
            <Head title={`Settings · ${project.name}`} />

            <div className="flex flex-col gap-4 p-4">
                <ProjectHeader project={project} />

                <div className="flex max-w-3xl flex-col gap-6">
                    {flash.new_key && <NewKeyNotice newKey={flash.new_key} />}

                    <Section
                        title="API keys"
                        description="Your app sends events with one of these keys. Only a fingerprint of each key is stored, so the full text is shown once, when you create it."
                    >
                        <KeyList keys={keys} />
                        <CreateKey projectId={project.id} />
                    </Section>

                    <Section title="Send your first events">
                        <UsageExample />
                    </Section>

                    <Section
                        title="Project"
                        description="The minimum requirements show how many users run below them. The alert settings decide when a version counts as a crash regression."
                    >
                        <ProjectForm project={project} />
                    </Section>

                    <Section title="Delete project">
                        <DeleteProject project={project} />
                    </Section>
                </div>
            </div>
        </>
    );
}

Settings.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard() },
        { title: props.project.name, href: show(props.project.id) },
        { title: 'Settings', href: settingsRoute(props.project.id) },
    ],
});

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
            <header>
                <h2 className="font-medium">{title}</h2>
                {description && (
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </header>
            {children}
        </section>
    );
}

function NewKeyNotice({ newKey }: { newKey: NewKey }) {
    const [copied, copy] = useClipboard();

    return (
        <Alert>
            <KeyRound />
            <AlertTitle>
                Copy your new API key{newKey.name ? ` “${newKey.name}”` : ''}{' '}
                now
            </AlertTitle>
            <AlertDescription>
                <p>
                    For your safety it will not be shown again. If you lose it,
                    create another and revoke this one.
                </p>
                <div className="mt-2 flex items-center gap-2">
                    <code className="min-w-0 flex-1 truncate rounded-md bg-muted px-2 py-1.5 font-mono text-xs text-foreground select-all">
                        {newKey.key}
                    </code>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => void copy(newKey.key)}
                    >
                        {copied === newKey.key ? <Check /> : <Copy />}
                        {copied === newKey.key ? 'Copied' : 'Copy'}
                    </Button>
                </div>
            </AlertDescription>
        </Alert>
    );
}

function KeyList({ keys }: { keys: ApiKeyRow[] }) {
    if (keys.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                This project has no keys. Create one below.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-border/60 rounded-lg border border-border">
            {keys.map((key) => (
                <li
                    key={key.id}
                    className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5 text-sm"
                >
                    <div className="min-w-0">
                        <p className="flex items-center gap-2">
                            <span className="truncate font-medium">
                                {key.name ?? 'Unnamed key'}
                            </span>
                            {key.revoked_at && (
                                <Badge variant="outline">Revoked</Badge>
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            <code>{key.prefix}…</code> ·{' '}
                            {key.last_used_at
                                ? `last used ${formatRelative(key.last_used_at)}`
                                : 'never used'}
                        </p>
                    </div>

                    {!key.revoked_at && (
                        <Confirm
                            trigger={
                                <Button variant="outline" size="sm">
                                    Revoke
                                </Button>
                            }
                            title="Revoke this key?"
                            description="Apps using it stop being able to send events right away. This cannot be undone."
                            action={ApiKeyController.destroy.form(key.id)}
                            label="Revoke key"
                        />
                    )}
                </li>
            ))}
        </ul>
    );
}

function CreateKey({ projectId }: { projectId: number }) {
    return (
        <Form
            {...ApiKeyController.store.form(projectId)}
            resetOnSuccess
            className="flex items-start gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid flex-1 gap-1">
                        <Label htmlFor="key-name" className="sr-only">
                            Key name
                        </Label>
                        <Input
                            id="key-name"
                            name="name"
                            placeholder="Name, for example “production”"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        Create key
                    </Button>
                </>
            )}
        </Form>
    );
}

function UsageExample() {
    const origin =
        typeof window === 'undefined'
            ? 'https://your-server'
            : window.location.origin;

    return (
        <div className="space-y-2 text-sm">
            <p className="text-muted-foreground">
                Send a batch of up to 100 events with the key in the{' '}
                <code>Authorization</code> header. The API answers 202 when it
                accepts them.
            </p>
            <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs leading-relaxed">
                {`curl -X POST ${origin}/api/v1/events \\
  -H "Authorization: Bearer apm_your_key" \\
  -H "Content-Type: application/json" \\
  -d '{"events":[{"type":"session_start","occurred_at":"${new Date().toISOString()}","app_version":"1.0.0","user_ref":"u_1","env":{"os":"Windows 11","ram_mb":16384}}]}'`}
            </pre>
            <p className="text-muted-foreground">
                Clients for the browser (<code>clients/js</code>) and for Ruby (
                <code>clients/ruby</code>) do this for you.
            </p>
        </div>
    );
}

function ProjectForm({ project }: { project: ProjectSettings }) {
    return (
        <Form
            {...ProjectController.update.form(project.id)}
            options={{ preserveScroll: true }}
            className="space-y-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={project.name}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="min_ram_mb">Minimum RAM (MB)</Label>
                            <Input
                                id="min_ram_mb"
                                name="min_ram_mb"
                                type="number"
                                min={0}
                                defaultValue={project.min_ram_mb ?? ''}
                            />
                            <InputError message={errors.min_ram_mb} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="min_os">Minimum OS</Label>
                            <Input
                                id="min_os"
                                name="min_os"
                                defaultValue={project.min_os ?? ''}
                                placeholder="Windows 10"
                            />
                            <InputError message={errors.min_os} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="regression_ratio">
                                Alert when a version crashes … times more
                            </Label>
                            <Input
                                id="regression_ratio"
                                name="regression_ratio"
                                type="number"
                                min={1}
                                step={0.1}
                                defaultValue={project.regression_ratio}
                            />
                            <InputError message={errors.regression_ratio} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="regression_min_sessions">
                                Minimum sessions per version
                            </Label>
                            <Input
                                id="regression_min_sessions"
                                name="regression_min_sessions"
                                type="number"
                                min={1}
                                defaultValue={project.regression_min_sessions}
                            />
                            <InputError
                                message={errors.regression_min_sessions}
                            />
                        </div>
                    </div>

                    <Button type="submit" disabled={processing}>
                        Save
                    </Button>
                </>
            )}
        </Form>
    );
}

function DeleteProject({ project }: { project: ProjectSettings }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
                Deletes the project, its keys and all of its sessions, events
                and errors.
            </p>
            <Confirm
                trigger={<Button variant="destructive">Delete project</Button>}
                title={`Delete “${project.name}”?`}
                description="All of its data is removed for good. This cannot be undone."
                action={ProjectController.destroy.form(project.id)}
                label="Delete project"
                destructive
            />
        </div>
    );
}

/** A button that opens a dialog and only sends the request once the user confirms. */
function Confirm({
    trigger,
    title,
    description,
    action,
    label,
    destructive = false,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    action: { action: string; method: 'get' | 'post' };
    label: string;
    destructive?: boolean;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <TriangleAlert
                            className="size-5 text-(--viz-critical)"
                            aria-hidden
                        />
                        {title}
                    </DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <Form {...action} className="flex justify-end">
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {label}
                        </Button>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
