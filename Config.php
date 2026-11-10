<?php

namespace Omnilex;

use Omnilex\Exception\InvalidConfigException;

/**
 * A source's configuration while its factory builds it: the options
 * given, the factory's defaults under them, and the keys that must be filled.
 * omnisense/contracts' Config with the family's prefix ("omnilex.factory_name",
 * "omnilex.required_options", "omnilex.name", "omnilex.limiter"), its messages
 * and its InvalidConfigException; a null or an empty string counts as not set.
 */
final class Config extends \Omnisense\Contracts\Provider\Config
{
    public const PREFIX = 'omnilex';

    /**
     * Sets each key not set yet (a null counts as not set).
     *
     * @param array<string, mixed> $defaults
     */
    public function defaults(array $defaults): static
    {
        foreach ($defaults as $key => $value) {
            if (!$this->offsetExists($key) || null === $this[$key]) {
                $this[$key] = $value;
            }
        }

        return $this;
    }

    /** @param string[] $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" source needs: %s.', $this['omnilex.factory_name'] ?? '?', implode(', ', $missing)));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->offsetExists($key) && null !== $this[$key] && '' !== $this[$key] ? $this[$key] : $default;
    }

    /**
     * A boolean option: an environment variable arrives as a string, so
     * "1", "true", "yes" and "on" are true, anything else ("false", "0",
     * "no", empty, unset) false - where PHP's (bool) "false" is true.
     */
    public function bool(string $key): bool
    {
        $value = $this->get($key);

        return \is_bool($value) ? $value : \in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException(str_replace(' provider', ' source', $message));
    }
}
