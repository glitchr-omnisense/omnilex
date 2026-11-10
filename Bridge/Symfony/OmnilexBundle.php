<?php

namespace Omnilex\Bridge\Symfony;

use Omnilex\Auth\TokenStoreInterface;
use Omnilex\Eurlex\EurlexSourceFactory;
use Omnilex\Judilibre\JudilibreSourceFactory;
use Omnilex\JusticeAdministrative\JusticeAdministrativeSourceFactory;
use Omnilex\Legifrance\LegifranceSourceFactory;
use Omnilex\Registry;
use Omnilex\Source\ArticleReaderInterface;
use Omnilex\Source\CitationsInterface;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\RecentInterface;
use Omnilex\Source\SearchInterface;
use Omnilex\Source\SourceFactoryInterface;
use Omnilex\Source\SourceInterface;
use Omnilex\Source\TextReaderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

// The bundle is the only class of the package that needs a framework: symfony/http-kernel,
// symfony/dependency-injection and symfony/config are suggested, not required.
if (!class_exists(AbstractBundle::class)) {
    throw new \LogicException('Omnilex\Bridge\Symfony\OmnilexBundle needs symfony/http-kernel, symfony/dependency-injection and symfony/config (composer require symfony/http-kernel symfony/dependency-injection symfony/config); the rest of omnisense/omnilex needs no framework.');
}

/**
 * Omnilex in a Symfony application: the source packages installed
 * (omnilex/legifrance, omnilex/judilibre, omnilex/eurlex,
 * omnilex/justice-administrative) registered, the sources built from
 * configuration and injectable by their name, as what they do:
 *
 *     omnilex:
 *         sources:
 *             legifrance: { factory: legifrance, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
 *             judilibre: { factory: judilibre, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
 *             eurlex: { factory: eurlex, options: { language: fr } }
 *             administratif: { factory: justice-administrative }
 *
 *     public function __construct(ArticleReaderInterface $legifrance, DecisionReaderInterface $judilibre, RecentInterface $administratif) {}
 *
 * Nothing is built, nor checked, when the container compiles: a source is
 * built the first time it is asked for, and a credential left empty only
 * shows then (InvalidConfigException). A source injected as something it
 * does not do fails where it is injected.
 *
 * The PISTE tokens are kept in cache.app, an hour each. An application's
 * own factories (a SourceFactoryInterface) are registered too,
 * autoconfigured. A source's "policy" and "usage" tighten its usage policy
 * and say what the site is (Omnilex\Registry); "limiter" names a service (an
 * omnisense/contracts RateLimiterInterface, omnibridge/symfony's over
 * symfony/rate-limiter) where every process counts, the registry's own
 * in-memory one otherwise.
 *
 * @suggested symfony/http-kernel (the Symfony bundle: symfony/dependency-injection and symfony/config with it)
 */
final class OmnilexBundle extends AbstractBundle
{
    protected string $extensionAlias = 'omnilex';

    /** The source packages this bundle knows, registered when installed. */
    private const FACTORIES = [
        LegifranceSourceFactory::class,
        JudilibreSourceFactory::class,
        EurlexSourceFactory::class,
        JusticeAdministrativeSourceFactory::class,
    ];

    /** What a source may be injected as. */
    private const INTERFACES = [
        SourceInterface::class,
        SearchInterface::class,
        TextReaderInterface::class,
        ArticleReaderInterface::class,
        DecisionReaderInterface::class,
        CitationsInterface::class,
        RecentInterface::class,
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('sources')
                    ->info('The sources, by name: a factory (legifrance, judilibre, eurlex, justice-administrative...) and its options.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('factory')->isRequired()->cannotBeEmpty()->end()
                            ->variableNode('options')->defaultValue([])->end()
                            ->variableNode('policy')->info('A tightening of the source\'s usage policy, in omnisense/contracts\' schema (never looser).')->end()
                            ->variableNode('usage')->info('What the site is: {commercial: true, open_source: false, contract: false}.')->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('limiter')->info('A service implementing Omnisense\Contracts\Policy\RateLimiterInterface, shared by the processes; null: in memory.')->defaultNull()->end()
                ->scalarNode('token_cache')
                    ->info('The cache pool that keeps the PISTE tokens between two requests; null to keep them in memory only.')
                    ->defaultValue('cache.app')
                ->end()
            ->end();
    }

    /** @param array{sources: array<string, array{factory: string, options: array<string, mixed>, policy?: mixed, usage?: mixed}>, token_cache: string|null, limiter: ?string} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(SourceFactoryInterface::class)->addTag('omnilex.source_factory');

        $services = $container->services();
        $tokens = null;
        if ($config['token_cache'] && interface_exists(CacheItemPoolInterface::class)) {
            $services->set(CacheTokenStore::class)->args([service($config['token_cache'])]);
            $services->alias(TokenStoreInterface::class, CacheTokenStore::class);
            $tokens = service(CacheTokenStore::class);
        }
        foreach (self::FACTORIES as $factory) {
            if (class_exists($factory) && is_subclass_of($factory, SourceFactoryInterface::class)) {
                $services->set($factory)->args([service('http_client')->nullOnInvalid(), $tokens])->tag('omnilex.source_factory');
            }
        }

        $services->set(Registry::class)
            ->args([tagged_iterator('omnilex.source_factory'), $config['sources'], null !== $config['limiter'] ? service($config['limiter']) : null])
            ->public();

        foreach (array_keys($config['sources']) as $name) {
            $id = 'omnilex.source.'.$name;
            $services->set($id, SourceInterface::class)->factory([service(Registry::class), 'get'])->args([$name]);
            foreach (self::INTERFACES as $type) {
                $builder->registerAliasForArgument($id, $type, $name);
            }
        }
    }
}
