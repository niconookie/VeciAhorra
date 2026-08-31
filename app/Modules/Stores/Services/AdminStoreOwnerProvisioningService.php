<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\RandomOpaqueUsernameGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\ChileanRutNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\OnboardingEmailNormalizer;
use VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository;

/** Crea una Store administrativa únicamente junto a un owner WordPress confirmado. */
final class AdminStoreOwnerProvisioningService
{
    public function __construct(
        private ?StoreService $stores = null,
        private ?StoreOwnershipRepository $ownership = null,
        private ?OnboardingEmailNormalizer $emails = null,
        private ?ChileanRutNormalizer $ruts = null,
        private ?RandomOpaqueUsernameGenerator $usernames = null
    ) {
        $this->stores ??= new StoreService();
        $this->ownership ??= new StoreOwnershipRepository();
        $this->emails ??= new OnboardingEmailNormalizer();
        $this->ruts ??= new ChileanRutNormalizer();
        $this->usernames ??= new RandomOpaqueUsernameGenerator();
    }

    /** @return array{store_id:int,user_id:int,user_created:bool,invitation_sent:bool} */
    public function create(array $commercial): array
    {
        $email = $this->normalizeEmail((string) ($commercial['email'] ?? ''));
        $rut = $this->normalizeOptionalRut((string) ($commercial['rut'] ?? ''));
        $commercial['email'] = $email;
        $commercial['rut'] = $rut ?? '';
        $locks = $this->acquireLocks($email, $rut);
        $userId = 0;
        $userCreated = false;

        try {
            $rutStoreId = $this->storeIdForRut($rut);
            $existingUserId = email_exists($email);
            $emailStoreId = $this->storeIdForEmail($email);
            if ($existingUserId === false && $emailStoreId !== null) {
                throw new InvalidArgumentException('El correo ya está vinculado a otro minimarket.');
            }
            if ($existingUserId === false && $rutStoreId !== null) {
                throw new InvalidArgumentException('El RUT ya pertenece a otro minimarket.');
            }
            if ($existingUserId !== false) {
                $userId = (int) $existingUserId;
                $this->assertCompatibleExistingUser($userId);
            } else {
                [$userId, $userCreated] = $this->createOperationalUser($email);
            }

            if ($emailStoreId !== null) {
                if ($this->isExactReplay($emailStoreId, $commercial, $userId)) {
                    return ['store_id'=>$emailStoreId, 'user_id'=>$userId, 'user_created'=>false, 'invitation_sent'=>false];
                }
                throw new InvalidArgumentException('El correo ya está vinculado a otro minimarket.');
            }
            if ($rutStoreId !== null) {
                if ($this->isExactReplay($rutStoreId, $commercial, $userId)) {
                    return ['store_id'=>$rutStoreId, 'user_id'=>$userId, 'user_created'=>false, 'invitation_sent'=>false];
                }
                throw new InvalidArgumentException('El RUT ya pertenece a otro minimarket.');
            }

            $ownedStore = $this->ownership->resolveStoreIdForOwnerUser($userId);
            if ($ownedStore !== null) {
                if ($this->isExactReplay($ownedStore, $commercial, $userId)) {
                    return [
                        'store_id' => $ownedStore,
                        'user_id' => $userId,
                        'user_created' => false,
                        'invitation_sent' => false,
                    ];
                }
                throw new InvalidArgumentException('El correo ya está vinculado a otro minimarket.');
            }

            $commercial['owner_user_id'] = $userId;
            global $wpdb;
            $previousSuppressErrors = $wpdb->suppress_errors(true);
            try {
                $storeId = $this->stores->create($commercial);
                if ($storeId <= 0) throw new RuntimeException('store_create_invalid_id');
                $this->ownership->reconcileCompatibilityProjection($storeId, $userId);
                $this->assertConfirmed($storeId, $userId);
            } catch (Throwable $exception) {
                if ($userCreated) {
                    error_log(sprintf('admin_store_owner_store_incomplete user_id=%d', $userId));
                    throw new InvalidArgumentException(
                        'La cuenta fue creada, pero no fue posible completar el minimarket. Reintenta la creación con el mismo correo.',
                        0,
                        $exception
                    );
                }
                throw new InvalidArgumentException('No fue posible crear y vincular el minimarket.', 0, $exception);
            } finally {
                $wpdb->suppress_errors($previousSuppressErrors);
            }

            $sent = $userCreated && $this->sendPasswordSetup($userId);
            if ($userCreated && ! $sent) {
                error_log(sprintf('admin_store_owner_invitation_failed store_id=%d user_id=%d', $storeId, $userId));
            }
            return ['store_id'=>$storeId, 'user_id'=>$userId, 'user_created'=>$userCreated, 'invitation_sent'=>$sent];
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $this->releaseLocks($locks);
        }
    }

