<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;

final class WebpayReturnController
{
    public function __construct(
        private WebpayReturnService $service,
        private ?PublicRouteResolver $routes = null
    ) {
    }

    public function process(array $payload): array
    {
        try {
            if (
                array_key_exists('token_ws', $payload)
                && array_key_exists('TBK_TOKEN', $payload)
            ) {
                error_log(
                    '[VeciAhorra] Retorno Webpay rechazado: parametros ambiguos.'
                );
            }

            $result = $this->service->process(
                WebpayReturnRequest::fromArray($payload)
            );

            $data = $result->toArray();
            unset(
                $data['payment_session_id'],
                $data['token_reference'],
                $data['financial']
            );
            if ($result->publicCheckoutId !== null) {
                $data['redirect_url'] = add_query_arg(
                    ['checkout_id' => $result->publicCheckoutId],
                    ($this->routes ??= new PublicRouteResolver())->checkout()
                );
            }

            return ['success' => true, 'data' => $data];
        } catch (InvalidArgumentException $exception) {
            return $this->error('invalid_webpay_return', $exception->getMessage());
        } catch (PersistenceException) {
            return $this->error(
                'webpay_return_persistence_error',
                'No fue posible procesar el retorno Webpay.'
            );
        } catch (Throwable) {
            return $this->error(
                'webpay_return_internal_error',
                'Ocurrio un error interno al procesar el retorno Webpay.'
            );
        }
    }

    private function error(string $code, string $message): array
    {
        return [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
