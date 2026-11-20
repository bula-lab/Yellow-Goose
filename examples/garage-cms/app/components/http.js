export async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const result = await response.json();
    if (!response.ok) {
        const error = new Error(result.error || 'Request failed.');
        error.errors = result.errors || {};
        error.status = response.status;
        throw error;
    }
    return result;
}

export function post(url, data, token, signal) {
    return request(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(data), signal });
}

export function money(quote) {
    return (quote.amountMinor / 100).toFixed(2) + ' ' + quote.currency;
}
