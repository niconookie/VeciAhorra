<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\StoreOnboardingApplicationWriter;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingPublicIdCollisionException;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerificationRepository;

final class OnboardingAmbiguousWrite extends RuntimeException
{
    /** @param array<string, mixed> $expectedApplication */
    public function __construct(public readonly array $expectedApplication) { parent::__construct(); }
}

final class StoreOnboardingApplicationRepository implements StoreOnboardingApplicationWriter
{
    public function classify(string $idempotencyHash, string $accountEmail, string $ownerRutNormalized, string $termsVersion): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $idempotencyHash) !== 1) throw new RuntimeException('onboarding_lookup_failed');
        global $wpdb;
        $previousSuppression = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE idempotency_key_hash=%s LIMIT 1",
            $idempotencyHash
        ), ARRAY_A);
        $error = (string) ($wpdb->last_error ?? '');
        $wpdb->suppress_errors($previousSuppression);
        if ($error !== '') throw new RuntimeException('onboarding_lookup_failed');
        if (! is_array($row)) return 'new';
        $application = $this->hydrate($row);
        $this->assertReplayCompatible($application->data);
        return $this->sameCreationIntent($application->data, [
            'account_email' => $accountEmail,
            'owner_rut_normalized' => $ownerRutNormalized,
            'terms_version' => $termsVersion,
        ]) ? 'compatible_replay' : 'conflict';
    }
    public function createProvisioning(array $data): StoreOnboardingApplication
    {
        $required = ['public_id','account_email','owner_rut_normalized','idempotency_key_hash','terms_version','terms_accepted_at','created_at','updated_at'];
        if (array_diff($required, array_keys($data)) !== [] || array_diff(array_keys($data), $required) !== []) {
            throw new InvalidArgumentException('onboarding_invalid_create_shape');
        }
        $limits = ['public_id'=>64,'account_email'=>190,'owner_rut_normalized'=>12,'idempotency_key_hash'=>64,'terms_version'=>32];
        foreach ($limits as $field => $limit) {
            $value = (string) $data[$field];
            if ($value === '' || strlen($value) > $limit) throw new InvalidArgumentException('onboarding_invalid_' . $field);
        }
        if (sanitize_email((string) $data['account_email']) !== (string) $data['account_email']
            || strtolower((string) $data['account_email']) !== (string) $data['account_email']) {
            throw new InvalidArgumentException('onboarding_invalid_account_email');
        }
        if (preg_match('/^(?:[0-9]{7,8}[0-9K]|[0-9]{7,8}-[0-9K])$/', (string) $data['owner_rut_normalized']) !== 1) {
            throw new InvalidArgumentException('onboarding_invalid_owner_rut_normalized');
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) $data['idempotency_key_hash']) !== 1) {
            throw new InvalidArgumentException('onboarding_invalid_idempotency_hash');
        }
        $termsAcceptedAt = $this->requireCanonicalUtcTimestamp((string) $data['terms_accepted_at'], 'terms_accepted_at');
        $createdAt = $this->requireCanonicalUtcTimestamp((string) $data['created_at'], 'created_at');
        $updatedAt = $this->requireCanonicalUtcTimestamp((string) $data['updated_at'], 'updated_at');
        if ($termsAcceptedAt > $createdAt || $createdAt > $updatedAt) {
            throw new InvalidArgumentException('onboarding_invalid_timestamp_order');
        }
        $row = $data + [
            'user_id'=>null, 'status'=>StoreOnboardingApplication::PROVISIONING, 'store_id'=>null,
            'failure_code'=>null, 'attempt_count'=>0, 'last_attempt_at'=>null, 'abandoned_at'=>null,
        ];
        global $wpdb;
        $previousSuppression = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($this->table(), $row);
        $insertError = (string) ($wpdb->last_error ?? '');
        [$insertErrno, $insertSqlState] = $this->captureDriverError($wpdb);
        $wpdb->suppress_errors($previousSuppression);
        if ($inserted !== 1) {
            if ($insertErrno !== null && $insertErrno !== 0 && $insertErrno !== 1062) {
                throw new RuntimeException('onboarding_create_failed');
            }
            $existing = $this->findByIdempotencyHash((string) $data['idempotency_key_hash']);
            if ($existing !== null) {
                $this->assertReplayCompatible($existing->data);
                if ($this->sameCreationIntent($existing->data, $data)) return $existing;
                throw new RuntimeException('onboarding_idempotency_conflict');
            }
            $publicIdOccupied = $this->findByPublicId((string) $data['public_id']) !== null;
            if ($publicIdOccupied && $insertErrno === 1062) {
                throw new OnboardingPublicIdCollisionException();
            }
            if ($insertErrno === 1062) {
                throw new RuntimeException('onboarding_create_uncertain');
            }
            throw new RuntimeException($insertError !== ''
                ? 'onboarding_create_failed'
                : 'onboarding_create_uncertain');
        }
        return $this->findById((int) $wpdb->insert_id) ?? throw new RuntimeException('onboarding_create_uncertain');
    }

    public function findByPublicId(string $publicId): ?StoreOnboardingApplication { return $this->findOne('public_id', $publicId); }
    public function findByUserId(int $userId): ?StoreOnboardingApplication { return $this->findOne('user_id', $userId); }
    public function findByIdempotencyHash(string $hash): ?StoreOnboardingApplication { return $this->findOne('idempotency_key_hash', $hash); }
    public function findByApplicationId(int $id): ?StoreOnboardingApplication { if($id<1)throw new InvalidArgumentException('onboarding_invalid_id');return $this->findById($id); }

    public function attachUser(int $id, int $userId, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        if ($userId <= 0) throw new InvalidArgumentException('onboarding_invalid_user_id');
        $this->requireForwardTimestamp($expectedUpdatedAt, $updatedAt);
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('onboarding_attach_user_transaction_failed');
        }
        $expectedApplication = null;
        try {
            $result = $this->attachUserInTransaction($id, $userId, $expectedUpdatedAt, $updatedAt, null, $expectedApplication);
            if ($wpdb->query('COMMIT') === false) {
                $this->requireCleanConnectionForReconciliation('onboarding_attach_user_outcome_uncertain');
                return $this->reconcileAttachedUser($id, $expectedApplication);
            }
            return $result;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            if ($exception instanceof OnboardingAmbiguousWrite) {
                $this->requireCleanConnectionForReconciliation('onboarding_attach_user_outcome_uncertain', false);
                return $this->reconcileAttachedUser($id, $exception->expectedApplication);
            }
            throw $exception;
        }
    }

    public function attachUserInTransaction(int $id, int $userId, string $expectedUpdatedAt, string $updatedAt, ?callable $compatible = null, ?array &$expectedApplication = null): StoreOnboardingApplication
    {
        if ($userId <= 0) throw new InvalidArgumentException('onboarding_invalid_user_id');
        $this->requireForwardTimestamp($expectedUpdatedAt, $updatedAt);
        global $wpdb;
        if ($wpdb instanceof \wpdb && (int) $wpdb->get_var('SELECT @@in_transaction') !== 1) {
            throw new RuntimeException('onboarding_attach_user_transaction_required');
        }
        $wpdb->last_error = '';
        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id=%d FOR UPDATE", $id
        ), ARRAY_A);
        $applicationReadError = (string) ($wpdb->last_error ?? '');
        if ($applicationReadError !== '') throw new RuntimeException('onboarding_read_failed');
        if (! is_array($application)) throw new RuntimeException('onboarding_not_found');
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $userId)) !== $userId) {
            throw new RuntimeException('onboarding_user_missing');
        }
        if ($compatible !== null && ! $this->userCompatible($compatible, $userId, (string) $application['account_email'])) {
            throw new RuntimeException('onboarding_user_incompatible');
        }
        $status=(string)$application['status'];$attached=$application['user_id']===null?null:(int)$application['user_id'];
        if ($status===StoreOnboardingApplication::ACCOUNT_CREATED) {
            if ($attached===$userId) {
                $expectedApplication=$this->applicationProjection($application);
                return $this->findById($id) ?? throw new RuntimeException('onboarding_update_uncertain');
            }
            throw new RuntimeException('onboarding_user_conflict');
        }
        if ($status!==StoreOnboardingApplication::PROVISIONING) throw new RuntimeException('onboarding_state_incompatible');
        if ($attached!==null) throw new RuntimeException('onboarding_corrupt_user_reference');
        if ((string)$application['updated_at']!==$expectedUpdatedAt) throw new RuntimeException('onboarding_concurrent_modification');
        $expectedApplication = $this->applicationProjection(array_merge($application, [
            'user_id'=>$userId, 'status'=>StoreOnboardingApplication::ACCOUNT_CREATED,
            'failure_code'=>null, 'updated_at'=>$updatedAt,
        ]));
        return $this->transition($id,StoreOnboardingApplication::PROVISIONING,StoreOnboardingApplication::ACCOUNT_CREATED,$expectedUpdatedAt,$updatedAt,['user_id'=>$userId],true,$expectedApplication);
    }

    public function recoverProvisioningFailure(int $id, string $expectedUpdatedAt, string $updatedAt, callable $userCompatible): StoreOnboardingApplication
    {
        $this->requireForwardTimestamp($expectedUpdatedAt,$updatedAt);
        $compatibilitySnapshot = $this->findById($id) ?? throw new RuntimeException('onboarding_not_found');
        $snapshotUserId = $compatibilitySnapshot->data['user_id'] === null ? null : (int) $compatibilitySnapshot->data['user_id'];
        if ($snapshotUserId !== null && !$this->userCompatible($userCompatible, $snapshotUserId, (string) $compatibilitySnapshot->data['account_email'])) {
            throw new RuntimeException('onboarding_recovery_user_incompatible');
        }
        if (in_array($compatibilitySnapshot->data['status'], [StoreOnboardingApplication::PROVISIONING, StoreOnboardingApplication::ACCOUNT_CREATED], true)) {
            $target = $snapshotUserId === null ? StoreOnboardingApplication::PROVISIONING : StoreOnboardingApplication::ACCOUNT_CREATED;
            if ($compatibilitySnapshot->data['status'] === $target && $compatibilitySnapshot->data['failure_code'] === null
                && $compatibilitySnapshot->data['store_id'] === null && $compatibilitySnapshot->data['updated_at'] === $updatedAt) {
                if ($target === StoreOnboardingApplication::ACCOUNT_CREATED) $this->assertConsumedVerificationReference($id, $snapshotUserId, false);
                else $this->assertUnconsumedVerificationReference($id, false);
                return $compatibilitySnapshot;
            }
            throw new RuntimeException('onboarding_recovery_conflict');
        }
        global $wpdb;
        if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('onboarding_recovery_transaction_failed');
        $expectedApplication = null;
        $expectedVerification = null;
        try {
            $wpdb->last_error='';
            $application=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
            $applicationReadError=(string)($wpdb->last_error??'');
            if($applicationReadError!=='')throw new RuntimeException('onboarding_read_failed');
            if(!is_array($application))throw new RuntimeException('onboarding_not_found');
            $recoverable=[StoreOnboardingApplication::ACCOUNT_PROVISIONING_FAILED,StoreOnboardingApplication::ACCOUNT_PROVISIONING_UNCERTAIN,StoreOnboardingApplication::EMAIL_DELIVERY_FAILED,StoreOnboardingApplication::EMAIL_DELIVERY_UNCERTAIN];
            if($application['status']!==StoreOnboardingApplication::PROVISIONING_FAILED||!in_array($application['failure_code'],$recoverable,true)||$application['store_id']!==null||(string)$application['updated_at']!==$expectedUpdatedAt)throw new RuntimeException('onboarding_recovery_forbidden');
            $userId=$application['user_id']===null?null:(int)$application['user_id'];$target=StoreOnboardingApplication::PROVISIONING;
            if ($userId === null) {
                $this->assertUnconsumedVerificationReference($id, true);
                $expectedVerification = $this->lockedVerificationProjection($id);
            }
            if($userId!==null){
                $expectedVerification=$this->lockedVerificationProjection($id);
                if($expectedVerification===null||$expectedVerification['consumed_at']===null||$expectedVerification['attached_user_id']!==$userId
                    ||$expectedVerification['purpose']!=='minimarket_account_activation'||$expectedVerification['generation']<1)throw new RuntimeException('onboarding_recovery_reference_invalid');
                if((int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$userId))!==$userId
                    ||$userId!==$snapshotUserId||(string)$application['account_email']!==(string)$compatibilitySnapshot->data['account_email'])throw new RuntimeException('onboarding_recovery_user_incompatible');
                $target=StoreOnboardingApplication::ACCOUNT_CREATED;
            }
            StoreOnboardingApplication::assertRecoveryTransition(StoreOnboardingApplication::PROVISIONING_FAILED, $target);
            $expectedApplication=$this->applicationProjection(array_merge($application,['status'=>$target,'failure_code'=>null,'updated_at'=>$updatedAt]));
            $changed=$wpdb->update($this->table(),['status'=>$target,'failure_code'=>null,'updated_at'=>$updatedAt],['id'=>$id,'status'=>StoreOnboardingApplication::PROVISIONING_FAILED,'updated_at'=>$expectedUpdatedAt]);
            if($changed!==1){$this->requireCleanConnectionForReconciliation('onboarding_recovery_outcome_uncertain');return $this->reconcileRecovery($id,$expectedApplication,$expectedVerification);}
            try{$result=$this->findById($id);}catch(\Throwable){$this->requireCleanConnectionForReconciliation('onboarding_recovery_outcome_uncertain');return $this->reconcileRecovery($id,$expectedApplication,$expectedVerification);}if($result===null){$this->requireCleanConnectionForReconciliation('onboarding_recovery_outcome_uncertain');return $this->reconcileRecovery($id,$expectedApplication,$expectedVerification);}
            if($wpdb->query('COMMIT')===false){$this->requireCleanConnectionForReconciliation('onboarding_recovery_outcome_uncertain');return $this->reconcileRecovery($id,$expectedApplication,$expectedVerification);}return $result;
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }
    public function markProfileIncomplete(int $id, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        return $this->transition($id, StoreOnboardingApplication::ACCOUNT_CREATED, StoreOnboardingApplication::PROFILE_INCOMPLETE, $expectedUpdatedAt, $updatedAt);
    }
    public function markReadyToMaterialize(int $id, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        return $this->transition($id, StoreOnboardingApplication::PROFILE_INCOMPLETE, StoreOnboardingApplication::READY_TO_MATERIALIZE, $expectedUpdatedAt, $updatedAt);
    }
    public function attachMaterializedStore(int $id, int $storeId, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        if ($storeId <= 0) throw new InvalidArgumentException('onboarding_invalid_store_id');
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('onboarding_materialization_transaction_failed');
        }
        try {
            $application = $wpdb->get_row($wpdb->prepare(
                "SELECT user_id,status,updated_at,store_id FROM {$this->table()} WHERE id=%d FOR UPDATE",
                $id
            ), ARRAY_A);
            if (! is_array($application)) throw new RuntimeException('onboarding_not_found');
            $status = (string) $application['status'];
            $isReplay = $status === StoreOnboardingApplication::STORE_MATERIALIZED;
            if (! $isReplay && ($status !== StoreOnboardingApplication::READY_TO_MATERIALIZE
                || (string) $application['updated_at'] !== $expectedUpdatedAt)) {
                throw new RuntimeException('onboarding_concurrent_modification');
            }
            if ($isReplay && (int) ($application['store_id'] ?? 0) !== $storeId) {
                throw new RuntimeException('onboarding_materialized_store_conflict');
            }
            $userId = (int) ($application['user_id'] ?? 0);
            $store = $wpdb->get_row($wpdb->prepare(
                "SELECT id,owner_user_id FROM {$this->storesTable()} WHERE id=%d FOR UPDATE",
                $storeId
            ), ARRAY_A);
            if (! is_array($store)) throw new RuntimeException('onboarding_store_missing');
            if ($userId <= 0 || (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",
                $userId
            )) !== $userId) {
                throw new RuntimeException('onboarding_user_missing');
            }
            if ($store['owner_user_id'] === null) throw new RuntimeException('onboarding_store_owner_missing');
            if ((int) $store['owner_user_id'] !== $userId) {
                throw new RuntimeException('onboarding_store_owner_conflict');
            }
            if ($isReplay) {
                $result = $this->findById($id) ?? throw new RuntimeException('onboarding_not_found');
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('onboarding_materialization_commit_failed');
                return $result;
            }
            $result = $this->transition(
                $id,
                StoreOnboardingApplication::READY_TO_MATERIALIZE,
                StoreOnboardingApplication::STORE_MATERIALIZED,
                $expectedUpdatedAt,
                $updatedAt,
                ['store_id'=>$storeId]
            );
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('onboarding_materialization_commit_failed');
            }
            return $result;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }
    public function markProvisioningFailed(int $id, string $failureCode, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        StoreOnboardingApplication::assertFailureCode($failureCode);
        $current = $this->findById($id) ?? throw new RuntimeException('onboarding_not_found');
        return $this->transition($id, (string)$current->data['status'], StoreOnboardingApplication::PROVISIONING_FAILED, $expectedUpdatedAt, $updatedAt, ['failure_code'=>$failureCode]);
    }
    public function markAbandoned(int $id, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        $current = $this->findById($id) ?? throw new RuntimeException('onboarding_not_found');
        return $this->transition($id, (string)$current->data['status'], StoreOnboardingApplication::ABANDONED, $expectedUpdatedAt, $updatedAt, ['abandoned_at'=>$updatedAt]);
    }
    public function incrementAttempt(int $id, string $expectedUpdatedAt, string $updatedAt): StoreOnboardingApplication
    {
        $this->requireForwardTimestamp($expectedUpdatedAt, $updatedAt);
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET attempt_count=attempt_count+1,last_attempt_at=%s,updated_at=%s WHERE id=%d AND updated_at=%s",
            $updatedAt, $updatedAt, $id, $expectedUpdatedAt
        ));
        if ($changed !== 1) throw new RuntimeException('onboarding_concurrent_modification');
        return $this->findById($id) ?? throw new RuntimeException('onboarding_update_uncertain');
    }

    private function transition(int $id, string $from, string $to, string $expectedUpdatedAt, string $updatedAt, array $extra = [], bool $ambiguousWrite = false, array $expectedApplication = []): StoreOnboardingApplication
    {
        StoreOnboardingApplication::assertTransition($from, $to);
        $this->requireForwardTimestamp($expectedUpdatedAt, $updatedAt);
        $allowed = ['user_id','store_id','failure_code','abandoned_at'];
        if (array_diff(array_keys($extra), $allowed) !== []) throw new InvalidArgumentException('onboarding_invalid_transition_shape');
        $set = array_merge(['status'=>$to,'updated_at'=>$updatedAt], $extra);
        if ($to !== StoreOnboardingApplication::PROVISIONING_FAILED) {
            $set['failure_code'] = null;
        }
        global $wpdb;
        $changed = $wpdb->update($this->table(), $set, ['id'=>$id,'status'=>$from,'updated_at'=>$expectedUpdatedAt]);
        if ($changed !== 1) throw $ambiguousWrite ? new OnboardingAmbiguousWrite($expectedApplication) : new RuntimeException('onboarding_concurrent_modification');
        try { $result = $this->findById($id); }
        catch (\Throwable $exception) {
            if ($ambiguousWrite) throw new OnboardingAmbiguousWrite($expectedApplication);
            throw $exception;
        }
        if ($result === null) throw $ambiguousWrite ? new OnboardingAmbiguousWrite($expectedApplication) : new RuntimeException('onboarding_update_uncertain');
        return $result;
    }

    private function findById(int $id): ?StoreOnboardingApplication { return $this->findOne('id', $id); }

    /** @param array<string, mixed>|null $expected */
    private function reconcileAttachedUser(int $id, ?array $expected): StoreOnboardingApplication
    {
        try { $row = $this->findApplicationRowForReconciliation($id); }
        catch (\Throwable) { throw new RuntimeException('onboarding_attach_user_outcome_uncertain'); }
        if ($expected === null) throw new RuntimeException('onboarding_attach_user_outcome_uncertain');
        if ($row === null) throw new RuntimeException('onboarding_attach_user_persistence_failed');
        if ($this->applicationProjection($row) !== $expected) throw new RuntimeException('onboarding_attach_user_conflict');
        try { return $this->hydrate($row); }
        catch (\Throwable) { throw new RuntimeException('onboarding_attach_user_outcome_uncertain'); }
    }

    /** @param array<string, mixed> $expectedApplication @param array<string, mixed>|null $expectedVerification */
    private function reconcileRecovery(int $id, array $expectedApplication, ?array $expectedVerification): StoreOnboardingApplication
    {
        try { $row = $this->findApplicationRowForReconciliation($id); }
        catch (\Throwable) { throw new RuntimeException('onboarding_recovery_outcome_uncertain'); }
        try { $verification = (new StoreOnboardingEmailVerificationRepository())->findByApplicationId($id); }
        catch (\Throwable) { throw new RuntimeException('onboarding_recovery_outcome_uncertain'); }
        $actualVerification = $verification === null ? null : $this->verificationProjection($verification);
        if ($row === null) throw new RuntimeException('onboarding_recovery_persistence_failed');
        if ($this->applicationProjection($row) !== $expectedApplication || $actualVerification !== $expectedVerification) throw new RuntimeException('onboarding_recovery_conflict');
        try { return $this->hydrate($row); }
        catch (\Throwable) { throw new RuntimeException('onboarding_recovery_outcome_uncertain'); }
    }

    private function assertConsumedVerificationReference(int $id, int $userId, bool $lock): void
    {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT purpose,generation,consumed_at,attached_user_id FROM {$this->verificationTable()} WHERE application_id=%d{$suffix}", $id
        ), ARRAY_A);
        if (!is_array($verification) || (string) ($wpdb->last_error ?? '') !== '' || $verification['consumed_at'] === null
            || (int) $verification['attached_user_id'] !== $userId || (string) $verification['purpose'] !== 'minimarket_account_activation'
            || (int) $verification['generation'] < 1) throw new RuntimeException('onboarding_recovery_reference_invalid');
    }

    private function assertUnconsumedVerificationReference(int $id, bool $lock): void
    {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT consumed_at,attached_user_id FROM {$this->verificationTable()} WHERE application_id=%d{$suffix}", $id
        ), ARRAY_A);
        if ((string) ($wpdb->last_error ?? '') !== '') throw new RuntimeException('onboarding_recovery_reference_invalid');
        if (is_array($verification) && ($verification['consumed_at'] !== null || $verification['attached_user_id'] !== null)) {
            throw new RuntimeException('onboarding_recovery_reference_invalid');
        }
    }

    private function requireCleanConnectionForReconciliation(string $reason, bool $rollback = true): void
    {
        global $wpdb;
        if ($rollback) $wpdb->query('ROLLBACK');
        $wpdb->last_error = '';
        $state = $wpdb->get_var('SELECT @@in_transaction');
        $stateError = (string) ($wpdb->last_error ?? '');
        if ($stateError !== '' || !($state === 0 || $state === '0')) {
            throw new RuntimeException($reason);
        }
    }

    /** @return array<string, mixed>|null */
    private function findApplicationRowForReconciliation(int $id): ?array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id=%d LIMIT 1", $id), ARRAY_A);
        $readError = (string) ($wpdb->last_error ?? '');
        if ($readError !== '') throw new RuntimeException('onboarding_read_failed');
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function applicationProjection(array $data): array
    {
        $required = ['id','public_id','user_id','account_email','owner_rut_normalized','status','idempotency_key_hash','terms_version','terms_accepted_at','store_id','failure_code','attempt_count','last_attempt_at','created_at','updated_at','abandoned_at'];
        if (array_diff($required, array_keys($data)) !== []) throw new RuntimeException('onboarding_snapshot_invalid');
        return [
            'id'=>(int)$data['id'], 'public_id'=>(string)$data['public_id'],
            'user_id'=>$data['user_id']===null?null:(int)$data['user_id'], 'account_email'=>(string)$data['account_email'],
            'owner_rut_normalized'=>(string)$data['owner_rut_normalized'], 'status'=>(string)$data['status'],
            'idempotency_key_hash'=>(string)$data['idempotency_key_hash'], 'terms_version'=>(string)$data['terms_version'],
            'terms_accepted_at'=>(string)$data['terms_accepted_at'], 'store_id'=>$data['store_id']===null?null:(int)$data['store_id'],
            'failure_code'=>$data['failure_code']===null?null:(string)$data['failure_code'], 'attempt_count'=>(int)$data['attempt_count'],
            'last_attempt_at'=>$data['last_attempt_at']===null?null:(string)$data['last_attempt_at'], 'created_at'=>(string)$data['created_at'],
            'updated_at'=>(string)$data['updated_at'], 'abandoned_at'=>$data['abandoned_at']===null?null:(string)$data['abandoned_at'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function lockedVerificationProjection(int $id): ?array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->verificationTable()} WHERE application_id=%d FOR UPDATE", $id), ARRAY_A);
        $readError = (string) ($wpdb->last_error ?? '');
        if ($readError !== '') throw new RuntimeException('onboarding_recovery_reference_invalid');
        if (!is_array($row)) return null;
        try { return $this->verificationProjection(StoreOnboardingEmailVerification::fromRow($row)); }
        catch (\Throwable) { throw new RuntimeException('onboarding_recovery_reference_invalid'); }
    }

    /** @return array<string, mixed> */
    private function verificationProjection(StoreOnboardingEmailVerification $verification): array
    {
        return [
            'id'=>$verification->id, 'application_id'=>$verification->applicationId, 'purpose'=>$verification->purpose,
            'generation'=>$verification->generation, 'candidate_user_id'=>$verification->candidateUserId,
            'attached_user_id'=>$verification->attachedUserId, 'email_binding_hash'=>$verification->emailBindingHash,
            'token_hash'=>$verification->tokenHash, 'expires_at'=>$verification->expiresAt, 'consumed_at'=>$verification->consumedAt,
            'failed_attempts'=>$verification->failedAttempts, 'resend_count'=>$verification->resendCount,
            'last_sent_at'=>$verification->lastSentAt, 'delivery_state'=>$verification->deliveryState,
            'delivery_attempt_count'=>$verification->deliveryAttemptCount, 'last_error_code'=>$verification->lastErrorCode,
            'created_at'=>$verification->createdAt, 'updated_at'=>$verification->updatedAt,
        ];
    }

    private function findOne(string $column, string|int $value): ?StoreOnboardingApplication
    {
        if (! in_array($column, ['id','public_id','user_id','idempotency_key_hash'], true)) throw new InvalidArgumentException('onboarding_invalid_lookup');
        global $wpdb;
        $placeholder = is_int($value) ? '%d' : '%s';
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE {$column}={$placeholder} LIMIT 1", $value), ARRAY_A);
        $readError = (string) ($wpdb->last_error ?? '');
        if ($readError !== '') throw new RuntimeException('onboarding_read_failed');
        if (! is_array($row)) return null;
        try { return $this->hydrate($row); }
        catch (\Throwable) { throw new RuntimeException('onboarding_read_failed'); }
    }

    private function hydrate(array $row): StoreOnboardingApplication
    {
        foreach (['terms_accepted_at','created_at','updated_at'] as $field) {
            $this->requireCanonicalUtcTimestamp((string) $row[$field], $field);
        }
        foreach (['last_attempt_at','abandoned_at'] as $field) {
            if ($row[$field] !== null) $this->requireCanonicalUtcTimestamp((string) $row[$field], $field);
        }
        return new StoreOnboardingApplication($row);
    }
    private function sameCreationIntent(array $existing, array $input): bool
    {
        foreach (['account_email','owner_rut_normalized','terms_version'] as $field) {
            if ((string)$existing[$field] !== (string)$input[$field]) return false;
        }
        return true;
    }

    private function assertReplayCompatible(array $data): void
    {
        $status = (string) $data['status'];
        $userId = $data['user_id'] === null ? null : (int) $data['user_id'];
        $storeId = $data['store_id'] === null ? null : (int) $data['store_id'];
        $failureCode = $data['failure_code'];
        $validUserReference = $userId === null || $userId > 0;
        $compatible = match ($status) {
            StoreOnboardingApplication::PROVISIONING => $userId === null && $storeId === null && $failureCode === null,
            StoreOnboardingApplication::ACCOUNT_CREATED,
            StoreOnboardingApplication::PROFILE_INCOMPLETE,
            StoreOnboardingApplication::READY_TO_MATERIALIZE => $userId !== null && $userId > 0 && $storeId === null && $failureCode === null,
            StoreOnboardingApplication::PROVISIONING_FAILED => $validUserReference && $storeId === null && is_string($failureCode),
            StoreOnboardingApplication::STORE_MATERIALIZED => $userId !== null && $userId > 0 && $storeId !== null && $storeId > 0 && $failureCode === null,
            StoreOnboardingApplication::ABANDONED => $validUserReference && $storeId === null && $failureCode === null && $data['abandoned_at'] !== null,
            default => false,
        };
        if (! $compatible) throw new RuntimeException('onboarding_replay_incompatible');
        global $wpdb;
        if ($userId !== null && (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID=%d",
            $userId
        )) !== $userId) {
            throw new RuntimeException('onboarding_replay_incompatible');
        }
        if ($status === StoreOnboardingApplication::STORE_MATERIALIZED) {
            $store = $wpdb->get_row($wpdb->prepare(
                "SELECT id,owner_user_id FROM {$this->storesTable()} WHERE id=%d",
                $storeId
            ), ARRAY_A);
            if (! is_array($store) || $store['owner_user_id'] === null || (int) $store['owner_user_id'] !== $userId) {
                throw new RuntimeException('onboarding_replay_incompatible');
            }
        }
    }

    private function requireForwardTimestamp(string $expectedUpdatedAt, string $updatedAt): void
    {
        $this->requireCanonicalUtcTimestamp($expectedUpdatedAt, 'expected_updated_at');
        $this->requireCanonicalUtcTimestamp($updatedAt, 'updated_at');
        if ($updatedAt <= $expectedUpdatedAt) {
            throw new InvalidArgumentException('onboarding_updated_at_must_advance');
        }
    }

    private function requireCanonicalUtcTimestamp(string $value, string $field): string
    {
        if (
            strlen($value) !== 19
            || trim($value) !== $value
            || $value < '1000-01-01 00:00:00'
            || $value > '9999-12-31 23:59:59'
        ) {
            throw new InvalidArgumentException($field . ' must be a canonical UTC timestamp');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ! $parsed
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
        ) {
            throw new InvalidArgumentException($field . ' must be a canonical UTC timestamp');
        }
        return $value;
    }
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . 'store_onboarding_applications';
    }
    private function storesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
    }
    private function verificationTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . 'store_onboarding_email_verifications';
    }
    private function userCompatible(callable $validator,int $userId,string $email): bool
    {
        try { return $validator($userId,$email) === true; } catch (\Throwable) { return false; }
    }

    /** @return array{?int,?string} */
    private function captureDriverError(object $wpdb): array
    {
        try {
            $dbh = $wpdb->dbh ?? null;
            if ($dbh instanceof \mysqli) {
                return [mysqli_errno($dbh), mysqli_sqlstate($dbh)];
            }
            // Isolated wpdb-compatible fakes expose the same structured driver
            // fields without requiring a live mutable database connection.
            if (is_object($dbh)) {
                $errno = property_exists($dbh, 'errno') && is_int($dbh->errno) ? $dbh->errno : null;
                $sqlState = property_exists($dbh, 'sqlstate') && is_string($dbh->sqlstate) ? $dbh->sqlstate : null;
                return [$errno, $sqlState];
            }
        } catch (\Throwable $exception) {
            return [null, null];
        }
        return [null, null];
    }
}
