<?php

declare(strict_types=1);

use Eolica\CloudflareTurnstile\ErrorCode;
use Eolica\CloudflareTurnstile\Exception\InvalidResponseException;
use Eolica\CloudflareTurnstile\VerifyResponse;

covers(VerifyResponse::class, InvalidResponseException::class);

it('can be constructed with only the success flag', function (): void {
    $response = new VerifyResponse(success: true);

    expect($response->success)
        ->toBeTrue()
        ->and($response->errorCodes)
        ->toBe([])
        ->and($response->challengedAt)
        ->toBeNull()
        ->and($response->hostname)
        ->toBeNull()
        ->and($response->action)
        ->toBeNull()
        ->and($response->cdata)
        ->toBeNull()
        ->and($response->ephemeralId)
        ->toBeNull();
});

it('parses a successful response', function (): void {
    $response = VerifyResponse::fromArray([
        'success' => true,
        'challenge_ts' => '2022-02-28T15:14:30.096Z',
        'hostname' => 'example.com',
        'error-codes' => [],
        'action' => 'login',
        'cdata' => 'session-id-123',
        'metadata' => ['ephemeral_id' => 'x:9f78e0ed210960d7693b167e'],
    ]);

    expect($response->success)
        ->toBeTrue()
        ->and($response->errorCodes)
        ->toBe([])
        ->and($response->challengedAt?->format(DATE_RFC3339_EXTENDED))
        ->toBe('2022-02-28T15:14:30.096+00:00')
        ->and($response->hostname)
        ->toBe('example.com')
        ->and($response->action)
        ->toBe('login')
        ->and($response->cdata)
        ->toBe('session-id-123')
        ->and($response->ephemeralId)
        ->toBe('x:9f78e0ed210960d7693b167e');
});

it('parses a failed response with only the required fields', function (): void {
    $response = VerifyResponse::fromArray([
        'success' => false,
        'error-codes' => ['invalid-input-response'],
    ]);

    expect($response->success)
        ->toBeFalse()
        ->and($response->errorCodes)
        ->toBe(['invalid-input-response'])
        ->and($response->challengedAt)
        ->toBeNull()
        ->and($response->hostname)
        ->toBeNull()
        ->and($response->action)
        ->toBeNull()
        ->and($response->cdata)
        ->toBeNull()
        ->and($response->ephemeralId)
        ->toBeNull();
});

it('keeps unknown error codes', function (): void {
    $response = VerifyResponse::fromArray([
        'success' => false,
        'error-codes' => ['timeout-or-duplicate', 'some-future-code'],
    ]);

    expect($response->errorCodes)
        ->toBe(['timeout-or-duplicate', 'some-future-code'])
        ->and($response->hasErrorCode(ErrorCode::TimeoutOrDuplicate))
        ->toBeTrue()
        ->and($response->hasErrorCode(ErrorCode::InvalidInputResponse))
        ->toBeFalse();
});

it('returns a failed copy with the given error code appended', function (): void {
    $response = new VerifyResponse(success: true, hostname: 'example.com', action: 'login');

    $failed = $response->failedWith(ErrorCode::HostnameMismatch)->failedWith(ErrorCode::ActionMismatch);

    expect($failed)
        ->toEqual(new VerifyResponse(
            success: false,
            errorCodes: ['hostname-mismatch', 'action-mismatch'],
            hostname: 'example.com',
            action: 'login',
        ))
        ->and($response->success)
        ->toBeTrue()
        ->and($response->errorCodes)
        ->toBe([]);
});

it('rejects malformed responses', function (array $data, string $field): void {
    expect(fn(): VerifyResponse => VerifyResponse::fromArray($data))
        ->toThrow(InvalidResponseException::class, sprintf('"%s"', $field));
})->with([
    'missing success' => [[], 'success'],
    'non-boolean success' => [['success' => 'true'], 'success'],
    'non-list error codes' => [['success' => false, 'error-codes' => 'bad-request'], 'error-codes'],
    'non-string error code' => [['success' => false, 'error-codes' => [42]], 'error-codes'],
    'non-string hostname' => [['success' => true, 'hostname' => 123], 'hostname'],
    'falsy non-string hostname' => [['success' => true, 'hostname' => false], 'hostname'],
    'malformed challenge timestamp' => [['success' => true, 'challenge_ts' => 'not a date'], 'challenge_ts'],
    'empty challenge timestamp' => [['success' => true, 'challenge_ts' => ''], 'challenge_ts'],
    'relative challenge timestamp' => [['success' => true, 'challenge_ts' => 'now'], 'challenge_ts'],
    'challenge timestamp without milliseconds' => [
        ['success' => true, 'challenge_ts' => '2022-02-28T15:14:30Z'],
        'challenge_ts',
    ],
    'non-array metadata' => [['success' => true, 'metadata' => 'x'], 'metadata'],
    'non-string ephemeral id' => [['success' => true, 'metadata' => ['ephemeral_id' => 1]], 'metadata.ephemeral_id'],
]);
