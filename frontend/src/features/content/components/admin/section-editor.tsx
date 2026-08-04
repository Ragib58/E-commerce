'use client';

import { useState } from 'react';

import { ApiError } from '@/lib/api/errors';
import type { AdminSection, SectionTypeOption } from '../../types';
import type { SectionInput } from '../../api/admin';
import { fromDateTimeLocal, toDateTimeLocal } from '../../lib/dates';
import { Checkbox, Field, ScheduleFields, Select, TextArea, TextInput } from './form-fields';

/**
 * The edit form for one homepage section.
 *
 * Renders the fields every section shares, plus a per-type settings panel
 * driven by the type's own default settings — which the API supplies. That is
 * what lets a new section type appear in this form with no change here: the
 * controls are derived from the keys the backend declares, not from a hardcoded
 * list of eleven forms.
 *
 * `type` is not editable. Changing a testimonial block into a product rail
 * would leave the old type's settings behind, and the merge-on-save semantics
 * mean they would never be cleared.
 */

interface SectionEditorProps {
  section: AdminSection;
  typeOption?: SectionTypeOption;
  onSave: (input: SectionInput) => Promise<unknown>;
  onCancel: () => void;
  isSaving: boolean;
}

export function SectionEditor({
  section,
  typeOption,
  onSave,
  onCancel,
  isSaving,
}: SectionEditorProps) {
  const [name, setName] = useState(section.name);
  const [heading, setHeading] = useState(section.heading ?? '');
  const [subheading, setSubheading] = useState(section.subheading ?? '');
  const [background, setBackground] = useState(section.style.background_color ?? '');
  const [width, setWidth] = useState(section.style.container_width || 'default');
  const [startsAt, setStartsAt] = useState(toDateTimeLocal(section.starts_at));
  const [endsAt, setEndsAt] = useState(toDateTimeLocal(section.ends_at));
  const [settings, setSettings] = useState<Record<string, unknown>>(section.settings);

  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});

    try {
      await onSave({
        name: name.trim(),
        // Empty strings are sent as null so clearing a heading actually clears
        // it — an empty string would be stored and rendered as a blank <h2>.
        heading: heading.trim() || null,
        subheading: subheading.trim() || null,
        background_color: background.trim() || null,
        container_width: width,
        starts_at: fromDateTimeLocal(startsAt),
        ends_at: fromDateTimeLocal(endsAt),
        settings,
      });
    } catch (saveError) {
      if (saveError instanceof ApiError) {
        setError(saveError.message);

        // Surface per-field messages against their controls. The API's own
        // wording explains *why* far better than a generic failure notice.
        const errors: Record<string, string> = {};

        for (const [key, messages] of Object.entries(saveError.errors ?? {})) {
          const first = messages[0];

          if (first) errors[key] = first;
        }

        setFieldErrors(errors);
      } else {
        setError('The section could not be saved.');
      }
    }
  }

  function updateSetting(key: string, value: unknown) {
    setSettings((current) => ({ ...current, [key]: value }));
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5 rounded-lg border border-border p-4">
      {error ? (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 p-3">
          <p className="text-sm text-destructive">{error}</p>
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label="Internal name"
          hint="Shown in this list only, never on the storefront."
          error={fieldErrors.name}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={name}
              describedBy={describedBy}
              onChange={(event) => setName(event.target.value)}
              required
              minLength={2}
              maxLength={120}
            />
          )}
        </Field>

        <Field label="Container width">
          {({ id }) => (
            <Select id={id} value={width} onChange={(event) => setWidth(event.target.value)}>
              <option value="default">Default</option>
              <option value="narrow">Narrow</option>
              <option value="wide">Wide</option>
              <option value="full">Full bleed</option>
            </Select>
          )}
        </Field>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label="Heading"
          hint="Shown to shoppers. Leave blank to hide the header."
          error={fieldErrors.heading}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={heading}
              describedBy={describedBy}
              onChange={(event) => setHeading(event.target.value)}
              maxLength={200}
            />
          )}
        </Field>

        <Field label="Subheading" error={fieldErrors.subheading}>
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={subheading}
              describedBy={describedBy}
              onChange={(event) => setSubheading(event.target.value)}
              maxLength={500}
            />
          )}
        </Field>
      </div>

      <Field
        label="Background colour"
        hint="A hex value such as #f5f5f5. Leave blank for the page background."
        error={fieldErrors.background_color}
      >
        {({ id, describedBy }) => (
          <div className="flex items-center gap-2">
            <TextInput
              id={id}
              value={background}
              describedBy={describedBy}
              placeholder="#f5f5f5"
              pattern="^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$"
              onChange={(event) => setBackground(event.target.value)}
              className="flex-1"
            />
            <input
              type="color"
              // Decorative companion to the text field, which remains the
              // accessible control — a colour input alone cannot express
              // "no background".
              aria-label="Pick a background colour"
              value={/^#[0-9a-fA-F]{6}$/.test(background) ? background : '#ffffff'}
              onChange={(event) => setBackground(event.target.value)}
              className="size-9 shrink-0 cursor-pointer rounded border border-border bg-background"
            />
          </div>
        )}
      </Field>

      <fieldset className="space-y-4 rounded-md border border-border p-3">
        <legend className="px-1 text-sm font-medium">Schedule</legend>
        <ScheduleFields
          startsAt={startsAt}
          endsAt={endsAt}
          onChange={(field, value) =>
            field === 'starts_at' ? setStartsAt(value) : setEndsAt(value)
          }
          disabled={isSaving}
        />
        {fieldErrors.ends_at ? (
          <p className="text-xs font-medium text-destructive">{fieldErrors.ends_at}</p>
        ) : null}
      </fieldset>

      <SectionSettingsFields
        type={section.type}
        settings={settings}
        typeOption={typeOption}
        errors={fieldErrors}
        onChange={updateSetting}
      />

      <div className="flex items-center gap-2">
        <button
          type="submit"
          disabled={isSaving}
          className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          {isSaving ? 'Saving…' : 'Save section'}
        </button>

        <button
          type="button"
          onClick={onCancel}
          disabled={isSaving}
          className="rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}

/**
 * Per-type settings controls.
 *
 * Handles the keys that need a purpose-built control, then falls back to
 * rendering whatever remains from the type's declared defaults. The fallback is
 * what keeps this form working when the backend introduces a new setting: the
 * key appears with a control matched to its value's type rather than being
 * silently uneditable.
 */
function SectionSettingsFields({
  type,
  settings,
  typeOption,
  errors,
  onChange,
}: {
  type: string;
  settings: Record<string, unknown>;
  typeOption?: SectionTypeOption;
  errors: Record<string, string>;
  onChange: (key: string, value: unknown) => void;
}) {
  // Keys with a bespoke control below, so the generic fallback skips them.
  const handled = new Set([
    'limit',
    'columns',
    'content',
    'items',
    'product_ids',
    'category_ids',
    'category_id',
    'image',
  ]);

  const declared = Object.keys(typeOption?.default_settings ?? settings);

  return (
    <fieldset className="space-y-4 rounded-md border border-border p-3">
      <legend className="px-1 text-sm font-medium">
        {typeOption?.label ?? type} settings
      </legend>

      {typeOption?.description ? (
        <p className="text-xs text-muted-foreground">{typeOption.description}</p>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        {declared.includes('limit') ? (
          <Field label="Maximum items" error={errors['settings.limit']}>
            {({ id }) => (
              <TextInput
                id={id}
                type="number"
                min={1}
                max={48}
                value={numberValue(settings.limit, 8)}
                onChange={(event) => onChange('limit', Number(event.target.value))}
              />
            )}
          </Field>
        ) : null}

        {declared.includes('columns') ? (
          <Field
            label="Columns"
            hint="The maximum on a wide screen; narrower screens step down."
            error={errors['settings.columns']}
          >
            {({ id, describedBy }) => (
              <TextInput
                id={id}
                type="number"
                min={1}
                max={6}
                describedBy={describedBy}
                value={numberValue(settings.columns, 4)}
                onChange={(event) => onChange('columns', Number(event.target.value))}
              />
            )}
          </Field>
        ) : null}
      </div>

      {declared.includes('content') ? (
        <Field
          label="Content"
          hint="Basic HTML is allowed. Scripts, styles, and event handlers are removed on save."
          error={errors['settings.content']}
        >
          {({ id, describedBy }) => (
            <TextArea
              id={id}
              rows={8}
              describedBy={describedBy}
              value={stringValue(settings.content)}
              onChange={(event) => onChange('content', event.target.value)}
              className="font-mono text-xs"
            />
          )}
        </Field>
      ) : null}

      {declared.includes('product_ids') ? (
        <Field
          label="Product IDs"
          hint="Comma-separated, in the order they should appear. Leave blank to use the category below, if set."
          error={errors['settings.product_ids']}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              describedBy={describedBy}
              value={idListValue(settings.product_ids)}
              placeholder="12, 34, 56"
              onChange={(event) => onChange('product_ids', parseIdList(event.target.value))}
            />
          )}
        </Field>
      ) : null}

      {declared.includes('category_ids') ? (
        <Field
          label="Category IDs"
          hint="Comma-separated. Leave blank to show the top-level categories."
          error={errors['settings.category_ids']}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              describedBy={describedBy}
              value={idListValue(settings.category_ids)}
              placeholder="1, 2, 3"
              onChange={(event) => onChange('category_ids', parseIdList(event.target.value))}
            />
          )}
        </Field>
      ) : null}

      {declared.includes('category_id') ? (
        <Field
          label="Fallback category ID"
          hint="Used when no product IDs are given, so the section stays populated as stock rotates."
          error={errors['settings.category_id']}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              type="number"
              min={1}
              describedBy={describedBy}
              value={settings.category_id == null ? '' : String(settings.category_id)}
              onChange={(event) =>
                onChange('category_id', event.target.value ? Number(event.target.value) : null)
              }
            />
          )}
        </Field>
      ) : null}

      {declared.includes('items') ? (
        <TestimonialEditor
          items={Array.isArray(settings.items) ? settings.items : []}
          onChange={(items) => onChange('items', items)}
        />
      ) : null}

      {/*
        Everything else the type declares. Booleans get a checkbox, strings and
        numbers get an input — so a setting added to the backend is editable
        here immediately, without waiting for a matching control to be written.
      */}
      <div className="grid gap-4 sm:grid-cols-2">
        {declared
          .filter((key) => !handled.has(key))
          .map((key) => {
            const value = settings[key] ?? typeOption?.default_settings?.[key];

            if (typeof value === 'boolean') {
              return (
                <Checkbox
                  key={key}
                  label={humanise(key)}
                  checked={value}
                  onChange={(checked) => onChange(key, checked)}
                />
              );
            }

            if (typeof value === 'number') {
              return (
                <Field key={key} label={humanise(key)} error={errors[`settings.${key}`]}>
                  {({ id }) => (
                    <TextInput
                      id={id}
                      type="number"
                      value={String(value)}
                      onChange={(event) => onChange(key, Number(event.target.value))}
                    />
                  )}
                </Field>
              );
            }

            return (
              <Field key={key} label={humanise(key)} error={errors[`settings.${key}`]}>
                {({ id }) => (
                  <TextInput
                    id={id}
                    value={value == null ? '' : String(value)}
                    onChange={(event) => onChange(key, event.target.value || null)}
                  />
                )}
              </Field>
            );
          })}
      </div>
    </fieldset>
  );
}

