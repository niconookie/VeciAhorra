<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Identity;

use VeciAhorra\Modules\Couriers\Repository\CourierRepository;

final class CourierContext
{
    public function __construct(private CourierRepository $repository = new CourierRepository()) {}
    public function resolve(): ?array
    {
        if (! is_user_logged_in() || ! current_user_can(CourierRole::CAPABILITY)) return null;
        $id = filter_var(get_user_meta(get_current_user_id(), CourierRole::META_KEY, true), FILTER_VALIDATE_INT);
        if (! is_int($id) || $id <= 0) return null;
        $courier = $this->repository->find($id);
        return $courier !== null && $this->repository->isApproved($courier) ? $courier : null;
    }
}
