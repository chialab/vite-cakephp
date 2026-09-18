<?php
declare(strict_types=1);

namespace Chialab\Vite\View\Helper;

use Cake\Core\Configure;
use Cake\View\Helper;
use RuntimeException;

/**
 * ViteHelper
 *
 * Emits the tags for assets produced by Vite, referenced by LOGICAL NAME.
 * Tags are built with the core HtmlHelper (script/css), not by hand.
 *
 * Two runtime states, determined by the `mode` field in the single manifest file:
 *  - mode "development"  -> dev   (inject @vite/client + entry as module)
 *  - mode "production"   -> prod  (tag according to each entry's format)
 *  - file absent         -> empty strings
 *
 * Usage model (independent methods, explicit style):
 *  - $this->Vite->css('home')
 *  - $this->Vite->js('home')
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class ViteHelper extends Helper
{
    /**
     * @var array<int, string>
     */
    protected array $helpers = ['Html'];

    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        // Filesystem path to the build directory. Defaults to WWW_ROOT . 'dist'.
        'buildPath' => null,
        // Public base prepended to the manifest paths. A leading "/" makes
        // HtmlHelper resolve it relative to the application base path.
        'basePath' => '/dist/',
        'fileName' => '.vite/php.json',
    ];

    /**
     * Cache of the manifest (read once per request).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $manifest = null;

    /**
     * Emits the stylesheet tags for an entry.
     *
     * In dev: if the entry declares a `css` source, emit a module script for
     * it (Vite serves .css as a style-injecting module with HMR). Otherwise
     * the CSS comes from the JS entry's imports, so nothing is emitted here.
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @param array $options Additional options for the HtmlHelper methods.
     * @return string The HTML tags for the entry's stylesheets.
     */
    public function css(string $entry, array $options = []): string
    {
        $mode = $this->mode();
        if ($mode === 'development') {
            return $this->devCss($entry, $options);
        }
        if ($mode === 'production') {
            return $this->prodCss($entry, $options);
        }

        if (Configure::read('debug')) {
            throw new RuntimeException(
                sprintf('Vite entry "%s" not found in manifest (mode: %s).', $entry, $mode)
            );
        }

        return '';
    }

    /**
     * Emits the <script> tags for an entry (plus @vite/client in dev, plus
     * optional modulepreload in prod for ESM entries).
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @param array $options Additional options for the HtmlHelper methods.
     * @return string The HTML tags for the entry's scripts.
     */
    public function js(string $entry, array $options = []): string
    {
        $mode = $this->mode();

        if ($mode === 'development') {
            return $this->devJs($entry, $options);
        }
        if ($mode === 'production') {
            return $this->prodJs($entry, $options);
        }

        if (Configure::read('debug')) {
            throw new RuntimeException(
                sprintf('Vite entry "%s" not found in manifest (mode: %s).', $entry, $mode)
            );
        }

        return '';
    }

    /**
     * Emits the @vite/client module script tag, deduplicated across all css()/js() calls
     * via HtmlHelper's default `once` behaviour.
     *
     * @return string The HTML script tag for @vite/client, or an empty string when already emitted.
     */
    protected function devClient(): string
    {
        // `once` (default) dedupes @vite/client across all css()/js() calls.
        return (string)$this->Html->script(
            $this->devUrl('@vite/client'),
            ['type' => 'module']
        );
    }

    /**
     * In dev, return the slots for an entry from the manifest inputs.
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @return array<string, mixed> The js/css source slots for the entry.
     * @throws \RuntimeException When the entry is not found in the manifest.
     */
    protected function devSlots(string $entry): array
    {
        $inputs = $this->manifest()['inputs'] ?? [];
        if (!isset($inputs[$entry])) {
            // Unknown entry: throw in dev (visible error).
            throw new RuntimeException(
                sprintf('Vite entry "%s" not found in manifest inputs.', $entry)
            );
        }

        return (array)$inputs[$entry];
    }

    /**
     * In dev, emit the <script> tags for an entry.
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @param array $options Array of options and HTML attributes.
     * @return string The @vite/client module script followed by the entry module script,
     *               or an empty string for CSS-only entries.
     */
    protected function devJs(string $entry, array $options = []): string
    {
        $slots = $this->devSlots($entry);
        $js = $slots['js'] ?? null;
        if ($js === null) {
            // CSS-only entry: nothing for js().
            return '';
        }

        return $this->devClient()
            . (string)$this->Html->script(
                $this->devUrl((string)$js),
                array_merge(['type' => 'module', 'once' => false], $options)
            );
    }

    /**
     * In dev, emit the module <script> tag for an entry's CSS.
     *
     * Vite serves CSS files as ESM modules (with HMR), so the tag is a
     * <script type="module">, not a <link>.
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @param array $options Array of options and HTML attributes.
     * @return string The @vite/client script followed by the CSS module script,
     *               or an empty string when CSS is handled by the JS entry's imports.
     */
    protected function devCss(string $entry, array $options = []): string
    {
        $slots = $this->devSlots($entry);
        $css = $slots['css'] ?? null;
        if ($css === null) {
            // CSS comes from the JS entry's imports; nothing explicit here.
            return '';
        }

        // Vite serves .css as a style-injecting ESM module (with HMR).
        return $this->devClient()
            . (string)$this->Html->script(
                $this->devUrl((string)$css),
                array_merge(['type' => 'module', 'once' => false], $options)
            );
    }

    /**
     * Builds the full dev-server URL for a given path using origin and base from the manifest.
     *
     * @param string $path The path to resolve (e.g. "@vite/client" or a source file).
     * @return string The absolute URL to the asset on the dev server.
     */
    protected function devUrl(string $path): string
    {
        $manifest = $this->manifest();
        $origin = rtrim((string)($manifest['origin'] ?? ''), '/');
        $base = trim((string)($manifest['base'] ?? '/'), '/');
        $prefix = $base === '' ? $origin : $origin . '/' . $base;

        return $prefix . '/' . ltrim($path, '/');
    }

    /**
     * In prod, emit the <link rel="stylesheet"> tags for an entry's CSS files from the manifest.
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @param array $options Array of options and HTML attributes.
     * @return string The HTML stylesheet tags for the entry, or an empty string if the entry is unknown.
     */
    protected function prodCss(string $entry, array $options = []): string
    {
        $item = $this->manifest()['inputs'][$entry] ?? null;
        if ($item === null) {
            // Unknown entry: silent in prod.
            return '';
        }

        $out = '';
        foreach (($item['css'] ?? []) as $file) {
            // rel defaults to "stylesheet".
            $out .= (string)$this->Html->css($this->asset((string)$file), $options);
        }

        return $out;
    }

    /**
     * In prod, emit the <script> tags for an entry from the manifest.
     *
     * For ESM entries also emits <link rel="modulepreload"> for each import chunk.
     * For IIFE entries emits a classic <script> tag.
     *
     * @param string $entry The logical name of the entry (e.g. "home").
     * @param array $options Array of options and HTML attributes.
     * @return string The HTML script tags for the entry, or an empty string if the entry is unknown or CSS-only.
     */
    protected function prodJs(string $entry, array $options = []): string
    {
        $item = $this->manifest()['inputs'][$entry] ?? null;
        if ($item === null) {
            return '';
        }

        // CSS-only entry: nothing to script. Use css() for it.
        $js = (string)($item['js'] ?? '');
        if ($js === '') {
            return '';
        }

        $out = '';
        $format = $item['format'] ?? 'es';

        if ($format === 'es') {
            foreach (($item['imports'] ?? []) as $imp) {
                // css() with a custom rel produces the <link rel="modulepreload">.
                $out .= (string)$this->Html->css(
                    $this->asset((string)$imp),
                    array_merge(['rel' => 'modulepreload'], $options)
                );
            }
            $out .= (string)$this->Html->script(
                $this->asset($js),
                array_merge(['type' => 'module', 'once' => false], $options)
            );
        } else {
            // IIFE: classic script.
            $out .= (string)$this->Html->script(
                $this->asset($js),
                array_merge(['once' => false], $options)
            );
        }

        return $out;
    }

    /**
     * Resolves a manifest file path to the public URL passed to HtmlHelper.
     *
     * Absolute paths (starting with "/") and remote URLs are returned as-is.
     * Relative paths are prefixed with `basePath`.
     *
     * @param string $file The file path from the manifest.
     * @return string The public URL for the asset.
     */
    protected function asset(string $file): string
    {
        // The unified manifest stores full public paths (publicPath baked in),
        // so absolute/remote paths are emitted as-is. `basePath` is only used
        // as a fallback for relative paths.
        if (str_starts_with($file, '/') || str_contains($file, '://')) {
            return $file;
        }
        $base = rtrim((string)$this->getConfig('basePath'), '/');

        return $base . '/' . ltrim($file, '/');
    }

    // -----------------------------------------------------------------------
    // Runtime state
    // -----------------------------------------------------------------------

    /**
     * Returns the runtime mode from the manifest file, or an empty string when no manifest is present.
     *
     * @return string Either "development", "production", or "".
     */
    protected function mode(): string
    {
        if (!is_file($this->manifestPath())) {
            return '';
        }

        return (string)($this->manifest()['mode'] ?? '');
    }

    /**
     * Returns the absolute filesystem path to the build directory (no trailing separator).
     *
     * Falls back to `WWW_ROOT . 'dist'` when `buildPath` config is not set.
     *
     * @return string
     */
    protected function buildPath(): string
    {
        $path = $this->getConfig('buildPath');
        if ($path === null) {
            $path = WWW_ROOT . 'dist';
        }

        return rtrim((string)$path, DIRECTORY_SEPARATOR);
    }

    /**
     * Returns the absolute filesystem path to the manifest file.
     *
     * @return string
     */
    protected function manifestPath(): string
    {
        return $this->buildPath() . DIRECTORY_SEPARATOR
            . (string)$this->getConfig('fileName');
    }

    /**
     * Returns the parsed manifest, reading and caching it on the first call.
     *
     * @return array<string, mixed>
     */
    protected function manifest(): array
    {
        if ($this->manifest === null) {
            $raw = (string)file_get_contents($this->manifestPath());
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true) ?: [];
            $this->manifest = $decoded;
        }

        return $this->manifest;
    }
}
