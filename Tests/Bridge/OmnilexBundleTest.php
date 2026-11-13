<?php

namespace Omnilex\Tests\Bridge;

use Composer\InstalledVersions;
use Omnilex\Auth\TokenStoreInterface;
use Omnilex\Bridge\Symfony\CacheTokenStore;
use Omnilex\Bridge\Symfony\OmnilexBundle;
use Omnilex\Model\Token;
use Omnilex\Registry;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\RecentInterface;
use Omnilex\Source\SearchInterface;
use Omnilex\Source\SourceInterface;
use Omnilex\Source\TextReaderInterface;
use Omnilex\Tests\DecisionsStubFactory;
use Omnilex\Tests\StubSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;

final class OmnilexBundleTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        if ($this->kernel) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    public function testAKernelBootsWithTheSourcesByNameAsWhatTheyDo(): void
    {
        $this->kernel = new OmnilexTestKernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $registry = $container->get(Registry::class);
        self::assertSame(['codes', 'jurisprudence'], $registry->names());
        // The source packages are optional: those installed beside this one (all of them in the harness).
        foreach (['legifrance', 'judilibre', 'eurlex', 'justice-administrative'] as $factory) {
            if (InstalledVersions::isInstalled('omnilex/'.$factory)) {
                self::assertContains($factory, $registry->factories(), 'the packages installed are registered');
            }
        }
        self::assertContains('stub', $registry->factories(), 'the application\'s own, autoconfigured');

        $site = $container->get(LegalWatch::class);
        self::assertSame('codes', $site->codes->getName(), 'one source by its argument name');
        self::assertSame($site->codes, $site->codesAsTexts, 'the same source, as another thing it does');
        self::assertSame($site->codes, $site->any);
        self::assertSame('decisions', $site->jurisprudence->getName());
        self::assertSame($site->jurisprudence, $site->watch);
        self::assertSame($registry->get('codes'), $site->codes);
    }

    public function testThePisteTokensAreKeptInTheApplicationsCache(): void
    {
        $this->kernel = new OmnilexTestKernel('test', false);
        $this->kernel->boot();
        $site = $this->kernel->getContainer()->get(LegalWatch::class);

        self::assertInstanceOf(CacheTokenStore::class, $site->tokens);
        self::assertNull($site->tokens->get('omnilex.token.x'));
        $site->tokens->set('omnilex.token.x', new Token('abc', new \DateTimeImmutable('+1 hour'), 'Bearer', 'openid'));
        self::assertSame('abc', $site->tokens->get('omnilex.token.x')?->accessToken);
        self::assertSame('openid', $site->tokens->get('omnilex.token.x')?->scope);
        $site->tokens->delete('omnilex.token.x');
        self::assertNull($site->tokens->get('omnilex.token.x'));
    }
}

final class LegalWatch
{
    public function __construct(
        public readonly SearchInterface $codes,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'omnilex.source.codes')]
        public readonly TextReaderInterface $codesAsTexts,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'omnilex.source.codes')]
        public readonly SourceInterface $any,
        public readonly DecisionReaderInterface $jurisprudence,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'omnilex.source.jurisprudence')]
        public readonly RecentInterface $watch,
        public readonly TokenStoreInterface $tokens,
    ) {
    }
}

final class OmnilexTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new OmnilexBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register('http_client', MockHttpClient::class);
            $container->register('cache.app', ArrayAdapter::class);
            $container->register(StubSourceFactory::class)->setAutoconfigured(true)->setAutowired(true);
            $container->register(DecisionsStubFactory::class)->setAutoconfigured(true);
            $container->register(LegalWatch::class)->setAutowired(true)->setPublic(true);
            $container->loadFromExtension('omnilex', [
                'sources' => [
                    'codes' => ['factory' => 'stub', 'options' => ['name' => 'codes']],
                    'jurisprudence' => ['factory' => 'decisions'],
                ],
            ]);
        });
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/omnilex-bundle-test-'.getmypid();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }
}
