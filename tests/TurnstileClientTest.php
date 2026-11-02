<?php

declare(strict_types=1);

use Eolica\CloudflareTurnstile\ErrorCode;
use Eolica\CloudflareTurnstile\Exception\HttpException;
use Eolica\CloudflareTurnstile\Exception\InvalidResponseException;
use Eolica\CloudflareTurnstile\Tests\Support\FakeHttpClient;
use Eolica\CloudflareTurnstile\TurnstileClient;
use Eolica\CloudflareTurnstile\VerifyResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;

covers(TurnstileClient::class, HttpException::class, InvalidResponseException::class);

const SECRET_KEY = '1x0000000000000000000000000000000AA';

function turnstile(FakeHttpClient $httpClient): TurnstileClient
{
    $factory = new Psr17Factory();

    return new TurnstileClient(SECRET_KEY, $httpClient, $factory, $factory);
}

function siteverifyResponse(string $body = '{"success":true}', int $statusCode = 200): Response
{
    return new Response($statusCode, ['Content-Type' => 'application/json'], $body);
}

it('sends a form encoded POST request to the siteverify endpoint', function (): void {
    $httpClient = new FakeHttpClient(siteverifyResponse());

    $_ = turnstile($httpClient)->verify(TOKEN);

    expect($httpClient->lastRequest?->getMethod())
        ->toBe('POST')
        ->and((string) $httpClient->lastRequest?->getUri())
        ->toBe('https://challenges.cloudflare.com/turnstile/v0/siteverify')
        ->and($httpClient->lastRequest?->getHeaderLine('Content-Type'))
        ->toBe('application/x-www-form-urlencoded')
        ->and((string) $httpClient->lastRequest?->getBody())
        ->toBe('secret=1x0000000000000000000000000000000AA&response=XXXX.DUMMY.TOKEN.XXXX');
});

it('sends the remote ip and the idempotency key when given', function (): void {
    $httpClient = new FakeHttpClient(siteverifyResponse());

    $_ = turnstile($httpClient)->verify(
        TOKEN,
        remoteIp: '203.0.113.1',
        idempotencyKey: '8a0c2f6e-3b1d-4c5e-9f7a-1b2c3d4e5f60',
    );

    expect((string) $httpClient->lastRequest?->getBody())
        ->toBe(
            'secret=1x0000000000000000000000000000000AA&response=XXXX.DUMMY.TOKEN.XXXX'
            . '&remoteip=203.0.113.1&idempotency_key=8a0c2f6e-3b1d-4c5e-9f7a-1b2c3d4e5f60',
        );
});

it('sends tokens within the allowed length', function (#[SensitiveParameter] string $token): void {
    $httpClient = new FakeHttpClient(siteverifyResponse());

    $_ = turnstile($httpClient)->verify($token);

    expect($httpClient->lastRequest)->not->toBeNull();
})->with([
    'one character' => ['x'],
    'maximum length' => [str_repeat('x', times: 2048)],
]);

it('rejects malformed tokens without calling the endpoint', function (
    #[SensitiveParameter]
    string $token,
    ErrorCode $errorCode,
): void {
    $httpClient = new FakeHttpClient(siteverifyResponse());

    expect(turnstile($httpClient)->verify($token))
        ->toEqual(new VerifyResponse(success: false, errorCodes: [$errorCode->value]))
        ->and($httpClient->lastRequest)
        ->toBeNull();
})->with([
    'empty' => ['', ErrorCode::MissingInputResponse],
    'too long' => [str_repeat('x', times: 2049), ErrorCode::InvalidInputResponse],
]);

it('returns the parsed siteverify response', function (int $statusCode): void {
    $httpClient = new FakeHttpClient(siteverifyResponse(
        '{"success":false,"error-codes":["timeout-or-duplicate"]}',
        $statusCode,
    ));

    expect(turnstile($httpClient)->verify(TOKEN))
        ->toEqual(new VerifyResponse(success: false, errorCodes: ['timeout-or-duplicate']));
})->with([200, 299]);

it('checks the expected hostname and action', function (
    ?string $expectedHostname,
    ?string $expectedAction,
    string $body,
    bool $success,
    array $errorCodes,
): void {
    $turnstile = turnstile(new FakeHttpClient(siteverifyResponse($body)))
        ->withExpectedHostname($expectedHostname)
        ->withExpectedAction($expectedAction);

    $response = $turnstile->verify(TOKEN);

    expect($response->success)->toBe($success)->and($response->errorCodes)->toBe($errorCodes);
})->with([
    'no expectations' => [
        null,
        null,
        '{"success":true,"hostname":"other.example","action":"signup"}',
        true,
        [],
    ],
    'matching hostname and action' => [
        'example.com',
        'login',
        '{"success":true,"hostname":"example.com","action":"login"}',
        true,
        [],
    ],
    'hostname mismatch' => [
        'example.com',
        null,
        '{"success":true,"hostname":"other.example","action":"signup"}',
        false,
        ['hostname-mismatch'],
    ],
    'action mismatch' => [
        null,
        'login',
        '{"success":true,"hostname":"other.example","action":"signup"}',
        false,
        ['action-mismatch'],
    ],
    'hostname and action mismatch' => [
        'example.com',
        'login',
        '{"success":true,"hostname":"other.example","action":"signup"}',
        false,
        ['hostname-mismatch', 'action-mismatch'],
    ],
    'missing hostname and action' => [
        'example.com',
        'login',
        '{"success":true}',
        false,
        ['hostname-mismatch', 'action-mismatch'],
    ],
    'empty expected hostname and action' => [
        '',
        '',
        '{"success":true}',
        false,
        ['hostname-mismatch', 'action-mismatch'],
    ],
    'failed verification' => [
        'example.com',
        'login',
        '{"success":false,"error-codes":["invalid-input-response"]}',
        false,
        ['invalid-input-response'],
    ],
]);

