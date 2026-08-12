<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Identity;

use VeciAhorra\Modules\Minimarket\Repository\MinimarketRepository;

final class StoreContext
{
    public function __construct(private ?MinimarketRepository $repository = null) {}

    public function current(): array|\WP_Error
    {
        if (! is_user_logged_in()) {
            return new \WP_Error('minimarket_not_authenticated', 'Debes iniciar sesión.', ['status' => 401]);
        }
        if (! current_user_can(MinimarketRole::CAPABILITY)) {
            return new \WP_Error('minimarket_forbidden', 'La cuenta no es un actor minimarket.', ['status' => 403]);
        }
        $storeId = (int) get_user_meta(get_current_user_id(), MinimarketRole::STORE_META_KEY, true);
        if ($storeId <= 0) {
            return new \WP_Error('minimarket_store_missing', 'La cuenta no tiene un Store asociado.', ['status' => 403]);
        }
        $store = ($this->repository ??= new MinimarketRepository())->findStore($storeId);
        if ($store === null) {
            return new \WP_Error('minimarket_store_missing', 'El Store asociado no existe.', ['status' => 403]);
        }
        if ($store['status'] !== 'active' || $store['onboarding_status'] !== 'complete' || empty($store['approved_at'])) {
            return new \WP_Error('minimarket_store_not_operational', 'El Store no está aprobado y activo.', ['status' => 403]);
        }
        return $store;
    }
}
