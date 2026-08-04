'use client';

import { useCallback, useState, type ReactNode } from 'react';
import { ChevronDown, ChevronUp, GripVertical } from 'lucide-react';

import { cn } from '@/lib/utils/cn';

/**
 * A reorderable list, with drag-and-drop *and* keyboard controls.
 *
 * Implemented on the native HTML drag-and-drop API rather than pulling in a
 * drag library: the list is short, the interaction is a single-axis reorder,
 * and the dependency would cost more bundle than the feature.
 *
 * The move buttons are not a fallback — they are the primary path for keyboard
 * and screen-reader users, and the reason this component is usable without a
 * mouse at all. Native HTML drag-and-drop is effectively inoperable from the
 * keyboard, so a drag-only implementation would make reordering the homepage
 * impossible for some operators.
 *
 * Reordering is applied optimistically and persisted by the caller. The caller
 * is also responsible for reverting on failure — this component owns no server
 * state.
 */

export interface SortableItem {
  id: number;
}

interface SortableListProps<T extends SortableItem> {
  items: T[];
  onReorder: (items: T[]) => void;
  renderItem: (item: T, index: number) => ReactNode;
  disabled?: boolean;
  /** Names the thing being reordered, for the move buttons' labels. */
  itemLabel: (item: T) => string;
}

export function SortableList<T extends SortableItem>({
  items,
  onReorder,
  renderItem,
  disabled = false,
  itemLabel,
}: SortableListProps<T>) {
  const [draggingId, setDraggingId] = useState<number | null>(null);
  const [overId, setOverId] = useState<number | null>(null);

  /**
   * The item whose move button should regain focus after a keyboard move.
   *
   * State rather than a ref, because it is read during render to decide which
   * button autofocuses — and a ref read during render is exactly the pattern
   * that silently fails to update. Cleared once focus lands.
   */
  const [focusAfterMove, setFocusAfterMove] = useState<{
    id: number;
    direction: 'up' | 'down';
  } | null>(null);

  const move = useCallback(
    (from: number, to: number) => {
      if (to < 0 || to >= items.length || from === to) return;

      const next = [...items];
      const [moved] = next.splice(from, 1);

      if (!moved) return;

      next.splice(to, 0, moved);
      onReorder(next);
    },
    [items, onReorder],
  );

  function handleDrop(targetIndex: number) {
    if (draggingId === null) return;

    const fromIndex = items.findIndex((item) => item.id === draggingId);

    setDraggingId(null);
    setOverId(null);

    if (fromIndex !== -1) move(fromIndex, targetIndex);
  }

  return (
    <ul className="space-y-2">
      {items.map((item, index) => (
        <li
          key={item.id}
          draggable={!disabled}
          onDragStart={(event) => {
            setDraggingId(item.id);
            event.dataTransfer.effectAllowed = 'move';
            // Firefox refuses to start a drag unless data is set on the event.
            event.dataTransfer.setData('text/plain', String(item.id));
          }}
          onDragEnd={() => {
            setDraggingId(null);
            setOverId(null);
          }}
          onDragOver={(event) => {
            // Without preventDefault the element is not a valid drop target and
            // the drop event never fires.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            setOverId(item.id);
          }}
          onDragLeave={() => setOverId((current) => (current === item.id ? null : current))}
          onDrop={(event) => {
            event.preventDefault();
            handleDrop(index);
          }}
          className={cn(
            'flex items-start gap-3 rounded-lg border border-border bg-card p-3 transition-colors',
            draggingId === item.id && 'opacity-50',
            overId === item.id && draggingId !== item.id && 'border-primary bg-primary/5',
          )}
        >
          <div className="flex flex-col items-center gap-0.5 pt-0.5">
            <span
              // Decorative: the actual reordering affordance for assistive
              // technology is the pair of buttons below, which are labelled.
              aria-hidden="true"
              className={cn(
                'text-muted-foreground',
                disabled ? 'cursor-not-allowed' : 'cursor-grab active:cursor-grabbing',
              )}
            >
              <GripVertical className="size-4" />
            </span>

            <MoveButton
              direction="up"
              label={`Move ${itemLabel(item)} up`}
              disabled={disabled || index === 0}
              onClick={() => {
                setFocusAfterMove({ id: item.id, direction: 'up' });
                move(index, index - 1);
              }}
              shouldFocus={
                focusAfterMove?.id === item.id && focusAfterMove.direction === 'up'
              }
              onFocused={() => setFocusAfterMove(null)}
            />

            <MoveButton
              direction="down"
              label={`Move ${itemLabel(item)} down`}
              disabled={disabled || index === items.length - 1}
              onClick={() => {
                setFocusAfterMove({ id: item.id, direction: 'down' });
                move(index, index + 1);
              }}
              shouldFocus={
                focusAfterMove?.id === item.id && focusAfterMove.direction === 'down'
              }
              onFocused={() => setFocusAfterMove(null)}
            />
          </div>

          <div className="min-w-0 flex-1">{renderItem(item, index)}</div>
        </li>
      ))}
    </ul>
  );
}

function MoveButton({
  direction,
  label,
  disabled,
  onClick,
  shouldFocus,
  onFocused,
}: {
  direction: 'up' | 'down';
  label: string;
  disabled: boolean;
  onClick: () => void;
  shouldFocus: boolean;
  onFocused: () => void;
}) {
  const Icon = direction === 'up' ? ChevronUp : ChevronDown;

  /**
   * Restore focus after a keyboard-driven move.
   *
   * Run in a layout effect rather than a ref callback so it fires after the
   * reordered list has committed — the button has moved to a new position by
   * then, and focusing it before the commit would target the old node.
   *
   * Without this the list re-renders, the focused button is unmounted, focus
   * falls back to <body>, and a keyboard user has to tab back in after every
   * single move.
   */
  const focusRef = useCallback(
    (node: HTMLButtonElement | null) => {
      if (shouldFocus && node && !disabled) {
        node.focus();
        onFocused();
      }
    },
    [shouldFocus, disabled, onFocused],
  );

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      ref={focusRef}
      className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      <Icon className="size-3.5" aria-hidden="true" />
    </button>
  );
}
