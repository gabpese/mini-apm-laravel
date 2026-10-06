export type ProjectRef = {
    id: number;
    name: string;
};

export type ProjectSummary = ProjectRef & {
    sessions: number;
    crashes: number;
    crash_rate: number;
    latest_version: string | null;
    regression: boolean;
};

export type Totals = {
    sessions: number;
    users: number;
    errors: number;
    crashes: number;
    crash_rate: number;
};

export type DailyRow = {
    date: string;
    sessions: number;
    errors: number;
    crashes: number;
};

export type FeatureRow = {
    name: string;
    uses: number;
};

export type DistributionRow = {
    label: string;
    users: number;
};

export type Environment = {
    users: number;
    below_minimum: number;
    below_ram: number;
    below_os: number;
    os: DistributionRow[];
    ram: DistributionRow[];
    gpu: DistributionRow[];
};

/** The newest version's regression against the one before it. */
export type Regression = {
    version: string;
    crash_rate: number;
    previous_version: string;
    previous_rate: number;
    ratio: number | null;
};

export type VersionRow = {
    version: string;
    sessions: number;
    users: number;
    crashes: number;
    crash_rate: number;
    regression: {
        previous_version: string;
        previous_rate: number;
        ratio: number | null;
    } | null;
};

export type Adoption = {
    versions: string[];
    rows: Array<Record<string, number | string>>;
};

export type ErrorGroupRow = {
    id: number;
    message: string;
    occurrences: number;
    crashes: number;
    first_seen_at: string;
    last_seen_at: string;
};

export type ApiKeyRow = {
    id: number;
    name: string | null;
    prefix: string;
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
};

export type ProjectSettings = ProjectRef & {
    min_ram_mb: number | null;
    min_os: string | null;
    regression_ratio: number;
    regression_min_sessions: number;
};

/** A key that was just created: the only time its full text exists. */
export type NewKey = {
    name: string | null;
    key: string;
};

/** Props shared by the project report pages. */
export type ReportProps = {
    project: ProjectRef;
    days: number;
    periods: number[];
};
