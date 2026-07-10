<?php
$label = is_string($label ?? null) ? $label : __('Cargando', 'veciahorra');
$active = ($active ?? true) === true;
?>
<div class="va-loader" role="status" aria-live="polite"<?php echo $active ? '' : ' hidden'; ?>>
    <span class="va-loader__indicator" aria-hidden="true"></span>
    <span class="va-loader__label"><?php echo esc_html($label); ?></span>
</div>
