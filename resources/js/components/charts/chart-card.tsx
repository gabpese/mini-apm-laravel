import { Table2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

export type TableView = {
    columns: string[];
    rows: Array<Array<string | number>>;
};

/**
 * A titled panel for a chart. Every chart has a table view of the same data, so
 * the numbers never depend on seeing colors or hovering.
 */
export function ChartCard({
    title,
    description,
    table,
    children,
}: {
    title: string;
    description?: string;
    table?: TableView;
    children: ReactNode;
}) {
    const [showTable, setShowTable] = useState(false);
    const tableId = useId();

    return (
        <section className="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <header className="mb-3 flex items-start justify-between gap-4">
                <div>
                    <h3 className="text-sm font-medium">{title}</h3>
                    {description && (
                        <p className="text-xs text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>

                {table && (
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-pressed={showTable}
                        aria-controls={tableId}
                        onClick={() => setShowTable((value) => !value)}
                    >
                        <Table2 />
                        {showTable ? 'Chart' : 'Table'}
                    </Button>
                )}
            </header>

            <div id={tableId}>
                {table && showTable ? <DataTable {...table} /> : children}
            </div>
        </section>
    );
}

function DataTable({ columns, rows }: TableView) {
    return (
        <div className="max-h-72 overflow-auto">
            <table className="w-full text-sm">
                <thead className="sticky top-0 bg-card text-left text-xs text-muted-foreground">
                    <tr>
                        {columns.map((column, index) => (
                            <th
                                key={column}
                                className={
                                    index === 0
                                        ? 'py-1.5 pr-4 font-medium'
                                        : 'px-2 py-1.5 text-right font-medium'
                                }
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, rowIndex) => (
                        <tr
                            key={rowIndex}
                            className="border-t border-border/60"
                        >
                            {row.map((cell, index) => (
                                <td
                                    key={index}
                                    className={
                                        index === 0
                                            ? 'py-1.5 pr-4'
                                            : 'px-2 py-1.5 text-right tabular-nums'
                                    }
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
