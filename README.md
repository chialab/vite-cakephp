# Vite plugin for CakePHP

A Vite plugin and CakePHP helper that connect a Vite build to a CakePHP application. The plugin produces a single JSON manifest that the helper reads to emit the correct `<script>` and `<link>` tags in both development and production.

## Requirements

| Dependency | Version |
|------------|---------|
| PHP        | ≥ 8.3   |
| CakePHP    | ^4.5    |
| Vite       | ^6.0    |

## Installation

```bash
composer require chialab/vite-cakephp
```

The Vite plugin ships inside the Composer package — no separate npm install is needed.

## Setup

### 1. Bootstrap the plugin

```php
// src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();
    $this->addPlugin('Chialab/Vite');
}
```

### 2. Load the helper

```php
// src/View/AppView.php
public function initialize(): void
{
    parent::initialize();
    $this->loadHelper('Chialab/Vite.Vite');
}
```

Pass configuration options as the second argument if the defaults do not match your project layout (see [PHP helper options](#php-helper-options)):

```php
$this->loadHelper('Chialab/Vite.Vite', [
    'buildPath' => ROOT . '/webroot/dist',
    'basePath' => '/dist/',
]);
```

### 3. Configure Vite

```js
// vite.config.js
import { defineConfig } from 'vite';
import { vitePhp } from './vendor/chialab/vite-cakephp/vite-plugin-php.js';

export default defineConfig({
    build: {
        outDir: 'webroot/dist',
    },
    plugins: [
        vitePhp({
            inputs: [
                {
                    name: 'app',
                    outDir: 'webroot/dist',
                    publicPath: '/dist',
                    inputs: {
                        home:    'resources/pages/home.ts',
                        product: 'resources/pages/product.ts',
                        styles:  'resources/css/global.css',
                    },
                },
            ],
        }),
    ],
});
```

## Usage

Call `js()` and `css()` independently in your layout or view templates. The logical entry name is `{group.name}/{entry}`:

```php
// Layout or view
<?= $this->Vite->js('app/home') ?>
<?= $this->Vite->css('app/home') ?>
```

```twig
{{ Vite.js('app/home') }}
{{ Vite.css('app/home') }}
```

Both methods return an empty string when the manifest file is absent (no dev server running, no build present), so they are always safe to call.

### CSS-only and JS+CSS entries

```php
// Emit only the stylesheet link for a CSS-only entry
<?= $this->Vite->css('app/styles') ?>

// Emit both script and stylesheet for a JS+CSS entry
<?= $this->Vite->js('app/product') ?>
<?= $this->Vite->css('app/product') ?>
```

---

## Configuration reference

### Vite plugin options

`vitePhp(options)` accepts a single options object.

| Option | Type | Required | Description |
|---|---|---|---|
| `inputs` | `BuildGroup[]` | yes | One group per build target (see below). |

#### `BuildGroup`

Each group maps to one Rollup build with its own output directory, public URL, and entry files.

| Field | Type | Default | Description |
|---|---|---|---|
| `name` | `string` | — | Namespace prefix for logical entry names, e.g. `"app"`. |
| `inputs` | `Record<string, EntryInput>` | — | Local entry name → source file mapping (see below). |
| `outDir` | `string` | — | Output directory for this group's assets, relative to the project root. |
| `publicPath` | `string` | — | Public URL base where `outDir` is served (baked into the manifest paths). |
| `format` | `"es" \| "iife"` | `"es"` | Build format. `"iife"` produces a self-contained bundle suitable for `file://` pages. |
| `external` | `string[]` | `[]` | External module IDs (meaningful for `"iife"` groups). |

#### `EntryInput`

Each entry in `inputs` can be written in two ways:

```js
// String shortcut: extension determines the slot
'resources/pages/home.ts'        // → { js: 'resources/pages/home.ts' }
'resources/css/global.css'       // → { css: 'resources/css/global.css' }

// Explicit slots object: JS and CSS bundled as one logical entry
{ js: 'resources/pages/home.ts', css: 'resources/pages/home.css' }
```

#### Multiple groups example

```js
vitePhp({
    inputs: [
        {
            // ESM group: shared vendor chunk, tree-shaken per page.
            name: 'app',
            outDir: 'webroot/dist',
            publicPath: '/dist',
            inputs: {
                home:    'resources/pages/home.ts',
                product: { js: 'resources/pages/product.ts', css: 'resources/pages/product.css' },
                styles:  'resources/css/global.css',
            },
        },
        {
            // IIFE group: self-contained, no shared chunks, works under file://.
            name: 'embed',
            format: 'iife',
            outDir: 'webroot/dist/embed',
            publicPath: '/dist/embed',
            inputs: {
                widget: 'resources/embed/widget.ts',
            },
        },
    ],
}),
```

Logical names for the above: `app/home`, `app/product`, `app/styles`, `embed/widget`.

---

### PHP helper options

| Option | Type | Default | Description |
|---|---|---|---|
| `buildPath` | `string\|null` | `WWW_ROOT . 'dist'` | Absolute filesystem path to the build directory (where Vite writes `outDir`). |
| `basePath` | `string` | `'/dist/'` | Public URL prefix prepended to relative paths from the manifest. A leading `/` is resolved relative to the application base by `HtmlHelper`. |
| `fileName` | `string` | `'.vite/php.json'` | Path to the manifest file, relative to `buildPath`. |

`buildPath` must match the `outDir` you configure in Vite. For example, if Vite writes to `webroot/dist`, set `buildPath` to `WWW_ROOT . 'dist'` (the default) or an equivalent absolute path.

---

## Runtime behaviour

The plugin writes a single file — `{outDir}/.vite/php.json` — whose `mode` field drives the helper's behaviour.

### Development

Start the Vite dev server normally:

```bash
npx vite
```

On server start the plugin writes:

```json
{
    "mode": "development",
    "origin": "http://localhost:5173",
    "base": "/",
    "inputs": {
        "app/home": { "js": "resources/pages/home.ts" }
    }
}
```

`js()` emits a `<script type="module">` pointing at the dev server, preceded by `@vite/client` (deduplicated across all calls). `css()` emits a `<script type="module">` for CSS entries — Vite serves CSS files as ESM modules with HMR. When the dev server exits the file is removed.

### Production

Build with:

```bash
npx vite build
```

The plugin writes:

```json
{
    "mode": "production",
    "inputs": {
        "app/home": {
            "format": "es",
            "js": "/dist/home-BcD1eF2g.js",
            "css": ["/dist/home-BcD1eF2g.css"],
            "imports": ["/dist/vendor-HiJ3kL4m.js"]
        }
    }
}
```

`js()` emits `<link rel="modulepreload">` for each import chunk (ESM) followed by `<script type="module">`, or a classic `<script>` for IIFE entries. `css()` emits `<link rel="stylesheet">` for each CSS file.

### No manifest

When the file is absent (not built, dev server not running) both `js()` and `css()` return an empty string. In debug mode an unknown `mode` value throws a `RuntimeException`.
