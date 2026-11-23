export function Frame({ children, identity }) {
    return <><header className="mast"><a className="brand" href="/">Kepler Outpost</a><nav aria-label="Main">
        <a href="/">Colonies</a>{identity ? <span>{identity}</span> : <a href="/login">Sign in</a>}</nav></header>
        <main>{children}</main></>;
}

export async function post(path, body, token) {
    const response = await fetch(path, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify(body) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Request failed.');
    return result;
}
