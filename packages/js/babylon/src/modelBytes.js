export async function modelBytes(projectId, assetVersionId, descriptor, resolveAsset, signal) {
    function modelUrl(value) {
        if (value?.status !== 'ready' || value.assetVersionId !== assetVersionId || (value.projectId && value.projectId !== projectId) || !value.modelUrl) {
            throw new Error('Model version unavailable.');
        }
        const url = new URL(value.modelUrl, location.href);
        if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Invalid model URL.');
        return url;
    }
    let url = descriptor?.modelUrl ? modelUrl(descriptor) : null;
    const expiry = url?.searchParams.get('exp');
    if (url && (!resolveAsset || !expiry || Number(expiry) > Date.now() / 1000)) {
        const response = await fetch(url, { credentials: 'same-origin', signal });
        if (response.ok) return new Uint8Array(await response.arrayBuffer());
        if (!resolveAsset || ![401, 403, 404].includes(response.status)) throw new Error('Cannot fetch model.');
    }
    if (!resolveAsset) throw new Error('Missing model descriptor.');
    url = modelUrl(await resolveAsset(projectId, assetVersionId, signal));
    const renewed = await fetch(url, { credentials: 'same-origin', signal });
    if (!renewed.ok) throw new Error('Cannot fetch model.');
    return new Uint8Array(await renewed.arrayBuffer());
}
