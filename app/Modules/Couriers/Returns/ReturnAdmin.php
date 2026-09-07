<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;
use VeciAhorra\Core\Config;
final class ReturnAdmin
{
    public function register():void{add_action('admin_menu',function(){add_submenu_page('veciahorra','Incidencias de entrega','Incidencias de entrega','manage_options','veciahorra-delivery-returns',[$this,'render']);});}
    public function render():void
    {
        if(!current_user_can('manage_options'))return;
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;
        $rows=$wpdb->get_results("SELECT r.*,d.status FROM {$p}delivery_returns r JOIN {$p}deliveries d ON d.id=r.delivery_id ORDER BY r.opened_at DESC",ARRAY_A);
        echo '<div class="wrap"><h1>Incidencias de entrega</h1><p>Vista operativa. La resolución administrativa se realiza en una fase posterior.</p>';
        foreach($rows as $r){echo '<article><h2>'.esc_html($r['status']==='return_pending'?'Retorno pendiente al minimarket':'Devuelto al minimarket; resolución administrativa pendiente').'</h2><dl>';
            foreach(['order_id'=>'Pedido','delivery_id'=>'Delivery','store_id'=>'Minimarket original','courier_id'=>'Repartidor','opened_by'=>'Actor de apertura','opened_code'=>'Motivo','opened_note'=>'Observación del repartidor','opened_at'=>'Inicio de retorno','received_code'=>'Condición recibida','received_note'=>'Observación del minimarket','received_by'=>'Actor de recepción','received_at'=>'Fecha de recepción'] as $key=>$label)echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html((string)($r[$key]??'Pendiente')).'</dd>';
            echo '</dl></article>';
        }echo '</div>';
    }
}
