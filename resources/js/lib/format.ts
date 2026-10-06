const number = new Intl.NumberFormat('en-US');
const compact = new Intl.NumberFormat('en-US', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** 1,284 */
export function formatNumber(value: number): string {
    return number.format(value);
}

/** 1,284 below 10K, then 12.9K, 4.2M. For axis ticks and tight spaces. */
export function formatCompact(value: number): string {
    return Math.abs(value) < 10_000
        ? number.format(value)
        : compact.format(value);
}

/** A rate between 0 and 1 as a percentage: 0.0473 -> 4.7% */
export function formatPercent(rate: number, digits = 1): string {
    return `${(rate * 100).toFixed(digits)}%`;
}

/** 2026-10-05 -> Oct 5. Parsed as a plain date, so the time zone cannot shift the day. */
export function formatDay(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

/** An ISO timestamp as a short local date and time. */
export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/** "3 hours ago", "2 days ago". */
export function formatRelative(iso: string, now: Date = new Date()): string {
    const seconds = Math.round(
        (new Date(iso).getTime() - now.getTime()) / 1000,
    );
    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    const formatter = new Intl.RelativeTimeFormat('en-US', { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(0, 'second');
}
