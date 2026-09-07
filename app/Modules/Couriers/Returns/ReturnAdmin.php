<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;
use VeciAhorra\Core\Config;
final class ReturnAdmin
{
    public function register():void
    {
        add_action('admin_menu',function(){add_submenu_page('veciahorra','Incidencias de entrega','Incidencias de entrega','manage_options','veciahorra-delivery-returns',[$this,'render']);});
    }
    public function render():void
    {
        if(!current_user_can('manage_options'))return;
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;
        wp_enqueue_script('veciahorra-return-refunds',VA_PLUGIN_URL.'assets/admin/return-refunds.js',[],Config::PLUGIN_VERSION,true);
        $rows=$wpdb->get_results("SELECT r.*,d.status,d.transition_version FROM {$p}delivery_returns r JOIN {$p}deliveries d ON d.id=r.delivery_id ORDER BY r.opened_at DESC",ARRAY_A);
        echo '<div class="wrap"><h1>Incidencias de entrega</h1><p>La regularización física del inventario queda pendiente. Esta acción no repone productos ni modifica reservas.</p>';
        foreach($rows as $r){
            echo '<article><h2>'.esc_html(match($r['status']){'return_pending'=>'Retorno pendiente al minimarket','return_closed'=>'Devolución financiera confirmada',default=>'Devuelto al minimarket; resolución administrativa pendiente'}).'</h2><dl>';
            foreach(['order_id'=>'Pedido','delivery_id'=>'Delivery','store_id'=>'Minimarket original','courier_id'=>'Repartidor','opened_by'=>'Actor de apertura','opened_code'=>'Motivo','opened_note'=>'Observación del repartidor','opened_at'=>'Inicio de retorno','received_code'=>'Condición recibida','received_note'=>'Observación del minimarket','received_by'=>'Actor de recepción','received_at'=>'Fecha de recepción'] as $key=>$label)echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html((string)($r[$key]??'Pendiente')).'</dd>';
            echo '</dl>';
            $refund=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}return_refunds WHERE delivery_id=%d",$r['delivery_id']),ARRAY_A);
            if($refund){
                echo '<p>Estado financiero: '.esc_html($refund['status']).'. Regularización física: pendiente.</p><dl>';
                foreach(['product_refund'=>'Productos CLP','platform_fee_refund'=>'Plataforma CLP','delivery_fee_refund'=>'Despacho CLP','total_refund'=>'Total CLP','confirmed_at'=>'Confirmación','actor_id'=>'Administrador','note'=>'Observación administrativa'] as $key=>$label)echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html((string)($refund[$key]??'Pendiente')).'</dd>';
                echo '</dl><details><summary>Historial de intentos</summary><ul>';
                $events=$wpdb->get_results($wpdb->prepare("SELECT attempt,event,actor_id,note,created_at FROM {$p}return_refund_attempts WHERE delivery_id=%d ORDER BY id",$r['delivery_id']),ARRAY_A);
                foreach($events as $event)echo '<li>'.esc_html(implode(' · ',array_values($event))).'</li>';
                echo '</ul></details>';
            }
            if($r['status']==='returned_to_store' && $r['received_at'] && (!$refund || in_array($refund['status'],['refund_failed','refund_pending'],true))){
                $pending=$refund && $refund['status']==='refund_pending';
                $intent=$pending?$wpdb->get_row($wpdb->prepare("SELECT idempotency_key FROM {$p}return_refund_attempts WHERE delivery_id=%d AND attempt=%d AND event='refund_pending'",$r['delivery_id'],$refund['attempt']),ARRAY_A):null;
                $key=$intent['idempotency_key']??bin2hex(random_bytes(24));
                $retry=$refund?(int)$refund['attempt']-($pending?1:0):0;
                echo '<form data-return-refund data-endpoint="'.esc_url(rest_url('veciahorra/v1/admin/deliveries/'.$r['delivery_id'].'/cancel-and-refund')).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'" data-version="'.(int)$r['transition_version'].'" data-key="'.esc_attr($key).'" data-retry="'.$retry.'">';
                echo '<label>Observación administrativa (1–500 caracteres)<textarea name="note" required maxlength="500">'.esc_textarea($pending?$refund['note']:'').'</textarea></label>';
                echo '<p>Se devolverán los productos de este pedido. Los cargos pendientes sólo se devuelven al completar todos los productos del Checkout.</p>';
                echo '<label><input type="checkbox" name="confirmed" required> Confirmo la cancelación y devolución financiera irreversible de este pedido.</label><p><button type="submit" class="button button-primary">'.($pending?'Consultar la misma solicitud':($refund?'Reintentar explícitamente tras rechazo':'Cancelar y reembolsar')).'</button></p><p data-result role="status"></p></form>';
            }
            echo '</article>';
        }
        echo '</div>';
    }
}
