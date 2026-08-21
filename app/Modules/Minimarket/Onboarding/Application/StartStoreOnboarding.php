<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Application;

use DateTimeZone;
use Throwable;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\CurrentOnboardingTerms;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingClock;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingPublicIdGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\StoreOnboardingApplicationWriter;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingConflictException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingPersistenceException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingPublicIdCollisionException;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\ChileanRutNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\OnboardingEmailNormalizer;

final class StartStoreOnboarding
{
    public function __construct(
        private StoreOnboardingApplicationWriter $applications,
        private OnboardingClock $clock,
        private OnboardingPublicIdGenerator $publicIds,
        private CurrentOnboardingTerms $terms,
        private OnboardingEmailNormalizer $emails,
        private ChileanRutNormalizer $ruts
    ) {}

    public function execute(StartStoreOnboardingCommand $command): StartStoreOnboardingResult
    {
        $email = $this->emails->normalize($command->accountEmail);
        $rut = $this->ruts->normalizeAndValidate($command->ownerRut);
        if ($command->termsAccepted !== true) {
            throw new OnboardingInputException('terms_not_accepted');
        }
        try {
            $termsVersion = trim($this->terms->version());
        } catch (Throwable $exception) {
            throw new OnboardingInputException('terms_version_unavailable');
        }
        if ($termsVersion === '' || strlen($termsVersion) > 32 || preg_match('/[\x00-\x1F\x7F]/', $termsVersion) === 1) {
            throw new OnboardingInputException('terms_version_unavailable');
        }
        if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $command->idempotencyKey) !== 1) {
            throw new OnboardingInputException('invalid_idempotency_key');
        }

        try {
            $instant = $this->clock->nowUtc();
            $timestamp = $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $exception) {
            throw new OnboardingPersistenceException('persistence_failed');
        }
        $idempotencyHash = hash('sha256', 'minimarket-onboarding-v1|' . $command->idempotencyKey);

        $attemptedPublicIds = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $publicId = $this->publicIds->generate();
            } catch (Throwable $exception) {
                throw new OnboardingPersistenceException('identity_generation_failed');
            }
            if (preg_match('/\Aonb_[a-f0-9]{40}\z/', $publicId) !== 1) {
                throw new OnboardingPersistenceException('identity_generation_failed');
            }
            if (isset($attemptedPublicIds[$publicId])) {
                throw new OnboardingPersistenceException('identity_generation_failed');
            }
            $attemptedPublicIds[$publicId] = true;
            try {
                $application = $this->applications->createProvisioning([
                    'public_id' => $publicId,
                    'account_email' => $email,
                    'owner_rut_normalized' => $rut,
                    'idempotency_key_hash' => $idempotencyHash,
                    'terms_version' => $termsVersion,
                    'terms_accepted_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                return new StartStoreOnboardingResult(
                    (string) $application->data['public_id'],
                    (string) $application->data['status'],
                    (string) $application->data['created_at'],
                    (string) $application->data['updated_at']
                );
            } catch (OnboardingPublicIdCollisionException $exception) {
                if ($attempt === 3) {
                    throw new OnboardingPersistenceException('identity_generation_failed');
                }
            } catch (\RuntimeException $exception) {
                throw match ($exception->getMessage()) {
                    'onboarding_idempotency_conflict' => new OnboardingConflictException('idempotency_conflict'),
                    'onboarding_create_uncertain' => new OnboardingPersistenceException('outcome_uncertain'),
                    default => new OnboardingPersistenceException('persistence_failed'),
                };
            } catch (Throwable $exception) {
                throw new OnboardingPersistenceException('persistence_failed');
            }
        }
        throw new OnboardingPersistenceException('identity_generation_failed');
    }
}
