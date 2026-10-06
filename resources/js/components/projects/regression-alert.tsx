import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatPercent } from '@/lib/format';
import { versions } from '@/routes/projects';
import type { Regression } from '@/types';

/**
 * The signature feature: the newest version crashes much more than the one
 * before it. Shown with an icon and words, never by color alone.
 */
export function RegressionAlert({
    projectId,
    regression,
    link = false,
}: {
    projectId: number;
    regression: Regression;
    /** Offer a link to the versions page. */
    link?: boolean;
}) {
    return (
        <Alert className="border-(--viz-critical)/50 bg-(--viz-critical)/10">
            <TriangleAlert className="text-(--viz-critical)" />
            <AlertTitle>
                Crash regression in version {regression.version}
            </AlertTitle>
            <AlertDescription>
                <p>
                    {formatPercent(regression.crash_rate)} of its sessions end
                    in a crash
                    {regression.ratio !== null
                        ? `, ${regression.ratio.toFixed(1)}× the rate of ${regression.previous_version} (${formatPercent(regression.previous_rate)}).`
                        : `, while ${regression.previous_version} had none.`}
                </p>
                {link && (
                    <Link
                        href={versions(projectId)}
                        className="mt-1 inline-block underline underline-offset-4"
                    >
                        Compare versions
                    </Link>
                )}
            </AlertDescription>
        </Alert>
    );
}
