import { useId, useLayoutEffect, useRef } from 'react';

export function Dialog({ open, onClose, title, children }) {
    const dialog = useRef(null);
    const label = useId();
    useLayoutEffect(() => {
        const node = dialog.current;
        if (!open || !node) return;
        const previous = document.activeElement;
        node.removeAttribute('open');
        node.showModal();
        // Native top-layer modality makes the rest of the document inert.
        return () => {
            node.close();
            if (previous?.isConnected && previous !== document.body) previous.focus({ preventScroll: true });
        };
    }, [open]);
    if (!open) return null;
    const stop = event => event.stopPropagation();
    return <dialog open ref={dialog} aria-labelledby={label} onCancel={event => { event.preventDefault(); onClose?.(); }}
        onKeyDown={event => {
            event.stopPropagation();
            if (event.key !== 'Tab') return;
            const controls = [...dialog.current.querySelectorAll('button, input, select, textarea, a[href], [tabindex]')]
                .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
            if (!controls.length) { event.preventDefault(); return; }
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }} onKeyUp={stop} onPointerDown={stop} onWheel={stop}>
        <h2 id={label}>{title}</h2>{children}
        <button type="button" onClick={() => onClose?.()}>Zamknij</button>
    </dialog>;
}
