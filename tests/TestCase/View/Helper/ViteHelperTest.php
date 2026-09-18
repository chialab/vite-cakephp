<?php
declare(strict_types=1);

namespace Chialab\Vite\Test\TestCase\View\Helper;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Chialab\Vite\View\Helper\ViteHelper;

/**
 * Vite\View\Helper\ViteHelper Test Case
 */
class ViteHelperTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \Chialab\Vite\View\Helper\ViteHelper
     */
    protected $Vite;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $view = new View();
        $this->Vite = new ViteHelper($view);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Vite);

        parent::tearDown();
    }

    /**
     * Test that no tags are emitted when the manifest is missing.
     *
     * @return void
     */
    public function testMissingManifest(): void
    {
        Configure::write('debug', false);
        $this->Vite->setConfig(['buildPath' => TMP . 'missing-build'], null, false);

        static::assertSame('', $this->Vite->css('app'));
        static::assertSame('', $this->Vite->js('app'));
    }
}
