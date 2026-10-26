---
title: Laravel
order: 16
---

# Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has no Omnilex provider. None is
needed: the registry is plain PHP, and a service provider of the application builds it once, from
a config file. The one piece to write is a token store over Laravel's cache, so that the PISTE
token is shared by the requests for the hour it lives.

```php
// config/omnilex.php
return [
    'sources' => [
        'legifrance' => ['factory' => 'legifrance', 'options' => [
            'client_id' => env('PISTE_CLIENT_ID'),
            'client_secret' => env('PISTE_CLIENT_SECRET'),
            'sandbox' => (bool) env('PISTE_SANDBOX', false),      // env() reads "false" as false
        ]],
        'eurlex' => ['factory' => 'eurlex', 'options' => ['language' => 'fr']],
        'administratif' => ['factory' => 'justice-administrative'],
    ],
];
```

```php
// app/Omnilex/LaravelTokenStore.php
namespace App\Omnilex;

use Illuminate\Contracts\Cache\Repository;
use Omnilex\Auth\TokenStoreInterface;
use Omnilex\Model\Token;

final class LaravelTokenStore implements TokenStoreInterface
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function get(string $key): ?Token
    {
        $data = $this->cache->get($key);

        return \is_array($data) && isset($data['access_token']) ? Token::fromArray($data) : null;
    }

    public function set(string $key, Token $token): void
    {
        $this->cache->put($key, $token->toArray(), $token->expiresAt);    // null: kept until deleted
    }

    public function delete(string $key): void
    {
        $this->cache->forget($key);
    }
}
```

```php
// app/Providers/OmnilexServiceProvider.php
namespace App\Providers;

use App\Omnilex\LaravelTokenStore;
use Illuminate\Support\ServiceProvider;
use Omnilex\Eurlex\EurlexSourceFactory;
use Omnilex\JusticeAdministrative\JusticeAdministrativeSourceFactory;
use Omnilex\Legifrance\LegifranceSourceFactory;
use Omnilex\Registry;
use Symfony\Component\HttpClient\HttpClient;

final class OmnilexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function ($app): Registry {
            $http = HttpClient::create(['timeout' => 60]);
            $tokens = new LaravelTokenStore($app['cache.store']);

            return new Registry([
                new LegifranceSourceFactory($http, $tokens),
                new EurlexSourceFactory($http),
                new JusticeAdministrativeSourceFactory($http),
            ], config('omnilex.sources'));
        });
    }
}
```

Register the provider (`bootstrap/providers.php` on Laravel 11 and later), then inject the
registry and ask it for what is needed (`$registry->articles('legifrance')`,
`$registry->having('recent')`).

The HTTP client is `symfony/http-client`'s (every provider requires it). List in the provider
every factory the application uses: a hand-built registry knows only the factories it is given.
This page was written from the contract's API; it was not run in a Laravel application.
