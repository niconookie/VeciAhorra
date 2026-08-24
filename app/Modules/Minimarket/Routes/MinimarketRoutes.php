<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Routes;

use Throwable;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Inventory\Requests\InventoryCreateRequest;
use VeciAhorra\Modules\Inventory\Requests\InventoryUpdateRequest;
use VeciAhorra\Modules\Minimarket\Identity\StoreContext;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\Owner\OwnerOnboardingRequest;
use VeciAhorra\Modules\Minimarket\Onboarding\Owner\OwnerOnboardingService;
use VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository;
use VeciAhorra\Modules\Minimarket\Repository\MinimarketRepository;
use VeciAhorra\Modules\Minimarket\Service\StoreFulfillmentService;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;

final class MinimarketRoutes
{
    private ?array $store = null;
    private ?array $onboardingStore = null;
    public function __construct(private ?StoreContext $context = null, private ?MinimarketRepository $repository = null, private ?StoreFulfillmentService $fulfillment = null) {}

    public function register(): void
    {
        $routes = [
            ['/minimarket/me', 'GET', 'me'], ['/minimarket/inventory', 'GET', 'inventory'],
            ['/minimarket/inventory', 'POST', 'createInventory'],
            ['/minimarket/inventory/(?P<id>\d+)', 'PATCH', 'updateInventory'],
            ['/minimarket/products', 'GET', 'products'], ['/minimarket/orders', 'GET', 'orders'],
            ['/minimarket/orders/(?P<id>\d+)', 'GET', 'order'],
            ['/minimarket/orders/(?P<id>\d+)/preparation/(?P<target>confirmed|preparing|ready-for-pickup)', 'POST', 'transitionPreparation'],
        ];
        foreach ($routes as [$path, $method, $callback]) {
            register_rest_route('veciahorra/v1', $path, [
                'methods' => $method, 'callback' => [$this, $callback],
                'permission_callback' => [$this, 'authorize'],
            ]);
        }
        register_rest_route('veciahorra/v1','/minimarket/onboarding',[['methods'=>'GET','callback'=>[$this,'onboarding'],'permission_callback'=>[$this,'authorizeOnboarding']],['methods'=>'PATCH','callback'=>[$this,'correctOnboarding'],'permission_callback'=>[$this,'authorizeOnboarding']]]);
        register_rest_route('veciahorra/v1','/minimarket/onboarding/resubmit',['methods'=>'POST','callback'=>[$this,'resubmitOnboarding'],'permission_callback'=>[$this,'authorizeOnboarding']]);
    }

    public function authorize(): bool|\WP_Error
    {
        $resolved = ($this->context ??= new StoreContext())->current();
        if ($resolved instanceof \WP_Error) return $resolved;
        $this->store = $resolved;
        return true;
    }

    public function authorizeOnboarding(\WP_REST_Request $request): bool|\WP_Error
    {
        if(!is_user_logged_in())return new \WP_Error('minimarket_not_authenticated','Debes iniciar sesion.',['status'=>401]);
        if(!current_user_can(MinimarketRole::CAPABILITY)&&!current_user_can(PendingMinimarketRole::CAPABILITY))return new \WP_Error('minimarket_forbidden','La cuenta no puede gestionar esta solicitud.',['status'=>403]);
        $nonce=$request->get_header('X-WP-Nonce');if(!is_string($nonce)||$nonce===''||!wp_verify_nonce($nonce,'wp_rest'))return new \WP_Error('rest_cookie_invalid_nonce','El nonce REST no es valido.',['status'=>403]);
        try{$id=(new StoreOwnershipRepository())->resolveStoreIdForOwnerUser(get_current_user_id())??0;$store=$id>0?$this->repo()->findStore($id):null;}catch(\RuntimeException){$store=null;}
        if(!is_array($store))return new \WP_Error('minimarket_store_missing','La cuenta no tiene una solicitud asociada.',['status'=>403]);$this->onboardingStore=$store;return true;
    }

