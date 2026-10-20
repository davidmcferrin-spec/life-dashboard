/**
 * Capture docs/screenshots from the fictional demo account.
 *
 * Expects `php scripts/seed-demo.php` to have filled lifedash_demo.
 * Points the app at that database for the duration of the run, then
 * restores .env. Requires Playwright (`npm install --no-save playwright`
 * and `npx playwright install chromium`).
 */

import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile, rm } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const exec = promisify(execFile);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outDir = path.join(root, 'docs', 'screenshots');
const base = process.env.DEMO_BASE || 'http://127.0.0.1:8091';
const user = 'demo';
const password = 'screenshot-demo';
const envPath = path.join(root, '.env');

let server;
let envBackup;

const shots = [];

try {
    const { chromium } = await import('playwright');
    envBackup = await readFile(envPath);
    await writeFile(envPath, demoEnv(envBackup.toString('utf8')));
    server = startServer();
    await waitFor(base + '/login');
    await mkdir(outDir, { recursive: true });

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
        colorScheme: 'dark',
    });
    const page = await context.newPage();
    await login(page);

    await page.goto(base + '/', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.ld-habit-name');
    await page.waitForSelector('.ld-shop-name');
    await page.locator('[data-widget-type="shopping"]').getByText('oat milk').waitFor();
    await page.locator('[data-widget-type="calls"]').getByText('Avery Quinn').waitFor();
    await pause(page);
    await save(page, 'dashboard-jarvis', { fullPage: false });

    await page.setViewportSize({ width: 2560, height: 1080 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.ld-habit-name');
    await pause(page);
    await save(page, 'dashboard-ultrawide', { fullPage: false });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-widget-type="shopping"] .ld-shop-name');
    await page.locator('[data-widget-type="shopping"]').scrollIntoViewIfNeeded();
    await page.locator('[data-widget-type="shopping"]').screenshot({
        path: path.join(outDir, 'shopping.png'),
    });
    shots.push('shopping.png');
    await page.locator('[data-widget-type="calls"]').scrollIntoViewIfNeeded();
    await page.locator('[data-widget-type="calls"] [data-call-filter="all"]').click();
    await page.locator('[data-widget-type="calls"]').getByText('Harbor Clinic').waitFor();
    await page.locator('[data-widget-type="calls"]').screenshot({
        path: path.join(outDir, 'calls.png'),
    });
    shots.push('calls.png');

    await theme(page, 'light', '#00e5ff');
    await save(page, 'dashboard-light', { fullPage: false });
    await theme(page, 'dark', '#00e5ff');
    await save(page, 'dashboard-dark', { fullPage: false });
    await theme(page, 'jarvis', '#f0b429');
    await save(page, 'dashboard-amber', { fullPage: false });
    await theme(page, 'jarvis', '#00e5ff');

    await page.goto(base + '/todos', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('text=Draft the Friday note');
    await pause(page);
    await save(page, 'todos', { fullPage: true });

    await page.goto(base + '/settings/trackers', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('text=Walk');
    await pause(page);
    await save(page, 'trackers', { fullPage: false });

    await page.goto(base + '/settings/money', { waitUntil: 'domcontentloaded' });
    await page.locator('#money-accounts').getByText('Harbor card').waitFor();
    await page.locator('#money-tx').getByText('Corner market').waitFor();
    await pause(page);
    await save(page, 'money', { fullPage: true });

    await page.goto(base + '/brain', { waitUntil: 'domcontentloaded' });
    await page.locator('#brain-tree').getByText('Pantry staples').click();
    await page.waitForFunction(() => document.getElementById('brain-title')?.value === 'Pantry staples');
    await page.click('[data-brain-tab="preview"]');
    await page.locator('#brain-preview').getByText('freezer').waitFor();
    await pause(page);
    await save(page, 'vault', { fullPage: false });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + '/pantry', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('text=Sourdough');
    await pause(page);
    await save(page, 'pantry-phone', { fullPage: true });
    await page.goto(base + '/pantry?view=scan', { waitUntil: 'domcontentloaded' });
    await page.locator('#manual-upc').waitFor();
    await pause(page);
    await save(page, 'pantry-scan-phone', { fullPage: false });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(base + '/settings', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('text=JARVIS accent');
    await pause(page);
    await save(page, 'settings', { fullPage: false });

    await page.goto(base + '/settings/agents', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('text=ld_demopantry');
    await page.waitForSelector('text=ld_demomoney');
    // The copied prompt names the account owner. The token list is the shot.
    await page.evaluate(() => {
        const prompt = document.getElementById('agent-prompt');
        const block = prompt ? prompt.closest('section') : null;
        if (block) block.remove();
        const audit = document.getElementById('agent-audit');
        const auditBlock = audit ? audit.closest('section') : null;
        if (auditBlock) auditBlock.remove();
    });
    await pause(page);
    await save(page, 'settings-agents', { fullPage: false });

    await browser.close();
    await compress();
    process.stdout.write('Wrote ' + shots.length + ' screenshots in docs/screenshots\n');
} finally {
    if (server) {
        server.kill('SIGTERM');
    }
    if (envBackup) {
        await writeFile(envPath, envBackup);
    }
}

function demoEnv(text) {
    const lines = text.split('\n').map((line) => {
        if (line.startsWith('DB_NAME=')) return 'DB_NAME=lifedash_demo';
        if (line.startsWith('APP_URL=')) return 'APP_URL=http://127.0.0.1:8091';
        if (line.startsWith('MCP_PUBLIC_URL=')) return 'MCP_PUBLIC_URL=';
        if (line.startsWith('SESSION_SECURE=')) return 'SESSION_SECURE=false';
        return line;
    });
    if (!lines.some((line) => line.startsWith('DB_NAME='))) {
        throw new Error('.env has no DB_NAME');
    }
    return lines.join('\n');
}

function startServer() {
    const child = spawn('php', ['-S', '127.0.0.1:8091', '-t', 'public', 'scripts/dev-router.php'], {
        cwd: root,
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    child.stderr.on('data', () => {});
    child.stdout.on('data', () => {});
    return child;
}

async function waitFor(url) {
    const deadline = Date.now() + 15000;
    let last = 'not up';
    while (Date.now() < deadline) {
        try {
            const res = await fetch(url);
            if (res.status < 500) return;
            last = String(res.status);
        } catch (err) {
            last = err instanceof Error ? err.message : 'fetch failed';
        }
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    throw new Error('Server did not start: ' + last);
}

async function login(page) {
    await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#identifier', user);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15000 });
}

async function theme(page, name, accent) {
    await page.evaluate(async ({ name, accent }) => {
        localStorage.setItem('dashboard_theme', name);
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await fetch('/api/preferences', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ theme: name, jarvis_accent: accent }),
        });
        if (!res.ok) {
            throw new Error('Could not set theme');
        }
    }, { name, accent });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.ld-habit-name');
    await pause(page);
}

async function pause(page) {
    await page.waitForTimeout(700);
}

async function save(page, name, options) {
    const file = path.join(outDir, name + '.png');
    await page.screenshot({ path: file, fullPage: options.fullPage });
    shots.push(name + '.png');
}

async function compress() {
    for (const name of shots) {
        const png = path.join(outDir, name);
        const webp = png.replace(/\.png$/, '.webp');
        await exec('cwebp', ['-quiet', '-q', '72', png, '-o', webp]);
        const { size } = await import('node:fs').then((fs) => fs.statSync(webp));
        if (size > 400 * 1024) {
            await exec('cwebp', ['-quiet', '-q', '58', png, '-o', webp]);
        }
        await rm(png);
    }
}
