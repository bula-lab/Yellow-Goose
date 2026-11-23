export async function responseData(response) {
    if (!response.ok) {
        const data = await response.json().catch(() => null);
        const error = new Error(typeof data?.error === 'string' ? data.error : 'Request failed.');
        error.status = response.status;
        error.errors = data?.errors || {};
        throw error;
    }
    return response.json();
}

export function localUrl(path) {
    const url = new URL(path, window.location.href);
    if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Expected a same-origin application URL.');
    }
    return url;
}
