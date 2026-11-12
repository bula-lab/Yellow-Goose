import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { supervise } from '../packages/framework/resources/dev.mjs';

const supervisor = new URL('../packages/framework/resources/dev.mjs', import.meta.url).href;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
function isAlive(pid) {
    try { process.kill(pid, 0); return true; }
    catch (error) { if (error.code === 'ESRCH') return false; throw error; }
}
function worker(file, stubborn = false) {
    return { name: 'worker', command: process.execPath, args: ['-e',
        "require('fs').writeFileSync(" + JSON.stringify(file) + ",String(process.pid));"
        + (stubborn ? "process.on('SIGTERM',()=>{});" : '')
        + "setInterval(()=>{},1000);"] };
}

test('child failure terminates its sibling and propagates failure', { timeout: 8000 }, async () => {
    const dir = mkdtempSync(join(tmpdir(), 'gaska-dev-'));
    const pidFile = join(dir, 'pid');
    try {
        const code = await supervise([worker(pidFile), {
            name: 'failure', command: process.execPath, args: ['-e',
                "setInterval(()=>{if(require('fs').existsSync(" + JSON.stringify(pidFile) + "))process.exit(7)},10)"],
        }], { stdio: 'ignore', graceMs: 100 });
        assert.equal(code, 7);
        assert.equal(isAlive(Number(readFileSync(pidFile))), false);
    } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('a missing executable fails without leaving a child behind', { timeout: 8000 }, async () => {
    const code = await supervise([
        { name: 'missing', command: '/nonexistent/gaska-test-binary' },
        { name: 'peer', command: process.execPath, args: ['-e', 'setInterval(()=>{},1000)'] },
    ], { stdio: 'ignore', graceMs: 100 });
    assert.equal(code, 1);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
    test(signal + ' stops the supervisor and a stubborn child', { timeout: 8000 }, async () => {
        const dir = mkdtempSync(join(tmpdir(), 'gaska-dev-'));
        const pidFile = join(dir, 'pid');
        const source = 'import {supervise} from ' + JSON.stringify(supervisor) + ';'
            + 'process.exitCode=await supervise(' + JSON.stringify([worker(pidFile, true)])
            + ',{stdio:"ignore",graceMs:100});';
        const processUnderTest = spawn(process.execPath, ['--input-type=module', '-e', source], { stdio: 'ignore' });
        const closed = once(processUnderTest, 'close');
        try {
            const deadline = Date.now() + 4000;
            while (!existsSync(pidFile)) {
                assert.ok(Date.now() < deadline, 'worker did not start');
                await pause(10);
            }
            processUnderTest.kill(signal);
            const [code] = await closed;
            assert.equal(code, signal === 'SIGINT' ? 130 : 143);
            assert.equal(isAlive(Number(readFileSync(pidFile))), false);
        } finally {
            processUnderTest.kill('SIGKILL');
            if (existsSync(pidFile)) {
                const pid = Number(readFileSync(pidFile));
                if (isAlive(pid)) process.kill(pid, 'SIGKILL');
            }
            rmSync(dir, { recursive: true, force: true });
        }
    });
}
