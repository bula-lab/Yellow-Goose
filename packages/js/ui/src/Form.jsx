import { Fragment, useEffect, useId, useRef, useState } from 'react';
import { localUrl, responseData } from './http.js';

function numeric(field) { return field.type === 'number' || field.type === 'integer'; }

function toDisplay(value, field) {
    if (value == null) return field.type === 'boolean' ? false : '';
    if (numeric(field) && field.unit) return value / field.unit.factor;
    return value;
}

function canonical(fields, display) {
    return Object.fromEntries(Object.entries(fields).map(([name, field]) => {
        let value = display[name];
        if (numeric(field)) {
            value = value === '' ? null : Number(value) * (field.unit?.factor ?? 1);
            if (field.type === 'integer' && value !== null && Math.abs(value - Math.round(value)) < 1e-8) value = Math.round(value);
        }
        return [name, value];
    }));
}

export function Form({ fields, initialValues = {}, onSubmit, action, csrfToken, onChange, onSuccess,
    submitLabel = 'Zapisz', children }) {
    const id = useId();
    const [display, setDisplay] = useState(() => Object.fromEntries(Object.entries(fields)
        .map(([name, field]) => [name, toDisplay(initialValues[name] ?? (field.required ? field.choices?.[0] : null), field)])));
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const request = useRef(null);
    useEffect(() => () => request.current?.abort(), []);

    function change(name, value) {
        const next = { ...display, [name]: value };
        setDisplay(next);
        setErrors(old => ({ ...old, [name]: undefined }));
        setMessage('');
        onChange?.(canonical(fields, next));
    }
    async function submit(event) {
        event.preventDefault();
        if (request.current) return;
        const controller = request.current = new AbortController();
        setBusy(true);
        setMessage('');
        setErrors({});
        try {
            const values = canonical(fields, display);
            const result = onSubmit ? await onSubmit(values, controller.signal) : await responseData(await fetch(localUrl(action), {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken ?? '' },
                body: JSON.stringify(values), signal: controller.signal,
            }));
            if (!controller.signal.aborted) onSuccess?.(result);
        } catch (error) {
            if (!controller.signal.aborted) {
                setMessage(error.message || 'Nie udało się zapisać zmian.');
                setErrors(error.errors || {});
            }
        } finally {
            request.current = null;
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    function renderField([name, field]) {
        const fieldId = id + '-' + name;
        const error = errors[name];
        const props = { id: fieldId, name, required: field.type !== 'boolean' && field.required,
            'aria-invalid': error ? true : undefined, 'aria-describedby': error ? fieldId + '-error' : undefined };
        const factor = field.unit?.factor ?? 1;
        let control;
        if (field.choices) {
            control = <select {...props} value={display[name] ?? ''} onChange={e => {
                const choice = field.choices.map(item => toDisplay(item, field)).find(item => String(item) === e.target.value);
                change(name, choice ?? '');
            }}>
                {!field.required && <option value="">—</option>}
                {field.choices.map(choice => <option key={String(choice)} value={toDisplay(choice, field)}>{String(toDisplay(choice, field))}</option>)}
            </select>;
        } else if (field.type === 'boolean') {
            control = <input {...props} type="checkbox" checked={Boolean(display[name])} onChange={e => change(name, e.target.checked)} />;
        } else {
            control = <input {...props} type={numeric(field) ? 'number' : 'text'} value={display[name] ?? ''}
                step={numeric(field) ? (field.type === 'integer' ? 1 / factor : 'any') : undefined}
                min={field.min == null ? undefined : field.min / factor} max={field.max == null ? undefined : field.max / factor}
                minLength={field.minLength} maxLength={field.maxLength} onChange={e => change(name, e.target.value)} />;
        }
        return <div key={name}><label htmlFor={fieldId}>{field.label || name}{field.unit && ' (' + field.unit.label + ')'}</label>
            {control}{error && <p id={fieldId + '-error'} role="alert">{[].concat(error).join(' ')}</p>}</div>;
    }
    const groups = new Map();
    for (const entry of Object.entries(fields)) {
        const group = entry[1].group || '';
        if (!groups.has(group)) groups.set(group, []);
        groups.get(group).push(entry);
    }
    return <form onSubmit={submit} aria-busy={busy}>
        {[...groups].map(([group, entries]) => group ? <fieldset key={group}><legend>{group}</legend>{entries.map(renderField)}</fieldset>
            : <Fragment key="">{entries.map(renderField)}</Fragment>)}
        {children}{message && <p role="alert">{message}</p>}
        <button type="submit" disabled={busy}>{submitLabel}</button>
    </form>;
}
