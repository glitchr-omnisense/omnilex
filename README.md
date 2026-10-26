# omnisense/omnilex

One contract for the sources of law - texts, codes, case law: searched with filters
(jurisdiction, date, subject, type), read **in the version in force at a given date**, their
links followed between decisions and texts, and what is new since a date listed for a legal
watch.

Published as `omnisense/omnilex` (formerly `glitchr/omnilex`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package and never installs
both). `omnisense/` is the vendor of every family's core, the providers keep `omnilex/`, the
namespace `Omnilex\` and the GitHub repositories do not change.

```php
$article = $legifrance->article('LEGITEXT000006070987', 'L36-11', new DateTimeImmutable('2018-01-01'));
$article->content;                      // the article as it read on 1 January 2018
$article->version->from;                // ... the day that wording started to apply

$gdpr = $eurlex->text('32016R0679', new DateTimeImmutable('2018-06-01'));
$gdpr->version->id;                     // 02016R0679-20160504: the consolidated version of that day

$decision = $eurlex->decision('ECLI:EU:C:2014:317');
$decision->citations;                   // interprets 31995L0046, cites 62012CJ0473...

$new = $administratif->recent(new DateTimeImmutable('-7 days'), new Query(jurisdictions: ['CE']));
```

This package holds the contract (`Source\*Interface`, `Source\SourceFactory`, `Registry`), the
models (`Text`, `Article`, `Version`, `Decision`, `Court`, `Citation`, `Reference`, `Query`,
`Results`, `Capabilities`), the identifiers (`Identifier`: ECLI, CELEX, ELI, NOR, numéro de
pourvoi, Légifrance's own - normalised and validated without the network), OAuth client
credentials for PISTE (`Auth\ClientCredentials`) and a bridge for Symfony. It needs no framework:
it requires nothing but `symfony/http-client-contracts`, each source package
`symfony/http-client`. Each source is a package of its own:

| Package | Source | Access |
|---|---|---|
| [`omnilex/legifrance`](https://github.com/glitchr-omnisense/omnilex-legifrance) | Légifrance: codes, laws, decrees, the Journal officiel, as in force at a date; case law | PISTE account (free), OAuth client credentials |
| [`omnilex/judilibre`](https://github.com/glitchr-omnisense/omnilex-judilibre) | Judilibre: the decisions of the Cour de cassation and of the judicial courts, pseudonymised | PISTE account (free), OAuth client credentials or API key |
| [`omnilex/eurlex`](https://github.com/glitchr-omnisense/omnilex-eurlex) | EUR-Lex: EU acts in their consolidated versions, the case law of the Court of Justice, their links | none (the Publications Office's open endpoint) |
| [`omnilex/justice-administrative`](https://github.com/glitchr-omnisense/omnilex-justice-administrative) | The Conseil d'État, the administrative courts of appeal and tribunals: decisions, pseudonymised | none |

## Capability interfaces

A source implements what its service does, and only that:

| Interface | Method | |
|---|---|---|
| `SearchInterface` | `search(Query): Results` | filters: jurisdiction, dates, subject, type, kind, in force at a date |
| `TextReaderInterface` | `text($id, $at): ?Text` | a text as in force on a day |
| `ArticleReaderInterface` | `article($id, $number, $at): ?Article` | one article as in force on a day |
| `DecisionReaderInterface` | `decision($id): ?Decision` | a court's decision |
| `CitationsInterface` | `citations($id): Citation[]` | the links between decisions and texts, both ways |
| `RecentInterface` | `recent($since, ?Query): Results` | what is new since a day |

`capabilities()` says the rest: the kinds of documents held, the identifiers read, the search
criteria applied. What a source cannot do is a `NotSupportedException` - a search criterion it
cannot apply is refused, never silently dropped.

## Three answers, never confused

- **Unknown** is `null` (or an empty page).
- **Down, refused or slowed** is an `UnavailableException` (`RateLimitedException`,
  `AuthenticationException`): never read as "not found", never cached, never "nothing new".
- **Not possible with this source** is a `NotSupportedException`.

## Pseudonymised decisions

Judilibre and the administrative courts publish their decisions with the names of the persons
removed. Omnilex gives these texts as published (`Decision::$pseudonymised`) and never tries to
put a name back on a person; an application built on it must not either.

## Scope

Omnilex reads **sources of law**. Legal scholarship (articles, theses) is read with
[`omnisense/omnischolar`](https://github.com/glitchr-omnisense/omnischolar) (`omnischolar/hal` on
the domain `shs.droit`); public registers (companies, VAT numbers) with `omnisense/omnistate`.

## Documentation

- [The manual's home: providers, when to use it](docs/index.md)
- [Installation and first calls](docs/installation.md)
- [Symfony](docs/symfony.md) and [Laravel](docs/laravel.md)
- [Concepts and models](docs/models.md)
- [Identifiers: ECLI, CELEX, ELI, NOR, pourvoi, Légifrance](docs/identifiers.md)
- [Sources and search: what each can and cannot do](docs/sources.md)
- [The law at a date: versions](docs/versions.md)
- [Authentication: PISTE and OAuth client credentials](docs/authentication.md)
- [Configuration reference](docs/configuration.md)
- [Errors](docs/errors.md)
- [Testing](docs/testing.md)
- [The Docker harness](docs/harness.md)
- [Writing a provider](docs/providers.md)
- [Verified and not](docs/verified.md)

## Plain PHP

```sh
composer require omnisense/omnilex omnilex/eurlex omnilex/justice-administrative
```

```php
use Omnilex\Eurlex\EurlexSourceFactory;
use Omnilex\JusticeAdministrative\JusticeAdministrativeSourceFactory;
use Omnilex\Registry;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();   // or the application's client; a MockHttpClient in a test
$registry = new Registry([new EurlexSourceFactory($http), new JusticeAdministrativeSourceFactory($http)], [
    'eurlex' => ['factory' => 'eurlex', 'options' => ['language' => 'fr']],
    'administratif' => ['factory' => 'justice-administrative'],
]);
$eurlex = $registry->articles('eurlex');           // typed: an ArticleReaderInterface
$administratif = $registry->recent('administratif');
```

No bundle, no container: a factory per source package, the registry built by hand.
[docs/installation.md](docs/installation.md) opens on a whole script that runs as it is, against
the real service.

## Symfony

`Omnilex\Bridge\Symfony\OmnilexBundle` does that wiring in a Symfony application
([docs/symfony.md](docs/symfony.md)); its components (`symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel`, a cache pool for the PISTE tokens) are not
required by this package.

```yaml
omnilex:
    sources:
        legifrance: { factory: legifrance, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
        judilibre: { factory: judilibre, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
        eurlex: { factory: eurlex, options: { language: fr } }
        administratif: { factory: justice-administrative }
```

```php
public function __construct(ArticleReaderInterface $legifrance, DecisionReaderInterface $judilibre, RecentInterface $administratif) {}
```

## Docker: every source, for real

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnilex sources
docker compose run --rm omnilex text eurlex 32016R0679 --at 2018-06-01
docker compose run --rm omnilex recent administratif 2026-10-01 --jurisdiction CE
docker compose run --rm omnilex bare          # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnilex test
```

## Responsibility

`omnisense/omnilex` and its `omnilex/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (re-use conditions and licence of their data, attribution, accounts and keys, quotas,
pseudonymisation), named with a link in the "Responsibility" section of the README of each provider
that reaches one. The MIT licence covers this code only and grants no right to any service or to its
data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
