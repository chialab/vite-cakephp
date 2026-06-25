<?php
declare(strict_types=1);

namespace Chialab\Vite\Test\TestCase\View\Helper;

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
}
