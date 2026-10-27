---
title: 'Authentication: PISTE'
order: 34
---

# Authentication: PISTE and OAuth client credentials

The Légifrance and Judilibre APIs are behind [PISTE](https://piste.gouv.fr), the French State's
API portal. An application declared there signs in as itself with the OAuth 2.0 client
credentials flow ([RFC 6749 § 4.4](https://datatracker.ietf.org/doc/html/rfc6749#section-4.4)):

```
POST https://oauth.piste.gouv.fr/api/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=...&client_secret=...&scope=openid
```

```json
{"access_token": "...", "token_type": "Bearer", "expires_in": 3600, "scope": "openid"}
```

Each call then carries `Authorization: Bearer <access_token>`. The sandbox has its own
authorisation server (`sandbox-oauth.piste.gouv.fr`), its own API host
(`sandbox-api.piste.gouv.fr`) and its own credentials.

## What to obtain

1. An account on <https://piste.gouv.fr> (free).
2. An application (one is created for the sandbox with the account; create one for
   production), and for each API - Légifrance, Judilibre - the acceptance of its terms of use
   and its subscription in the application.
3. The application's **client id** and **client secret** (its OAuth credentials). Keep them in
   `.env.local` or the secrets vault: never in the code.

Each API's own steps are in its package's README.

## In the code

The factories of `omnilex/legifrance` and `omnilex/judilibre` take `client_id`, `client_secret`
and `sandbox`, and do the rest:

```php
$legifrance = (new LegifranceSourceFactory($http, $tokenStore))->create([
    'client_id' => $_ENV['PISTE_CLIENT_ID'],
    'client_secret' => $_ENV['PISTE_CLIENT_SECRET'],
]);
```

`Omnilex\Auth\ClientCredentials` asks for a token on the first call, keeps it until a minute
before it dies, and asks for a fresh one after a `401`. Its interfaces:

| | |
|---|---|
| `TokenProviderInterface` | `token(): Token`, `forget()`: what signs a source's calls |
| `ClientCredentials` | the client-credentials flow, for any authorisation server |
| `Piste::credentials($http, $id, $secret, sandbox: false, store: null)` | the same, on PISTE's servers |
| `StaticToken` | a token the application obtained itself |
| `TokenStoreInterface` | `get`, `set`, `delete`: where a token is kept between two requests of an application |

Without a store, a PHP application asks for a new token at every request it serves. With one,
the token is shared for the hour it lives. The Symfony bundle gives a store over `cache.app`
(`Bridge\Symfony\CacheTokenStore`); elsewhere, implement the three methods over your cache. The
store's key names the authorisation server and the client id, never the secret.

## When it is refused

| | |
|---|---|
| The authorisation server refuses the client id or secret | `AuthenticationException` |
| The API refuses the token: API not subscribed, terms not accepted (401, 403) | `AuthenticationException`, after one retry with a fresh token on a 401 |
| The authorisation server or the API is down | `UnavailableException` |
| The application's quota is spent (429) | `RateLimitedException` |

All three extend `UnavailableException`: a caller that reads "refused" as "this article does not
exist" would be wrong, and cannot do so by accident.
