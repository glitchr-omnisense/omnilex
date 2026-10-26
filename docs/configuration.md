---
title: Configuration reference
order: 40
---

# Configuration reference

Every option below is read from the factories' code at this commit (`populate()` and what `build()`
reads). Options reach a factory through `create($options)`, a `Registry`'s `options`, or the
bundle's `omnilex.sources.<name>.options` ([Symfony](symfony.md#configuration)).

## The registry

```php
new Registry(iterable $factories, array $config);
// $config: ['eurlex' => ['factory' => 'eurlex', 'options' => ['language' => 'fr']], ...]
```

| Method | |
|---|---|
| `get(string $name): SourceInterface` | the source, built on the first call and kept |
| `search()`, `texts()`, `articles()`, `decisions()`, `citations()`, `recent()` (each `string $name`) | the source as that interface, or `NotSupportedException` (`The "administratif" source does not read texts.`) |
| `has(string $name): bool` | whether it is configured |
| `names(): list<string>` | the configured sources, in the configured order |
| `all(): array<string, SourceInterface>` | every configured source, built; throws for one whose options are incomplete |
| `usable(): array<string, SourceInterface>` | those that can be built: a source whose options are incomplete (`InvalidConfigException`) is left out |
| `having(string $operation): array` | the usable sources that do one thing: `search`, `text`, `article`, `decision`, `citations`, `recent`, or an interface's class name |
| `reading(Scheme\|Kind $what): array` | the usable sources that read that kind of identifier, or hold that kind of document |
| `factories(): list<string>` | the factories installed: what `factory` may name |
| `options(string $name): array` | the options it is configured with (the factory's defaults not included) |

A later factory of the same name replaces an earlier one. A name not configured is an
`InvalidConfigException` (`No "x" source; configured: eurlex, administratif.`), so is a factory
not installed (`No "x" factory for the "y" source; installed: eurlex.`). Nothing is built before a
source is asked for.

## How options are read

`Omnilex\Config` (an `ArrayObject`) holds the options given, with the factory's defaults under
them: `defaults()` sets each key not given **or given as `null`**. `get($key, $default)` gives the
default for a key missing, `null` or `''` - so an empty environment variable is "not set". Before
`build()`, `validateNotEmpty()` refuses each key of `omnilex.required_options` that is missing, `''`
or `[]` (`The "legifrance" source needs: client_id, client_secret.`).

The keys every factory sets for itself: `omnilex.factory_name` (what `getName()` reads) and
`omnilex.required_options`.

## Options every source takes

Set by `SourceFactory::createConfig()` under each provider's own defaults:

| Option | Type | Default | |
|---|---|---|---|
| `user_agent` | string | `omnilex/1.x (+https://github.com/glitchr-omnisense/omnilex)` | the `User-Agent` of every call |
| `throttle` | float | each provider's (below); `0.0` for a provider that sets none | seconds left between two calls of the source |
| `base_uri` | string | each provider's | the service's address, to point at a copy or a proxy |

A factory's constructor takes the HTTP client and the token store:

```php
new LegifranceSourceFactory(?HttpClientInterface $http = null, ?TokenStoreInterface $tokens = null);
```

Without a client, each factory makes its own (`HttpClient::create()` with `timeout` 60 seconds; 30
for Judilibre). The store is read by the PISTE sources only ([Authentication](authentication.md)).

> [!WARNING]
> Known defect: the boolean options (`sandbox`, `content`, `resolve_references`) are read with
> PHP's `(bool)`: the strings `'false'` and `'no'` are true (seen: `sandbox: 'false'` builds a
> source on PISTE's sandbox), where an option given by an environment variable is expected to read
> `"false"` as false. Give a real boolean (`%env(bool:...)%` in Symfony).

## legifrance

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | the PISTE application's OAuth client id |
| `client_secret` | string | - | yes | its secret |
| `sandbox` | bool | `false` | no | PISTE's sandbox: `https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app` and `https://sandbox-oauth.piste.gouv.fr/api/oauth/token`, with the sandbox application's credentials |
| `throttle` | float | `0.5` | no | |
| `base_uri` | string | `https://api.piste.gouv.fr/dila/legifrance/lf-engine-app` (or the sandbox's) | no | |
| `token_uri` | string | `https://oauth.piste.gouv.fr/api/oauth/token` (or the sandbox's) | no | |
| `user_agent` | string | as above | no | |

## judilibre

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id`, `client_secret` | string | `null` | these two, or `key_id` | OAuth client credentials through PISTE |
| `key_id` | string | `null` | | the PISTE application's API key, sent as the `KeyId` header; when set, the OAuth pair is not used |
| `sandbox` | bool | `false` | no | `https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0` and the sandbox's token server |
| `recent` | `update` or `creation` | `update` | no | the date `recent()` reads: created or updated in the base, or created; another value is an `InvalidArgumentException` |
| `resolve_references` | bool | `false` | no | `resolve_references=true` on every call: labels in place of keys in the answers (`Court::$code` is then `null`) |
| `throttle` | float | `0.5` | no | |
| `base_uri` | string | `https://api.piste.gouv.fr/cassation/judilibre/v1.0` (or the sandbox's) | no | |
| `token_uri` | string | PISTE's (or the sandbox's) | no | |
| `user_agent` | string | as above | no | |

Neither `key_id` nor both OAuth options: `InvalidConfigException` (`The "judilibre" source needs:
client_id and client_secret, or key_id.`).

## eurlex

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `language` | ISO 639-1 | `fr` | no | the titles', labels' and texts' language: one of the 24 official languages of the Union (`bg`, `cs`, `da`, `de`, `el`, `en`, `es`, `et`, `fi`, `fr`, `ga`, `hr`, `hu`, `it`, `lt`, `lv`, `mt`, `nl`, `pl`, `pt`, `ro`, `sk`, `sl`, `sv`); another is an `InvalidArgumentException` |
| `content` | bool | `true` | no | whether `text()` and `decision()` also fetch the document itself (one more call, up to a few megabytes); `article()` always does |
| `throttle` | float | `0.5` | no | |
| `base_uri` | string | `https://publications.europa.eu/webapi/rdf/sparql` | no | the SPARQL endpoint |
| `resource_uri` | string | `https://publications.europa.eu/resource/` | no | where the documents are read by CELEX number |
| `user_agent` | string | as above | no | |

## justice-administrative

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `recent` | `lastModified` or `Date_Lecture` | `lastModified` | no | the date `recent()` reads: added to the base, or read in open court; another value is an `InvalidArgumentException` |
| `throttle` | float | `1.0` | no | |
| `base_uri` | string | `https://opendata.justice-administrative.fr/recherche/api` | no | |
| `user_agent` | string | as above | no | |

## The bundle

`omnilex.sources` and `omnilex.token_cache` ([Symfony](symfony.md#configuration)).
