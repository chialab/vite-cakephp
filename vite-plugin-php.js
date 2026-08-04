/**
 * @import { Plugin } from 'vite'
 * @import { ManifestEntry, EntryInput, EntrySlots, BuildGroup } from './vite-plugin-php';
 */

import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import process from 'node:process';
import { build, mergeConfig } from 'vite';

const VIRTUAL_ENTRY_ID = 'vite-plugin-php:entry.js';

/**
 * Normalizes an input into explicit slots (string shortcut -> by extension).
 * @param {EntryInput} input
 * @returns {EntrySlots}
 */
function slotsOf(input) {
    if (typeof input === 'string') {
        return input.endsWith('.css') ? { css: input } : { js: input };
    }
    return input;
}

/**
 * Shared plugin. In build mode it runs one nested build per group/input,
 * re-emits their outputs into the host bundle, and writes the unified manifest
 * to `.vite/php.json` with `mode: "production"`. In dev mode it writes the
 * same file with `mode: "development"` plus origin/base/inputs, and removes it on exit.
 * @param {BuildGroup[]} inputs Project entries (used to write the name→source map into the hot file).
 * @returns {Plugin}
 */
export function vitePhp(inputs) {
    /**
     * @type {Record<string, EntrySlots>}
     */
    const mappedInputs = {};
    for (const group of inputs) {
        for (const [local, input] of Object.entries(group.inputs)) {
            mappedInputs[`${group.name}/${local}`] = slotsOf(input);
        }
    }

    let cleanupRegistered = false;
    let userConfig = null;

    return {
        name: 'vite-plugin-php',
        enforce: 'pre',

        // Ensures the native manifest without having to remember it in every config.
        config(config) {
            userConfig = config;

            return {
                build: {
                    manifest: true,
                    lib: {
                        entry: `./${VIRTUAL_ENTRY_ID}`,
                        formats: ['es'],
                        fileName: 'index',
                    },
                },
            };
        },

        configureServer(server) {
            const outDir = userConfig.build?.outDir ?? 'dist';
            const hotFile = resolve(outDir, '.vite/php.json');
            const writeHot = () => {
                // resolvedUrls is populated after listen: the actual port is only
                // known here (Vite may auto-increment it if busy).
                const local = server.resolvedUrls?.local?.[0];
                if (!local) {
                    return;
                }
                const url = new URL(local);
                mkdirSync(dirname(hotFile), { recursive: true });
                writeFileSync(
                    hotFile,
                    JSON.stringify(
                        {
                            mode: 'development',
                            origin: url.origin,
                            base: url.pathname,
                            inputs: mappedInputs,
                        },
                        null,
                        2
                    )
                );
            };

            const removeHot = () => {
                try {
                    rmSync(hotFile, { force: true });
                } catch {
                    /* ignore */
                }
            };

            server.httpServer?.once('listening', writeHot);

            if (!cleanupRegistered) {
                cleanupRegistered = true;
                process.once('exit', removeHot);
                process.once('SIGINT', () => {
                    removeHot();
                    process.exit();
                });
                process.once('SIGTERM', () => {
                    removeHot();
                    process.exit();
                });
            }
        },

        resolveId(id) {
            if (id.endsWith(`/${VIRTUAL_ENTRY_ID}`)) {
                return `\0${VIRTUAL_ENTRY_ID}`;
            }
        },

        async load(id) {
            if (id !== `\0${VIRTUAL_ENTRY_ID}`) {
                return;
            }

            const outDir = userConfig.build?.outDir ?? 'dist';
            const defaultFormat = userConfig.build?.lib?.formats?.[0] ?? 'es';
            const esm = inputs.filter((g) => g.format === 'es' || (g.format == null && defaultFormat === 'es'));
            const iife = inputs.filter((g) => g.format === 'iife' || (g.format == null && defaultFormat === 'iife'));

            /**
             * Unified manifest, keyed by `<group>/<local>`.
             * @type {Record<string, ManifestEntry>}
             */
            const manifest = {};
            /** @type {(arr: string[], value: string) => void} */
            const pushUnique = (arr, value) => {
                if (!arr.includes(value)) {
                    arr.push(value);
                }
            };

            for (const group of [...esm, ...iife]) {
                const groupOutDir = group.outDir ?? outDir;
                const format = group.format ?? defaultFormat;
                /** @type {Record<string, string>[]} */
                const inputs = [];
                if (format === 'iife') {
                    for (const [local, raw] of Object.entries(group.inputs)) {
                        const slots = slotsOf(raw);
                        if (slots.js) {
                            inputs.push({ [local]: slots.js });
                        }
                        if (slots.css) {
                            inputs.push({ [`${local}.css`]: slots.css });
                        }
                    }
                } else {
                    const input = {};
                    for (const [local, raw] of Object.entries(group.inputs)) {
                        const slots = slotsOf(raw);
                        if (slots.js) {
                            input[local] = slots.js;
                        }
                        if (slots.css) {
                            input[`${local}.css`] = slots.css;
                        }
                    }
                    inputs.push(input);
                }

                // Public URL prefix for this group's assets.
                const publicPath = (group.publicPath ?? userConfig.base ?? '/').replace(/\/$/, '');
                /** @type {(file: string) => string} */
                const publicOf = (file) => `${publicPath}/${file.replace(/^\//, '')}`;

                for (const input of inputs) {
                    const buildConfig = mergeConfig(userConfig, {
                        configFile: false,
                        base: group.publicPath ?? userConfig.base,
                        logLevel: 'error',
                        define:
                            format === 'iife'
                                ? {
                                      'import.meta.url': '__import_meta_url__',
                                  }
                                : {},
                        build: {
                            write: false,
                            emptyOutDir: false,
                            cssCodeSplit: true,
                            lib: {
                                entry: input,
                                name: format === 'iife' ? group.name : undefined,
                                inlineDynamicImports: format === 'iife',
                            },
                            rolldownOptions: {
                                output: {
                                    extend: format === 'iife',
                                    ...(format === 'iife' && {
                                        intro: [
                                            "var _documentCurrentScript = typeof document !== 'undefined' ? document.currentScript : null;",
                                            "var __import_meta_url__ = (_documentCurrentScript && _documentCurrentScript.tagName.toUpperCase() === 'SCRIPT' && _documentCurrentScript.src || new URL('index.js', document.baseURI).href);",
                                            userConfig.build?.rollupOptions?.output?.intro ?? '',
                                        ]
                                            .filter(Boolean)
                                            .join('\n'),
                                    }),
                                },
                            },
                        },
                    });
                    if (format === 'iife' && Object.keys(input)[0].endsWith('.css')) {
                        // Force CSS-only entries to be emitted as ES modules, so that Vite outputs them correctly.
                        buildConfig.build.lib.formats = ['es'];
                    } else {
                        buildConfig.build.lib.formats = [format];
                    }
                    buildConfig.plugins = userConfig.plugins.filter((p) => p.name !== 'vite-plugin-php');

                    const output = await build(buildConfig);
                    const outputs = Array.isArray(output) ? output : [output];

                    // --- re-emit the produced files into the host bundle ---
                    for (const o of outputs) {
                        for (const file of o.output) {
                            const id = `${group.name}/${file.name}`;
                            const fileName = join(relative(outDir, groupOutDir), file.fileName);
                            if (file.type === 'asset') {
                                this.emitFile({
                                    id,
                                    fileName,
                                    type: 'asset',
                                    source: file.source,
                                });
                            } else {
                                this.emitFile({
                                    id,
                                    fileName,
                                    type: 'prebuilt-chunk',
                                    code: file.code,
                                    map: file.map,
                                    isEntry: file.isEntry,
                                });
                            }
                        }
                    }

                    // --- accumulate the unified manifest from the same outputs ---
                    /** @type {import('rollup').OutputChunk[]} */
                    const entryChunks = [];
                    for (const o of outputs) {
                        for (const file of o.output) {
                            if (file.type === 'chunk' && file.isEntry) {
                                entryChunks.push(file);
                            } else if (file.type === 'asset' && file.name?.endsWith('.css')) {
                                // CSS-only entry: create a stub chunk to hold the CSS.
                                entryChunks.push({
                                    ...file,
                                    viteMetadata: {
                                        importedCss: [file.fileName],
                                    },
                                });
                            }
                        }
                    }
                    // CSS entries first (author-declared) so they precede JS-graph CSS.
                    entryChunks.sort(
                        (a, b) => Number((b.name ?? '').endsWith('.css')) - Number((a.name ?? '').endsWith('.css'))
                    );

                    for (const chunk of entryChunks) {
                        const name = chunk.name ?? '';
                        const isCss = name.endsWith('.css');
                        const local = isCss ? name.slice(0, -'.css'.length) : name;
                        const key = `${group.name}/${local}`;
                        const entry = (manifest[key] ??= {
                            format,
                            js: '',
                            css: [],
                            imports: [],
                        });

                        // CSS associated with this entry chunk (transitive included).
                        for (const css of chunk.viteMetadata?.importedCss ?? []) {
                            pushUnique(entry.css, publicOf(css));
                        }

                        if (isCss) {
                            // CSS-only entry: ignore the JS stub, keep only the styles.
                            continue;
                        }

                        entry.js = publicOf(chunk.fileName);
                        // Imported chunks -> modulepreload candidates (ESM only).
                        for (const imp of chunk.imports ?? []) {
                            pushUnique(entry.imports, publicOf(imp));
                        }
                    }
                }
            }

            // Unified manifest next to the dev hot file.
            this.emitFile({
                type: 'asset',
                fileName: '.vite/php.json',
                source: JSON.stringify({ mode: 'production', inputs: manifest }, null, 2),
            });

            return 'export default {}';
        },

        generateBundle(options, bundle) {
            for (const fileName in bundle) {
                if (bundle[fileName].facadeModuleId?.endsWith(VIRTUAL_ENTRY_ID)) {
                    delete bundle[fileName];
                }
            }
        }
    };
}