    public function resendInvitation(int $storeId): bool
    {
        if ($storeId <= 0) throw new InvalidArgumentException('Minimarket inválido.');
        $store = $this->stores->find($storeId);
        $userId = is_object($store) ? (int) ($store->owner_user_id ?? 0) : 0;
        if ($userId <= 0 || get_userdata($userId) === false) {
            throw new InvalidArgumentException('El minimarket no tiene una cuenta válida vinculada.');
        }
        if ($this->ownership->resolveStoreIdForOwnerUser($userId) !== $storeId) {
            throw new InvalidArgumentException('No fue posible confirmar el owner único del minimarket.');
        }
        $sent = $this->sendPasswordSetup($userId);
        if (! $sent) error_log(sprintf('admin_store_owner_invitation_failed store_id=%d user_id=%d', $storeId, $userId));
        return $sent;
    }

    /** @return array{linked:bool,status:string} */
    public function accountSummary(int $storeId): array
    {
        $store = $this->stores->find($storeId);
        $userId = is_object($store) ? (int) ($store->owner_user_id ?? 0) : 0;
        $user = $userId > 0 ? get_userdata($userId) : false;
        if (! $user instanceof \WP_User) return ['linked'=>false, 'status'=>'Sin cuenta'];
        try {
            $unique = $this->ownership->resolveStoreIdForOwnerUser($userId) === $storeId;
        } catch (RuntimeException) {
            $unique = false;
        }
        return ['linked'=>$unique, 'status'=>$unique && (int) $user->user_status === 0 ? 'Activa' : 'Requiere revisión'];
    }

    private function normalizeEmail(string $email): string
    {
        try {
            $normalized = $this->emails->normalize($email);
            if (strlen($normalized) > 100) throw new OnboardingInputException('invalid_email');
            return $normalized;
        }
        catch (OnboardingInputException) { throw new InvalidArgumentException('El correo no es válido.'); }
    }

    private function normalizeOptionalRut(string $rut): ?string
    {
        $rut = trim($rut);
        if ($rut === '') return null;
        try { return $this->ruts->normalizeAndValidate($rut); }
        catch (OnboardingInputException) { throw new InvalidArgumentException('El RUT no es válido.'); }
    }

