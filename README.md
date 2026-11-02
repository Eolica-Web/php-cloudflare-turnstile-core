# Cloudflare Turnstile

Cloudflare Turnstile integration for PHP.

It validates Turnstile tokens on the server through the [siteverify endpoint](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/), using any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client.

## Requirements

- PHP 8.5 or higher
- A PSR-18 HTTP client and PSR-17 request and stream factories, e.g. [Guzzle](https://docs.guzzlephp.org) or [Symfony HttpClient](https://symfony.com/doc/current/http_client.html)

## Installation

Install via [Composer](https://getcomposer.org):

```bash
composer require eolica/cloudflare-turnstile
```

If your project doesn't have a PSR-18 client yet, install one, e.g. Guzzle:

```bash
composer require guzzlehttp/guzzle
```

## Usage

Create a `TurnstileClient` instance with your secret key, a PSR-18 client and PSR-17 request and stream factories:

```php
use Eolica\CloudflareTurnstile\TurnstileClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();

$turnstile = new TurnstileClient(
    secretKey: $secretKey,
    httpClient: new Client(),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

With Symfony HttpClient, `Symfony\Component\HttpClient\Psr18Client` implements all three interfaces (it needs a PSR-17 implementation such as `nyholm/psr7` installed), so you can pass the same instance for each of them.

### Verifying a token

The Turnstile widget adds the token to your form in the `cf-turnstile-response` field. Verify it on the server:

```php
$response = $turnstile->verify($_POST['cf-turnstile-response'] ?? '');

if (!$response->success) {
    // Reject the request
}
```

Tokens are single use and expire after 300 seconds, so verify each one only once.

Empty tokens and tokens longer than 2048 characters are rejected without calling Cloudflare, returning the same error codes Cloudflare would (`missing-input-response` and `invalid-input-response`).

`verify()` also accepts the visitor's IP address and an idempotency key:

```php
$response = $turnstile->verify(
    $token,
    remoteIp: $visitorIp,
    idempotencyKey: $uuid,
);
```

- `remoteIp`: the visitor's IP address. If your application runs behind a proxy or Cloudflare itself, take it from a header you trust, such as `CF-Connecting-IP`, instead of `REMOTE_ADDR`.
- `idempotencyKey`: a UUID that lets you retry the same verification without getting a `timeout-or-duplicate` error.

### Validating the hostname and action

Cloudflare recommends checking that the token was issued for your site and for the expected action. Set the expected values and any mismatch makes the verification fail:

```php
$response = $turnstile
    ->withExpectedHostname('example.com')
    ->withExpectedAction('login')
    ->verify($token);
```

`withExpectedHostname()` and `withExpectedAction()` return a new instance, so you can configure the hostname once and set a different action for each form. Pass `null` to remove an expectation. Both can also be passed to the constructor as `expectedHostname` and `expectedAction`.

They are only checked when Cloudflare reports a successful verification.

### The response

`verify()` returns a `VerifyResponse` with the following properties:

| Property       | Type                 | Description                                                     |
|----------------|----------------------|-----------------------------------------------------------------|
| `success`      | `bool`               | Whether the token is valid and matches the expectations         |
| `errorCodes`   | `list<string>`       | Error codes, including unknown ones Cloudflare might add        |
| `challengedAt` | `?DateTimeImmutable` | When the challenge was solved                                   |
| `hostname`     | `?string`            | Hostname of the site where the challenge was solved             |
| `action`       | `?string`            | Action configured in the widget                                 |
| `cdata`        | `?string`            | Custom data configured in the widget                            |
| `ephemeralId`  | `?string`            | Device identifier, only available for Enterprise customers      |

Use `hasErrorCode()` to check for a specific error:

```php
use Eolica\CloudflareTurnstile\ErrorCode;

if ($response->hasErrorCode(ErrorCode::TimeoutOrDuplicate)) {
    // The token expired or was already used
}
```

| `ErrorCode` case       | Value                    | Meaning                                         |
|------------------------|--------------------------|-------------------------------------------------|
| `MissingInputSecret`   | `missing-input-secret`   | The secret key was not sent                     |
| `InvalidInputSecret`   | `invalid-input-secret`   | The secret key is invalid                       |
| `MissingInputResponse` | `missing-input-response` | The token was empty                             |
| `InvalidInputResponse` | `invalid-input-response` | The token is invalid or malformed               |
| `BadRequest`           | `bad-request`            | The request was rejected as malformed           |
| `TimeoutOrDuplicate`   | `timeout-or-duplicate`   | The token expired or was already verified       |
| `InternalError`        | `internal-error`         | Cloudflare failed to verify the token           |
| `HostnameMismatch`     | `hostname-mismatch`      | The hostname doesn't match the expected one     |
| `ActionMismatch`       | `action-mismatch`        | The action doesn't match the expected one       |

`HostnameMismatch` and `ActionMismatch` come from this package, Cloudflare never returns them.

### Errors

When the verification can't be completed, `verify()` throws an exception implementing `Eolica\CloudflareTurnstile\Exception\TurnstileException`:

- `HttpException`: the HTTP client failed or Cloudflare responded with a non 2xx status code. Cloudflare responds with `400 Bad Request` when the secret key is missing or invalid, so a misconfigured secret key ends up here. The error codes Cloudflare returns are included in the message, e.g. `The siteverify endpoint responded with an unexpected 400 status code (invalid-input-secret).`, and available through the `errorCodes` property and the `hasErrorCode()` method.
- `InvalidResponseException`: Cloudflare's response could not be parsed.

Decide whether your application should reject the request (fail closed, recommended) or let it through (fail open) in that case:

```php
use Eolica\CloudflareTurnstile\ErrorCode;
use Eolica\CloudflareTurnstile\Exception\HttpException;
use Eolica\CloudflareTurnstile\Exception\TurnstileException;

try {
    $response = $turnstile->verify($token);
} catch (HttpException $e) {
    if ($e->hasErrorCode(ErrorCode::InvalidInputSecret)) {
        // The secret key is wrong, alert the developers
    }

    // Log the error and reject the request
} catch (TurnstileException $e) {
    // Log the error and reject the request
}
```

## Testing

Cloudflare provides [test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) to use in development and automated tests:

| Secret key                            | Result                                |
|---------------------------------------|---------------------------------------|
| `1x0000000000000000000000000000000AA` | Always passes                         |
| `2x0000000000000000000000000000000AA` | Always fails                          |
| `3x0000000000000000000000000000000AA` | Fails with `timeout-or-duplicate`     |

With these secret keys you can use the dummy token `XXXX.DUMMY.TOKEN.XXXX`.

## License

The MIT License (MIT). See the [license file](LICENSE) for more information.