it('keeps the original instance unchanged when setting expectations', function (): void {
    $turnstile = turnstile(new FakeHttpClient(siteverifyResponse(
        '{"success":true,"hostname":"other.example","action":"signup"}',
    )));

    $_ = $turnstile->withExpectedHostname('example.com')->withExpectedAction('login');

    expect($turnstile->verify(TOKEN)->success)->toBeTrue();
});

it('accepts the expected hostname and action through the constructor', function (): void {
    $factory = new Psr17Factory();
    $httpClient = new FakeHttpClient(siteverifyResponse(
        '{"success":true,"hostname":"other.example","action":"signup"}',
    ));

    $turnstile = new TurnstileClient(
        SECRET_KEY,
        $httpClient,
        $factory,
        $factory,
        expectedHostname: 'example.com',
        expectedAction: 'login',
    );

    expect($turnstile->verify(TOKEN)->errorCodes)->toBe(['hostname-mismatch', 'action-mismatch']);
});

it('throws when the endpoint responds with a non successful status code', function (int $statusCode): void {
    $httpClient = new FakeHttpClient(siteverifyResponse(statusCode: $statusCode));

    expect(fn(): VerifyResponse => turnstile($httpClient)->verify(TOKEN))
        ->toThrow(HttpException::class, sprintf(
            'The siteverify endpoint responded with an unexpected %d status code.',
            $statusCode,
        ));
})->with([199, 300, 500]);

it('includes the error codes of a non successful response in the exception', function (
    int $statusCode,
    string $body,
    string $message,
    array $errorCodes,
): void {
    $httpClient = new FakeHttpClient(siteverifyResponse($body, $statusCode));

    $exception = thrownBy(HttpException::class, fn(): VerifyResponse => turnstile($httpClient)->verify(TOKEN));

    expect($exception)
        ->toBeInstanceOf(HttpException::class)
        ->and($exception?->getMessage())
        ->toBe($message)
        ->and($exception?->errorCodes)
        ->toBe($errorCodes);
})->with([
    'one error code' => [
        400,
        '{"success":false,"error-codes":["invalid-input-secret"]}',
        'The siteverify endpoint responded with an unexpected 400 status code (invalid-input-secret).',
        ['invalid-input-secret'],
    ],
    'several error codes' => [
        400,
        '{"success":false,"error-codes":["missing-input-secret","bad-request"]}',
        'The siteverify endpoint responded with an unexpected 400 status code (missing-input-secret, bad-request).',
        ['missing-input-secret', 'bad-request'],
    ],
    'no error codes' => [
        500,
        '{"success":false}',
        'The siteverify endpoint responded with an unexpected 500 status code.',
        [],
    ],
    'non JSON body' => [
        502,
        '<html>',
        'The siteverify endpoint responded with an unexpected 502 status code.',
        [],
    ],
    'invalid siteverify body' => [
        400,
        '{"error-codes":["invalid-input-secret"]}',
        'The siteverify endpoint responded with an unexpected 400 status code.',
        [],
    ],
]);

it('wraps HTTP client exceptions', function (): void {
    $clientException = new class extends RuntimeException implements ClientExceptionInterface {};
    $exception = thrownBy(HttpException::class, fn(): VerifyResponse => turnstile(new FakeHttpClient($clientException))
        ->verify(TOKEN));

    expect($exception)
        ->toBeInstanceOf(HttpException::class)
        ->and($exception?->getMessage())
        ->toBe('The request to the siteverify endpoint failed.')
        ->and($exception?->getPrevious())
        ->toBe($clientException)
        ->and($exception?->errorCodes)
        ->toBe([]);
});

it('tells whether the exception has an error code', function (): void {
    $exception = HttpException::unexpectedStatusCode(400, ['invalid-input-secret', 'some-future-code']);

    expect($exception->hasErrorCode(ErrorCode::InvalidInputSecret))
        ->toBeTrue()
        ->and($exception->hasErrorCode(ErrorCode::MissingInputSecret))
        ->toBeFalse();
});

it('throws when the response body is not valid JSON', function (): void {
    $httpClient = new FakeHttpClient(siteverifyResponse('<html>'));

    $exception = thrownBy(InvalidResponseException::class, fn(): VerifyResponse => turnstile($httpClient)->verify(
        TOKEN,
    ));

    expect($exception)
        ->toBeInstanceOf(InvalidResponseException::class)
        ->and($exception?->getMessage())
        ->toBe('The siteverify response body is not a valid JSON object.')
        ->and($exception?->getPrevious())
        ->toBeInstanceOf(JsonException::class);
});

it('throws when the response body is not a JSON object', function (): void {
    $httpClient = new FakeHttpClient(siteverifyResponse('true'));

    expect(fn(): VerifyResponse => turnstile($httpClient)->verify(TOKEN))
        ->toThrow(InvalidResponseException::class, 'The siteverify response body is not a valid JSON object.');
});
