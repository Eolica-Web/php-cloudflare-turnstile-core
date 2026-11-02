<?php

declare(strict_types=1);

namespace Eolica\CloudflareTurnstile\Exception;

use JsonException;
use RuntimeException;

final class InvalidResponseException extends RuntimeException implements TurnstileException
{
    /**
     * @internal
     */
    public static function malformedBody(?JsonException $previous = null): self
    {
        return new self('The siteverify response body is not a valid JSON object.', previous: $previous);
    }

    /**
     * @internal
     */
    public static function invalidField(string $field): self
    {
        return new self(sprintf('The siteverify response has a missing or invalid "%s" field.', $field));
    }
}
