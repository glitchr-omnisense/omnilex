<?php

namespace Omnilex\Auth;

use Omnilex\Exception\AuthenticationException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Http\Redaction;
use Omnilex\Model\Token;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth 2.0, client credentials (RFC 6749 § 4.4): the application signs in
 * as itself, with the client id and secret its portal gave it. This is how
 * PISTE opens the Légifrance and Judilibre APIs:
 *
 *   POST https://oauth.piste.gouv.fr/api/oauth/token
 *   grant_type=client_credentials&client_id=...&client_secret=...&scope=openid
 *
 * The token is kept until a minute before it dies, in memory and in the
 * store when one is given.
 */
final class ClientCredentials implements TokenProviderInterface
{
    private const LEEWAY = 60;

    private ?Token $token = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $tokenUri,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $scope = 'openid',
        private readonly ?TokenStoreInterface $store = null,
        private readonly string $source = 'oauth',
    ) {
    }

    public function token(): Token
    {
        if (null !== $this->token && !$this->token->isExpired(self::LEEWAY)) {
            return $this->token;
        }
        $stored = $this->store?->get($this->key());
        if (null !== $stored && !$stored->isExpired(self::LEEWAY)) {
            return $this->token = $stored;
        }

        try {
            $response = $this->http->request('POST', $this->tokenUri, [
                'headers' => ['Accept' => 'application/json'],
                'body' => ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'scope' => $this->scope],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            // The client secret, in the body, kept out: the previous a copy of the client's exception, no trace keeping the options.
            $secrets = Redaction::secrets($this->tokenUri, ['body' => ['client_secret' => $this->clientSecret]]);

            throw Redaction::withoutArguments(new UnavailableException($this->source, 'the authorisation server is unreachable: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 500) {
            throw new UnavailableException($this->source, \sprintf('the authorisation server answered %d', $status), $status);
        }
        $data = json_decode($body, true);
        if ($status >= 400 || !\is_array($data) || !isset($data['access_token'])) {
            $why = \is_array($data) ? ($data['error_description'] ?? $data['error'] ?? null) : null;
            throw new AuthenticationException($this->source, 'the client id or secret is refused'.(\is_string($why) ? ': '.trim($why) : \sprintf(' (%d)', $status)), $status);
        }

        $lifetime = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : null;
        $this->token = new Token(
            (string) $data['access_token'],
            null !== $lifetime ? (new \DateTimeImmutable())->modify("+$lifetime seconds") : null,
            (string) ($data['token_type'] ?? 'Bearer'),
            isset($data['scope']) ? (string) $data['scope'] : null,
        );
        $this->store?->set($this->key(), $this->token);

        return $this->token;
    }

    public function forget(): void
    {
        $this->token = null;
        $this->store?->delete($this->key());
    }

    /** One key per authorisation server and client: the secret is not part of it. */
    private function key(): string
    {
        return 'omnilex.token.'.hash('xxh128', $this->tokenUri.'|'.$this->clientId.'|'.$this->scope);
    }
}
