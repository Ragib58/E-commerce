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

  /*
   * Storefront content — the homepage builder, banners, CMS pages.
   *
   * Nested under one root so saving a section can invalidate the homepage and
   * its preview together, without touching the banner list that has not
   * changed.
   */
  content: {
    all: ['content'] as const,

    homepage: {
      all: ['content', 'homepage'] as const,
      sections: () => ['content', 'homepage', 'sections'] as const,
      preview: (at?: string) =>
        at
          ? (['content', 'homepage', 'preview', at] as const)
          : (['content', 'homepage', 'preview'] as const),
    },

    banners: {
      all: ['content', 'banners'] as const,
      list: (filters?: Record<string, unknown>) =>
        filters
          ? (['content', 'banners', 'list', filters] as const)
          : (['content', 'banners', 'list'] as const),
    },

    pages: {
      all: ['content', 'pages'] as const,
      list: (filters?: Record<string, unknown>) =>
        filters
          ? (['content', 'pages', 'list', filters] as const)
          : (['content', 'pages', 'list'] as const),
      detail: (slug: string) => ['content', 'pages', 'detail', slug] as const,
    },
  },

  /*
   * The shopping cart.
   *
   * A single key: there is exactly one cart per visitor, and every mutation
   * returns the whole recomputed cart which is written straight into this
   * entry. No per-line keys, because a line is never fetched on its own.
   */
  cart: {
    all: ['cart'] as const,
    detail: () => ['cart', 'detail'] as const,
  },

  /*
   * Wishlist, compare, and recently viewed.
   *
   * The guest variants are keyed on the identifier list itself rather than a
   * constant. That is deliberate: those lists live in localStorage, so there is
   * no server event to invalidate on — keying on the contents means removing an
   * item refetches instead of serving a cached page that still contains it.
   */
  wishlist: {
    all: ['wishlist'] as const,
    list: () => ['wishlist', 'list'] as const,
    guest: (identifiers: string[]) => ['wishlist', 'guest', identifiers] as const,
  },

  compare: {
    all: ['compare'] as const,
    products: (identifiers: string[]) => ['compare', 'products', identifiers] as const,
  },

  recentlyViewed: {
    all: ['recently-viewed'] as const,
    products: (identifiers: string[]) => ['recently-viewed', 'products', identifiers] as const,
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
