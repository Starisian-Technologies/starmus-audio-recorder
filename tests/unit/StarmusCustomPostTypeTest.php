<?php

declare(strict_types=1);
namespace Starmus\tests\unit;
use PHPUnit\Framework\TestCase;
use Starmus\includes\StarmusCustomPostType;

final class StarmusCustomPostTypeTest extends TestCase
{
    public function testRegisterMethodExists(): void
    {
        $this->assertTrue(
            method_exists(StarmusCustomPostType::class, 'register'),
            'register() method does not exist'
        );
    }
}
