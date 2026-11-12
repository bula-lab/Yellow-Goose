import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

process.env.NODE_ENV ??= 'production';

try {
    const chunks = [];
    let size = 0;
    for await (const chunk of process.stdin) {
        size += chunk.length;
        if (size > 1048576) throw new Error('SSR input limit exceeded');
        chunks.push(chunk);
    }
    const { page, props } = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    if (typeof page !== 'string' || !props || typeof props !== 'object' || Array.isArray(props)) {
        throw new Error('Invalid SSR payload');
    }
    const { render } = await import(pathToFileURL(resolve(process.argv[2])).href);
    const html = await render(page, props);
    if (typeof html !== 'string') throw new Error('SSR render must return HTML');
    process.stdout.write(JSON.stringify({ html }));
} catch (error) {
    console.error(error);
    process.exitCode = 1;
}