/**
 * Testimonials, edited inline.
 *
 * They live in the section's settings rather than a table of their own — see
 * HomepageService — so this is where they are managed.
 */
function TestimonialEditor({
  items,
  onChange,
}: {
  items: unknown[];
  onChange: (items: unknown[]) => void;
}) {
  const entries = items.filter(
    (item): item is Record<string, unknown> => typeof item === 'object' && item !== null,
  );

  function update(index: number, key: string, value: unknown) {
    onChange(entries.map((entry, i) => (i === index ? { ...entry, [key]: value } : entry)));
  }

  return (
    <div className="space-y-3">
      <p className="text-sm font-medium">Testimonials</p>

      {entries.map((entry, index) => (
        <div key={index} className="space-y-2 rounded-md border border-border p-3">
          <TextArea
            rows={2}
            aria-label={`Testimonial ${index + 1} quote`}
            placeholder="Quote"
            value={stringValue(entry.quote)}
            onChange={(event) => update(index, 'quote', event.target.value)}
          />

          <div className="grid gap-2 sm:grid-cols-3">
            <TextInput
              aria-label={`Testimonial ${index + 1} author`}
              placeholder="Author"
              value={stringValue(entry.author)}
              onChange={(event) => update(index, 'author', event.target.value)}
            />
            <TextInput
              aria-label={`Testimonial ${index + 1} role`}
              placeholder="Role"
              value={stringValue(entry.role)}
              onChange={(event) => update(index, 'role', event.target.value)}
            />
            <TextInput
              type="number"
              min={0}
              max={5}
              aria-label={`Testimonial ${index + 1} rating`}
              placeholder="Rating"
              value={entry.rating == null ? '' : String(entry.rating)}
              onChange={(event) =>
                update(index, 'rating', event.target.value ? Number(event.target.value) : null)
              }
            />
          </div>

          <button
            type="button"
            onClick={() => onChange(entries.filter((_, i) => i !== index))}
            className="text-xs font-medium text-destructive hover:underline"
          >
            Remove
          </button>
        </div>
      ))}

      <button
        type="button"
        onClick={() => onChange([...entries, { quote: '', author: '', role: '', rating: 5 }])}
        className="rounded-md border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted"
      >
        Add testimonial
      </button>
    </div>
  );
}

function stringValue(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function numberValue(value: unknown, fallback: number): string {
  return typeof value === 'number' ? String(value) : String(fallback);
}

function idListValue(value: unknown): string {
  return Array.isArray(value) ? value.join(', ') : '';
}

function parseIdList(value: string): number[] {
  return value
    .split(',')
    .map((part) => Number(part.trim()))
    .filter((id) => Number.isInteger(id) && id > 0);
}

function humanise(key: string): string {
  return key.replace(/_/g, ' ').replace(/^./, (char) => char.toUpperCase());
}
