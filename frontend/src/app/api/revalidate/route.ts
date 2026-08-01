import { createHash, timingSafeEqual as nodeTimingSafeEqual } from 'node:crypto';
import { revalidateTag } from 'next/cache';
import { NextResponse, type NextRequest } from 'next/server';
import { z } from 'zod';
import { isValidCacheTag } from '@/config/cache';

// node:crypto requires the Node.js runtime; this route would fail on the Edge
// runtime, which Next.js may otherwise select.
export const runtime = 'nodejs';

/**
 * Cache invalidation webhook.
 *
 * Laravel calls this whenever admin-managed content changes, which is what
 * lets a settings edit appear on the storefront immediately instead of waiting
 * out the ISR window. Without it, the "everything is dynamic" guarantee would
 * be true of the data but not of what visitors actually see.
 *
 * Authenticated with a shared secret compared in constant time — a plain `===`
 * on a secret leaks its length and prefix through timing.
 */

const revalidateSchema = z.object({
  tags: z.array(z.string()).min(1).max(20),
  keys: z.array(z.string()).optional(),
});

function timingSafeEqual(provided: string, expected: string): boolean {
  // Hash both sides first so the comparison always runs over equal-length,
  // fixed-size digests. This removes the length check that would otherwise
  // leak the secret's length, and makes the loop's duration independent of
  // where the first mismatching byte falls.
  const a = createHash('sha256').update(provided).digest();
  const b = createHash('sha256').update(expected).digest();

  return nodeTimingSafeEqual(a, b);
}

export async function POST(request: NextRequest) {
  const secret = process.env.REVALIDATION_SECRET;

  // Fail closed: an unset secret must not mean "allow everyone".
  if (!secret) {
    console.error('[revalidate] REVALIDATION_SECRET is not configured; refusing the request.');

    return NextResponse.json(
      { success: false, message: 'Revalidation is not configured.' },
      { status: 503 },
    );
  }

  const provided = request.headers.get('X-Revalidation-Secret') ?? '';

  if (!timingSafeEqual(provided, secret)) {
    return NextResponse.json({ success: false, message: 'Invalid secret.' }, { status: 401 });
  }

  let body: unknown;

  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ success: false, message: 'Malformed JSON body.' }, { status: 400 });
  }

  const parsed = revalidateSchema.safeParse(body);

  if (!parsed.success) {
    return NextResponse.json(
      { success: false, message: 'Invalid payload.', errors: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // Only known tags are honoured, so a compromised or buggy caller cannot
  // purge arbitrary cache entries.
  const validTags = parsed.data.tags.filter(isValidCacheTag);
  const rejected = parsed.data.tags.filter((tag) => !isValidCacheTag(tag));

  for (const tag of validTags) {
    // Next.js 16 requires an explicit cache profile. 'max' purges every
    // cached entry carrying the tag regardless of its remaining lifetime,
    // which is the intent here — an administrator has changed the content and
    // the old copy is simply wrong.
    revalidateTag(tag, 'max');
  }

  if (rejected.length > 0) {
    console.warn('[revalidate] Ignored unknown cache tags.', rejected);
  }

  return NextResponse.json({
    success: true,
    message: 'Cache revalidated.',
    data: { revalidated: validTags, ignored: rejected },
  });
}
