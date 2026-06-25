import type { Plugin } from 'vite';

export type EntryFormat = 'iife' | 'es';
export interface EntrySlots {
    js?: string;
    css?: string;
}
export type EntryInput = string | EntrySlots;

export interface BuildGroup {
    /** Namespace prefix for the logical names (manifest keys, dev inputs). */
    name: string;
    /** Build format. Defaults to `esm`. Dev is always ESM regardless. */
    format?: EntryFormat;
    /** Output dir, relative to the project root. */
    outDir: string;
    /** Public URL base where `outDir` is served (baked into the manifest paths). */
    publicPath: string;
    /** Externals (meaningful for IIFE groups). */
    external?: string[];
    /**
     * Logical names → source files mapping.
     * Each source file is either a single string (for JS or CSS) or an object with `js` and/or `css` keys.
     * The logical names are used as keys in the manifest and as dev inputs (prefixed with the group name).
     * The source files are relative to the project root.
     * In dev, each source file is served under its own URL (with the group name as prefix).
     * In build, each source file is bundled into a single output file (per type) under `outDir`.
     */
    inputs: Record<string, EntryInput>;
}

export interface ManifestEntry {
    /** Build format of the entry. */
    format: EntryFormat;
    /** Public URL of the entry JS file. */
    js?: string;
    /** Public URL of the entry CSS files. */
    css?: string[];
    /** Public URL of the imported chunks (ESM only). */
    imports?: string[];
}

export interface DevManifest {
    mode: 'development';
    /** Dev server origin (protocol + host + port), e.g. `http://localhost:5173`. */
    origin: string;
    /** Dev server base path, e.g. `/`. */
    base: string;
    /** Logical name → source slots map, used to build URLs in dev. */
    inputs: Record<string, EntrySlots>;
}

export interface ProdManifest {
    mode: 'production';
    /** Logical name → built asset map. */
    inputs: Record<string, ManifestEntry>;
}

/** Discriminated union written to `.vite/php.json` by the plugin. */
export type PhpManifest = DevManifest | ProdManifest;

export interface VitePhpPluginOptions {
    /** Project entries (used to write the name→source map into the hot file). */
    inputs: BuildGroup[];
}

export declare function vitePhp(options: VitePhpPluginOptions): Plugin;
