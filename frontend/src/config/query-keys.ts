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

  /*
   * Catalog.
   *
   * The hierarchy is what makes partial invalidation reliable: after editing a
   * product, `invalidateQueries({ queryKey: queryKeys.catalog.products.all })`
   * clears every product list regardless of its filters, without touching the
   * category tree that has not changed.
   */
  catalog: {
    all: ['catalog'] as const,

    products: {
      all: ['catalog', 'products'] as const,
      list: (filters?: Record<string, unknown>) =>
        filters
          ? (['catalog', 'products', 'list', filters] as const)
          : (['catalog', 'products', 'list'] as const),
      detail: (id: string) => ['catalog', 'products', 'detail', id] as const,
      variants: (productId: string) =>
        ['catalog', 'products', 'variants', productId] as const,
      stockHistory: (productId: string) =>
        ['catalog', 'products', 'stock-history', productId] as const,
    },

    categories: {
      all: ['catalog', 'categories'] as const,
      list: (filters?: Record<string, unknown>) =>
        filters
          ? (['catalog', 'categories', 'list', filters] as const)
          : (['catalog', 'categories', 'list'] as const),
      tree: () => ['catalog', 'categories', 'tree'] as const,
      detail: (id: string) => ['catalog', 'categories', 'detail', id] as const,
    },

    brands: {
      all: ['catalog', 'brands'] as const,
      list: (filters?: Record<string, unknown>) =>
        filters
          ? (['catalog', 'brands', 'list', filters] as const)
          : (['catalog', 'brands', 'list'] as const),
      detail: (id: string) => ['catalog', 'brands', 'detail', id] as const,
    },

    attributes: () => ['catalog', 'attributes'] as const,
    filters: () => ['catalog', 'filters'] as const,
  },

  inventory: {
    all: ['inventory'] as const,
    alerts: () => ['inventory', 'alerts'] as const,
    summary: () => ['inventory', 'summary'] as const,
    movements: (filters?: Record<string, unknown>) =>
      filters ? (['inventory', 'movements', filters] as const) : (['inventory', 'movements'] as const),
  },

  health: {
    all: ['health'] as const,
    liveness: () => ['health', 'liveness'] as const,
    readiness: () => ['health', 'readiness'] as const,
  },
} as const;
