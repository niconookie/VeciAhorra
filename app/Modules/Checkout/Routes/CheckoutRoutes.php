<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Routes;

use InvalidArgumentException;
use VeciAhorra\Modules\Checkout\Controller\CheckoutController;
use VeciAhorra\Modules\Checkout\Requests\CheckoutRequest;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CheckoutRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/checkout';

    public function __construct(private CheckoutController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/validate',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'validate'],
                'permission_callback' => [$this, 'canAccessCheckout'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE,
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'initialize'],
                'permission_callback' => [$this, 'canAccessCheckout'],
            ]
        );
    }

    public function validate(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->validatedPayload($request);

        return $payload instanceof WP_REST_Response
            ? $payload
            : $this->response($this->controller->validate($payload));
    }

    public function initialize(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->validatedPayload($request);

        return $payload instanceof WP_REST_Response
            ? $payload
            : $this->response(
                $this->controller->initialize($payload),
                201
            );
    }

    public function canAccessCheckout(WP_REST_Request $request): bool
    {
        return true;
    }

    /** @return array<string, never>|WP_REST_Response */
    private function validatedPayload(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        try {
            return (new CheckoutRequest($body))->validated();
        } catch (InvalidArgumentException $exception) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }
    }

    /** @return array<string, mixed>|WP_REST_Response */
    private function jsonObject(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        if (! $request->is_json_content_type()) {
            return $this->badRequest(
                'El cuerpo debe usar application/json.'
            );
        }

        $rawBody = trim($request->get_body());
        $object = json_decode($rawBody);

        if (
            $rawBody === ''
            || json_last_error() !== JSON_ERROR_NONE
            || ! is_object($object)
        ) {
            return $this->badRequest(
                'El cuerpo debe ser un objeto JSON valido.'
            );
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded)
            ? $decoded
            : $this->badRequest(
                'No fue posible interpretar el cuerpo JSON.'
            );
    }

    private function badRequest(string $message): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'error' => ['code' => 'invalid_json', 'message' => $message],
        ], 400);
    }

    private function response(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : (($result['error']['code'] ?? '') === 'validation_error'
                ? 422
                : 500);

        return new WP_REST_Response($result, $status);
    }
}
