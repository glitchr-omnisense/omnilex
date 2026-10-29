<?php

namespace Omnilex\Tests;

use Omnilex\Config;
use Omnilex\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** An option given by an environment variable arrives as a string: "false" and "0" are false. */
    public function testABooleanGivenAsAString(): void
    {
        $c = new Config(['a' => 'false', 'b' => '0', 'c' => 'no', 'd' => '', 'e' => 'true', 'f' => '1', 'g' => true, 'h' => false, 'i' => 'On']);

        self::assertSame([false, false, false, false, true, true, true, false, true, false], array_map($c->bool(...), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'unset']));
    }

    /** A misconfigured source was a plain \InvalidArgumentException before: a catch of it still works. */
    public function testAMisconfiguredSourceIsStillAnInvalidArgumentException(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, new InvalidConfigException('x'));
        self::assertInstanceOf(\LogicException::class, new InvalidConfigException('x'));
    }
}
