<?php

namespace Omnilex\Source;

use Omnilex\Auth\TokenStoreInterface;
use Omnilex\Config;
use Omnilex\Policy\PackagePolicy;
use Omnilex\Policy\SourceGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a source's factory fills a Config - its name
 * ("omnilex.factory_name"), the options it needs
 * ("omnilex.required_options") and their defaults - then builds the source
 * from it, on the application's HTTP client when one is given, and with the
 * application's token store for the sources that sign in (PISTE).
 *
 * Options every source takes: base_uri, user_agent, throttle (seconds
 * between two calls: the site's spacing, on top of the source's usage
 * policy), policy (the site's tightening of that policy, in
 * omnisense/contracts' schema: never looser), usage (what the site is:
 * commercial, open_source, contract), max_wait (the seconds a request may
 * wait for a limit, 10 by default; a longer wait refuses it). The registry
 * gives "omnilex.limiter", where the requests are counted, and "omnilex.name".
 */
abstract class SourceFactory implements SourceFactoryInterface
{
    public function __construct(
        protected readonly ?HttpClientInterface $http = null,
        protected readonly ?TokenStoreInterface $tokens = null,
    ) {
    }

    public function getName(): string
    {
        return $this->createConfig()['omnilex.factory_name'];
    }

    public function create(array $options = []): SourceInterface
    {
        $config = $this->createConfig($options);
        $config->validateNotEmpty($config->get('omnilex.required_options', []));
        $source = $this->build($config);
        if ($source instanceof HttpSource) {
            $limiter = $config->get('omnilex.limiter');
            $source->governedBy(new SourceGuard(
                $source->getName(),
                static fn (): UsagePolicy => $source->usagePolicy(),
                $config->map('policy'),
                $config->map('usage'),
                $limiter instanceof RateLimiterInterface ? $limiter : null,
                $config->float('max_wait', SourceGuard::DEFAULT_MAX_WAIT),
            ), PackagePolicy::of(static::class));
        }

        return $source;
    }

    /** @param array<string, mixed> $options */
    public function createConfig(array $options = []): Config
    {
        $config = new Config($options);
        $this->populate($config);
        $config->defaults(['user_agent' => null, 'throttle' => 0.0]);

        return $config;
    }

    /** The User-Agent a source sends: the one configured, else "omnilex/1.x". */
    protected static function userAgent(Config $c): string
    {
        return $c->get('user_agent') ?? 'omnilex/1.x (+https://github.com/glitchr-omnisense/omnilex)';
    }

    /** The factory's name, its required options, the defaults of the others. */
    abstract protected function populate(Config $c): void;

    /** The source, from a Config that holds everything it needs. */
    abstract protected function build(Config $c): SourceInterface;
}
