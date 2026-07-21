<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Domain;

use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;

final class StoreLifecycleContract
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_REJECTED = 'rejected';
    public const ONBOARDING_DRAFT = 'draft';
    public const ONBOARDING_COMPLETE = 'complete';

    public const STATE_DRAFT = 'draft';
    public const STATE_IN_REVIEW = 'in_review';
    public const STATE_REJECTED = 'rejected';
    public const STATE_APPROVED_INACTIVE = 'approved_inactive';
    public const STATE_ACTIVE = 'active';
    public const STATE_INVALID = 'invalid';

    public const ACTION_SAVE = 'save';
    public const ACTION_SUBMIT_FOR_REVIEW = 'submit_for_review';
    public const ACTION_DELETE_IF_UNREFERENCED = 'delete_if_unreferenced';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';
    public const ACTION_RETURN_TO_DRAFT = 'return_to_draft';
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';

    private const ACTIONS_BY_STATE = [
        self::STATE_DRAFT => [self::ACTION_SAVE, self::ACTION_SUBMIT_FOR_REVIEW, self::ACTION_DELETE_IF_UNREFERENCED],
        self::STATE_IN_REVIEW => [self::ACTION_SAVE, self::ACTION_APPROVE, self::ACTION_REJECT],
        self::STATE_REJECTED => [self::ACTION_SAVE, self::ACTION_RETURN_TO_DRAFT],
        self::STATE_APPROVED_INACTIVE => [self::ACTION_SAVE, self::ACTION_ACTIVATE],
        self::STATE_ACTIVE => [self::ACTION_SAVE, self::ACTION_DEACTIVATE],
    ];

    public function classify(string $status, string $onboardingStatus, mixed $approvedAt): string
    {
        $approval = $this->approvalKind($approvedAt);
        if ($approval === 'invalid') {
            return self::STATE_INVALID;
        }

        $approved = $approval === 'approved';
        $key = $status . '|' . $onboardingStatus . '|' . ($approved ? 'approved' : 'unapproved');

        return [
            'pending|draft|unapproved' => self::STATE_DRAFT,
            'pending|complete|unapproved' => self::STATE_IN_REVIEW,
            'rejected|complete|unapproved' => self::STATE_REJECTED,
            'inactive|complete|approved' => self::STATE_APPROVED_INACTIVE,
            'active|complete|approved' => self::STATE_ACTIVE,
        ][$key] ?? self::STATE_INVALID;
    }

    public function validate(string $status, string $onboardingStatus, mixed $approvedAt): string
    {
        if (! in_array($status, $this->statuses(), true)) {
            throw new StoreLifecycleException('unknown_status', 'El estado del minimarket no pertenece al contrato.', 'status');
        }
        if (! in_array($onboardingStatus, $this->onboardingStatuses(), true)) {
            throw new StoreLifecycleException('unknown_onboarding_status', 'El estado de onboarding no pertenece al contrato.', 'onboarding_status');
        }
        if ($this->approvalKind($approvedAt) === 'invalid') {
            throw new StoreLifecycleException('invalid_combination', 'approved_at no tiene el formato persistido esperado.', 'approved_at');
        }

        $state = $this->classify($status, $onboardingStatus, $approvedAt);
        if ($state === self::STATE_INVALID) {
            throw new StoreLifecycleException('invalid_combination', 'La combinacion de ciclo de vida del minimarket no es valida.', $this->invalidField($status, $onboardingStatus, $approvedAt));
        }

        return $state;
    }

    public function allowedActions(string $status, string $onboardingStatus, mixed $approvedAt): array
    {
        return self::ACTIONS_BY_STATE[$this->validate($status, $onboardingStatus, $approvedAt)];
    }

    public function assertActionAllowed(string $action, string $status, string $onboardingStatus, mixed $approvedAt): void
    {
        $state = $this->validate($status, $onboardingStatus, $approvedAt);
        if (! in_array($action, self::ACTIONS_BY_STATE[$state], true)) {
            throw new StoreLifecycleException('action_not_allowed', 'La accion no esta permitida desde el estado actual.', null, $state, $action);
        }
    }

    public function transitionAuthorities(
        string $action,
        string $status,
        string $onboardingStatus,
        mixed $approvedAt,
        ?string $approvalTimestamp = null
    ): array {
        $this->assertActionAllowed($action, $status, $onboardingStatus, $approvedAt);

        $authorities = match ($action) {
            self::ACTION_SUBMIT_FOR_REVIEW => [self::STATUS_PENDING, self::ONBOARDING_COMPLETE, null],
            self::ACTION_RETURN_TO_DRAFT => [self::STATUS_PENDING, self::ONBOARDING_DRAFT, null],
            self::ACTION_APPROVE => [self::STATUS_INACTIVE, self::ONBOARDING_COMPLETE, $approvalTimestamp],
            self::ACTION_REJECT => [self::STATUS_REJECTED, self::ONBOARDING_COMPLETE, null],
            self::ACTION_ACTIVATE => [self::STATUS_ACTIVE, self::ONBOARDING_COMPLETE, $approvedAt],
            self::ACTION_DEACTIVATE => [self::STATUS_INACTIVE, self::ONBOARDING_COMPLETE, $approvedAt],
            default => throw new StoreLifecycleException(
                'action_not_allowed',
                'La accion no corresponde a una transicion ejecutable.',
                null,
                $this->classify($status, $onboardingStatus, $approvedAt),
                $action
            ),
        };

        $result = [
            'status' => $authorities[0],
            'onboarding_status' => $authorities[1],
            'approved_at' => $authorities[2],
        ];
        $this->validate($result['status'], $result['onboarding_status'], $result['approved_at']);

        return $result;
    }

    public function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_REJECTED];
    }

    public function onboardingStatuses(): array
    {
        return [self::ONBOARDING_DRAFT, self::ONBOARDING_COMPLETE];
    }

    private function invalidField(string $status, string $onboardingStatus, mixed $approvedAt): string
    {
        if (($approvedAt !== null && $approvedAt !== '') || in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
            return 'approved_at';
        }
        return $onboardingStatus !== self::ONBOARDING_COMPLETE ? 'onboarding_status' : 'status';
    }

    private function approvalKind(mixed $approvedAt): string
    {
        if ($approvedAt === null || $approvedAt === '') {
            return 'unapproved';
        }
        if (! is_string($approvedAt)) {
            return 'invalid';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $approvedAt);
        return $date !== false && $date->format('Y-m-d H:i:s') === $approvedAt
            ? 'approved'
            : 'invalid';
    }
}