    public function onboarding(): \WP_REST_Response{return$this->execute(fn()=>(new OwnerOnboardingService())->snapshot($this->onboardingId(),get_current_user_id()));}
    public function correctOnboarding(\WP_REST_Request$request):\WP_REST_Response{return$this->execute(function()use($request){if(!$request->is_json_content_type())throw new \InvalidArgumentException('El cuerpo debe usar application/json.');$input=$request->get_json_params();if(!is_array($input))throw new \InvalidArgumentException('El cuerpo debe ser un objeto JSON.');$validated=(new OwnerOnboardingRequest($input))->correction();return(new OwnerOnboardingService())->correct($this->onboardingId(),get_current_user_id(),$validated['fields'],$validated['expected_updated_at']);});}
    public function resubmitOnboarding(\WP_REST_Request$request):\WP_REST_Response{return$this->execute(function()use($request){if(!$request->is_json_content_type())throw new \InvalidArgumentException('El cuerpo debe usar application/json.');$input=$request->get_json_params();if(!is_array($input))throw new \InvalidArgumentException('El cuerpo debe ser un objeto JSON.');return(new OwnerOnboardingService())->resubmit($this->onboardingId(),get_current_user_id(),(new OwnerOnboardingRequest($input))->resubmit());});}

    public function me(): \WP_REST_Response
    {
        $store = $this->store();
        return $this->ok(['store' => $this->publicStore($store), 'summary' => $this->repo()->summary((int) $store['id'])]);
    }
    public function inventory(): \WP_REST_Response { return $this->ok($this->decorate($this->repo()->inventories($this->id()))); }
    public function products(\WP_REST_Request $request): \WP_REST_Response { return $this->ok($this->decorate($this->repo()->availableProducts($this->id(), sanitize_text_field((string) $request->get_param('search'))))); }
    public function orders(): \WP_REST_Response { return $this->ok($this->repo()->orders($this->id())); }
    public function order(\WP_REST_Request $request): \WP_REST_Response { return $this->execute(fn () => $this->repo()->order((int) $request['id'], $this->id())); }
    public function transitionPreparation(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->execute(fn () => ($this->fulfillment ??= new StoreFulfillmentService($this->repo()))->transition((int) $request['id'], $this->id(), str_replace('-', '_', (string) $request['target'])));
    }

    public function createInventory(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $input = (array) $request->get_json_params();
            $this->rejectAuthority($input);
            $data = (new InventoryCreateRequest([...$input, 'minimarket_id' => $this->id()]))->validated();
            return $this->repo()->createInventory($this->id(), $data);
        }, 201);
    }
    public function updateInventory(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $input = (array) $request->get_json_params();
            $this->rejectAuthority($input);
            return $this->repo()->updateInventory((int) $request['id'], $this->id(), (new InventoryUpdateRequest($input))->validated());
        });
    }

    private function rejectAuthority(array $input): void
    {
        if (array_key_exists('store_id', $input) || array_key_exists('minimarket_id', $input)) {
            throw new \InvalidArgumentException('El Store se deriva exclusivamente del usuario autenticado.');
        }
    }
    private function execute(callable $callback, int $success = 200): \WP_REST_Response
    {
        try { return $this->ok($callback(), $success); }
        catch (RecordNotFoundException $e) { return $this->error('resource_not_found', $e->getMessage(), 404); }
        catch (ConflictException $e) { return $this->error($e->errorCode(), $e->getMessage(), 409); }
        catch (StoreLifecycleException $e) { return $this->error($e->reason(), $e->getMessage(), $e->reason()==='store_not_found'?404:409); }
        catch (\InvalidArgumentException $e) { return $this->error('validation_error', $e->getMessage(), 422); }
        catch (Throwable) { return $this->error('minimarket_internal_error', 'No fue posible completar la operación.', 500); }
    }
    private function store(): array { return $this->store ?? throw new \LogicException('Store context unavailable.'); }
    private function id(): int { return (int) $this->store()['id']; }
    private function onboardingId():int{return(int)($this->onboardingStore['id']??0);}
    private function repo(): MinimarketRepository { return $this->repository ??= new MinimarketRepository(); }
    private function publicStore(array $s): array { return array_intersect_key($s, array_flip(['id','business_name','status','onboarding_status','approved_at'])); }
    private function decorate(array $rows): array { foreach ($rows as &$row) { $row['image_url'] = ! empty($row['image_id']) ? wp_get_attachment_image_url((int) $row['image_id'], 'thumbnail') ?: null : null; } return $rows; }
    private function ok(array $data, int $status = 200): \WP_REST_Response { return new \WP_REST_Response(['success' => true, 'data' => $data], $status); }
    private function error(string $code, string $message, int $status): \WP_REST_Response { return new \WP_REST_Response(['success' => false, 'error' => compact('code','message')], $status); }
}
