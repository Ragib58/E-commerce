import { cn } from '@/lib/utils/cn';

/**
 * Renders admin-authored HTML from the CMS.
 *
 * The single place in the application where `dangerouslySetInnerHTML` is used,
 * deliberately concentrated here so the safety argument is made once and can be
 * audited in one file.
 *
 * **Why this is safe.** The HTML has already been reduced to a strict allowlist
 * by the backend's HtmlSanitiser *on write*, so the stored value is the safe
 * value — script, style, iframe, object, form, every `on*` handler, and any
 * href scheme outside http/https/mailto/tel are removed before the row is
 * saved. Sanitising again here would be theatre: this component receives the
 * same string the database holds, and a second pass on the client cannot make a
 * stored payload safer than the write-time filter already did.
 *
 * **What that assumes.** That nothing writes to `cms_pages.content` or a
 * custom-content section's `settings.content` except through CmsPageService and
 * HomepageService. A future import script or console command that bypasses them
 * would break this component's guarantee, which is why both services sanitise
 * in the service layer rather than in a form request.
 *
 * Styling is applied through descendant selectors rather than by injecting
 * classes into the markup: the authored HTML has no classes of ours, and
 * rewriting it to add them would mean parsing and re-serialising it here.
 */

interface RichTextProps {
  html: string | null | undefined;
  className?: string;
}

export function RichText({ html, className }: RichTextProps) {
  if (!html || html.trim() === '') return null;

  return (
    <div
      className={cn(
        'max-w-none text-sm leading-relaxed text-foreground sm:text-base',
        // Headings
        '[&_h1]:mt-8 [&_h1]:text-2xl [&_h1]:font-semibold [&_h1]:tracking-tight sm:[&_h1]:text-3xl',
        '[&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight sm:[&_h2]:text-2xl',
        '[&_h3]:mt-6 [&_h3]:text-lg [&_h3]:font-semibold',
        '[&_h4]:mt-6 [&_h4]:text-base [&_h4]:font-semibold',
        '[&_h1:first-child]:mt-0 [&_h2:first-child]:mt-0 [&_h3:first-child]:mt-0',
        // Text
        '[&_p]:my-4 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0',
        '[&_strong]:font-semibold',
        '[&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:opacity-80',
        // Lists
        '[&_ul]:my-4 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-4 [&_ol]:list-decimal [&_ol]:pl-6',
        '[&_li]:my-1',
        // Blocks
        '[&_blockquote]:my-6 [&_blockquote]:border-l-4 [&_blockquote]:border-border [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-muted-foreground',
        '[&_hr]:my-8 [&_hr]:border-border',
        '[&_pre]:my-4 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-muted [&_pre]:p-4 [&_pre]:text-xs',
        '[&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.9em]',
        '[&_pre_code]:bg-transparent [&_pre_code]:p-0',
        // Media. Images are constrained rather than allowed to overflow, and
        // the sanitiser has already added loading="lazy" to each one.
        '[&_img]:my-4 [&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
        '[&_figure]:my-6 [&_figcaption]:mt-2 [&_figcaption]:text-xs [&_figcaption]:text-muted-foreground',
        // Tables must scroll inside their own box rather than widening the page
        // on a phone — the wrapper below is what provides that.
        '[&_table]:w-full [&_table]:border-collapse [&_table]:text-left [&_table]:text-sm',
        '[&_th]:border [&_th]:border-border [&_th]:bg-muted [&_th]:p-2 [&_th]:font-semibold',
        '[&_td]:border [&_td]:border-border [&_td]:p-2',
        className,
      )}
    >
      {/* Sanitised on write by the backend — see the file docblock for the
          full safety argument and what it assumes. */}
      <div
        className="[&>table]:block [&>table]:overflow-x-auto"
        dangerouslySetInnerHTML={{ __html: html }}
      />
    </div>
  );
}
