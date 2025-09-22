<?php
/**
 * @package Starmus\tests\unit
 * @version 0.7.2
 * @since 0.3.1
 */
declare(strict_types=1);
namespace Starmus\tests\unit;
use PHPUnit\Framework\TestCase;
use Starmus\StarmusPlugin;

final class StarmusPluginTest extends TestCase
{
    public function testSingletonInstanceReturnsSameObject(): void
    {
        $instance1 = StarmusPlugin::get_instance();
        $instance2 = StarmusPlugin::get_instance();

        $this->assertInstanceOf(StarmusPlugin::class, $instance1);
        $this->assertSame($instance1, $instance2, 'Expected the same instance (singleton)');
    }

    public function testInitMethodRunsWithoutError(): void
    {
        $plugin = StarmusPlugin::get_instance();

        // You might want to mock dependencies later
        $this->expectNotToPerformAssertions();
        $plugin->init();
    }
}
