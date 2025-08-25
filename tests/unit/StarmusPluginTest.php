<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Starisian\src\includes\StarmusPlugin;

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
