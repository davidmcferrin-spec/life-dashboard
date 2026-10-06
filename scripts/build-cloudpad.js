#!/usr/bin/env node
'use strict';

const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const excalidrawProd = path.join(root, 'node_modules/@excalidraw/excalidraw/dist/excalidraw.production.min.js');

const shared = {
    bundle: true,
    minify: true,
    format: 'iife',
    platform: 'browser',
    loader: { '.jsx': 'jsx', '.js': 'jsx' },
    define: { 'process.env.NODE_ENV': '"production"' },
    banner: { js: 'var process={env:{NODE_ENV:"production"}};' },
    alias: {
        '@excalidraw/excalidraw': excalidrawProd,
    },
    logLevel: 'info',
};

function copyDir(src, dest) {
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
        const from = path.join(src, entry.name);
        const to = path.join(dest, entry.name);
        if (entry.isDirectory()) {
            copyDir(from, to);
        } else {
            fs.copyFileSync(from, to);
        }
    }
}

async function main() {
    await esbuild.build({
        ...shared,
        entryPoints: [path.join(root, 'resources/js/cloudpad-canvas.jsx')],
        outfile: path.join(root, 'public/assets/js/cloudpad-canvas.bundle.js'),
    });

    await esbuild.build({
        ...shared,
        entryPoints: [path.join(root, 'resources/js/share-cloudpad.jsx')],
        outfile: path.join(root, 'public/assets/js/share-cloudpad.bundle.js'),
    });

    const assetsSrc = path.join(root, 'node_modules/@excalidraw/excalidraw/dist/excalidraw-assets');
    const assetsDest = path.join(root, 'public/assets/excalidraw-assets');
    if (!fs.existsSync(assetsSrc)) {
        throw new Error('Excalidraw assets not found — run npm install first.');
    }
    fs.rmSync(assetsDest, { recursive: true, force: true });
    copyDir(assetsSrc, assetsDest);

    console.log('CloudPad bundles and assets written to public/assets/');
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
