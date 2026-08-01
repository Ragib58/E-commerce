import { z } from 'zod';

/**
 * Validation schemas shared between React Hook Form and the request bodies.
 *
 * One schema per form means the client-side rules and the typed payload cannot
 * drift apart. The server revalidates everything — these exist to give
 * immediate feedback, not to be trusted.
 *
 * The password rule intentionally mirrors Laravel's `Password::defaults()`
 * (min 12, mixed classes). A mismatch here would be worse than no validation:
 * the user would pass the client check and then be rejected by the API for
 * reasons the form never explained.
 */

const email = z
  .string()
  .min(1, 'Email address is required.')
  .email('Enter a valid email address.')
  .max(255, 'Email address is too long.')
  .transform((value) => value.trim().toLowerCase());

const strongPassword = z
  .string()
  .min(12, 'Password must be at least 12 characters.')
  .max(255, 'Password is too long.')
  .regex(/[a-zA-Z]/, 'Password must contain at least one letter.')
  .regex(/\d/, 'Password must contain at least one number.')
  .regex(/[^a-zA-Z0-9]/, 'Password must contain at least one symbol.');

export const loginSchema = z.object({
  email,
  // Deliberately not `strongPassword`: applying the policy to a login form
  // would block an account created under an older policy from signing in.
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean().optional(),
});

export const registerSchema = z
  .object({
    name: z
      .string()
      .min(2, 'Name must be at least 2 characters.')
      .max(120, 'Name is too long.')
      .transform((value) => value.trim()),
    email,
    password: strongPassword,
    password_confirmation: z.string(),
    phone: z
      .string()
      .max(32, 'Phone number is too long.')
      .regex(/^[\d\s()+-]*$/, 'Phone number contains invalid characters.')
      .optional()
      .or(z.literal('')),
    accepts_terms: z.literal(true, {
      errorMap: () => ({ message: 'You must accept the terms and conditions.' }),
    }),
  })
  // Attached to the confirmation field so the message renders next to the
  // input the user must actually correct.
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  });

export const forgotPasswordSchema = z.object({ email });

export const resetPasswordSchema = z
  .object({
    token: z.string().min(1),
    email,
    password: strongPassword,
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  });

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, 'Your current password is required.'),
    password: strongPassword,
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
  .refine((data) => data.current_password !== data.password, {
    message: 'The new password must differ from your current password.',
    path: ['password'],
  });

export const updateProfileSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters.').max(120).optional(),
  phone: z
    .string()
    .max(32)
    .regex(/^[\d\s()+-]*$/, 'Phone number contains invalid characters.')
    .optional()
    .or(z.literal('')),
  date_of_birth: z.string().optional().or(z.literal('')),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>;
export type ResetPasswordInput = z.infer<typeof resetPasswordSchema>;
export type ChangePasswordInput = z.infer<typeof changePasswordSchema>;
export type UpdateProfileInput = z.infer<typeof updateProfileSchema>;
