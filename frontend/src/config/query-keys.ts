import type { SettingsGroupName } from '@/features/settings/types';

/**
 * Centralised TanStack Query keys.
 *
 * Defining them as a hierarchy in one place makes partial invalidation
 * reliable: `invalidateQueries({ queryKey: queryKeys.settings.all })` clears
 * every settings query including group-scoped ones, which is impossible to get
 * consistently right when keys are inline string arrays scattered across hooks.
 */

export const queryKeys = {
  auth: {
    all: ['auth'] as const,
    customer: () => ['auth', 'customer'] as const,
    admin: () => ['auth', 'admin'] as const,
  },

  admins: {
    all: ['admins'] as const,
    list: (filters?: Record<string, unknown>) =>
      filters ? (['admins', 'list', filters] as const) : (['admins', 'list'] as const),
    detail: (id: string) => ['admins', 'detail', id] as const,
    roles: () => ['admins', 'roles'] as const,
    permissions: () => ['admins', 'permissions'] as const,
  },

  settings: {
    all: ['settings'] as const,
    public: (group?: SettingsGroupName) =>
      group ? (['settings', 'public', group] as const) : (['settings', 'public'] as const),
  },

  menus: {
    all: ['menus'] as const,
    byLocation: (location: string) => ['menus', location] as const,
  },

  health: {
    all: ['health'] as const,
    liveness: () => ['health', 'liveness'] as const,
    readiness: () => ['health', 'readiness'] as const,
  },
} as const;
