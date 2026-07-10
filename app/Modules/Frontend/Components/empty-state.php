<?php
$title = is_string($title ?? null) ? $title : '';
$message = is_string($message ?? null) ? $message : '';
?>
<section class="va-empty-state">
    <h2 class="va-empty-state__title"><?php echo esc_html($title); ?></h2>
    <p class="va-empty-state__message"><?php echo esc_html($message); ?></p>
</section>
