<?php
$title = is_string($title ?? null) ? $title : '';
$text = is_string($text ?? null) ? $text : '';
?>
<article class="va-card">
    <?php if ($title !== '') : ?>
        <h2 class="va-card__title"><?php echo esc_html($title); ?></h2>
    <?php endif; ?>
    <p class="va-card__text"><?php echo esc_html($text); ?></p>
</article>
