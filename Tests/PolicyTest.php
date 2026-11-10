<?php

namespace Omnilex\Tests;

use Omnilex\Exception\RateLimitedException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Policy\PackagePolicy;
use Omnilex\Registry;
use Omnilex\Source\SourceFactoryInterface;
use Omnilex\Tests\Policy\HttpStubSource;
use Omnilex\Tests\Policy\HttpStubSourceFactory;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every request of a source over HTTP goes through its usage policy: a
 * limit reached waits when its window ends within max_wait, else the
 * request is not sent; a 429 holds the next ones; a site tightens, never
 * loosens; a use the terms forbid fails at once.
 */
final class PolicyTest extends TestCase
{
    private int $sent = 0;

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (): MockResponse {
            ++$this->sent;

            return new MockResponse('{"title": "Un"}');
        });
    }

    /** @param array{options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>} $entry */
    private function registry(array $entry, ?UsagePolicy $declared = null, ?InMemoryRateLimiter $limiter = null, ?MockHttpClient $http = null): Registry
    {
        return new Registry([new HttpStubSourceFactory($http ?? $this->http(), $declared)], ['stub' => ['factory' => 'http-stub'] + $entry], $limiter);
    }

    private static function stub(Registry $registry): HttpStubSource
    {
        $source = $registry->get('stub');
        \assert($source instanceof HttpStubSource);

        return $source;
    }

    public function testALimitReachedRefusesTheRequestBeforeItIsSent(): void
    {
        $registry = $this->registry(['policy' => ['rateLimits' => [['max' => 1, 'window' => 'hour']]]]);
        self::assertSame('Un', self::stub($registry)->title('1'));
        try {
            self::stub($registry)->title('2');
            self::fail('the second request of the hour is refused');
        } catch (RateLimitedException $e) {
            self::assertFalse($e->wasSent());
            self::assertInstanceOf(UnavailableException::class, $e);
            self::assertGreaterThan(3500, $e->retryAfter);
            self::assertInstanceOf(RateLimit::class, $e->limit);
            self::assertSame(1, $this->sent, 'never sent');
        }
        self::assertSame(0, $registry->guard('stub')->remaining(HttpStubSource::REQUEST));
    }

    public function testALimitThatEndsWithinMaxWaitIsWaitedOut(): void
    {
        $registry = $this->registry(['policy' => ['rateLimits' => [['max' => 1, 'window' => 'second']]]]);
        $source = self::stub($registry);
        $start = microtime(true);
        $source->title('1');
        $source->title('2');
        $source->title('3');

        self::assertSame(3, $this->sent);
        self::assertGreaterThan(1.0, microtime(true) - $start, 'one request a second: the third waited for the third second');
    }

    public function testMaxWaitZeroRefusesAtOnce(): void
    {
        $source = self::stub($this->registry(['options' => ['max_wait' => 0], 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'second']]]]));
        $source->title('1');

        $this->expectException(RateLimitedException::class);
        $source->title('2');
    }

    public function testA429HoldsTheNextRequestsForItsRetryAfter(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->sent;

            return new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]);
        });
        $source = self::stub($this->registry([], null, null, $http));
        try {
            $source->title('1');
            self::fail();
        } catch (RateLimitedException $e) {
            self::assertTrue($e->wasSent(), 'the service answered 429');
            self::assertSame(30, $e->retryAfter);
        }
        try {
            $source->title('2');
            self::fail();
        } catch (RateLimitedException $e) {
            self::assertFalse($e->wasSent(), 'held, longer than max_wait: not sent');
            self::assertSame(1, $this->sent);
        }
    }

    public function testASiteTightensItNeverLoosens(): void
    {
        $declared = new UsagePolicy('http-stub', [RateLimit::perSecond(5)]);
        self::assertCount(2, $this->registry(['policy' => ['rateLimits' => [['max' => 2, 'window' => 'second']]]], $declared)->policy('stub')->rateLimits ?? []);

        $this->expectException(InvalidPolicyException::class);
        self::stub($this->registry(['policy' => ['rateLimits' => [['max' => 10, 'window' => 'second']]]], $declared))->title('1');
    }

    public function testAUseTheTermsForbidFailsAtOnce(): void
    {
        $declared = UsagePolicy::fromArray(['provider' => 'http-stub', 'rights' => ['commercial' => 'forbidden']]);
        try {
            self::stub($this->registry(['usage' => ['commercial' => true]], $declared))->title('1');
            self::fail();
        } catch (UsageNotPermitted) {
            self::assertSame(0, $this->sent);
        }
    }

    public function testSourcesOnOneLimiterCountTogether(): void
    {
        $limiter = new InMemoryRateLimiter();
        $declared = new UsagePolicy('http-stub', [RateLimit::perHour(1)]);
        self::stub($this->registry([], $declared, $limiter))->title('1');

        $this->expectException(RateLimitedException::class);
        self::stub($this->registry([], $declared, $limiter))->title('2');
    }

    public function testAPolicyByNameAndEverySourcesPolicies(): void
    {
        $registry = $this->registry([], new UsagePolicy('http-stub', [RateLimit::perSecond(3)]));
        self::assertSame('http-stub', $registry->policy('stub')->provider);
        self::assertSame(['stub'], array_keys($registry->policies()));
        self::assertSame(3, $registry->guard('stub')->remaining(HttpStubSource::REQUEST));
        self::assertNull((new Registry([new StubSourceFactory()], ['stub' => ['factory' => 'stub', 'options' => ['name' => 'stub']]]))->policy('stub')->rateLimits, 'a source that declares nothing: unknown');
    }

    public function testASourceWithoutAFactoryIsGovernedToo(): void
    {
        $source = new HttpStubSource($this->http(), new UsagePolicy('http-stub', [RateLimit::perHour(1)]));
        $source->title('1');

        $this->expectException(RateLimitedException::class);
        $source->title('2');
    }

    /** Every source package installed declares its policy (policy.json, named in its composer.json), under its factory's name. */
    public function testEveryInstalledSourceDeclaresItsPolicy(): void
    {
        /** @var array<string, array{0: string, 1: class-string}> $plugins */
        $plugins = require __DIR__.'/../docker/harness/plugins.php';
        $factories = array_values(array_filter(array_column($plugins, 1), static fn (string $class): bool => class_exists($class)));
        if ([] === $factories) {
            self::markTestSkipped('no source package installed');
        }
        foreach ($factories as $class) {
            $policy = PackagePolicy::of($class);
            self::assertNotNull($policy, $class.' declares a policy');
            $factory = new $class(new MockHttpClient());
            \assert($factory instanceof SourceFactoryInterface);
            self::assertSame($factory->getName(), $policy->provider, $class);
            self::assertNotNull($policy->provenance->terms ?? $policy->provenance->policy, $class.' says where its terms were read');
        }
    }
}
