<?php

namespace Omnilex\Tests;

use Omnilex\Auth\ClientCredentials;
use Omnilex\Exception\OmnilexException;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of the client secret or the access token anywhere in what a log of a failed call writes:
 * the messages, the previous exceptions (copies of the HTTP client's, their class kept), the traces'
 * arguments - the token request's body, the call's headers - when zend.exception_ignore_args is
 * off. The authorisation server not reached, then the service not reached, refusing, down.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['lex-secret-1234567', 'lex-access-1234567'];

    public function testNoPartOfAFailureChainHoldsACredential(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $down = static fn (string $method, string $url): never => throw new TransportException(\sprintf('Could not resolve host: x.test for "%s".', $url), 6);
        $token = static fn (): MockResponse => new MockResponse('{"access_token": "lex-access-1234567", "token_type": "Bearer", "expires_in": 3600}');
        $cases = [
            'token server unreachable' => [$down],
            'service unreachable' => [$token, $down],
            'service refuses' => [$token, static fn (): MockResponse => new MockResponse('', ['http_code' => 403])],
            'service down' => [$token, static fn (): MockResponse => new MockResponse('', ['http_code' => 500])],
        ];
        try {
            foreach ($cases as $case => $answers) {
                $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$answers) {
                    return array_shift($answers)($method, $url, $options);
                });
                try {
                    (new StubSource('stub', $http, new ClientCredentials($http, 'https://auth.test/token', 'lex-id', 'lex-secret-1234567')))->search(new Query(text: 'bail', kind: Kind::TEXT));
                    self::fail("no exception: $case");
                } catch (OmnilexException $e) {
                    self::assertNotEmpty($e->getTrace());
                    for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                        foreach (self::SECRETS as $secret) {
                            self::assertFalse(str_contains($one->getMessage(), $secret), "$case: ".$one->getMessage());
                            self::assertFalse(str_contains(print_r($one->getTrace(), true), $secret), "$case: a trace holds $secret");
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }
}
