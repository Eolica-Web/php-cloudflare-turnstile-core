<?php

declare(strict_types=1);

namespace Eolica\CloudflareTurnstile\Exception;

use Eolica\CloudflareTurnstile\ErrorCode;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class HttpException extends RuntimeException implements TurnstileException
{
    /**
     * @param list<string> $errorCodes
     */
    private function __construct(
        string $message,
        public readonly array $errorCodes = [],
        ?ClientExceptionInterface $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @internal
     */
    public static function requestFailed(ClientExceptionInterface $previous): self
    {
        return new self('The request to the siteverify endpoint failed.', previous: $previous);
    }

    /**
     * @internal
     *
     * @param list<string> $errorCodes
     */
    public static function unexpectedStatusCode(int $statusCode, array $errorCodes = []): self
    {
        $message = sprintf('The siteverify endpoint responded with an unexpected %d status code', $statusCode);

        if (count($errorCodes) > 0) {
            $message .= sprintf(' (%s)', implode(', ', $errorCodes));
        }

        return new self($message . '.', $errorCodes);
    }

    public function hasErrorCode(ErrorCode $code): bool
    {
        return in_array($code->value, $this->errorCodes, strict: true);
    }
}
