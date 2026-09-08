<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Cart\Proximity;

use DomainException;
use InvalidArgumentException;
use VeciAhorra\Core\Config;
use VeciAhorra\Core\LaunchGate;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartAggregate;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Service\CheckoutFeeCalculator;
use VeciAhorra\Modules\Checkout\Service\CheckoutFeeConfiguration;
use VeciAhorra\Modules\Payments\Service\IdempotencyService;
use VeciAhorra\Modules\Sectorization\TerritorialAuthority;

final class PickupProposalService
{
    public const TTL_SECONDS = 300;

    public function search(array $owner, array $input): array
    {
        $this->authorize($owner);
        $this->keys($input, ['latitude', 'longitude', 'confirmed']);
        if (($input['confirmed'] ?? null) !== true) throw new InvalidArgumentException('Confirma el punto antes de buscar.');
        $point = GeoPoint::validate($input['latitude'] ?? null, $input['longitude'] ?? null);
        $result = (new CartAggregate())->mutate($owner, function (array $cart) use ($point, $owner): array {
            global $wpdb;
            $source = $this->source($cart);
            $this->eligible($cart, $source);
            $candidates = [];
            foreach ($this->rows('SELECT id FROM ' . $this->p() . 'stores ORDER BY id') as $store) {
                $candidate = $this->candidate((int)$store['id'], (int)$cart['service_zone_id'], $source['products'], false);
                if ($candidate === null) continue;
                $candidate['distance'] = GeoPoint::distance($point, $candidate['point']);
                $candidates[] = $candidate;
            }
            usort($candidates, static fn(array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: ($a['store_id'] <=> $b['store_id']));
            if ($candidates === []) return ['proposal' => null];
            $best = $candidates[0];
            foreach ($best['items'] as &$item) {
                $previous = 0;
                foreach ($source['lines'] as $line) {
                    if ((int)$line['product_id'] === $item['product_id']) $previous += CheckoutFeeCalculator::clp($line['unit_price_snapshot']) * (int)$line['quantity'];
                }
                $item['previous_subtotal'] = $previous . '.00';
            }
            unset($item);
            $publicId = bin2hex(random_bytes(24));
            $expires = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
            $presentation = [
                'proposal_id' => $publicId, 'expires_at' => str_replace(' ', 'T', $expires) . 'Z',
                'store_name' => $best['name'], 'distance_metres' => round($best['distance']),
                'fulfillment_method' => 'pickup', 'items' => $best['items'],
                'current_product_subtotal' => $source['subtotal'] . '.00',
                'summary' => $best['summary'],
            ];
            $this->write($wpdb->insert($this->p() . 'pickup_proposals', [
                'public_id' => $publicId, 'cart_id' => $cart['id'], 'cart_version' => $cart['version'],
                'owner_key' => CartAggregate::ownerKey($owner), 'service_zone_id' => $cart['service_zone_id'],
                'store_id' => $best['store_id'], 'store_point_hash' => $this->hash($best['point']),
                'source_hash' => $this->hash($source['lines']), 'offers_json' => json_encode($best['offers'], JSON_THROW_ON_ERROR),
                'presentation_json' => json_encode($presentation, JSON_THROW_ON_ERROR),
                'status' => 'offered', 'expires_at' => $expires, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]));
            // The customer's address and coordinates are never stored in the proposal.
            return ['proposal' => $presentation];
        });
        return $result['result'];
    }

    public function accept(array $owner, array $input): array
    {
        $this->authorize($owner);
        $this->keys($input, ['proposal_id', 'accepted', 'idempotency_key']);
        if (($input['accepted'] ?? null) !== true || !is_string($input['proposal_id'] ?? null)
            || preg_match('/^[a-f0-9]{48}$/D', $input['proposal_id']) !== 1) {
            throw new InvalidArgumentException('La aceptación de la propuesta no es válida.');
        }
        if (!is_int($owner['expected_version'] ?? null) || $owner['expected_version'] < 1) throw new DomainException('expected_version_required');
        $key = (new IdempotencyService())->key(is_string($input['idempotency_key'] ?? null) ? $input['idempotency_key'] : '');
        $fingerprint = $this->hash([$owner['cart_id'] ?? null, $owner['expected_version'], $input['proposal_id'], true]);
        return (new CartAggregate())->withCartLock($owner, function (array $cart) use ($owner, $input, $key, $fingerprint): array {
            global $wpdb;
            $rows = $this->rows('SELECT * FROM ' . $this->p() . 'pickup_proposals WHERE public_id=%s AND cart_id=%d AND owner_key=%s FOR UPDATE', $input['proposal_id'], $cart['id'], CartAggregate::ownerKey($owner));
            $proposal = $rows[0] ?? throw new DomainException('pickup_proposal_conflict');
            if ($proposal['status'] === 'accepted') {
                if (!hash_equals((string)$proposal['accepted_key'], $key) || !hash_equals((string)$proposal['accepted_fingerprint'], $fingerprint)) throw new DomainException('pickup_replay_conflict');
                return json_decode($proposal['accepted_result'], true, 512, JSON_THROW_ON_ERROR);
            }
            if ($proposal['status'] !== 'offered' || $proposal['expires_at'] <= gmdate('Y-m-d H:i:s')
                || (int)$proposal['cart_version'] !== $owner['expected_version']) throw new DomainException('pickup_proposal_expired_or_stale');
            $changed = (new CartAggregate())->mutate($owner, function (array $lockedCart) use ($proposal, $owner): void {
                global $wpdb;
                $source = $this->source($lockedCart);
                $this->eligible($lockedCart, $source);
                if (!hash_equals($proposal['source_hash'], $this->hash($source['lines']))
                    || (int)$proposal['service_zone_id'] !== (int)$lockedCart['service_zone_id']) throw new DomainException('pickup_source_changed');
                $candidate = $this->candidate((int)$proposal['store_id'], (int)$lockedCart['service_zone_id'], $source['products'], true);
                $display = json_decode($proposal['presentation_json'], true, 512, JSON_THROW_ON_ERROR);
                if ($candidate === null || !hash_equals($proposal['store_point_hash'], $this->hash($candidate['point']))
                    || $candidate['offers'] !== json_decode($proposal['offers_json'], true, 512, JSON_THROW_ON_ERROR)
                    || $candidate['summary'] !== $display['summary']) throw new DomainException('pickup_offer_changed');
                if ($wpdb->delete($this->p() . 'cart_items', ['cart_id' => $lockedCart['id']]) === false) throw new DomainException('pickup_replace_failed');
                $repository = new CartRepository();
                foreach ($candidate['offers'] as $offer) {
                    $repository->create([
                        ...$offer, 'user_id' => $owner['user_id'], 'session_id' => null,
                        'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
                    ]);
                }
                $this->write($wpdb->update($this->p() . 'carts', ['fulfillment_method' => 'pickup'], ['id' => $lockedCart['id']]));
            }, fn(): array => (new CartService(new CartRepository()))->getPublicCart($owner));
            $result = ['cart' => $changed['cart']];
            $this->write($wpdb->update($this->p() . 'pickup_proposals', [
                'status' => 'accepted', 'accepted_key' => $key, 'accepted_fingerprint' => $fingerprint,
                'accepted_result' => json_encode($result, JSON_THROW_ON_ERROR),
            ], ['id' => $proposal['id'], 'status' => 'offered']));
            return $result;
        });
    }

    private function authorize(array $owner): void
    {
        if (!is_int($owner['user_id'] ?? null) || $owner['user_id'] <= 0 || $owner['user_id'] !== get_current_user_id()
            || !(new LaunchGate())->commerceEnabled()) throw new DomainException('pickup_unavailable');
    }

    private function eligible(array $cart, array $source): void
    {
        $minimum = (new CheckoutFeeConfiguration())->current()['delivery_minimum_subtotal_clp'];
        if ($cart['status'] !== 'active' || $cart['fulfillment_method'] !== 'delivery' || $source['products'] === []
            || !($source['subtotal'] < $minimum)) throw new DomainException('pickup_not_eligible');
        (new TerritorialAuthority())->activeZone((int)$cart['service_zone_id'], true);
    }

    private function source(array $cart): array
    {
        $lines = $this->rows('SELECT inventory_id,product_id,minimarket_id,quantity,unit_price_snapshot FROM ' . $this->p() . 'cart_items WHERE cart_id=%d ORDER BY inventory_id,id', $cart['id']);
        $products = []; $subtotal = 0;
        foreach ($lines as $line) {
            $id = (int)$line['product_id']; $quantity = (int)$line['quantity'];
            $price = $this->price($line['unit_price_snapshot']);
            if ($quantity < 1 || $price === null) throw new DomainException('pickup_invalid_source');
            (new TerritorialAuthority())->storeInZone((int)$cart['service_zone_id'], (int)$line['minimarket_id'], true);
            $products[$id] = ($products[$id] ?? 0) + $quantity;
            $subtotal += $price * $quantity;
        }
        ksort($products, SORT_NUMERIC);
        return ['lines' => $lines, 'products' => $products, 'subtotal' => $subtotal];
    }

    private function candidate(int $storeId, int $zone, array $products, bool $lock): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $stores = $this->rows('SELECT * FROM ' . $this->p() . 'stores WHERE id=%d' . $suffix, $storeId);
        $store = $stores[0] ?? null;
        if ($store === null || $store['status'] !== 'active' || $store['onboarding_status'] !== 'complete' || empty($store['approved_at'])) return null;
        try {
            (new TerritorialAuthority())->storeInZone($zone, $storeId, $lock);
            $point = GeoPoint::validate($store['pickup_latitude'], $store['pickup_longitude']);
        } catch (DomainException|InvalidArgumentException) { return null; }
        $offers = []; $items = []; $subtotal = 0;
        foreach ($products as $productId => $quantity) {
            $product = $this->rows('SELECT id,name,status FROM ' . $this->p() . 'products WHERE id=%d' . $suffix, $productId)[0] ?? null;
            if ($product === null || $product['status'] !== 'active') return null;
            $inventories = $this->rows('SELECT * FROM ' . $this->p() . 'inventory WHERE minimarket_id=%d AND product_id=%d ORDER BY id' . $suffix, $storeId, $productId);
            $offer = null;
            foreach ($inventories as $inventory) {
                $price = $this->price($inventory['price']);
                if ($inventory['status'] !== 'active' || (int)$inventory['stock'] < $quantity || $price === null) continue;
                $offer = ['inventory_id' => (int)$inventory['id'], 'product_id' => (int)$productId, 'minimarket_id' => $storeId, 'quantity' => $quantity, 'unit_price_snapshot' => $price . '.00'];
                break;
            }
            if ($offer === null) return null;
            $offers[] = $offer;
            $items[] = ['product_id' => (int)$productId, 'name' => (string)$product['name'], 'quantity' => $quantity, 'unit_price' => $offer['unit_price_snapshot'], 'subtotal' => ($price * $quantity) . '.00'];
            $subtotal += $price * $quantity;
        }
        try { $summary = (new CheckoutFeeCalculator())->calculate($subtotal, 'pickup', false); }
        catch (InvalidArgumentException) { return null; }
        return ['store_id' => $storeId, 'name' => (string)$store['business_name'], 'point' => $point, 'offers' => $offers, 'items' => $items, 'summary' => $summary];
    }

    private function price(mixed $value): ?int
    {
        if (!is_string($value)) return null;
        try { $price = CheckoutFeeCalculator::clp($value); }
        catch (InvalidArgumentException) { return null; }
        return $price > 0 ? $price : null;
    }
    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) throw new InvalidArgumentException('La solicitud contiene campos no admitidos.');
    }
    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
    private function p(): string { global $wpdb; return $wpdb->prefix . Config::TABLE_PREFIX; }
    private function write(int|bool $rows): void { if ($rows !== 1) throw new DomainException('pickup_write_failed'); }
    private function rows(string $sql, mixed ...$args): array
    {
        global $wpdb;
        $result = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);
        if ($wpdb->last_error !== '') throw new DomainException('pickup_read_failed');
        return $result;
    }
}
