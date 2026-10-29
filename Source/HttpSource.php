<?php

namespace Omnilex\Source;

use Omnilex\Auth\TokenProviderInterface;
use Omnilex\Exception\AuthenticationException;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\ProviderException;
use Omnilex\Exception\RateLimitedException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Http\Redaction;
use Omnilex\Model\Identifier;
use Omnilex\Model\Query;
use Omnilex\Model\Scheme;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the sources over HTTP share: the calls, signed when the source has
 * a token provider (a token refused once is asked afresh, once), their
 * errors mapped the same way -
 *
 *   404, 410                      null
 *   401, 403, a refused token     AuthenticationException
 *   429                           RateLimitedException (Retry-After kept)
 *   423, 5xx, transport errors    UnavailableException
 *   any other 4xx                 ProviderException
 *
 * - a minimum interval between two calls, the reading of identifiers given
 * as strings, and the refusal of the search criteria the source cannot
 * apply.
 */
abstract class HttpSource implements SourceInterface
{
    private float $lastCall = 0.0;

    /**
     * @param array<string, string> $headers  sent with every call (User-Agent...)
     * @param float                 $throttle seconds to leave between two calls
     */
    public function __construct(
        protected readonly HttpClientInterface $http,
        protected readonly string $baseUri,
        protected readonly array $headers = [],
        protected readonly float $throttle = 0.0,
        protected readonly ?TokenProviderInterface $auth = null,
    ) {
    }

    /**
     * GETs JSON. Null when the service answers 404 / 410.
     *
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    protected function getJson(string $path, array $query = [], array $headers = []): ?array
    {
        return $this->decode($this->call('GET', $path, $query, null, $headers + ['Accept' => 'application/json']));
    }

    /**
     * POSTs a JSON body, reads a JSON answer. Null when the service answers 404 / 410.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function postJson(string $path, array $body, array $headers = []): ?array
    {
        return $this->decode($this->call('POST', $path, [], $body, $headers + ['Accept' => 'application/json']));
    }

    /**
     * GETs a body as text (XHTML, XML...). Null when the service answers 404 / 410.
     *
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $query = [], array $headers = []): ?string
    {
        return $this->call('GET', $path, $query, null, $headers);
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    private function call(string $method, string $path, array $query, ?array $json, array $headers, bool $retried = false): ?string
    {
        $this->wait();
        $url = str_starts_with($path, 'http') ? $path : rtrim($this->baseUri, '/').'/'.ltrim($path, '/');
        $pairs = [];
        foreach ($query as $key => $values) {
            // A list repeats its key (type=arret&type=qpc); null and empty are left out.
            foreach (\is_array($values) ? $values : [$values] as $value) {
                if (null !== $value && '' !== $value) {
                    $pairs[] = rawurlencode((string) $key).'='.rawurlencode(\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                }
            }
        }
        if ($pairs) {
            $url .= (str_contains($url, '?') ? '&' : '?').implode('&', $pairs);
        }
        $options = ['headers' => $headers + $this->headers];
        if (null !== $this->auth) {
            $token = $this->auth->token();
            $options['headers']['Authorization'] = $token->type.' '.$token->accessToken;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (404 === $status || 410 === $status) {
                return null;
            }
            $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? null;
            if (401 === $status || 403 === $status || (400 === $status && null !== $challenge)) {
                if (401 === $status && null !== $this->auth && !$retried) {
                    $this->auth->forget();

                    return $this->call($method, $path, $query, $json, $headers, true);
                }
                throw Redaction::withoutArguments(new AuthenticationException($this->getName(), \sprintf('the service refuses the credentials (%d)%s', $status, self::excerpt($challenge ?? $response->getContent(false))), $status));
            }
            if (429 === $status) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;
                throw Redaction::withoutArguments(new RateLimitedException($this->getName(), null !== $retry && is_numeric($retry) ? (int) $retry : null));
            }
            if ($status >= 500 || 423 === $status) {
                throw Redaction::withoutArguments(new UnavailableException($this->getName(), \sprintf('the service answered %d%s', $status, self::excerpt($response->getContent(false))), $status));
            }
            if ($status >= 400) {
                throw Redaction::withoutArguments(new ProviderException($this->getName(), \sprintf('the service answered %d%s', $status, self::excerpt($response->getContent(false))), $status));
            }

            return $response->getContent();
        } catch (TransportExceptionInterface $e) {
            // The key or the token (the query, the headers) masked in the message and the previous, a copy; no trace keeps the arguments.
            $secrets = Redaction::secrets($url, $options);

            throw Redaction::withoutArguments(new UnavailableException($this->getName(), 'unreachable: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        } catch (ExceptionInterface $e) {
            $secrets = Redaction::secrets($url, $options);

            throw Redaction::withoutArguments(new ProviderException($this->getName(), Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
    }

    private function decode(?string $body): ?array
    {
        if (null === $body) {
            return null;
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException($this->getName(), 'the answer is not JSON: '.$e->getMessage(), null, $e);
        }

        return \is_array($data) ? $data : throw new ProviderException($this->getName(), 'the answer is not a JSON object');
    }

    /** Leaves the configured interval since the last call. */
    private function wait(): void
    {
        if ($this->throttle > 0 && $this->lastCall > 0) {
            $left = $this->throttle - (microtime(true) - $this->lastCall);
            if ($left > 0) {
                usleep((int) ($left * 1_000_000));
            }
        }
        $this->lastCall = microtime(true);
    }

