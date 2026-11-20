export async function responseData(response) {
    const data = await response.json();
    if (!response.ok) {
        const error = new Error(typeof data.error === 'string' ? data.error : 'Request failed.');
        error.status = response.status;
        error.errors = data.errors || {};
        throw error;
    }
    return data;
}

export function localUrl(path) {
    const url = new URL(path, window.location.href);
    if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Expected a same-origin application URL.');
    }
    return url;
}
