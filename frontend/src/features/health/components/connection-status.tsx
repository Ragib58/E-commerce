'use client';

import { useReadiness } from '../hooks/use-health';
import type { DependencyCheck } from '../api';

/**
 * Live backend connection panel.
 *
 * Exercises the full client-side data path — TanStack Query, the typed API
 * client, Zod validation — so the frontend/backend link is verifiable in the
 * browser, not just from curl.
 */

const STATUS_STYLES: Record<string, string> = {
  ok: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  degraded: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  down: 'bg-red-500/10 text-red-600 dark:text-red-400',
};

export function ConnectionStatus() {
  const { data, isPending, isError, error, dataUpdatedAt } = useReadiness();

  return (
    <div className="rounded-lg border border-border bg-card p-6">
      <div className="flex items-center justify-between gap-4">
        <h2 className="text-sm font-semibold">API connection</h2>
        {data ? <StatusBadge status={data.readiness.status} /> : null}
      </div>

      {isPending ? (
        <p className="mt-4 text-sm text-muted-foreground">Checking backend…</p>
      ) : isError ? (
        <div className="mt-4 text-sm">
          <p className="font-medium text-destructive">Unable to reach the API</p>
          <p className="mt-1 text-muted-foreground">
            {error instanceof Error ? error.message : 'Unknown error.'}
          </p>
        </div>
      ) : (
        <>
          <ul className="mt-4 space-y-2 text-sm">
            {Object.entries(data.readiness.checks).map(([name, check]) => (
              <DependencyRow key={name} name={name} check={check} />
            ))}
          </ul>

          {dataUpdatedAt ? (
            <p className="mt-4 text-xs text-muted-foreground">
              Last checked {new Date(dataUpdatedAt).toLocaleTimeString()} · refreshes every 30s
            </p>
          ) : null}
        </>
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  return (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase ${
        STATUS_STYLES[status] ?? STATUS_STYLES.down
      }`}
    >
      {status}
    </span>
  );
}

function DependencyRow({ name, check }: { name: string; check: DependencyCheck }) {
  return (
    <li className="flex items-center justify-between gap-3 border-b border-border pb-2 last:border-0">
      <span className="flex items-center gap-2">
        <span
          aria-hidden="true"
          className={`size-2 rounded-full ${
            check.status === 'ok'
              ? 'bg-emerald-500'
              : check.status === 'degraded'
                ? 'bg-amber-500'
                : 'bg-red-500'
          }`}
        />
        <span className="capitalize">{name}</span>
        {!check.critical ? (
          <span className="text-xs text-muted-foreground">(optional)</span>
        ) : null}
      </span>
      <span className="text-muted-foreground">
        {check.latency_ms !== null ? `${check.latency_ms} ms` : check.status}
      </span>
    </li>
  );
}
