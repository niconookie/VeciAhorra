<?php
$allowedTypes = ['error', 'success', 'warning', 'info'];
$type = in_array($type ?? '', $allowedTypes, true) ? $type : 'info';
$message = is_string($message ?? null) ? $message : '';
$role = $type === 'error' ? 'alert' : 'status';
?>
<div class="va-alert va-alert--<?php echo esc_attr($type); ?>" role="<?php echo esc_attr($role); ?>">
    <?php echo esc_html($message); ?>
</div>
