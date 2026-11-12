import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

// A child exiting (even with code 0) means the development stack is no longer usable.
export function supervise(commands, { cwd, env = process.env, stdio = 'inherit', graceMs = 2000 } = {}) {
    return new Promise(resolveExit => {
        const children = [];
        let remaining = commands.length;
        let stopping = false;
        let exitCode = 1;
        let killTimer;
        function stop(code) {
            if (stopping) return;
            stopping = true;
            exitCode = code;
            for (const child of children) if (child.exitCode === null) child.kill('SIGTERM');
            killTimer = setTimeout(() => {
                for (const child of children) if (child.exitCode === null) child.kill('SIGKILL');
            }, graceMs);
            killTimer.unref();
        }
        const interrupt = () => stop(130);
        const terminate = () => stop(143);
        process.on('SIGINT', interrupt);
        process.on('SIGTERM', terminate);
        for (const { name, command, args = [] } of commands) {
            const child = spawn(command, args, { cwd, env, stdio, shell: false });
            children.push(child);
            child.on('error', error => {
                console.error(name + ': ' + error.message);
                stop(1);
            });
            child.on('exit', (code, signal) => {
                if (!stopping) {
                    console.error(name + ' zakończył pracę (' + (signal ?? code) + '). Zatrzymywanie środowiska.');
                    stop(code || 1);
                }
            });
            child.on('close', () => {
                if (--remaining === 0) {
                    clearTimeout(killTimer);
                    process.removeListener('SIGINT', interrupt);
                    process.removeListener('SIGTERM', terminate);
                    resolveExit(exitCode);
                }
            });
        }
    });
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    const [root, php, host = '127.0.0.1', port = '8000', vitePort = '5173'] = process.argv.slice(2);
    try {
        const require = createRequire(resolve(root, 'package.json'));
        const vite = resolve(dirname(require.resolve('vite/package.json')), 'bin/vite.js');
        const env = { ...process.env, GASKA_VITE_PORT: vitePort,
            GASKA_VITE_DEV_SERVER: 'http://127.0.0.1:' + vitePort };
        console.log('Uruchamianie PHP: http://' + host + ':' + port + ' oraz Vite: ' + env.GASKA_VITE_DEV_SERVER);
        process.exitCode = await supervise([
            { name: 'Vite', command: process.execPath, args: [vite, '--host', '127.0.0.1', '--port', vitePort, '--strictPort'] },
            { name: 'PHP', command: php, args: ['-S', host + ':' + port, '-t', root + '/public', root + '/public/router.php'] },
        ], { cwd: root, env });
    } catch (error) {
        console.error('Nie można uruchomić development: ' + error.message);
        process.exitCode = 1;
    }
}
