<?php

declare(strict_types=1);

namespace Eolica\CloudflareTurnstile;

use Eolica\CloudflareTurnstile\Exception\HttpException;
use Eolica\CloudflareTurnstile\Exception\InvalidResponseException;
use JsonException;
use NoDiscard;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
final readonly class TurnstileClient
{
    public const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const int MAX_TOKEN_LENGTH = 2048;

    public function __construct(
        #[SensitiveParameter]
        private string $secretKey,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ?string $expectedHostname = null,
        private ?string $expectedAction = null,
    ) {}

    public function withExpectedHostname(?string $hostname): self
    {
        return clone($this, ['expectedHostname' => $hostname]);
    }

    public function withExpectedAction(?string $action): self
    {
        return clone($this, ['expectedAction' => $action]);
    }

    /**
     * @throws HttpException
     * @throws InvalidResponseException
     */
    #[NoDiscard]
    public function verify(
        #[SensitiveParameter]
        string $token,
        ?string $remoteIp = null,
        ?string $idempotencyKey = null,
    ): VerifyResponse {
        $tokenLength = strlen($token);

        if ($tokenLength < 1) {
            return new VerifyResponse(success: false, errorCodes: [ErrorCode::MissingInputResponse->value]);
        }

        if ($tokenLength > self::MAX_TOKEN_LENGTH) {
            return new VerifyResponse(success: false, errorCodes: [ErrorCode::InvalidInputResponse->value]);
        }

        $body = http_build_query([
            'secret' => $this->secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
            'idempotency_key' => $idempotencyKey,
        ]);

        $request = $this->requestFactory
            ->createRequest('POST', self::SITEVERIFY_URL)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw HttpException::requestFailed($e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw HttpException::unexpectedStatusCode($statusCode, self::errorCodesFrom($response));
        }

        return $this->checkExpectations(self::parse($response));
    }

    /**
     * @throws InvalidResponseException
     */
    private static function parse(ResponseInterface $response): VerifyResponse
    {
        try {
            $data = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidResponseException::malformedBody($e);
        }

        if (!is_array($data)) {
            throw InvalidResponseException::malformedBody();
        }

        return VerifyResponse::fromArray($data);
    }

    /**
     * @return list<string>
     */
    private static function errorCodesFrom(ResponseInterface $response): array
    {
        try {
            return self::parse($response)->errorCodes;
        } catch (InvalidResponseException) {
            return [];
        }
    }

    private function checkExpectations(VerifyResponse $response): VerifyResponse
    {
        if (!$response->success) {
            return $response;
        }

        if ($this->expectedHostname !== null && $this->expectedHostname !== $response->hostname) {
            $response = $response->failedWith(ErrorCode::HostnameMismatch);
        }

        if ($this->expectedAction !== null && $this->expectedAction !== $response->action) {
            $response = $response->failedWith(ErrorCode::ActionMismatch);
        }

        return $response;
    }
}