    private function storeIdForRut(?string $rut): ?int
    {
        if ($rut === null) return null;
        global $wpdb;
        $table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
        $rows = $wpdb->get_results("SELECT id,rut FROM {$table} WHERE rut IS NOT NULL AND TRIM(rut)<>''", ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') throw new RuntimeException('store_rut_read_failed');
        $matches = [];
        foreach ($rows as $row) {
            try { $existing = $this->ruts->normalizeAndValidate((string) $row['rut']); }
            catch (OnboardingInputException) { continue; }
            if (hash_equals($rut, $existing)) $matches[] = (int) $row['id'];
        }
        if (count($matches) > 1) throw new InvalidArgumentException('El RUT está vinculado a más de un minimarket y requiere revisión.');
        return $matches === [] ? null : $matches[0];
    }

    private function assertCompatibleExistingUser(int $userId): void
    {
        $user = get_userdata($userId);
        if (! $user instanceof \WP_User) throw new InvalidArgumentException('No fue posible confirmar la cuenta existente.');
        if (user_can($user, 'manage_options') || user_can($user, 'edit_others_posts')) {
            throw new InvalidArgumentException('El correo pertenece a una cuenta privilegiada incompatible.');
        }
        if (count($user->roles) !== 1 || ! in_array($user->roles[0], [MinimarketRole::ROLE, PendingMinimarketRole::ROLE], true)) {
            throw new InvalidArgumentException('El correo pertenece a una cuenta incompatible.');
        }
    }

    private function storeIdForEmail(string $email): ?int
    {
        global $wpdb;
        $table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE LOWER(TRIM(email))=%s ORDER BY id", $email));
        if (! is_array($ids) || $wpdb->last_error !== '') throw new RuntimeException('store_email_read_failed');
        if (count($ids) > 1) throw new InvalidArgumentException('El correo está vinculado a más de un minimarket y requiere revisión.');
        return $ids === [] ? null : (int) $ids[0];
    }

    /** @return array{int,bool} */
    private function createOperationalUser(string $email): array
    {
        $password = wp_generate_password(32, true, true);
        $result = wp_insert_user([
            'user_login' => $this->usernames->generate(),
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => 'Minimarket VeciAhorra',
            'role' => MinimarketRole::ROLE,
        ]);
        unset($password);
        if ($result instanceof \WP_Error || (int) $result <= 0) {
            throw new InvalidArgumentException('No fue posible crear la cuenta del minimarket.');
        }
        return [(int) $result, true];
    }

    private function assertConfirmed(int $storeId, int $userId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,owner_user_id,status,onboarding_status,approved_at FROM {$table} WHERE id=%d", $storeId), ARRAY_A);
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE owner_user_id=%d", $userId));
        if (! is_array($row) || (int) $row['owner_user_id'] !== $userId || get_userdata($userId) === false || $count !== 1
            || $row['status'] !== 'pending' || $row['onboarding_status'] !== 'draft' || $row['approved_at'] !== null) {
            throw new RuntimeException('store_owner_confirmation_failed');
        }
    }

    private function isExactReplay(int $storeId, array $commercial, int $userId): bool
    {
        $store = $this->stores->find($storeId);
        if (! is_object($store) || (int) ($store->owner_user_id ?? 0) !== $userId) return false;
        foreach (['business_name','legal_name','owner_name','rut','email','phone','mobile','address','commune','city','region'] as $field) {
            if ((string) ($store->{$field} ?? '') !== (string) ($commercial[$field] ?? '')) return false;
        }
        return (string) $store->status === 'pending' && (string) $store->onboarding_status === 'draft' && $store->approved_at === null;
    }

    private function sendPasswordSetup(int $userId): bool
    {
        $user = get_userdata($userId);
        if (! $user instanceof \WP_User) return false;
        $result = retrieve_password($user->user_login);
        return $result === true;
    }

    /** @return list<string> */
    private function acquireLocks(string $email, ?string $rut): array
    {
        global $wpdb;
        $identities = ['email\0' . hash('sha256', $email)];
        if ($rut !== null) $identities[] = 'rut\0' . hash('sha256', $rut);
        $locks = array_map(static fn(string $identity): string => 'va-admin-store-' . hash_hmac('sha256', $identity, (string) AUTH_SALT), $identities);
        sort($locks, SORT_STRING);
        $acquired = [];
        foreach ($locks as $lock) {
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)', $lock)) !== '1') {
                $this->releaseLocks($acquired);
                throw new InvalidArgumentException('La creación está siendo procesada; intenta nuevamente.');
            }
            $acquired[] = $lock;
        }
        return $acquired;
    }

    /** @param list<string> $locks */
    private function releaseLocks(array $locks): void
    {
        global $wpdb;
        foreach (array_reverse($locks) as $lock) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}
