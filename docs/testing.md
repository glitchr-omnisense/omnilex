---
title: Testing
order: 60
---

# Testing

Testing an application that reads the law through omnilex, and what the family's own tests cover.
The core ships no fake source and no conformance suite: its test doubles (`Tests/StubSource.php`,
`Tests/DecisionsStub.php` and their factories) are autoloaded only in its own development
(`autoload-dev`). An application tests its code against a real provider on a `MockHttpClient`, or
with a source of its own.

## A provider on a MockHttpClient

Every factory and every source takes its HTTP client. Given Symfony's `MockHttpClient`, the
provider runs whole - its requests, its mapping, its errors - and nothing leaves the machine. For
the PISTE sources, `Omnilex\Auth\StaticToken` stands in for the OAuth exchange:

```php
<?php

use Omnilex\Auth\StaticToken;
use Omnilex\Exception\UnavailableException;
use Omnilex\Legifrance\LegifranceSource;
use Omnilex\Source\ArticleReaderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The application's code: an article quoted with its code and the day its wording applies from. */
final class Quote
{
    public function __construct(private readonly ArticleReaderInterface $legifrance)
    {
    }

    public function of(string $id): ?string
    {
        try {
            $article = $this->legifrance->article($id);
        } catch (UnavailableException) {
            return null;                                     // down, refused or slowed: not "no such article"
        }

        return null === $article ? null : \sprintf('%s, art. %s (since %s): %s', $article->textTitle, $article->number, $article->version?->from?->format('Y-m-d'), $article->content);
    }
}

final class QuoteTest extends TestCase
{
    public function testAnArticleIsQuotedWithItsCodeAndItsVersion(): void
    {
        $answer = new JsonMockResponse(['article' => [
            'id' => 'LEGIARTI000032041571', 'num' => '1240', 'etat' => 'VIGUEUR', 'dateDebut' => '2016-10-01',
            'texte' => 'Tout fait quelconque de l\'homme, qui cause à autrui un dommage, oblige celui par la faute duquel il est arrivé à le réparer.',
            'textTitles' => [['titre' => 'Code civil', 'nature' => 'CODE', 'cid' => 'LEGITEXT000006070721']],
        ]]);
        $source = new LegifranceSource(new MockHttpClient($answer), new StaticToken('test-token'), throttle: 0.0);

        self::assertSame('Code civil, art. 1240 (since 2016-10-01): Tout fait quelconque de l\'homme, qui cause à autrui un dommage, oblige celui par la faute duquel il est arrivé à le réparer.', (new Quote($source))->of('LEGIARTI000032041571'));
        self::assertSame('https://api.piste.gouv.fr/dila/legifrance/lf-engine-app/consult/getArticle', $answer->getRequestUrl());
        self::assertContains('Authorization: Bearer test-token', $answer->getRequestOptions()['headers']);
    }

    public function testNothingWhenLegifranceIsDown(): void
    {
        $source = new LegifranceSource(new MockHttpClient(new MockResponse('', ['http_code' => 503])), new StaticToken('t'), throttle: 0.0);

        self::assertNull((new Quote($source))->of('LEGIARTI000032041571'));
    }
}
```

```
$ vendor/bin/phpunit --testdox tests/
Quote
 ✔ An article is quoted with its code and its version
 ✔ Nothing when legifrance is down

OK (2 tests, 4 assertions)
```

(run on 2026-10-26 in the harness's image, PHPUnit 11.5.57, no network)

- Build the source directly (`new LegifranceSource($http, $token, throttle: 0.0)`), or through
  its factory with `throttle: 0` in the options: the default throttle sleeps between two calls.
- Each provider's `Tests/Fixtures` holds answers to start from: recorded from the real service
  for EUR-Lex and the administrative courts (2026-10-04), composed from the published
  descriptions for Légifrance and Judilibre. The harness keeps two EUR-Lex answers in
  `docker/harness/recorded/`.
- A 503 tests "down" (`UnavailableException`), a 429 with `Retry-After` the slowed path, a 401
  twice the refused credentials, a 404 "not found" (`null`).
- `ClientCredentials` with a `MockHttpClient` answering the token server tests the OAuth exchange
  itself, a `TokenStoreInterface` in memory what is kept ([Authentication](authentication.md)).

## A source of the application's own

For code that only needs models back, a class implementing the capability interfaces it needs
(and `SourceInterface`'s `getName()` and `capabilities()`), given to the registry through a
factory - the core's own `Tests/StubSourceFactory.php` and `Tests/DecisionsStubFactory.php` are
such doubles, to copy rather than depend on.

## Symfony

In a functional test, the registry is the public service `Omnilex\Registry`. Replace the HTTP
client the factories receive by configuring `framework.http_client.mock_response_factory` in the
test environment, or configure the source under test with a factory of the application's own
(autoconfigured). `Tests/Bridge/OmnilexBundleTest.php` boots a kernel the latter way. Not run in
an application for this page.

## The family's own tests

The workspace (`~/Sites/omnilex`, every package from its checkout) runs 143 tests, 747 assertions
([Verified, and not](verified.md)):

| Test case | What it checks |
|---|---|
| `RegistryTest` (5) | sources built once by name; typed getters and their refusal; a source without its options left out of `usable()`/`having()`/`reading()`; a required option; an unknown factory |
| `HttpSourceTest` (10) | a call's query (a list repeats its key, `null` left out) and headers; 404 as `null`; an identifier read from a string; a criterion refused; 5xx, 423 and transport errors as `UnavailableException`; 429 with `Retry-After`; another 4xx as `ProviderException`; a token asked once and again after a 401; refused credentials; the throttle |
| `ClientCredentialsTest` (6) | the OAuth request; a token about to die replaced; the store sparing the server; refused credentials; the server down; a static token |
| `IdentifierTest` (52) | 48 spellings parsed; normalisation; invalid identifiers refused; what an identifier tells; a collection keeping each once |
| `ModelTest` (5) | the version in force on a day; a query's criteria; a text's article whatever the spelling; capabilities and operations; a token's death |
| `OmnilexBundleTest` (2) | a kernel with the sources injected by name as what they do; the PISTE token in the application's cache |
| `BareTest` (3) | `bare --recorded --json` in a process of its own: no framework class loaded, a text at a date from recorded answers; loading the bundle does name the framework |
| `EurlexSourceTest` (17), `JudilibreSourceTest` (14), `JusticeAdministrativeSourceTest` (11), `LegifranceSourceTest` (18) | each provider on `MockHttpClient` and its fixtures (their manuals) |
