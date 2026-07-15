<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Routes;

use VeciAhorra\Modules\Payments\Controller\PaymentController;
use VeciAhorra\Modules\Payments\Controller\WebpayReturnController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use InvalidArgumentException;
use VeciAhorra\Modules\Frontend\Support\CartSession;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Requests\PaymentSessionRequest;

final class PaymentRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/payments';
    private const WEBPAY_RETURN_FIELDS = [
        'token_ws' => true,
        'TBK_TOKEN' => true,
        'TBK_ORDEN_COMPRA' => true,
        'TBK_ID_SESION' => true,
    ];

    public function __construct(
        private PaymentController $controller,
        private WebpayReturnController $webpayReturnController
    ) {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/webpay/return',
            [
                'methods' => [
                    WP_REST_Server::CREATABLE,
                    WP_REST_Server::READABLE,
                ],
                'callback' => [$this, 'webpayReturn'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/session',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'startPublicSession'],
                'permission_callback' => [$this, 'canAccessPublicSession'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/session/(?P<payment_session_id>[^/]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'showPublicSession'],
                'permission_callback' => [$this, 'canAccessPublicSession'],
            ]
        );

        register_rest_route(self::NAMESPACE, self::RESOURCE, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManagePayments'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'store'],
                'permission_callback' => [$this, 'canManagePayments'],
            ],
        ]);

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/confirm',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'confirm'],
                'permission_callback' => [$this, 'canManagePayments'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'canManagePayments'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)/session',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createSession'],
                'permission_callback' => [$this, 'canManagePayments'],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->index());
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->show(
            (int) ($request->get_url_params()['id'] ?? 0)
        ));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->store((array) $request->get_json_params()),
            201
        );
    }

    public function createSession(
        WP_REST_Request $request
    ): WP_REST_Response {
        return $this->response($this->controller->createSession(
            (int) ($request->get_url_params()['id'] ?? 0)
        ));
    }

    public function startPublicSession(
        WP_REST_Request $request
    ): WP_REST_Response {
        $owner = $this->publicOwner();
        $key = $request->get_header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $this->badRequest(
                'missing_idempotency_key',
                'El header Idempotency-Key es obligatorio.'
            );
        }

        $normalizedKey = trim($key, " \t");

        if (
            strlen($normalizedKey) < 16
            || strlen($normalizedKey) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $normalizedKey) !== 1
        ) {
            return $this->badRequest(
                'invalid_idempotency_key',
                'El header Idempotency-Key no es valido.'
            );
        }

        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        try {
            $data = (new PaymentSessionRequest($body))->validated();
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest(
                'invalid_checkout_id',
                $exception->getMessage()
            );
        }

        $result = $this->controller->startPublicSession(
            $data['checkout_id'],
            $normalizedKey,
            $owner
        );
        $status = ($result['success'] ?? false) === true
            && ($result['data']['reused'] ?? false) === false
                ? 201
                : $this->resultStatus($result);

        return new WP_REST_Response($result, $status);
    }

    public function showPublicSession(
        WP_REST_Request $request
    ): WP_REST_Response {
        $publicId = (string) (
            $request->get_url_params()['payment_session_id'] ?? ''
        );

        if (! PaymentSession::validPublicId($publicId)) {
            return $this->badRequest(
                'invalid_payment_session_id',
                'El payment_session_id no es valido.'
            );
        }

        return $this->response($this->controller->showPublicSession(
            $publicId,
            $this->publicOwner()
        ));
    }

    public function canAccessPublicSession(WP_REST_Request $request): bool
    {
        return true;
    }

    public function webpayReturn(WP_REST_Request $request): WP_REST_Response
    {
        $payload = match (strtoupper($request->get_method())) {
            'POST' => $request->get_body_params(),
            'GET' => $request->get_query_params(),
            default => null,
        };

        if (! is_array($payload)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'invalid_webpay_return_method',
                    'message' => 'El metodo del retorno Webpay no es valido.',
                ],
            ], 405);
        }

        $result = $this->webpayReturnController->process(
            array_intersect_key($payload, self::WEBPAY_RETURN_FIELDS)
        );

        if (($result['success'] ?? false) !== true) {
            return new WP_REST_Response($result, 400);
        }

        $status = $result['data']['result'] ?? 'invalid';
        $httpStatus = match ($status) {
            'inconsistent' => 409,
            'gateway_error' => 502,
            'invalid' => 400,
            default => 200,
        };

        $redirectUrl = $result['data']['redirect_url'] ?? null;
        if (is_string($redirectUrl) && wp_http_validate_url($redirectUrl)) {
            $response = new WP_REST_Response($result, 303);
            $response->header('Location', $redirectUrl);
            return $response;
        }

        return new WP_REST_Response($result, $httpStatus);
    }

    public function confirm(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->confirm(
            (array) $request->get_json_params()
        ));
    }

    public function canManagePayments(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    private function response(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : $this->resultStatus($result);

        return new WP_REST_Response($result, $status);
    }

    private function resultStatus(array $result): int
    {
        return ($result['success'] ?? false) === true
            ? 200
            : match ($result['error']['code'] ?? '') {
                'validation_error' => 422,
                'resource_not_found', 'payment_not_found' => 404,
                'idempotency_conflict', 'state_conflict' => 409,
                default => 500,
            };
    }

    private function publicOwner(): array
    {
        $userId = get_current_user_id();

        return $userId > 0
            ? ['user_id' => $userId, 'session_id' => null]
            : [
                'user_id' => null,
                'session_id' => (new CartSession())->identifier(),
            ];
    }

    private function jsonObject(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        if (! $request->is_json_content_type()) {
            return $this->badRequest('invalid_json', 'El cuerpo debe usar JSON.');
        }

        $raw = trim($request->get_body());
        $object = json_decode($raw);

        if ($raw === '' || json_last_error() !== JSON_ERROR_NONE || ! is_object($object)) {
            return $this->badRequest('invalid_json', 'El cuerpo JSON no es valido.');
        }

        return (array) json_decode($raw, true);
    }

    private function badRequest(string $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], 400);
    }
}
