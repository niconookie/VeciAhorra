<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Cart\Proximity;

final class PickupModule
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', static fn() => add_submenu_page('veciahorra', 'Retiro cercano', 'Retiro cercano', 'manage_options', 'veciahorra-pickup', [new PickupSettings(), 'page']));
    }
    public function routes(): void
    {
        foreach (['search', 'accept'] as $operation) {
            register_rest_route('veciahorra/v1', '/cart/proximity/' . $operation, [
                'methods' => 'POST', 'permission_callback' => [$this, 'permission'],
                'callback' => fn(\WP_REST_Request $request) => $this->handle($request, $operation),
            ]);
        }
    }
    public function permission(\WP_REST_Request $request): bool
    {
        return get_current_user_id() > 0 && (bool)wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
    }
    public function handle(\WP_REST_Request $request, string $operation): \WP_REST_Response
    {
        try {
            if (!$this->permission($request)) return $this->response(['success' => false, 'error' => ['code' => 'pickup_unauthorized', 'message' => 'Inicia sesión nuevamente.']], 403);
            $input = $request->get_json_params();
            if (!$request->is_json_content_type() || !is_array($input) || !is_object(json_decode($request->get_body()))) throw new \InvalidArgumentException('Se requiere un objeto JSON.');
            $owner = ['user_id' => get_current_user_id(), 'cart_id' => $input['cart_id'] ?? null, 'expected_version' => $input['expected_version'] ?? null];
            unset($input['cart_id'], $input['expected_version']);
            if ($operation === 'accept') {
                if (isset($input['idempotency_key'])) throw new \InvalidArgumentException('Envía la clave de idempotencia en el encabezado.');
                $input['idempotency_key'] = (string)$request->get_header('Idempotency-Key');
            }
            $service = new PickupProposalService();
            $result = match ($operation) {
                'search' => $service->search($owner, $input),
                'accept' => $service->accept($owner, $input),
                default => throw new \InvalidArgumentException('Operación no válida.'),
            };
            return $this->response(['success' => true, 'data' => $result], 200);
        } catch (\InvalidArgumentException $error) {
            return $this->response(['success' => false, 'error' => ['code' => 'validation_error', 'message' => $error->getMessage()]], 422);
        } catch (\DomainException) {
            return $this->response(['success' => false, 'error' => ['code' => 'cart_conflict', 'message' => 'El carrito o la propuesta cambió. Revisa tu carrito y realiza una nueva búsqueda.']], 409);
        } catch (\Throwable) {
            return $this->response(['success' => false, 'error' => ['code' => 'pickup_error', 'message' => 'No fue posible completar la búsqueda o el cambio.']], 500);
        }
    }
    private function response(array $data, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }
}
