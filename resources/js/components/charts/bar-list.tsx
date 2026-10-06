import { TriangleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

export type BarItem = {
    label: string;
    value: number;
    /** Text shown at the end of the row. Defaults to the value. */
    display?: string;
    /** Extra text for the hover and screen readers. */
    detail?: string;
    /** Draws the bar in the critical color, with a warning icon. */
    critical?: boolean;
};

/**
 * Horizontal bars for comparing magnitudes. One hue; the critical color is only
 * used for a flagged row, and always together with an icon.
 */
export function BarList({
    items,
    max,
    emptyText = 'No data in this period.',
}: {
    items: BarItem[];
    /** Value that fills the whole track. Defaults to the largest value. */
    max?: number;
    emptyText?: string;
}) {
    if (items.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-muted-foreground">
                {emptyText}
            </p>
        );
    }

    const scale = max ?? Math.max(...items.map((item) => item.value), 1);

    return (
        <ul className="space-y-2.5">
            {items.map((item) => (
                <li
                    key={item.label}
                    title={item.detail}
                    className="grid grid-cols-[minmax(5rem,10rem)_1fr_auto] items-center gap-3 text-sm"
                >
                    <span className="flex items-center gap-1.5 truncate">
                        {item.critical && (
                            <TriangleAlert
                                aria-label="Regression"
                                className="size-3.5 shrink-0 text-(--viz-critical)"
                            />
                        )}
                        <span className="truncate">{item.label}</span>
                    </span>

                    <span
                        className="h-3 rounded-r-[4px] bg-transparent"
                        aria-hidden
                    >
                        <span
                            className={cn(
                                'block h-full rounded-r-[4px]',
                                item.critical
                                    ? 'bg-(--viz-critical)'
                                    : 'bg-(--viz-1)',
                            )}
                            style={{
                                width: `${Math.max((item.value / scale) * 100, item.value > 0 ? 1 : 0)}%`,
                            }}
                        />
                    </span>

                    <span className="min-w-12 text-right tabular-nums">
                        {item.display ?? item.value}
                        {item.detail && (
                            <span className="sr-only">, {item.detail}</span>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}
