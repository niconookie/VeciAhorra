<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Routes;

use InvalidArgumentException;
use VeciAhorra\Modules\Checkout\Controller\CheckoutController;
use VeciAhorra\Modules\Checkout\Requests\CheckoutRequest;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use VeciAhorra\Modules\Frontend\Support\CartSession;
use VeciAhorra\Modules\Checkout\Models\Checkout;

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
            self::RESOURCE . '/(?P<checkout_id>[^/]+)/payment-status',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'paymentStatus'],
                'permission_callback' => [$this, 'canAccessCheckout'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<checkout_id>[^/]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
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
        $owner = $this->ownerOrError($request);

        if ($owner instanceof WP_REST_Response) {
            return $owner;
        }

        $payload = $this->validatedPayload($request);

        return $payload instanceof WP_REST_Response
            ? $payload
            : $this->response($this->controller->validate([
                ...$payload,
                ...$owner,
            ]));
    }

    public function initialize(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);

        if ($owner instanceof WP_REST_Response) {
            return $owner;
        }

        $payload = $this->validatedPayload($request);

        if ($payload instanceof WP_REST_Response) {
            return $payload;
        }

        try {
            $key = (new \VeciAhorra\Modules\Payments\Service\IdempotencyService())->key(
                (string) $request->get_header('Idempotency-Key')
            );
        } catch (InvalidArgumentException $exception) {
            return new WP_REST_Response(['success' => false, 'error' => [
                'code' => 'validation_error', 'message' => $exception->getMessage(),
            ]], 422);
        }
        $result = $this->controller->initialize([
            ...$payload, ...$owner, 'idempotency_key' => $key,
        ]);
        $status = ($result['success'] ?? false) === true
            && ($result['data']['reservation_created'] ?? false) === true
                ? 201
                : 200;

        return $this->response($result, $status);
    }

    public function canAccessCheckout(WP_REST_Request $request): bool
    {
        return true;
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);

        if ($owner instanceof WP_REST_Response) {
            return $owner;
        }

        $publicId = (string) ($request->get_url_params()['checkout_id'] ?? '');

        if (! Checkout::validPublicId($publicId)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'invalid_checkout_id',
                    'message' => 'El checkout_id no es valido.',
                ],
            ], 400);
        }

        return $this->response($this->controller->show($publicId, $owner));
    }

    public function paymentStatus(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);
        if ($owner instanceof WP_REST_Response) {
            return $owner;
        }
        $publicId = (string) ($request->get_url_params()['checkout_id'] ?? '');
        $result = Checkout::validPublicId($publicId)
            ? $this->controller->paymentStatus($publicId, $owner)
            : ['success' => false, 'error' => [
                'code' => 'resource_not_found',
                'message' => 'El Checkout no esta disponible.',
            ]];
        $response = $this->response($result);
        $response->header('Cache-Control', 'no-store, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    /** @return array{session_id: ?string, user_id: ?int}|WP_REST_Response */
    private function ownerOrError(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        $userId = get_current_user_id();

        if ($userId > 0) {
            return ['session_id' => null, 'user_id' => $userId];
        }

        $headerSession = trim((string) $request->get_header(
            'X-Veciahorra-Cart-Session'
        ));
        $sessionId = preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $headerSession) === 1
            ? $headerSession
            : (new CartSession())->identifier();

        return [
            'session_id' => $sessionId,
            'user_id' => null,
        ];
    }

    /** @return array{fulfillment_method: string}|WP_REST_Response */
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
            : match ($result['error']['code'] ?? '') {
                'resource_not_found' => 404,
                'order_already_attached', 'state_conflict' => 409,
                'validation_error' => 422,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
