---
title: Symfony
order: 15
---

# Symfony

Omnilex runs without a framework ([Installation](installation.md)); in a Symfony application its
bundle does the wiring. The bundle lives in this package (`Omnilex\Bridge\Symfony`), not in
omnibridge/symfony. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` (6.4, 7 or 8), and a PSR-6 cache pool for the tokens - are not required by
`omnisense/omnilex`: the application has them, and nothing of them is loaded outside Symfony.

## Registering the bundle

There is no Flex recipe: add the bundle by hand.

```php
// config/bundles.php
return [
    // ...
    Omnilex\Bridge\Symfony\OmnilexBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/omnilex.yaml
omnilex:
    sources:                       # by name: a factory and its options
        legifrance: { factory: legifrance, options: { client_id: '%env(default::PISTE_CLIENT_ID)%', client_secret: '%env(default::PISTE_CLIENT_SECRET)%' } }
        judilibre: { factory: judilibre, options: { client_id: '%env(default::PISTE_CLIENT_ID)%', client_secret: '%env(default::PISTE_CLIENT_SECRET)%' } }
        eurlex: { factory: eurlex, options: { language: fr } }
        administratif: { factory: justice-administrative }
    token_cache: cache.app         # where the PISTE tokens are kept; null: in memory only
```

```sh
# .env.local, or bin/console secrets:set
PISTE_CLIENT_ID=...
PISTE_CLIENT_SECRET=...
```

The whole tree, from `OmnilexBundle::configure()`:

| Key | Type | Default | |
|---|---|---|---|
| `omnilex.sources` | map, keyed by the source's name | `{}` | the sources the application uses |
| `omnilex.sources.<name>.factory` | string, required, not empty | - | a factory's name: `legifrance`, `judilibre`, `eurlex`, `justice-administrative`, or an application's own factory's `getName()` |
| `omnilex.sources.<name>.options` | any value (a map) | `[]` | handed as they are to the factory's `create()` ([Configuration](configuration.md)) |
| `omnilex.token_cache` | string or `null` | `cache.app` | the id of a PSR-6 pool (`Psr\Cache\CacheItemPoolInterface`) that keeps the PISTE tokens between two requests; `null`: each PHP process asks for its own token |

The options are not validated when the container compiles but when the source is built: a
credential left empty shows only then, as an `InvalidConfigException`. Use
`'%env(default::PISTE_CLIENT_ID)%'` so that the variable may be absent: the site still boots,
and `Registry::usable()` leaves the source out.

> [!WARNING]
> Known defect: the factories cast `sandbox`, `content` and `resolve_references` with PHP's
> `(bool)`, so the string `'false'` reads as true. Give them as `'%env(bool:PISTE_SANDBOX)%'`,
> never as a plain `%env()%` that may hold `false`.

## Services

| Service id | Class | Autowiring | |
|---|---|---|---|
| `Omnilex\Registry` | `Registry` | `Registry $registry` | every configured source by name. Public. |
| `omnilex.source.<name>` | `SourceInterface` | `SourceInterface $<name>`, `SearchInterface $<name>`, `TextReaderInterface $<name>`, `ArticleReaderInterface $<name>`, `DecisionReaderInterface $<name>`, `CitationsInterface $<name>`, `RecentInterface $<name>` | one source, built by `Registry::get('<name>')` the first time it is injected |
| `Omnilex\Bridge\Symfony\CacheTokenStore` | `CacheTokenStore` | `TokenStoreInterface` (alias) | the tokens in the `token_cache` pool, each until it dies; registered when `token_cache` is set and `psr/cache` is installed |
| `Omnilex\Legifrance\LegifranceSourceFactory`, `...\Judilibre\JudilibreSourceFactory`, `...\Eurlex\EurlexSourceFactory`, `...\JusticeAdministrative\JusticeAdministrativeSourceFactory` | the provider's factory | by its class | registered when its package is installed; given the `http_client` service (or `null` when the application has none) and the token store; tagged `omnilex.source_factory` |

```php
use Omnilex\Exception\UnavailableException;
use Omnilex\Model\Query;
use Omnilex\Source\RecentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WatchController extends AbstractController
{
    #[Route('/watch/conseil-etat')]
    public function __invoke(RecentInterface $administratif): Response
    {
        try {
            $decisions = $administratif->recent(new \DateTimeImmutable('-7 days'), new Query(jurisdictions: ['CE']));
        } catch (UnavailableException) {
            return $this->render('watch/unavailable.html.twig', status: 503);
        }

        return $this->render('watch/index.html.twig', ['decisions' => $decisions]);
    }
}
```

The argument's name selects the source (Symfony's named autowiring aliases); its type the
interface. An alias is registered under each of the seven interfaces for every source, whatever
it implements: a source injected as something it does not do fails where it is injected (PHP's
`TypeError` when the service is built). Inject `SourceInterface` or the `Registry` when in doubt,
and ask `Registry::articles('<name>')`, which throws a `NotSupportedException` that names the
operation.

Nothing is built when the container compiles: a source is built the first time it is asked for.

## An application's own source

A class of the application that implements `Omnilex\Source\SourceFactoryInterface` (usually by
extending `Omnilex\Source\SourceFactory`) is autoconfigured: tagged `omnilex.source_factory`, it
joins the registry, and its `getName()` can be named as a `factory` ([Writing a
provider](providers.md)). It is autowired as any service of the application; the bundle gives the
HTTP client and the token store to its own four factories only.

The bundle registers no command, event, Twig function or route. Cache what the sources answer
(they are remote, and PISTE counts the calls) - never an `UnavailableException`.

`Tests/Bridge/OmnilexBundleTest.php` boots a kernel with this bundle alone (an `http_client`
`MockHttpClient`, a `cache.app` `ArrayAdapter`, two autoconfigured factories of the test's own):
the sources injected by name as what they do, and a PISTE token kept in the application's cache.
