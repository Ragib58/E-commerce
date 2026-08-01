/**
 * Shapes of the Laravel API envelope.
 *
 * Every endpoint returns one of these two shapes, so a consumer can discriminate
 * on `success` without inspecting the HTTP status.
 */

export interface ApiSuccessResponse<TData> {
  success: true;
  message: string;
  data: TData;
  meta?: ApiMeta;
}

export interface ApiErrorResponse {
  success: false;
  message: string;
  code?: string;
  errors?: Record<string, string[]>;
  debug?: unknown;
}

export type ApiResponse<TData> = ApiSuccessResponse<TData> | ApiErrorResponse;

export interface ApiMeta {
  version?: string;
  groups?: string[];
  pagination?: ApiPagination;
  [key: string]: unknown;
}

export interface ApiPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/** A successful response with its metadata preserved. */
export interface ApiResult<TData> {
  data: TData;
  meta: ApiMeta;
  message: string;
}

export interface RequestOptions extends Omit<RequestInit, 'body' | 'method'> {
  /** Query string parameters. Undefined and null values are omitted. */
  params?: Record<string, string | number | boolean | undefined | null>;

  /** JSON request body. Serialised automatically. */
  body?: unknown;

  /** Milliseconds before the request is aborted. Defaults to 10000. */
  timeout?: number;

  /**
   * Next.js caching directives. `revalidate` sets the ISR window; `tags`
   * enable targeted invalidation via revalidateTag from the webhook Laravel
   * calls when admin content changes.
   */
  next?: {
    revalidate?: number | false;
    tags?: string[];
  };
}
