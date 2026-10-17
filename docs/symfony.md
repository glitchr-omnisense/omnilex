# Symfony

Omnilex runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel`, and a cache pool for the tokens - are not required by `omniscient/omnilex`:
the application has them, and nothing of them is loaded outside Symfony.

Register `Omnilex\Bridge\Symfony\OmnilexBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnilex\Bridge\Symfony\OmnilexBundle::class => ['all' => true],
];
```

```yaml
# config/packages/omnilex.yaml
omnilex:
    sources:                       # by name: a factory and its options
        legifrance: { factory: legifrance, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
        judilibre: { factory: judilibre, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
        eurlex: { factory: eurlex, options: { language: fr } }
        administratif: { factory: justice-administrative }
    token_cache: cache.app         # where the PISTE tokens are kept; null: in memory only
```

```sh
# .env.local, or bin/console secrets:set
PISTE_CLIENT_ID=...
PISTE_CLIENT_SECRET=...
```

Every `omnilex/*` package installed registers its factory, on the application's `http_client`
and with the token store. What is autowired:

| Service | |
|---|---|
| `ArticleReaderInterface $legifrance` | one source by the argument's name (the configured name), as one thing it does |
| `SearchInterface`, `TextReaderInterface`, `DecisionReaderInterface`, `CitationsInterface`, `RecentInterface`, `SourceInterface` | the same source, typed as each interface |
| `Registry` | every configured source by name (`get()`, `decisions()`, `having('recent')`...) |
| `TokenStoreInterface` | the store over the token cache |

A source injected as something it does not do fails where it is injected (a `TypeError` at
construction): inject `SourceInterface` or the `Registry` when in doubt.

Nothing is built when the container compiles: a source is built the first time it is asked
for, and a credential left empty only shows then (`InvalidConfigException`). A site whose PISTE
credentials are not there yet still boots; use `'%env(default::PISTE_CLIENT_ID)%'` to let the
variable be absent.

An application's own source - a class implementing `SourceFactoryInterface` - is registered too,
autoconfigured, and can be named as a `factory`.

```php
final class WatchController extends AbstractController
{
    #[Route('/veille/conseil-etat')]
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

Cache what the sources answer (they are remote, and PISTE counts the calls) - never an
`UnavailableException`.
