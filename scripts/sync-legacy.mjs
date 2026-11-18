import { readdir, readFile, writeFile } from 'node:fs/promises';

// Composer consumers do not install npm packages. Ship local copies from one source.
const resources = new URL('../packages/framework/resources/', import.meta.url);
let changed = false;
for (const name of ['react', 'vite']) {
    const source = new URL(`../packages/js/${name}/src/`, import.meta.url);
    for (const file of await readdir(source)) {
        const data = await readFile(new URL(file, source));
        const target = new URL(file, resources);
        if (process.argv.includes('--check')) {
            if (!(await readFile(target)).equals(data)) {
                console.error('Stale legacy resource: ' + file);
                changed = true;
            }
        } else await writeFile(target, data);
    }
}
if (changed) process.exitCode = 1;
