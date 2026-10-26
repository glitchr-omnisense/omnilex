---
title: Writing a provider
order: 80
---

# Writing a provider

A provider is a package of its own, `omnilex/<service>`, requiring `omnisense/omnilex` and
`symfony/http-client`, with:

- a **factory** extending `Omnilex\Source\SourceFactory`: `populate(Config)` sets its name
  (`omnilex.factory_name`), its required options (`omnilex.required_options`) and the defaults of
  the others; `build(Config)` returns the source, on `$this->http` (the application's client, or
  one of its own when `null`) and, for a service that signs in, `$this->tokens` (the application's
  token store). `SourceFactory::userAgent($c)` gives the `User-Agent` to send;
- a **source** extending `Omnilex\Source\HttpSource` and implementing the capability interfaces of
  what its service does, and only those;
- **`capabilities()`** true to the code: the kinds held, the identifiers read besides its own, the
  `Query` criteria its search applies - `accept()` refuses every other one;
- **tests** on `MockHttpClient`, with the service's answers in `Tests/Fixtures`.

## What HttpSource gives

| Member | |
|---|---|
| `__construct(HttpClientInterface $http, string $baseUri, array $headers = [], float $throttle = 0.0, ?TokenProviderInterface $auth = null)` | headers sent with every call; calls signed with `Authorization: <type> <token>` when a token provider is given |
| `getJson(string $path, array $query = [], array $headers = []): ?array` | a GET of JSON; `null` on 404 or 410 |
| `postJson(string $path, array $body, array $headers = []): ?array` | a POST of a JSON body, JSON back |
| `get(string $path, array $query = [], array $headers = []): ?string` | a GET of text (XHTML, XML) |
| `accept(Query $query, array $ignored = [])` | refuses each criterion the query sets that `capabilities()->criteria` does not name (except `$ignored`: `recent()` passes `['from', 'to']`) |
| `identify(Identifier\|string $id, Scheme ...$schemes): ?Identifier` | the identifier when it is of one of those schemes, else `null` (the source's own identifier, kept as the string) |
| `plain(?string $html): ?string` | HTML as plain text: blocks become lines, entities decoded, white space collapsed |
| `day(int\|string\|null $value): ?DateTimeImmutable` | a day from what services send (`2018-12-20`, an ISO date-time, milliseconds since 1970 read in Paris time); `null` for nothing, for what does not parse, and for 2900 and later ("no end") |

A path starting with `http` is used as it is, else appended to `$baseUri`. A query value that is
a list repeats its key (`type=arret&type=qpc`), `null` and `''` are left out, booleans are
`true`/`false`. The throttle sleeps what is left of the interval since the last call. The errors
are mapped once, for every source: [Errors](errors.md#how-http-becomes-an-answer-or-an-exception).

## A whole provider

A source of a case-law database that searches by text and dates and reads a decision by its id or
its ECLI:

```php
<?php

namespace Acme\Hudoc;

use Omnilex\Config;
use Omnilex\Source\SourceFactory;
use Omnilex\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

final class HudocSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilex.factory_name' => 'hudoc',
            'omnilex.required_options' => [],
            'base_uri' => 'https://hudoc.example/api',
            'throttle' => 0.5,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new HudocSource($this->http ?? HttpClient::create(['timeout' => 30]), (string) $c->get('base_uri'), ['User-Agent' => self::userAgent($c)], (float) $c['throttle']);
    }
}
```

```php
<?php

namespace Acme\Hudoc;

use Omnilex\Model\Capabilities;
use Omnilex\Model\Court;
use Omnilex\Model\Decision;
use Omnilex\Model\Identifier;
use Omnilex\Model\Identifiers;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Reference;
use Omnilex\Model\Results;
use Omnilex\Model\Scheme;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\HttpSource;
use Omnilex\Source\SearchInterface;

final class HudocSource extends HttpSource implements SearchInterface, DecisionReaderInterface
{
    public function getName(): string
    {
        return 'hudoc';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(kinds: [Kind::DECISION], identifiers: [Scheme::ECLI], criteria: ['text', 'from', 'to'], jurisdictions: ['COE'], pageSize: 50);
    }

    public function search(Query $query): Results
    {
        $this->accept($query);                          // a criterion not in capabilities(): NotSupportedException
        $page = max(0, (int) $query->cursor);
        $data = $this->getJson('search', ['q' => $query->text, 'from' => $query->from?->format('Y-m-d'), 'to' => $query->to?->format('Y-m-d'), 'size' => min($query->limit, 50), 'page' => $page]) ?? [];

        return new Results(array_map(fn (array $hit) => new Reference(
            kind: Kind::DECISION,
            id: (string) $hit['itemid'],
            title: (string) $hit['docname'],
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::ECLI, $hit['ecli'] ?? null)),
            date: self::day($hit['judgementdate'] ?? null),
            source: $this->getName(),
            raw: $hit,
        ), $data['hits'] ?? []), ($data['more'] ?? false) ? (string) ($page + 1) : null, $data['total'] ?? null);
    }

    public function decision(Identifier|string $id): ?Decision
    {
        $ecli = self::identify($id, Scheme::ECLI);          // an ECLI, or the source's own id as given
        $data = $this->getJson('documents/'.rawurlencode($ecli?->value ?? (string) $id));    // 404: null
        if (null === $data) {
            return null;
        }

        return new Decision(
            id: (string) $data['itemid'],
            title: $data['docname'] ?? null,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::ECLI, $data['ecli'] ?? null)),
            court: new Court('European Court of Human Rights', 'ECHR', 'COE'),
            date: self::day($data['judgementdate'] ?? null),
            content: self::plain($data['html'] ?? null),
            html: $data['html'] ?? null,
            language: 'en',
            source: $this->getName(),
            raw: $data,
        );
    }
}
```

Used on a `MockHttpClient` that answers a search, the decision `001-1`, a 404 and a 502:

```php
$registry = new Registry([new HudocSourceFactory($http)], ['hudoc' => ['factory' => 'hudoc', 'options' => ['throttle' => 0]]]);

foreach ($registry->search('hudoc')->search(new Query(text: 'article 8', from: '2024-01-01')) as $reference) {
    echo $reference->date->format('Y-m-d'), ' ', $reference->id, ' ', $reference->title, ' ', $reference->identifiers->value(Scheme::ECLI), "\n";
}
$decision = $registry->decisions('hudoc')->decision('001-1');
echo $decision->court->name, ': ', str_replace("\n", ' / ', $decision->content), "\n";
var_dump($registry->decisions('hudoc')->decision('001-404'));
// then: a search with jurisdictions, and decision('down')
```

```
2024-01-15 001-1 CASE OF A v. B ECLI:CE:ECHR:2024:0115JUD000000120
European Court of Human Rights: PROCEDURE / 1. The case originated...
NULL
The "hudoc" source cannot filter by jurisdictions.
Omnilex\Exception\UnavailableException: [hudoc] the service answered 502: Bad gateway
```

(run on 2026-10-26 in the harness's image, no network)

## Rules the existing providers keep

- Unknown is `null` or an empty page, never an exception; down, refused or slowed is never `null`.
- A criterion the service cannot apply is refused (`accept()`, or `NotSupportedException::criterion()`
  for a combination: one court at a time, a number along with a text), not dropped.
- A string identifier is read with `identify()`; an identifier of a scheme the source does not
  read is `NotSupportedException::identifier()`, not `null`.
- Decisions published pseudonymised are given as they are, `pseudonymised: true`, and nothing
  tries to put the names back.
- Dates through `day()`; texts through `plain()`; what the models do not carry in `raw`, the full
  texts removed from it.

## Registering it

- Plain PHP: give the registry an instance.
- Symfony: a service implementing `SourceFactoryInterface` is autoconfigured with the tag
  `omnilex.source_factory`; the bundle gives the HTTP client and the token store to its own four
  factories only, so an application's factory takes them by autowiring.
- The harness: an entry in `docker/harness/plugins.php` and, for the console, in
  `docker/harness/config/sources.php`.
