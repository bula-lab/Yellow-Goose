import { useEffect, useId, useRef, useState } from 'react';
import { localUrl, responseData } from './http.js';

const firstQuery = () => ({ filters: {}, sort: null, direction: 'asc', cursor: null, previous: [] });

export function DataTable({ fields, sortable = [], filterable = [], endpoint, initialPage, rowKey = 'id',
    renderCell, limit = 25, state, caption = 'Wyniki' }) {
    const id = useId();
    const [query, setQuery] = useState(firstQuery);
    const [page, setPage] = useState(initialPage || { items: [], nextCursor: null });
    const [status, setStatus] = useState(initialPage ? 'ready' : 'loading');
    const [refresh, setRefresh] = useState(0);
    const first = useRef(true);
    const [draft, setDraft] = useState({});
    const tableKey = 'table:' + endpoint;
    useEffect(() => {
        if (first.current) {
            first.current = false;
            const saved = state?.get(tableKey);
            if (saved) { setDraft(saved.filters); setQuery(saved); return; }
            if (initialPage) return;
        }
        const controller = new AbortController();
        setStatus('loading');
        const url = localUrl(endpoint);
        url.searchParams.set('limit', String(limit));
        if (query.cursor != null) url.searchParams.set('cursor', query.cursor);
        if (query.sort) { url.searchParams.set('sort', query.sort); url.searchParams.set('direction', query.direction); }
        for (const [name, value] of Object.entries(query.filters)) if (value) url.searchParams.set('filter[' + name + ']', value);
        fetch(url, { credentials: 'same-origin', signal: controller.signal }).then(responseData).then(result => {
            if (!Array.isArray(result.items) || (result.nextCursor != null && typeof result.nextCursor !== 'string')) throw new Error('Invalid table response.');
            if (!controller.signal.aborted) { setPage(result); setStatus('ready'); }
        }).catch(() => { if (!controller.signal.aborted) setStatus('error'); });
        return () => controller.abort();
    }, [query, endpoint, limit, refresh, state, tableKey, initialPage]);

    function go(next) { state?.set(tableKey, next); setQuery(next); }
    return <section aria-busy={status === 'loading'}>
        {filterable.length > 0 && <form onSubmit={e => { e.preventDefault(); go({ ...query, filters: draft, cursor: null, previous: [] }); }}>
            {filterable.map(name => <label key={name} htmlFor={id + name}>{fields[name]?.label || name}
                <input id={id + name} value={draft[name] ?? ''} onChange={e => setDraft({ ...draft, [name]: e.target.value })} />
            </label>)}<button type="submit">Filtruj</button>
        </form>}
        {status === 'error' && <div role="alert">Nie udało się pobrać wyników. <button onClick={() => setRefresh(n => n + 1)}>Ponów</button></div>}
        <table><caption>{caption}</caption><thead><tr>{Object.keys(fields).map(name => <th key={name} scope="col"
            aria-sort={query.sort === name ? (query.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
            {sortable.includes(name) ? <button onClick={() => go({ ...query, sort: name,
                direction: query.sort === name && query.direction === 'asc' ? 'desc' : 'asc', cursor: null, previous: [] })}>
                {fields[name].label || name}</button> : (fields[name].label || name)}
        </th>)}</tr></thead><tbody>{page.items.map(row => <tr key={row[rowKey]}>{Object.keys(fields).map(name =>
            <td key={name}>{renderCell ? renderCell(name, row) : String(row[name] ?? '')}</td>)}</tr>)}</tbody></table>
        <button disabled={!query.previous.length || status === 'loading'} onClick={() =>
            go({ ...query, cursor: query.previous.at(-1), previous: query.previous.slice(0, -1) })}>Poprzednia</button>
        <button disabled={page.nextCursor == null || status === 'loading'} onClick={() =>
            go({ ...query, cursor: page.nextCursor, previous: [...query.previous, query.cursor] })}>Następna</button>
    </section>;
}
