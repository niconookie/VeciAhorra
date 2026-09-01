<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Requests;

use InvalidArgumentException;

final class WebpayReturnRequest
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{16,191}$/D';
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    private function __construct(
        public readonly string $flow,
        public readonly ?string $token,
        public readonly ?string $buyOrder,
        public readonly ?string $sessionId
    ) {
    }

    public static function fromArray(array $input, ?string $method = null): self
    {
        $normal = array_key_exists('token_ws', $input);
        $aborted = array_key_exists('TBK_TOKEN', $input);
        $hasBuyOrder = array_key_exists('TBK_ORDEN_COMPRA', $input);
        $hasSessionId = array_key_exists('TBK_ID_SESION', $input);

        if ($normal && ($aborted || $hasBuyOrder || $hasSessionId)) {
            throw new InvalidArgumentException(
                'El retorno Webpay contiene parametros ambiguos.'
            );
        }

        if (! $normal && ! $aborted) {
            if (! $hasBuyOrder || ! $hasSessionId) {
                throw new InvalidArgumentException(
                    'El retorno Webpay no contiene un token reconocido.'
                );
            }
            if (strtoupper((string) $method) !== 'GET') {
                throw new InvalidArgumentException(
                    'El retorno Webpay de timeout no usa el metodo esperado.'
                );
            }

            return new self(
                'timeout',
                null,
                self::optionalString($input, 'TBK_ORDEN_COMPRA'),
                self::optionalString($input, 'TBK_ID_SESION')
            );
        }

        $token = self::requiredString(
            $input[$normal ? 'token_ws' : 'TBK_TOKEN'],
            self::TOKEN_PATTERN,
            'El token Webpay no es valido.'
        );

        return new self(
            $normal ? 'commit' : 'abort',
            $token,
            $normal ? null : self::optionalString(
                $input,
                'TBK_ORDEN_COMPRA'
            ),
            $normal ? null : self::optionalString(
                $input,
                'TBK_ID_SESION'
            )
        );
    }

    private static function optionalString(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        return self::requiredString(
            $input[$key],
            self::REFERENCE_PATTERN,
            "El parametro {$key} no es valido."
        );
    }

    private static function requiredString(
        mixed $value,
        string $pattern,
        string $message
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException($message);
        }

        $value = trim($value, " \t");

        if (
            $value === ''
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match($pattern, $value) !== 1
        ) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
