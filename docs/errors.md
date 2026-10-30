---
title: Errors
order: 50
---

# Errors

Not found is `null` (or an empty list, an empty page): `text()`, `article()`, `decision()` answer
`null` for what the service does not know, `citations()` an empty list. Everything else is an
exception implementing `Omnilex\Exception\OmnilexException` (an interface extending `\Throwable`):

| Exception | Extends | Thrown when |
|---|---|---|
| `InvalidConfigException` | `\InvalidArgumentException` (a `\LogicException`) | a source not configured, a factory not installed, a required option missing or empty (Légifrance's credentials, Judilibre's credentials or key), an option a source refuses (EUR-Lex's `language`, Judilibre's and the administrative courts' `recent`) |
| `NotSupportedException` | `\LogicException` | the source does not do that: an operation it has no interface for (the registry's getters), an identifier it cannot read, a search criterion it cannot apply, an article asked without its number where one is needed |
| `ProviderException` | `\RuntimeException` | the service answered with an error: any 4xx but those below, an answer that is not JSON or not what was expected, a route that no longer answers (the administrative courts' engine) |
| `UnavailableException` | `ProviderException` | the service could not be reached, is down, or refuses for now: 5xx, 423, a transport error or a timeout; the authorisation server unreachable or answering 5xx |
| `AuthenticationException` | `UnavailableException` | the credentials are refused: 401 (after one retry with a fresh token), 403, a 400 with a `WWW-Authenticate` challenge, a token refused by the authorisation server |
| `RateLimitedException` | `UnavailableException` | 429: slow down, or the PISTE application's quota is spent |

`ProviderException` and its children keep what the service said:

| Property | |
|---|---|
| `source` | the source's name (`piste` or the source's for the token server) |
| `status` | the HTTP status, when there was one |
| `retryAfter` | `RateLimitedException` only: the seconds of `Retry-After`, when numeric |
| `getMessage()` | `[<source>] <message>`: `the service answered 503: <the body's first 300 characters, tags stripped>`, `unreachable: <transport error>`, `the service refuses the credentials (401): <challenge or body>`, `rate limited, retry in 30 s`, `the client id or secret is refused: <error_description>` |
| `getPrevious()` | the HTTP client's exception, for a transport error |

## How HTTP becomes an answer or an exception

`HttpSource` (every provider) maps each answer the same way:

| Answer | Result |
|---|---|
| 2xx | the body (JSON decoded for the JSON calls: a body that is not a JSON object is a `ProviderException`) |
| 404, 410 | `null`: not found |
| 401 | the token forgotten and asked afresh, the call made once more; refused again: `AuthenticationException` |
| 403, or 400 with `WWW-Authenticate` | `AuthenticationException` |
| 429 | `RateLimitedException`, `Retry-After` kept |
| 423, 5xx | `UnavailableException` |
| any other 4xx | `ProviderException` |
| a transport error, a timeout | `UnavailableException` |

`AuthenticationException` and `RateLimitedException` extend `UnavailableException` on purpose: a
caller that reads "refused" as "this article does not exist" would be wrong, and cannot do so by
accident.

## What to catch

```php
use Omnilex\Exception\AuthenticationException;
use Omnilex\Exception\ProviderException;
use Omnilex\Exception\RateLimitedException;
use Omnilex\Exception\UnavailableException;

try {
    $article = $registry->articles('legifrance')->article('LEGITEXT000006070721', '1240');
} catch (RateLimitedException $e) {
    $retry = $e->retryAfter ?? 60;                      // try again later
} catch (AuthenticationException $e) {
    $logger->critical($e->getMessage());                // the PISTE application: credentials, subscription, terms
} catch (UnavailableException) {
    // down: show "unavailable", never "no such article", and cache nothing
} catch (ProviderException $e) {
    $logger->error($e->getMessage(), ['status' => $e->status]);
}

if (null === $article) {
    // not found: the service does not know it, or knows no version that day
}
```

`InvalidConfigException` and `NotSupportedException` are programming errors (`LogicException`s):
test `Registry::has()`, `Capabilities::operations()` and `capabilities()->filters()` rather than
catch them. A caller that caches answers never caches an `UnavailableException`; a legal watch
that meets one tries again later rather than record "nothing new".

## Outside the family

- `InvalidArgumentException`: from `Identifier::of()` and its shortcuts (`"2016/679" is not a valid
  CELEX number.`), `Identifier::legifrance()`, `Query`'s dates (`"x" is not a date.`), EUR-Lex's
  search text without a word (`The search text holds no word.`) or a code of an authority table
  that is not one (`types`, `subjects`, `jurisdictions` with other characters than letters, digits,
  `_`, `.`, `-`). EUR-Lex's `language`, Judilibre's and the administrative courts' `recent` given
  wrong are an `InvalidConfigException` when the source is built, as every misconfiguration.
- `ValueError`: `Identifier::of()` with a scheme name that is not a `Scheme`.
- `TypeError`: in Symfony, a source injected under an interface it does not implement
  ([Symfony](symfony.md#services)).

## No secret in a failure

A source keeps its credentials out of what it throws: a key in the query, the bearer of its token,
the client secret sent for the token (`ClientCredentials`) are masked in the HTTP client's message
(`Http\Redaction::text()`), the previous exception is a copy of the client's (`Redaction::copy()`:
the same class and code), and no trace of the chain keeps its arguments, where the query, headers
and body stood (`zend.exception_ignore_args` off).
