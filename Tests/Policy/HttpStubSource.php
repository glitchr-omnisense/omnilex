<?php

namespace Omnilex\Tests\Policy;

use Omnilex\Model\Capabilities;
use Omnilex\Source\HttpSource;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A source over HTTP that reads {"title": ...} by id, declaring the policy it is given. */
final class HttpStubSource extends HttpSource
{
    public function __construct(HttpClientInterface $http, private readonly ?UsagePolicy $policy = null, float $throttle = 0.0)
    {
        parent::__construct($http, 'https://stub.example.org/', [], $throttle);
    }

    public function getName(): string
    {
        return 'http-stub';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities();
    }

    public function usagePolicy(): UsagePolicy
    {
        return $this->policy ?? parent::usagePolicy();
    }

    public function title(string $id): ?string
    {
        $data = $this->getJson('texts/'.$id);

        return null === $data ? null : (\is_string($data['title'] ?? null) ? $data['title'] : '');
    }
}
