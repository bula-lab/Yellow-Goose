export async function api(url, { method = 'GET', data, csrfToken } = {}) {
    let response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(data === undefined ? {} : { 'Content-Type': 'application/json' }),
                ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
            },
            ...(data === undefined ? {} : { body: JSON.stringify(data) }),
        });
    } catch {
        throw new Error('Brak połączenia z serwerem. Spróbuj ponownie.');
    }
    if (response.status === 204) return null;
    const result = await response.json().catch(() => null);
    if (!response.ok) {
        const error = new Error(result?.error || 'Nie udało się wykonać operacji.');
        error.errors = result?.errors || {};
        error.status = response.status;
        throw error;
    }
    if (!result) throw new Error('Nieprawidłowa odpowiedź serwera.');
    return result;
}
