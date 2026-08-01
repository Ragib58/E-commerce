import { apiClient } from '@/lib/api/client';
import {
  adminSchema,
  adminSessionSchema,
  customerSchema,
  customerSessionSchema,
  type AdminSession,
  type AdminUser,
  type Customer,
  type CustomerSession,
} from '../types';
import type {
  ChangePasswordInput,
  ForgotPasswordInput,
  LoginInput,
  RegisterInput,
  ResetPasswordInput,
  UpdateProfileInput,
} from '../schemas';

/**
 * Authentication endpoints for both realms.
 *
 * Every response is parsed through its schema, so a shape change in the API
 * fails loudly at the boundary rather than producing `undefined` deep inside a
 * component.
 *
 * Auth requests are never cached — `cache: 'no-store'` on the reads — because
 * a cached /me response would show a signed-out user as signed in.
 */

/* -------------------------------------------------------------------------- */
/* Customer                                                                   */
/* -------------------------------------------------------------------------- */

export async function register(input: RegisterInput): Promise<CustomerSession> {
  const result = await apiClient.post<unknown>('/auth/register', { body: input });

  return customerSessionSchema.parse(result.data);
}

export async function login(input: LoginInput): Promise<CustomerSession> {
  const result = await apiClient.post<unknown>('/auth/login', {
    body: { email: input.email, password: input.password },
  });

  return customerSessionSchema.parse(result.data);
}

export async function logout(): Promise<void> {
  await apiClient.post('/auth/logout');
}

export async function logoutAll(): Promise<void> {
  await apiClient.post('/auth/logout-all');
}

export async function fetchCurrentCustomer(): Promise<Customer> {
  const result = await apiClient.get<unknown>('/auth/me', { cache: 'no-store' });

  return customerSchema.parse(result.data);
}

export async function updateProfile(input: UpdateProfileInput): Promise<Customer> {
  const result = await apiClient.patch<unknown>('/auth/profile', { body: input });

  return customerSchema.parse(result.data);
}

export async function changePassword(input: ChangePasswordInput): Promise<void> {
  await apiClient.post('/auth/change-password', { body: input });
}

export async function forgotPassword(input: ForgotPasswordInput): Promise<string> {
  const result = await apiClient.post<unknown>('/auth/forgot-password', { body: input });

  return result.message;
}

export async function resetPassword(input: ResetPasswordInput): Promise<string> {
  const result = await apiClient.post<unknown>('/auth/reset-password', { body: input });

  return result.message;
}

export async function resendVerificationEmail(): Promise<string> {
  const result = await apiClient.post<unknown>('/auth/email/resend');

  return result.message;
}

/* -------------------------------------------------------------------------- */
/* Admin                                                                      */
/* -------------------------------------------------------------------------- */

export async function adminLogin(input: LoginInput): Promise<AdminSession> {
  const result = await apiClient.post<unknown>('/admin/auth/login', {
    body: { email: input.email, password: input.password },
  });

  return adminSessionSchema.parse(result.data);
}

export async function adminLogout(): Promise<void> {
  await apiClient.post('/admin/auth/logout');
}

export async function fetchCurrentAdmin(): Promise<AdminUser> {
  // Called on every panel load, which is what makes a role change take effect
  // on refresh rather than requiring the admin to sign out and back in.
  const result = await apiClient.get<unknown>('/admin/auth/me', { cache: 'no-store' });

  return adminSchema.parse(result.data);
}

export async function adminChangePassword(input: ChangePasswordInput): Promise<void> {
  await apiClient.post('/admin/auth/change-password', { body: input });
}

export async function adminForgotPassword(input: ForgotPasswordInput): Promise<string> {
  const result = await apiClient.post<unknown>('/admin/auth/forgot-password', { body: input });

  return result.message;
}

export async function adminResetPassword(input: ResetPasswordInput): Promise<string> {
  const result = await apiClient.post<unknown>('/admin/auth/reset-password', { body: input });

  return result.message;
}
