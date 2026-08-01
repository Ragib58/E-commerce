import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Merge conditional class names, resolving Tailwind conflicts.
 *
 * `clsx` handles conditionals; `twMerge` resolves collisions so a later class
 * wins — `cn('p-2', 'p-4')` yields `p-4` rather than emitting both and letting
 * CSS source order decide. This is the standard shadcn/ui helper and every
 * generated component expects it at this path.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
