<?php

namespace Omnilex\Tests;

use Omnilex\Auth\Piste;
use Omnilex\Exception\AuthenticationException;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\ProviderException;
use Omnilex\Exception\RateLimitedException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Model\Identifier;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpSourceTest extends TestCase
{
    public function testACallCarriesItsQueryAndHeaders(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['normalized_headers']['user-agent'][0] ?? null, $options['normalized_headers']['accept'][0] ?? null];

            return new MockResponse('{"items":[{"id":"a","title":"Un"},{"id":"b","title":"Deux"}],"next":"2","total":12}');
        });
        $results = (new StubSource('stub', $http))->search(new Query(text: 'bail d\'habitation', kind: Kind::TEXT));

        self::assertSame(['GET', 'https://stub.test/api/search?q=bail%20d%27habitation&type=a&type=b', 'User-Agent: omnilex-tests', 'Accept: application/json'], $seen, 'a list repeats its key, null is left out');
        self::assertCount(2, $results);
        self::assertSame('2', $results->next);
        self::assertFalse($results->isLast());
        self::assertSame(12, $results->total);
        self::assertSame('Deux', $results->items[1]->title);
    }

    public function testNotFoundIsNull(): void
    {
        $source = new StubSource('stub', new MockHttpClient([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 410])]));

        self::assertNull($source->text('JUSC1732516D'));
        self::assertNull($source->text('JUSC1732516D'));
    }

    public function testAnIdentifierIsReadFromAString(): void
    {
        $body = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'];

            return new MockResponse('{"id":"x","title":"Décret"}');
        });
        $source = new StubSource('stub', $http);

        self::assertSame('Décret', $source->text('nor: jusc1732516d')?->title);
        self::assertSame('{"id":"JUSC1732516D"}', $body);
        $source->text(Identifier::celex('32016R0679'));
        self::assertSame('{"id":"celex:32016R0679"}', $body, 'not one of the schemes the source reads: left as given');
    }

    public function testACriterionTheSourceCannotApplyIsRefused(): void
    {
        $calls = 0;
        $source = new StubSource('stub', new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}');
        }));

        try {
            $source->search(new Query(text: 'bail', jurisdictions: ['cc']));
            self::fail('The jurisdiction was dropped.');
        } catch (NotSupportedException $e) {
            self::assertSame('The "stub" source cannot filter by jurisdictions.', $e->getMessage());
        }
        self::assertSame(0, $calls, 'refused before any call');

        $source->search(new Query(text: 'bail', limit: 5, sort: Query::NEWEST));
        self::assertSame(1, $calls, 'limit and sort are no criteria');
    }

    public function testDownIsNeverNotFound(): void
    {
        $source = new StubSource('stub', new MockHttpClient([
            new MockResponse('<html><body><h1>Bad Gateway</h1></body></html>', ['http_code' => 502]),
            new MockResponse('', ['http_code' => 423]),
            new MockResponse('', ['error' => 'Connection refused']),
            static fn () => throw new TransportException('Timeout'),
        ]));

        foreach ([[502, 'the service answered 502: Bad Gateway'], [423, 'the service answered 423'], [null, 'unreachable: Connection refused'], [null, 'unreachable: Timeout']] as [$status, $message]) {
            try {
                $source->text('x');
                self::fail('A service that is down was read as an answer.');
            } catch (UnavailableException $e) {
                self::assertSame($status, $e->status);
                self::assertStringContainsString($message, $e->getMessage());
                self::assertSame('stub', $e->source);
            }
        }
    }

    public function testRateLimited(): void
    {
        $source = new StubSource('stub', new MockHttpClient([new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 30']]), new MockResponse('', ['http_code' => 429])]));

        try {
            $source->text('x');
            self::fail();
        } catch (RateLimitedException $e) {
            self::assertSame(30, $e->retryAfter);
            self::assertSame('[stub] rate limited, retry in 30 s', $e->getMessage());
            self::assertInstanceOf(UnavailableException::class, $e, 'a caller that catches "down" catches "slow down" too');
        }
        try {
            $source->text('x');
            self::fail();
        } catch (RateLimitedException $e) {
            // Since omnisense/contracts: the 429 holds the next requests for its Retry-After, longer than max_wait: not sent.
            self::assertFalse($e->wasSent());
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        $source = new StubSource('stub', new MockHttpClient([new MockResponse('', ['http_code' => 429])]));
        try {
            $source->text('x');
            self::fail();
        } catch (RateLimitedException $e) {
            self::assertNull($e->retryAfter);
            self::assertTrue($e->wasSent());
        }
    }

    public function testAnyOtherErrorIsAProviderException(): void
    {
        $source = new StubSource('stub', new MockHttpClient([new MockResponse('{"error":"Bad request: unknown fond"}', ['http_code' => 400]), new MockResponse('<html>', ['http_code' => 200])]));

        try {
            $source->text('x');
            self::fail();
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(UnavailableException::class, $e);
            self::assertSame(400, $e->status);
            self::assertStringContainsString('unknown fond', $e->getMessage());
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('the answer is not JSON');
        $source->text('x');
    }

    public function testASignedCallAsksForItsTokenOnceAndAgainWhenRefused(): void
    {
        $log = [];
        $tokens = ['first', 'second'];
        $api = [401, 200, 200];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$log, &$tokens, &$api): MockResponse {
            if (Piste::SANDBOX_TOKEN_URI === $url) {
                $log[] = 'token';

                return new MockResponse(json_encode(['access_token' => array_shift($tokens), 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'openid']));
            }
            $log[] = $options['normalized_headers']['authorization'][0];

            return new MockResponse('{"id":"x","title":"Loi"}', ['http_code' => array_shift($api)]);
        });
        $source = (new StubSourceFactory($http))->create(['name' => 'signed', 'client_id' => 'id', 'client_secret' => 'secret']);

        self::assertSame('Loi', $source->text('x')?->title);
        self::assertSame('Loi', $source->text('y')?->title);
        self::assertSame(['token', 'Authorization: Bearer first', 'token', 'Authorization: Bearer second', 'Authorization: Bearer second'], $log, 'a 401 drops the token and asks once more; the fresh one is kept');
    }

    public function testCredentialsRefusedAreNeitherNotFoundNorAnAnswer(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (Piste::SANDBOX_TOKEN_URI === $url) {
                return new MockResponse('{"access_token":"t","expires_in":3600}');
            }

            // What PISTE's gateway answers to a call without a valid token.
            return new MockResponse('', ['http_code' => 400, 'response_headers' => ['WWW-Authenticate: Bearer realm="DefaultRealm",error="invalid_request",error_description="Unable to find token in the message"']]);
        });
        $source = (new StubSourceFactory($http))->create(['name' => 'signed', 'client_id' => 'id', 'client_secret' => 'secret']);

        try {
            $source->text('x');
            self::fail();
        } catch (AuthenticationException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('Unable to find token in the message', $e->getMessage());
            self::assertInstanceOf(UnavailableException::class, $e);
        }

        $forbidden = new StubSource('stub', new MockHttpClient(new MockResponse('{"error":"not subscribed"}', ['http_code' => 403])));
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('the service refuses the credentials (403): {"error":"not subscribed"}');
        $forbidden->text('x');
    }

    public function testTheThrottleLeavesTimeBetweenTwoCalls(): void
    {
        $source = new StubSource('stub', new MockHttpClient(static fn () => new MockResponse('{"id":"x","title":"Loi"}')), null, 0.15);
        $start = microtime(true);
        $source->text('a');
        $first = microtime(true) - $start;
        $source->text('b');
        $source->text('c');

        self::assertLessThan(0.1, $first, 'the first call does not wait');
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $start, 'the two that follow wait 0.15 s each');
    }
}
