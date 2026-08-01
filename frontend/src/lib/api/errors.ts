import type { ApiErrorResponse } from './types';

/**
 * A structured API failure.
 *
 * Carrying the status, machine-readable code, and per-field validation errors
 * lets callers branch precisely — `error.isValidationError` to map messages
 * onto a form, `error.status === 401` to redirect — instead of parsing
 * message strings.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly errors: Record<string, string[]>;
  readonly requestId: string | undefined;

  constructor(params: {
    message: string;
    status: number;
    code?: string;
    errors?: Record<string, string[]>;
    requestId?: string;
  }) {
    super(params.message);
    this.name = 'ApiError';
    this.status = params.status;
    this.code = params.code;
    this.errors = params.errors ?? {};
    this.requestId = params.requestId;

    // Restores the prototype chain so `instanceof ApiError` works after
    // TypeScript's ES5-target class downlevelling.
    Object.setPrototypeOf(this, ApiError.prototype);
  }

  static fromResponse(
    payload: ApiErrorResponse | null,
    status: number,
    requestId?: string,
  ): ApiError {
    return new ApiError({
      message: payload?.message ?? `Request failed with status ${status}.`,
      status,
      code: payload?.code,
      errors: payload?.errors,
      requestId,
    });
  }

  get isValidationError(): boolean {
    return this.status === 422;
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isNotFound(): boolean {
    return this.status === 404;
  }

  get isRateLimited(): boolean {
    return this.status === 429;
  }

  /**
   * Whether retrying could plausibly succeed. Drives TanStack Query's retry
   * predicate: retrying a 422 only wastes a round-trip.
   */
  get isRetryable(): boolean {
    return this.status === 0 || this.status === 408 || this.status === 429 || this.status >= 500;
  }

  /** First message for a field, for inline form display. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

/** Thrown when a request exceeds its timeout or the network is unreachable. */
export class NetworkError extends ApiError {
  constructor(message: string, cause?: unknown) {
    super({ message, status: 0, code: 'NETWORK_ERROR' });
    this.name = 'NetworkError';
    this.cause = cause;
    Object.setPrototypeOf(this, NetworkError.prototype);
  }
}
