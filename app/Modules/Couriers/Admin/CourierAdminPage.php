<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Admin;

use VeciAhorra\Modules\Couriers\Identity\CourierRole;
use VeciAhorra\Modules\Couriers\Repository\CourierRepository;

final class CourierAdminPage
{
    public function __construct(private CourierRepository $repository=new CourierRepository()){}
    public function register():void{add_action('admin_menu',[$this,'menu']);}
    public function menu():void{add_submenu_page('veciahorra','Repartidores','Repartidores','manage_options','veciahorra-couriers',[$this,'render']);}
    public function render():void
    {
        if(!current_user_can('manage_options'))wp_die('No autorizado.');
        $notice='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            check_admin_referer('veciahorra_courier_admin');
            try{$this->handle();$notice='Cambios guardados.';}catch(\Throwable $e){$notice=$e->getMessage();}
        }
        echo '<div class="wrap"><h1>Repartidores</h1>';if($notice!=='')echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        echo '<table class="widefat"><thead><tr><th>ID</th><th>Nombre</th><th>Contacto</th><th>Estado</th><th>Usuario</th><th>Acciones</th></tr></thead><tbody>';
        foreach($this->repository->all() as $c){$users=get_users(['meta_key'=>CourierRole::META_KEY,'meta_value'=>(string)$c['id'],'number'=>2]);echo '<tr><td>'.(int)$c['id'].'</td><td>'.esc_html($c['display_name']).'</td><td>'.esc_html($c['phone'].' '.$c['email']).'</td><td>'.esc_html($c['status']).'</td><td>'.esc_html($users[0]->user_login??'—').'</td><td>'.$this->action((int)$c['id'],$c['status']==='approved'?'inactive':'approved').'</td></tr>';}
        echo '</tbody></table><h2>Crear o editar</h2><form method="post">';wp_nonce_field('veciahorra_courier_admin');
        echo '<input name="courier_id" type="number" min="1" placeholder="ID para editar (opcional)"> <input name="display_name" required maxlength="150" placeholder="Nombre"> <input name="phone" required maxlength="30" placeholder="Teléfono"> <input name="email" type="email" maxlength="150" placeholder="Email"> <input name="user_id" type="number" min="1" placeholder="WordPress user ID"> <button class="button button-primary" name="action" value="save">Guardar</button></form></div>';
    }
    private function action(int $id,string $status):string{return '<form method="post" style="display:inline">'.wp_nonce_field('veciahorra_courier_admin','_wpnonce',true,false).'<input type="hidden" name="courier_id" value="'.$id.'"><button class="button" name="action" value="'.$status.'">'.($status==='approved'?'Aprobar/reactivar':'Desactivar').'</button></form>';}
    private function handle():void
    {
        $action=sanitize_key((string)($_POST['action']??''));$id=absint($_POST['courier_id']??0);$now=current_time('mysql',true);
        if(in_array($action,['approved','inactive'],true)){if($action==='approved'&&count(get_users(['meta_key'=>CourierRole::META_KEY,'meta_value'=>(string)$id,'number'=>2]))!==1)throw new \DomainException('Courier debe tener exactamente un usuario asociado.');$this->repository->transition($id,$action,$now);return;}
        if($action!=='save')throw new \InvalidArgumentException('Accion invalida.');
        $name=sanitize_text_field(wp_unslash((string)($_POST['display_name']??'')));$phone=sanitize_text_field(wp_unslash((string)($_POST['phone']??'')));$email=sanitize_email(wp_unslash((string)($_POST['email']??'')));
        if($name===''||$phone===''||strlen($name)>150||strlen($phone)>30||($email!==''&&!is_email($email)))throw new \InvalidArgumentException('Datos Courier invalidos.');
        $saved=$this->repository->save(['display_name'=>$name,'phone'=>$phone,'email'=>$email===''?null:$email,...($id===0?['status'=>'pending','approved_at'=>null,'created_at'=>$now]:[]),'updated_at'=>$now],$id===0?null:$id);
        $userId=absint($_POST['user_id']??0);if($userId>0){if(!get_userdata($userId))throw new \InvalidArgumentException('Usuario inexistente.');$other=get_users(['meta_key'=>CourierRole::META_KEY,'meta_value'=>(string)$saved,'exclude'=>[$userId],'number'=>1]);if($other!==[])throw new \DomainException('Courier ya asociado.');update_user_meta($userId,CourierRole::META_KEY,$saved);(new \WP_User($userId))->set_role(CourierRole::ROLE);}
    }
}
