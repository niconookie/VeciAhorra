<?php
$label = is_string($label ?? null) ? $label : '';
$type = in_array($type ?? '', ['button', 'submit', 'reset'], true) ? $type : 'button';
$disabled = ($disabled ?? false) === true;
?>
<button class="va-button" type="<?php echo esc_attr($type); ?>"<?php disabled($disabled); ?>>
    <?php echo esc_html($label); ?>
</button>
