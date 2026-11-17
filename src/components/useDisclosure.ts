/**
 * Shared interaction state for the dashboard header popovers.
 *
 * The account menu and the notification bell both open on mouse hover, pin on
 * click/keyboard, close on outside interaction, and return focus to the trigger
 * on Escape. Keeping that small state machine here prevents the two header
 * widgets from drifting apart while letting each keep its own ARIA role and
 * markup.
 */

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FocusEvent as ReactFocusEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type PointerEvent as ReactPointerEvent,
} from 'react';

export function useDisclosure({ focusSelector }: { focusSelector: string }) {
  const [open, setOpen] = useState(false);
  const [pinned, setPinned] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const focusFirstOnOpen = useRef(false);

  const close = useCallback((refocusTrigger = false) => {
    setOpen(false);
    setPinned(false);

    if (refocusTrigger) {
      triggerRef.current?.focus();
    }
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onDocumentPointerDown = (event: PointerEvent) => {
      const wrapper = wrapperRef.current;
      if (wrapper && !wrapper.contains(event.target as Node)) {
        setOpen(false);
        setPinned(false);
      }
    };

    document.addEventListener('pointerdown', onDocumentPointerDown);
    return () => document.removeEventListener('pointerdown', onDocumentPointerDown);
  }, [open]);

  useEffect(() => {
    if (open && focusFirstOnOpen.current) {
      focusFirstOnOpen.current = false;
      menuRef.current?.querySelector<HTMLElement>(focusSelector)?.focus();
    }
  }, [open, focusSelector]);

  const onPointerEnter = (event: ReactPointerEvent<HTMLDivElement>) => {
    // Touch and pen "enter" is not hover intent; only a mouse should pre-open.
    if (event.pointerType === 'mouse') {
      setOpen(true);
    }
  };

  const onPointerLeave = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.pointerType !== 'mouse' || pinned) {
      return;
    }

    const active = document.activeElement;
    if (active instanceof HTMLElement && wrapperRef.current?.contains(active)) {
      return;
    }

    setOpen(false);
  };

  const onBlur = (event: ReactFocusEvent<HTMLDivElement>) => {
    const next = event.relatedTarget;

    /*
     * `relatedTarget === null` is ambiguous: Safari does not focus a link on
     * mousedown, so clicking a menu item can blur with no related target. The
     * document-level pointerdown handler already covers "clicked elsewhere".
     */
    if (!(next instanceof HTMLElement) || wrapperRef.current?.contains(next)) {
      return;
    }

    close();
  };

  const onTriggerClick = () => {
    if (open && pinned) {
      close();
      return;
    }

    setOpen(true);
    setPinned(true);
  };

  const onTriggerKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>) => {
    if (event.key !== 'ArrowDown') {
      return;
    }

    event.preventDefault();
    focusFirstOnOpen.current = true;
    setOpen(true);
    setPinned(true);
  };

  const onMenuKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'Escape') {
      return;
    }

    event.preventDefault();
    close(true);
  };

  return {
    open,
    wrapperRef,
    triggerRef,
    menuRef,
    close,
    onPointerEnter,
    onPointerLeave,
    onBlur,
    onTriggerClick,
    onTriggerKeyDown,
    onMenuKeyDown,
  };
}
