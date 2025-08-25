<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Starisian\src\includes\StarmusCustomPostType;

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
