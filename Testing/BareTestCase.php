<?php

namespace Omnilex\Testing;

use Omnilex\Registry;

/**
 * omnisense/contracts' bare check for an omnilex package: each of its
 * classes loaded in a PHP process of its own, with nothing from outside but
 * omnisense/contracts, omnisense/omnilex's core and what the package
 * requires - no framework, no application. A source's test names its entry
 * class and the packages it requires:
 *
 *   final class LoadAllTest extends \Omnilex\Testing\BareTestCase
 *   {
 *       protected static function entryClass(): string { return HalSourceFactory::class; }
 *       protected static function requiredPackages(): array { return ['symfony/http-client-contracts']; }
 *   }
 *
 * A class over a suggested package says so ("@suggested <package>") and is
 * not loaded: the core's Symfony bundle.
 *
 * @suggested phpunit/phpunit (test support: loaded by a package's tests only)
 */
abstract class BareTestCase extends \Omnisense\Contracts\Testing\BareTestCase
{
    /** @var non-empty-string */
    protected const AUTOLOAD_ENV = 'OMNILEX_AUTOLOAD';

    protected static function coreDirs(): array
    {
        return [...parent::coreDirs(), self::dirOf(Registry::class)];
    }
}
