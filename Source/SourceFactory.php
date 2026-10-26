<?php

namespace Omnilex\Source;

use Omnilex\Auth\TokenStoreInterface;
use Omnilex\Config;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a source's factory fills a Config - its name
 * ("omnilex.factory_name"), the options it needs
 * ("omnilex.required_options") and their defaults - then builds the source
 * from it, on the application's HTTP client when one is given, and with the
 * application's token store for the sources that sign in (PISTE).
 *
 * Options every source takes: base_uri, user_agent, throttle (seconds
 * between two calls).
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

        return $this->build($config);
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