    private static function excerpt(?string $body): string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', strip_tags((string) $body)));

        return '' === $text ? '' : ': '.mb_substr($text, 0, 300);
    }

    /**
     * Refuses the criteria of a query the source cannot apply (those its
     * Capabilities::$criteria do not name), rather than answer unfiltered.
     *
     * @param list<string> $ignored criteria this operation does not read (recent() leaves from, to out)
     */
    protected function accept(Query $query, array $ignored = []): void
    {
        $capabilities = $this->capabilities();
        foreach ($query->criteria() as $criterion) {
            if (!\in_array($criterion, $ignored, true) && !$capabilities->filters($criterion)) {
                throw NotSupportedException::criterion($this->getName(), $criterion);
            }
        }
    }

    /**
     * An identifier given as an Identifier or a string, when it is of one of
     * the schemes the source reads; null when it is none of them (one of
     * the source's own identifiers, kept as the string).
     */
    protected static function identify(Identifier|string $id, Scheme ...$schemes): ?Identifier
    {
        if ($id instanceof Identifier) {
            return \in_array($id->scheme, $schemes, true) ? $id : null;
        }
        $parsed = Identifier::parse($id);

        return null !== $parsed && \in_array($parsed->scheme, $schemes, true) ? $parsed : null;
    }

    /** HTML or XHTML as plain text: blocks become lines, entities are decoded, white space collapsed. */
    protected static function plain(?string $html): ?string
    {
        if (null === $html) {
            return null;
        }
        $text = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $text = (string) preg_replace('~<br\s*/?>|</(p|div|h[1-6]|li|tr|table|blockquote|section|article)>~i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~[ \t\x{00A0}]+~u', ' ', $text);
        $text = (string) preg_replace('~ ?\n ?~', "\n", $text);
        // A list's marker laid out in a cell of its own ("a)", "1.", "—") goes back before its item.
        $text = (string) preg_replace('~^(\(?[0-9a-zA-Z]{1,4}[.)]|[—–-])\n+(?=\S)~mu', '$1 ', $text);
        $text = trim((string) preg_replace('~\n{3,}~', "\n\n", $text));

        return '' === $text ? null : $text;
    }

    /**
     * A day from what the services send: "2018-12-20", an ISO date-time,
     * milliseconds since 1970 (Légifrance). Null for nothing, for what does
     * not parse, and for the far-future dates that stand for "no end"
     * (2999-01-01, 9999-12-31).
     */
    protected static function day(int|string|null $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        try {
            if (\is_int($value) || preg_match('~^-?\d{10,}$~', $value)) {
                // Midnight in Paris is the evening before in UTC: read the day where the law is dated.
                $date = (new \DateTimeImmutable('@'.intdiv((int) $value, 1000)))->setTimezone(new \DateTimeZone('Europe/Paris'));
            } else {
                $date = new \DateTimeImmutable($value, $utc);
            }
        } catch (\Exception) {
            return null;
        }
        if ((int) $date->format('Y') >= 2900) {
            return null;
        }

        return new \DateTimeImmutable($date->format('Y-m-d'), $utc);
    }
}
