<?php

declare(strict_types=1);

pest()->project()->github('Eolica-Web/php-cloudflare-turnstile');

/**
 * @mago-expect lint:no-literal-password
 */
const TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

/**
 * @template T of Throwable
 *
 * @param class-string<T> $class
 *
 * @return T|null
 */
function thrownBy(string $class, Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }

        throw $e;
    }

    return null;
}
