# Vite plugin for CakePHP

## Installation

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org).

The recommended way to install composer packages is:

```
composer require chialab/vite-cakephp
```

Then, use your JavaScript package manager to install the Vite dependencies:

```
npm install -D vite
```

### Configuration

#### CakePHP Helper

First, load the plugin in your `Application` class:

```php
// src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();
    $this->addPlugin('Chialab/Vite');
}
```

Then load the `Vite` helper in your `AppView` class:

```php
// src/View/AppView.php
public function initialize(): void
{
    parent::initialize();
    $this->loadHelper('Chialab/Vite.Vite');
}
```

#### Vite Configuration

Create a `vite.config.js` file in the root of your project and configure it as follows:

```js
import { defineConfig } from 'vite';
import { vitePhp } from './vendor/chialab/vite-cakephp/vite';

export default defineConfig({
    plugins: [
        vitePhp({
            entries: {
                // Regular site entries: ESM, vendor shared between them.
                home: { input: 'resources/pages/home.ts' },
                product: { input: 'resources/pages/product.ts' },
                
                // Entry meant for pages that must also run under file:// (download).
                // Self-contained IIFE, duplicated vendor, relative base.
                widget: { input: 'resources/elements/widget.ts', format: 'iife' },
            }
        }),
    ],
});
```
