<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Verification;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Onboarding\OnboardingAmbiguousWrite;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;

final class VerificationAmbiguousWrite extends RuntimeException {}
final class VerificationClosedOutcome extends RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}

final class StoreOnboardingEmailVerificationRepository
{
    public function findById(int $id): ?StoreOnboardingEmailVerification { return $this->find('id', $id); }
    public function findByApplicationId(int $id): ?StoreOnboardingEmailVerification { return $this->find('application_id', $id); }
    public function findByTokenHash(string $hash): ?StoreOnboardingEmailVerification { $this->hash($hash); return $this->find('token_hash', $hash); }

    public function create(int $applicationId, string $purpose, ?int $candidateUserId, string $emailHash, string $tokenHash, string $expiresAt, string $now, ?callable $candidateCompatible = null): StoreOnboardingEmailVerification
    {
        $this->intent($applicationId, $purpose, $candidateUserId, $emailHash, $tokenHash, $expiresAt, $now);
        if ($candidateUserId !== null && ($candidateCompatible === null || !$this->compatible($candidateCompatible, [$candidateUserId]))) {
            throw new RuntimeException('verification_candidate_user_incompatible');
        }
        $reconcile = fn() => $this->reconcileCreate($applicationId, $purpose, $candidateUserId, $emailHash, $tokenHash, $expiresAt);
        return $this->transaction(function () use ($applicationId, $purpose, $candidateUserId, $emailHash, $tokenHash, $expiresAt, $now, $candidateCompatible) {
            $this->eligibleApplication($applicationId);
            $existing = $this->findLockedByApplication($applicationId);
            if ($existing !== null) {
                if ($this->sameCreateIntent($existing, $purpose, $candidateUserId, $emailHash, $tokenHash, $expiresAt)) return $existing;
                throw new RuntimeException('verification_conflict');
            }
            $occupied = $this->find('token_hash', $tokenHash, true);
            if ($occupied !== null) throw new RuntimeException('verification_conflict');
            if ($candidateUserId !== null) {
                global $wpdb;
                if ((int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $candidateUserId)) !== $candidateUserId) throw new RuntimeException('verification_candidate_user_incompatible');
            }
            global $wpdb;
            $ok = $wpdb->insert($this->table(), ['application_id'=>$applicationId, 'purpose'=>$purpose, 'generation'=>1, 'candidate_user_id'=>$candidateUserId, 'attached_user_id'=>null, 'email_binding_hash'=>$emailHash, 'token_hash'=>$tokenHash, 'expires_at'=>$expiresAt, 'consumed_at'=>null, 'failed_attempts'=>0, 'resend_count'=>0, 'last_sent_at'=>null, 'delivery_state'=>StoreOnboardingEmailVerification::PENDING, 'delivery_attempt_count'=>0, 'last_error_code'=>null, 'created_at'=>$now, 'updated_at'=>$now]);
            if ($ok !== 1) throw new VerificationAmbiguousWrite();
            return $this->findAfterWriteOrAmbiguous($applicationId);
        }, $reconcile);
    }

    public function rotate(int $applicationId, int $expectedGeneration, string $expectedUpdatedAt, string $tokenHash, string $expiresAt, string $now): StoreOnboardingEmailVerification
    {
        $this->hash($tokenHash); StoreOnboardingEmailVerification::timestamp($expiresAt); $this->advance($expectedUpdatedAt, $now);
        if ($expectedGeneration < 1 || $expiresAt <= $now) throw new InvalidArgumentException('verification_invalid_rotation');
        $expected = null;
        return $this->transaction(function () use ($applicationId, $expectedGeneration, $expectedUpdatedAt, $tokenHash, $expiresAt, $now, &$expected) {
            $this->eligibleApplication($applicationId);
            $v = $this->findLockedByApplication($applicationId) ?? throw new RuntimeException('verification_not_found');
            if ($v->consumedAt !== null || $v->generation === PHP_INT_MAX || $v->resendCount >= 65535) throw new RuntimeException('verification_rotation_forbidden');
            if ($v->generation === $expectedGeneration + 1 && hash_equals($v->tokenHash, $tokenHash) && $v->expiresAt === $expiresAt) return $v;
            if ($v->generation !== $expectedGeneration || $v->updatedAt !== $expectedUpdatedAt) throw new RuntimeException('verification_concurrent_modification');
            if (hash_equals($v->tokenHash, $tokenHash)) throw new RuntimeException('verification_conflict');
            $occupied = $this->find('token_hash', $tokenHash, true);
            if ($occupied !== null && $occupied->id !== $v->id) throw new RuntimeException('verification_conflict');
            $expected = ['generation'=>$expectedGeneration + 1, 'resend'=>$v->resendCount + 1];
            global $wpdb;
            $changed = $wpdb->update($this->table(), ['generation'=>$expected['generation'], 'token_hash'=>$tokenHash, 'expires_at'=>$expiresAt, 'consumed_at'=>null, 'attached_user_id'=>null, 'failed_attempts'=>0, 'resend_count'=>$expected['resend'], 'last_sent_at'=>null, 'delivery_state'=>StoreOnboardingEmailVerification::PENDING, 'delivery_attempt_count'=>0, 'last_error_code'=>null, 'updated_at'=>$now], ['id'=>$v->id, 'generation'=>$expectedGeneration, 'updated_at'=>$expectedUpdatedAt]);
            if ($changed !== 1) throw new VerificationAmbiguousWrite();
            return $this->findAfterWriteOrAmbiguous($applicationId);
        }, function () use ($applicationId, $tokenHash, $expiresAt, $now, &$expected) {
            if (!is_array($expected)) throw new VerificationClosedOutcome('verification_persistence_failed');
            $v = $this->freshByApplication($applicationId);
            if ($v !== null && $v->generation === $expected['generation'] && $v->resendCount === $expected['resend'] && hash_equals($v->tokenHash, $tokenHash)
                && $v->expiresAt === $expiresAt && $v->updatedAt === $now && $v->deliveryState === StoreOnboardingEmailVerification::PENDING && $v->deliveryAttemptCount === 0 && $v->failedAttempts === 0) return $v;
            throw new VerificationClosedOutcome($v === null ? 'verification_persistence_failed' : 'verification_conflict');
        });
    }

    public function markDeliveryAttempt(int $applicationId, int $generation, string $expectedUpdatedAt, string $now): StoreOnboardingEmailVerification
    {
        return $this->mutate($applicationId, $generation, $expectedUpdatedAt, $now,
            function ($v) { if ($v->consumedAt !== null || $v->deliveryState !== StoreOnboardingEmailVerification::PENDING || $v->deliveryAttemptCount >= 65535) throw new RuntimeException('verification_delivery_forbidden'); return ['delivery_attempt_count'=>$v->deliveryAttemptCount + 1]; },
            fn($v, $before) => $v->deliveryState === StoreOnboardingEmailVerification::PENDING
                && ($v === $before ? $v->deliveryAttemptCount > 0 : $v->deliveryAttemptCount === $before->deliveryAttemptCount + 1)
        );
    }

    public function markSent(int $a, int $g, string $e, string $now): StoreOnboardingEmailVerification { return $this->delivery($a, $g, $e, $now, StoreOnboardingEmailVerification::SENT, null); }
    public function markFailed(int $a, int $g, string $e, string $now): StoreOnboardingEmailVerification { return $this->delivery($a, $g, $e, $now, StoreOnboardingEmailVerification::FAILED, StoreOnboardingEmailVerification::DELIVERY_FAILED); }
    public function markUncertain(int $a, int $g, string $e, string $now): StoreOnboardingEmailVerification { return $this->delivery($a, $g, $e, $now, StoreOnboardingEmailVerification::UNCERTAIN, StoreOnboardingEmailVerification::DELIVERY_UNCERTAIN); }

    public function resolveUncertainDelivery(int $a, int $g, string $e, string $now, string $target): StoreOnboardingEmailVerification
    {
        if (!in_array($target, [StoreOnboardingEmailVerification::SENT, StoreOnboardingEmailVerification::FAILED], true)) throw new InvalidArgumentException('verification_invalid_delivery_resolution');
        $error = $target === StoreOnboardingEmailVerification::FAILED ? StoreOnboardingEmailVerification::DELIVERY_FAILED : null;
        return $this->mutate($a, $g, $e, $now, function ($v) use ($target, $error, $now) {
            StoreOnboardingEmailVerification::assertUncertainResolution($v->deliveryState, $target);
            return ['delivery_state'=>$target, 'last_sent_at'=>$target === StoreOnboardingEmailVerification::SENT ? $now : null, 'last_error_code'=>$error];
        }, fn($v, $before) => $this->sameDeliveryResult($v, $target, $error, $target === StoreOnboardingEmailVerification::SENT ? $now : null, $before->deliveryAttemptCount));
    }

    public function recordInvalidAttempt(int $applicationId, int $generation, string $expectedUpdatedAt, string $now): StoreOnboardingEmailVerification
    {
        return $this->mutate($applicationId, $generation, $expectedUpdatedAt, $now, function ($v) use ($now) {
            if ($v->consumedAt !== null || $v->expiresAt <= $now) throw new RuntimeException('verification_attempt_forbidden');
            if ($v->failedAttempts >= 5) return [];
            return ['failed_attempts'=>$v->failedAttempts + 1];
        }, fn($v, $before) => $v->failedAttempts >= $before->failedAttempts && $v->failedAttempts <= 5);
    }

    public function bindCandidateUser(int $applicationId,int $generation,int $userId,string $expectedUpdatedAt,string $now):StoreOnboardingEmailVerification
    {
        if($userId<1)throw new InvalidArgumentException('verification_invalid_user');$this->advance($expectedUpdatedAt,$now);
        return $this->transaction(function()use($applicationId,$generation,$userId,$expectedUpdatedAt,$now){
            $this->eligibleApplication($applicationId);$v=$this->findLockedByApplication($applicationId)??throw new RuntimeException('verification_not_found');
            if($v->generation!==$generation||$v->consumedAt!==null)throw new RuntimeException('verification_concurrent_modification');
            if($v->candidateUserId===$userId)return $v;if($v->candidateUserId!==null)throw new RuntimeException('verification_conflict');
            global $wpdb;if((int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$userId))!==$userId)throw new RuntimeException('verification_reference_invalid');
            if($v->updatedAt!==$expectedUpdatedAt)throw new RuntimeException('verification_concurrent_modification');
            if($wpdb->update($this->table(),['candidate_user_id'=>$userId,'updated_at'=>$now],['id'=>$v->id,'generation'=>$generation,'candidate_user_id'=>null,'updated_at'=>$expectedUpdatedAt])!==1)throw new VerificationAmbiguousWrite();
            return $this->findAfterWriteOrAmbiguous($applicationId);
        },function()use($applicationId,$generation,$userId,$now){$v=$this->freshByApplication($applicationId);if($v!==null&&$v->generation===$generation&&$v->candidateUserId===$userId&&$v->attachedUserId===null&&$v->consumedAt===null&&$v->updatedAt===$now)return $v;throw new VerificationClosedOutcome($v===null?'verification_persistence_failed':'verification_conflict');});
    }

    public function consumeAndAttach(int $applicationId, int $generation, string $tokenHash, int $userId, string $expectedApplicationUpdatedAt, string $expectedVerificationUpdatedAt, string $now, callable $compatible): StoreOnboardingEmailVerification
    {
        $this->hash($tokenHash); if ($userId <= 0) throw new InvalidArgumentException('verification_invalid_user'); StoreOnboardingEmailVerification::timestamp($now);
        $compatibilityApplication = $this->freshApplication($applicationId);
        $compatibilityVerification = $this->freshByApplication($applicationId);
        if ($compatibilityApplication === null || $compatibilityVerification === null
            || !$this->compatible($compatible, [$userId, (string) $compatibilityApplication['account_email'], $compatibilityVerification->candidateUserId])) {
            throw new RuntimeException('verification_user_incompatible');
        }
        return $this->transaction(function () use ($applicationId, $generation, $tokenHash, $userId, $expectedApplicationUpdatedAt, $expectedVerificationUpdatedAt, $now, $compatibilityApplication, $compatibilityVerification) {
            global $wpdb;
            $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->applicationsTable()} WHERE id=%d FOR UPDATE", $applicationId), ARRAY_A);
            if (!is_array($app) || $wpdb->last_error !== '') throw new RuntimeException('verification_reference_invalid');
            $v = $this->findLockedByApplication($applicationId) ?? throw new RuntimeException('verification_not_found');
            if ($v->generation !== $generation || !hash_equals($v->tokenHash, $tokenHash)) throw new RuntimeException('verification_consumption_forbidden');
            if ($v->consumedAt !== null) {
                if ($v->attachedUserId === $userId && (int) ($app['user_id'] ?? 0) === $userId && $app['status'] === StoreOnboardingApplication::ACCOUNT_CREATED) return $v;
                throw new RuntimeException('verification_conflict');
            }
            if ($v->updatedAt !== $expectedVerificationUpdatedAt || $v->expiresAt <= $now || $v->failedAttempts >= 5 || $v->deliveryState !== StoreOnboardingEmailVerification::SENT || $app['store_id'] !== null) throw new RuntimeException('verification_consumption_forbidden');
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $userId)) !== $userId
                || (string) $app['account_email'] !== (string) $compatibilityApplication['account_email']
                || $v->candidateUserId !== $compatibilityVerification->candidateUserId) throw new RuntimeException('verification_user_incompatible');
            try { $application = (new StoreOnboardingApplicationRepository())->attachUserInTransaction($applicationId, $userId, $expectedApplicationUpdatedAt, $now); }
            catch (OnboardingAmbiguousWrite) { throw new VerificationAmbiguousWrite(); }
            $changed = $wpdb->update($this->table(), ['consumed_at'=>$now, 'attached_user_id'=>$userId, 'last_error_code'=>null, 'updated_at'=>$now], ['id'=>$v->id, 'generation'=>$generation, 'updated_at'=>$expectedVerificationUpdatedAt, 'consumed_at'=>null]);
            if ($changed !== 1 || (int) $application->data['user_id'] !== $userId) throw new VerificationAmbiguousWrite();
            return $this->findAfterWriteOrAmbiguous($applicationId);
        }, function () use ($applicationId, $generation, $tokenHash, $userId) {
            $v = $this->freshByApplication($applicationId); $app = $this->freshApplication($applicationId);
            if ($v === null || $app === null) throw new VerificationClosedOutcome('verification_persistence_failed');
            $verificationApplied = $v->generation === $generation && hash_equals($v->tokenHash, $tokenHash) && $v->attachedUserId === $userId && $v->consumedAt !== null;
            $applicationApplied = (int) ($app['user_id'] ?? 0) === $userId && ($app['status'] ?? null) === StoreOnboardingApplication::ACCOUNT_CREATED;
            if ($verificationApplied && $applicationApplied) return $v;
            if ($verificationApplied !== $applicationApplied) throw new VerificationClosedOutcome('verification_outcome_uncertain');
            throw new VerificationClosedOutcome('verification_persistence_failed');
        });
    }

    private function delivery(int $a, int $g, string $e, string $now, string $state, ?string $error): StoreOnboardingEmailVerification
    {
        return $this->mutate($a, $g, $e, $now, function ($v) use ($state, $error, $now) {
            if ($v->consumedAt !== null || $v->deliveryAttemptCount < 1) throw new RuntimeException('verification_delivery_forbidden');
            StoreOnboardingEmailVerification::assertOrdinaryDeliveryTransition($v->deliveryState, $state);
            return ['delivery_state'=>$state, 'last_sent_at'=>$state === StoreOnboardingEmailVerification::SENT ? $now : null, 'last_error_code'=>$error];
        }, fn($v, $before) => $this->sameDeliveryResult($v, $state, $error, $state === StoreOnboardingEmailVerification::SENT ? $now : null, $before->deliveryAttemptCount));
    }

    private function mutate(int $a, int $g, string $e, string $now, callable $change, callable $replay): StoreOnboardingEmailVerification
    {
        $this->advance($e, $now); $before = null;
        return $this->transaction(function () use ($a, $g, $e, $now, $change, $replay, &$before) {
            $this->eligibleApplication($a); $v = $this->findLockedByApplication($a) ?? throw new RuntimeException('verification_not_found');
            if ($v->generation !== $g) throw new RuntimeException('verification_concurrent_modification');
            if ($v->updatedAt !== $e) {
                if ($v->updatedAt === $now && $replay($v, $v)) return $v;
                throw new RuntimeException('verification_concurrent_modification');
            }
            $before = $v; $data = $change($v); if ($data === []) return $v;
            global $wpdb; $data['updated_at'] = $now;
            if ($wpdb->update($this->table(), $data, ['id'=>$v->id, 'generation'=>$g, 'updated_at'=>$e]) !== 1) throw new VerificationAmbiguousWrite();
            return $this->findAfterWriteOrAmbiguous($a);
        }, function () use ($a, $g, $now, $replay, &$before) {
            if (!$before instanceof StoreOnboardingEmailVerification) throw new VerificationClosedOutcome('verification_persistence_failed');
            $v = $this->freshByApplication($a);
            if ($v !== null && $v->generation === $g && $v->updatedAt === $now && $replay($v, $before)) return $v;
            throw new VerificationClosedOutcome($v === null ? 'verification_persistence_failed' : 'verification_conflict');
        });
    }

    private function transaction(callable $work, callable $reconcile): mixed
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('verification_persistence_failed');
        try { $result = $work(); }
        catch (VerificationAmbiguousWrite) { $this->requireCleanConnectionForReconciliation(); return $this->reconcile($reconcile); }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        if ($wpdb->query('COMMIT') !== false) return $result;
        $this->requireCleanConnectionForReconciliation();
        return $this->reconcile($reconcile);
    }

    private function reconcile(callable $reconcile): mixed
    {
        try { return $reconcile(); }
        catch (VerificationClosedOutcome $e) { throw new RuntimeException($e->reason); }
        catch (Throwable) { throw new RuntimeException('verification_outcome_uncertain'); }
    }

    private function requireCleanConnectionForReconciliation(): void
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        $wpdb->last_error = '';
        $state = $wpdb->get_var('SELECT @@in_transaction');
        $stateError = (string) ($wpdb->last_error ?? '');
        if ($stateError !== '' || !($state === 0 || $state === '0')) {
            throw new RuntimeException('verification_outcome_uncertain');
        }
    }

    private function reconcileCreate(int $a, string $p, ?int $u, string $eh, string $th, string $x): StoreOnboardingEmailVerification
    {
        $byApplication = $this->freshByApplication($a); $byToken = $this->findByTokenHash($th);
        if ($byApplication !== null && $this->sameCreateIntent($byApplication, $p, $u, $eh, $th, $x)) return $byApplication;
        if ($byApplication !== null || $byToken !== null) throw new VerificationClosedOutcome('verification_conflict');
        throw new VerificationClosedOutcome('verification_persistence_failed');
    }
    private function sameCreateIntent(StoreOnboardingEmailVerification $v, string $p, ?int $u, string $eh, string $th, string $x): bool { return $v->purpose === $p && $v->generation === 1 && $v->candidateUserId === $u && hash_equals($v->emailBindingHash, $eh) && hash_equals($v->tokenHash, $th) && $v->expiresAt === $x; }
    private function sameDeliveryResult(StoreOnboardingEmailVerification $v, string $state, ?string $error, ?string $sentAt, int $attempts): bool { return $v->deliveryState === $state && $v->lastErrorCode === $error && $v->lastSentAt === $sentAt && $v->deliveryAttemptCount === $attempts; }
    private function eligibleApplication(int $id): array { global $wpdb; $r=$wpdb->get_row($wpdb->prepare("SELECT id,status,user_id,store_id FROM {$this->applicationsTable()} WHERE id=%d FOR UPDATE",$id),ARRAY_A); if(!is_array($r)||$wpdb->last_error!==''||$r['status']!==StoreOnboardingApplication::PROVISIONING||$r['user_id']!==null||$r['store_id']!==null) throw new RuntimeException('verification_application_ineligible'); return $r; }
    private function findLockedByApplication(int $id): ?StoreOnboardingEmailVerification { return $this->find('application_id', $id, true); }
    private function findAfterWriteOrAmbiguous(int $id): StoreOnboardingEmailVerification { try{$verification=$this->findLockedByApplication($id);}catch(Throwable){throw new VerificationAmbiguousWrite();} return $verification??throw new VerificationAmbiguousWrite(); }
    private function freshByApplication(int $id): ?StoreOnboardingEmailVerification { return $this->find('application_id', $id); }
    private function freshApplication(int $id): ?array { global $wpdb; $wpdb->last_error=''; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->applicationsTable()} WHERE id=%d",$id),ARRAY_A); $error=(string)($wpdb->last_error??''); if($error!=='') throw new RuntimeException('verification_read_failed'); return is_array($r)?$r:null; }
    private function find(string $column, string|int $value, bool $lock=false): ?StoreOnboardingEmailVerification { if(!in_array($column,['id','application_id','token_hash'],true)) throw new InvalidArgumentException('verification_invalid_lookup'); global $wpdb; $p=is_int($value)?'%d':'%s'; $wpdb->last_error=''; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE {$column}={$p} LIMIT 1".($lock?' FOR UPDATE':''),$value),ARRAY_A); $readError=(string)($wpdb->last_error??''); if($readError!=='') throw new RuntimeException('verification_read_failed'); if(!is_array($r)) return null; try{$v=StoreOnboardingEmailVerification::fromRow($r);}catch(Throwable){throw new RuntimeException('verification_read_failed');} $wpdb->last_error=''; $app=$wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$this->applicationsTable()} WHERE id=%d",$v->applicationId),ARRAY_A); $referenceError=(string)($wpdb->last_error??''); if(!is_array($app)||$referenceError!==''||$v->attachedUserId!==null&&(int)($app['user_id']??0)!==$v->attachedUserId) throw new RuntimeException('verification_reference_invalid'); return $v; }
    private function intent(int $a,string $p,?int $u,string $eh,string $th,string $x,string $n):void { if($a<=0||$u!==null&&$u<=0||$p!==StoreOnboardingEmailVerification::PURPOSE) throw new InvalidArgumentException('verification_invalid_intent'); $this->hash($eh);$this->hash($th);StoreOnboardingEmailVerification::timestamp($x);StoreOnboardingEmailVerification::timestamp($n);if($x<=$n)throw new InvalidArgumentException('verification_invalid_expiry'); }
    private function hash(string $h):void { if(strlen($h)!==32)throw new InvalidArgumentException('verification_invalid_hash'); }
    private function advance(string $old,string $new):void { StoreOnboardingEmailVerification::timestamp($old);StoreOnboardingEmailVerification::timestamp($new);if($new<=$old)throw new InvalidArgumentException('verification_timestamp_must_advance'); }
    private function compatible(callable $validator,array $arguments):bool { try{return $validator(...$arguments)===true;}catch(Throwable){return false;} }
    private function table():string { global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_email_verifications'; }
    private function applicationsTable():string { global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_applications'; }
}
