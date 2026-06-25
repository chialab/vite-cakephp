<?php
declare(strict_types=1);

namespace Chialab\Vite\View\Helper;

use Cake\View\Helper;

/**
 * Rna compatible helper
 *
 * @property-read \Cake\View\Helper\HtmlHelper $Html
 * @property-read \Chialab\Vite\View\Helper\ViteHelper $Vite
 */
class RnaHelper extends Helper
{
    /**
     * @inheritDoc
     */
    public $helpers = ['Html', 'Vite'];

    /**
     * @inheritDoc
     */
    protected $_defaultConfig = [
        'buildPath' => WWW_ROOT . 'build',
        'entrypointFile' => 'entrypoints.json',
    ];

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $buildPath = $this->getConfig('buildPath');
        $basePath = '/' . trim(str_replace(WWW_ROOT, '', $buildPath), '/') . '/';

        $view = $this->getView();
        $view->loadHelper('Chialab/Vite.Vite', [
            'buildPath' => $buildPath,
            'basePath' => $basePath,
        ]);
    }

    /**
     * Get dev server data.
     *
     * @deprecated Vite helper does not require this method anymore.
     * @param string $pluginName The plugin name.
     * @param array $options Array of options and HTML attributes.
     * @return string Script to inject dev server functionality.
     */
    public function devServer(?string $pluginName = null, ?array $options = []): string
    {
        return '';
    }

    /**
     * Get css assets.
     *
     * @param string $asset The assets name.
     * @param array $options Array of options and HTML attributes.
     * @return string HTML to load CSS resources.
     */
    public function css(string $asset, array $options = []): string
    {
        [$group, $asset] = pluginSplit($asset);
        if ($group !== null) {
            return $this->Vite->css($group . '/' . $asset, $options);
        }

        return $this->Vite->css($asset, $options);
    }

    /**
     * Get js assets.
     *
     * @param string $asset The assets name.
     * @param array $options Array of options and HTML attributes.
     * @return string HTML to load JS resources.
     */
    public function script(string $asset, array $options = []): string
    {
        [$group, $asset] = pluginSplit($asset);
        if ($group !== null) {
            return $this->Vite->js($group . '/' . $asset, $options);
        }

        return $this->Vite->js($asset, $options);
    }
}
