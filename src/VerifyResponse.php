<?php

declare(strict_types=1);

namespace Eolica\CloudflareTurnstile;

use DateTimeImmutable;
use Eolica\CloudflareTurnstile\Exception\InvalidResponseException;

/**
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/#response-fields
 */
final readonly class VerifyResponse
{
    /**
     * @param list<string> $errorCodes
     */
    public function __construct(
        public bool $success,
        public array $errorCodes = [],
        public ?DateTimeImmutable $challengedAt = null,
        public ?string $hostname = null,
        public ?string $action = null,
        public ?string $cdata = null,
        public ?string $ephemeralId = null,
        public bool $resultWithTestingKey = false,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public static function fromArray(array $data): self
    {
        $success = $data['success'] ?? null;

        if (!is_bool($success)) {
            throw InvalidResponseException::invalidField('success');
        }

        $metadata = $data['metadata'] ?? [];

        if (!is_array($metadata)) {
            throw InvalidResponseException::invalidField('metadata');
        }

        return new self(
            success: $success,
            errorCodes: self::parseErrorCodes($data),
            challengedAt: self::parseChallengeTimestamp($data),
            hostname: self::optionalString($data, 'hostname'),
            action: self::optionalString($data, 'action'),
            cdata: self::optionalString($data, 'cdata'),
            ephemeralId: self::optionalString($metadata, 'ephemeral_id', 'metadata.ephemeral_id'),
            resultWithTestingKey: self::parseResultWithTestingKey($metadata),
        );
    }

    public function hasErrorCode(ErrorCode $code): bool
    {
        return in_array($code->value, $this->errorCodes, strict: true);
    }

    /**
     * @internal
     */
    public function failedWith(ErrorCode $code): self
    {
        return clone($this, [
            'success' => false,
            'errorCodes' => [...$this->errorCodes, $code->value],
        ]);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function parseErrorCodes(array $data): array
    {
        $errorCodes = $data['error-codes'] ?? [];

        if (!is_array($errorCodes) || !array_is_list($errorCodes)) {
            throw InvalidResponseException::invalidField('error-codes');
        }

        foreach ($errorCodes as $errorCode) {
            if (!is_string($errorCode)) {
                throw InvalidResponseException::invalidField('error-codes');
            }
        }

        /** @var list<string> $errorCodes */
        return $errorCodes;
    }

    /**
     * @param array<mixed> $data
     */
    private static function parseChallengeTimestamp(array $data): ?DateTimeImmutable
    {
        $timestamp = self::optionalString($data, 'challenge_ts');

        if ($timestamp === null) {
            return null;
        }

        $challengedAt = DateTimeImmutable::createFromFormat(DATE_RFC3339_EXTENDED, $timestamp);

        if (!$challengedAt instanceof DateTimeImmutable) {
            throw InvalidResponseException::invalidField('challenge_ts');
        }

        return $challengedAt;
    }

    /**
     * @param array<mixed> $metadata
     */
    private static function parseResultWithTestingKey(array $metadata): bool
    {
        $resultWithTestingKey = $metadata['result_with_testing_key'] ?? false;

        if (!is_bool($resultWithTestingKey)) {
            throw InvalidResponseException::invalidField('metadata.result_with_testing_key');
        }

        return $resultWithTestingKey;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(array $data, string $key, ?string $field = null): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw InvalidResponseException::invalidField($field ?? $key);
        }

        return $value;
    }
}
