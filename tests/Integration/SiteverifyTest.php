<?php

declare(strict_types=1);

use Eolica\CloudflareTurnstile\ErrorCode;
use Eolica\CloudflareTurnstile\Exception\HttpException;
use Eolica\CloudflareTurnstile\TurnstileClient;
use Eolica\CloudflareTurnstile\VerifyResponse;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * @see https://developers.cloudflare.com/turnstile/troubleshooting/testing/
 */
function cloudflareTurnstile(#[SensitiveParameter] string $secretKey): TurnstileClient
{
    $client = new Psr18Client();

    return new TurnstileClient($secretKey, $client, $client, $client);
}

it('passes with the always passing test secret key', function (): void {
    $response = cloudflareTurnstile('1x0000000000000000000000000000000AA')->verify(
        TOKEN,
        remoteIp: '203.0.113.1',
        idempotencyKey: '8a0c2f6e-3b1d-4c5e-9f7a-1b2c3d4e5f60',
    );

    expect($response->success)
        ->toBeTrue()
        ->and($response->errorCodes)
        ->toBe([])
        ->and($response->challengedAt)
        ->toBeInstanceOf(DateTimeImmutable::class)
        ->and($response->hostname)
        ->toBeString();
});

it('fails with the failing test secret keys', function (
    #[SensitiveParameter]
    string $secretKey,
    ErrorCode $errorCode,
): void {
    $response = cloudflareTurnstile($secretKey)->verify(TOKEN);

    expect($response->success)->toBeFalse()->and($response->errorCodes)->toBe([$errorCode->value]);
})->with([
    'always fails' => ['2x0000000000000000000000000000000AA', ErrorCode::InvalidInputResponse],
    'token already spent' => ['3x0000000000000000000000000000000AA', ErrorCode::TimeoutOrDuplicate],
]);

it('fails when the hostname does not match the expected one', function (): void {
    $response = cloudflareTurnstile('1x0000000000000000000000000000000AA')
        ->withExpectedHostname('expected.invalid')
        ->verify(TOKEN);

    expect($response->success)->toBeFalse()->and($response->errorCodes)->toBe([ErrorCode::HostnameMismatch->value]);
});

it('throws with the error code when the secret key is invalid', function (
    #[SensitiveParameter]
    string $secretKey,
    ErrorCode $errorCode,
): void {
    $exception = thrownBy(HttpException::class, fn(): VerifyResponse => cloudflareTurnstile($secretKey)->verify(TOKEN));

    expect($exception)->toBeInstanceOf(HttpException::class)->and($exception?->errorCodes)->toBe([$errorCode->value]);
})->with([
    'invalid' => ['invalid-secret-key', ErrorCode::InvalidInputSecret],
    'missing' => ['', ErrorCode::MissingInputSecret],
]);
