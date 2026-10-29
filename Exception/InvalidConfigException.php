<?php

namespace Omnilex\Exception;

/**
 * A source misconfigured: a key missing, an unknown factory, an option a
 * source refuses (EUR-Lex's language, Judilibre's recent). An
 * \InvalidArgumentException, and so a \LogicException, as it was.
 */
final class InvalidConfigException extends \InvalidArgumentException implements OmnilexException
{
}
