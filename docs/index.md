# omnisense/omnilex

Omnilex is one PHP contract for reading the sources of law - texts, articles and court decisions -
from the services that publish them: Légifrance (French codes, laws, decrees, the Journal
officiel), Judilibre (the decisions of the French judicial courts), EUR-Lex (EU law and the case
law of the Court of Justice) and the open data of the French administrative courts. The
application asks a source by name for what it does and reads neutral models:

```php
$article = $registry->articles('eurlex')->article('32016R0679', '17', new DateTimeImmutable('2018-06-01'));
$article->title;                       // Droit à l'effacement («droit à l'oubli»)
$article->version->id;                 // 02016R0679-20160504: the version in force that day

$registry->decisions('administratif')->decision('ECLI:FR:CECHS:2026:505010.20261002');
$registry->search('judilibre')->search(new Query(text: 'expropriation', jurisdictions: ['cc'], from: '2020-01-01'));
$registry->recent('legifrance')->recent(new DateTimeImmutable('-7 days'));      // what a legal watch reads
```

Each service is a provider package (`omnilex/legifrance`, `omnilex/judilibre`, `omnilex/eurlex`,
`omnilex/justice-administrative`) with a factory that builds a **source**. A source implements the
capability interfaces of what its service does, and only those (search, text, article, decision,
citations, recent); its `Capabilities` say what it holds, which identifiers it reads and which
search criteria it applies. Identifiers (ECLI, CELEX, ELI, NOR, numéro de pourvoi, Légifrance's
own) are normalised and validated without the network. Texts and articles are read **as in force
at a date**. "Not found" is `null`; a service down, refusing the credentials or asking to slow
down is an exception, never read as "not found"; a criterion a source cannot apply is refused,
not dropped. The core requires PHP 8.2 and `symfony/http-client-contracts`, nothing of a
framework; it ships a Symfony bundle.

## When to use it

- A site or a back office that quotes an article, a text or a decision, with its version and its
  link to the official page.
- A legal watch: what was published or decided since a day, per source, without mistaking a
  service that is down for "nothing new".
- Code that reads an identifier given by a user (an ECLI, a CELEX number, a Légifrance page's
  address) and finds the source that reads it (`Registry::reading()`).

It is not a legal database: nothing is stored here. Consolidated versions are documentary (only
the Journal officiel and the Official Journal of the EU are authentic), and decisions published
pseudonymised are given as they are - no field holds the parties, and nothing here tries to put a
name back.

## Providers

Four packages. Operations are the interfaces each source implements; read from the code at this
commit.

| Package | Factory name | Holds | Operations | Access | Called live |
|---|---|---|---|---|---|
| [`omnilex/legifrance`](https://github.com/glitchr-omnisense/omnilex-legifrance) | `legifrance` | texts, articles, decisions (FR) | search, text, article, decision, citations, recent | PISTE, OAuth client credentials | no (no credentials) |
| [`omnilex/judilibre`](https://github.com/glitchr-omnisense/omnilex-judilibre) | `judilibre` | decisions of the judicial courts (FR) | search, decision, citations, recent | PISTE, OAuth or API key | no (no credentials) |
| [`omnilex/eurlex`](https://github.com/glitchr-omnisense/omnilex-eurlex) | `eurlex` | texts, articles, decisions (EU) | search, text, article, decision, citations, recent | open | yes, 2026-10-26 |
| [`omnilex/justice-administrative`](https://github.com/glitchr-omnisense/omnilex-justice-administrative) | `justice-administrative` | decisions of the administrative courts (FR) | search, decision, recent | open | yes, 2026-10-26 |

What each reads, filters and allows: [Sources and search](sources.md).

## How this manual is organised

- [Installation](installation.md): requirements, a first script in plain PHP and its output.
- [Symfony](symfony.md): the bundle, its configuration tree, its services.
- [Laravel](laravel.md): a service provider written by hand (there is no Laravel bridge).
- [Concepts and models](models.md): the contract interface by interface, every model, field and
  enumeration.
- [Identifiers](identifiers.md): the schemes, their normalisation, what an identifier tells.
- Usage: [sources and search](sources.md) (what each source does, the criteria, a legal watch),
  [the law at a date](versions.md) (versions), [authentication](authentication.md) (PISTE).
- [Configuration reference](configuration.md): the registry, every option of every factory.
- [Errors](errors.md): the exceptions, the HTTP statuses behind them, what to catch.
- [Testing](testing.md): testing code that uses the family, the family's own tests.
- [The Docker harness](harness.md): the console, bare PHP, the tests.
- [Writing a provider](providers.md).
- [Verified, and not](verified.md).
