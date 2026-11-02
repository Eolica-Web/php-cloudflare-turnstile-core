<?php

declare(strict_types=1);

namespace Eolica\CloudflareTurnstile;

/**
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/#error-codes
 */
enum ErrorCode: string
{
    case MissingInputSecret = 'missing-input-secret';
    case InvalidInputSecret = 'invalid-input-secret';
    case MissingInputResponse = 'missing-input-response';
    case InvalidInputResponse = 'invalid-input-response';
    case BadRequest = 'bad-request';
    case TimeoutOrDuplicate = 'timeout-or-duplicate';
    case InternalError = 'internal-error';
    case HostnameMismatch = 'hostname-mismatch';
    case ActionMismatch = 'action-mismatch';
}
