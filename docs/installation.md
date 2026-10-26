# Installation and first calls

```sh
composer require omnisense/omnilex omnilex/eurlex omnilex/justice-administrative
composer require omnilex/legifrance omnilex/judilibre    # with a PISTE account
```

PHP 8.2 or later.

Omnilex needs no framework. The core requires nothing but `symfony/http-client-contracts`, each
source package `symfony/http-client`: two libraries that stand alone. It runs the same in plain
PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle does the wiring
([Symfony](symfony.md)).

## Plain PHP

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnilex\Eurlex\EurlexSourceFactory;
use Omnilex\Registry;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$registry = new Registry([new EurlexSourceFactory($http)], [
    'eurlex' => ['factory' => 'eurlex', 'options' => ['language' => 'fr']],
]);

// Article 17 of the General Data Protection Regulation, as in force on 1 June 2018
$article = $registry->articles('eurlex')->article('32016R0679', '17', new DateTimeImmutable('2018-06-01'));

echo $article->textTitle, "\n\n";
echo 'Article ', $article->number, ' - ', $article->title, "\n";
echo 'version ', $article->version->id, ', from ', $article->version->from->format('Y-m-d'), ', ', $article->version->status->value, "\n\n";
echo strtok($article->content, "\n"), "\n";
```

```
$ php bare.php
Règlement (UE) 2016/679 du Parlement européen et du Conseil du 27 avril 2016 relatif à la protection des personnes physiques à l'égard du traitement des données à caractère personnel et à la libre circulation de ces données, et abrogeant la directive 95/46/CE (règlement général sur la protection des données) (Texte présentant de l'intérêt pour l'EEE)

Article 17 - Droit à l'effacement («droit à l'oubli»)
version 02016R0679-20160504, from 2016-05-04, in_force

1. La personne concernée a le droit d'obtenir du responsable du traitement l'effacement, dans les meilleurs délais, de données à caractère personnel la concernant et le responsable du traitement a l'obligation d'effacer ces données à caractère personnel dans les meilleurs délais, lorsque l'un des motifs suivants s'applique:
```

(as answered on 2026-10-05)

No key, no account: the script asks the Publications Office's open endpoint. That is all there is
to it:

- a **factory** per source package (`EurlexSourceFactory`, `JusticeAdministrativeSourceFactory`,
  `LegifranceSourceFactory`, `JudilibreSourceFactory`), which takes the HTTP client to call with -
  the application's, a `MockHttpClient` in a test; with none given it makes its own
  (`HttpClient::create()`) - and, for the sources behind PISTE, a token store
  ([authentication](authentication.md));
- the **registry**, built by hand from the factories and the sources' options, by name;
- the **sources** it gives, each as what it does: `articles()`, `texts()`, `decisions()`,
  `search()`, `citations()`, `recent()`.

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnilex bare`
([harness](harness.md)).

## One source

```php
use Omnilex\Eurlex\EurlexSourceFactory;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Symfony\Component\HttpClient\HttpClient;

$eurlex = (new EurlexSourceFactory(HttpClient::create()))->create(['language' => 'fr']);

$text = $eurlex->text('32016R0679', new DateTimeImmutable('2018-06-01'));
$text->title;                            // Règlement (UE) 2016/679 ...
$text->version->id;                      // 02016R0679-20160504
$text->isInForce();                      // true

$article = $eurlex->article('32016R0679', '17');
$article->title;                         // Droit à l'effacement («droit à l'oubli»)

$results = $eurlex->search(new Query(text: 'protection des données', kind: Kind::TEXT, types: ['REG'], from: '2016-01-01'));
foreach ($results as $reference) {
    echo $reference->date?->format('Y-m-d'), ' ', $reference->id, ' ', $reference->title, "\n";
}
$next = $eurlex->search(new Query(text: 'protection des données', cursor: $results->next));   // the next page
```

A document is named by an `Identifier` or a string the source reads: a normalised identifier
(`'32016R0679'`, `'ECLI:EU:C:2014:317'`, `'JUSC1732516D'`, `'LEGIARTI000033219357'`), the URL of
its page, or the source's own identifier - the `id` its search gave.

## Several sources

```php
use Omnilex\Registry;

$registry = new Registry(
    [new EurlexSourceFactory($http), new JusticeAdministrativeSourceFactory($http), new LegifranceSourceFactory($http)],
    [
        'eurlex' => ['factory' => 'eurlex', 'options' => ['language' => 'fr']],
        'administratif' => ['factory' => 'justice-administrative'],
        'legifrance' => ['factory' => 'legifrance', 'options' => ['client_id' => '...', 'client_secret' => '...']],
    ],
);

$registry->decisions('eurlex')->decision('ECLI:EU:C:2014:317');   // typed: a DecisionReaderInterface
$registry->recent('administratif')->recent(new DateTimeImmutable('-7 days'));
$registry->having('recent');                 // the sources a watch can ask
$registry->reading(Scheme::ECLI);            // the sources that read an ECLI
```

Nothing is built before a source is asked for: a registry whose PISTE credentials are missing
still works for the other sources. `having()`, `reading()` and `usable()` leave out the sources
whose options are incomplete; `get()` and `all()` refuse them (`InvalidConfigException`).

## In a framework

- **Symfony**: `Omnilex\Bridge\Symfony\OmnilexBundle` registers the factories on the
  application's `http_client`, keeps the PISTE tokens in `cache.app`, builds the registry from
  `config/packages/omnilex.yaml` and makes each source injectable by its name, as what it does:
  see [Symfony](symfony.md). Its components (`symfony/config`, `symfony/dependency-injection`,
  `symfony/http-kernel`, a cache pool) are not required by this package: a Symfony application
  has them.
- **Any other**: build the `Registry` once, where the framework builds its services (a service
  provider, a container definition), as the script above does.

## A legal watch

```php
foreach ($registry->having('recent') as $name => $source) {
    try {
        $new = $source->recent($lastRun, new Query(kind: Kind::DECISION));
    } catch (UnavailableException $e) {
        continue;   // down, refused or slowed: try again later - not "nothing new"
    }
    // store $new->items for validation; follow $new->next for the pages after
}
```

Each source says which date `recent()` reads: the day a decision was added to the base
(administrative courts, Judilibre), the day a text was published (Légifrance), the date of the
document (EUR-Lex).

## Errors

| Exception | When |
|---|---|
| `NotSupportedException` | the source does not do that, cannot read that identifier, cannot apply that criterion |
| `AuthenticationException` | the credentials are refused: wrong client id or secret, API not subscribed, terms not accepted |
| `RateLimitedException` | 429: slow down (`$retryAfter` seconds, when the service says) |
| `UnavailableException` | 5xx, 423, a timeout, a network error (the two above extend it): never "nothing" |
| `ProviderException` | any other error answer (a malformed query: 400) |
| `InvalidConfigException` | a source not configured, a factory not installed, an option missing |

All implement `Omnilex\Exception\OmnilexException`. Not found is `null` or an empty page.

## Rate limits

Every source takes a `throttle` option: the seconds left between two calls (0.5 by default, 1
for the administrative courts' open data). The PISTE quotas are per application and shown on
the portal; a quota spent comes back as a `RateLimitedException`. Cache what the sources answer
- a version of a text that is no longer in force never changes - but never an
`UnavailableException`.
