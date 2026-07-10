<?php
/** @var string $title */
/** @var string $message */
/** @var string $instanceId */
$titleId = $instanceId . '-title';
?>
<section class="va-placeholder" aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <h1 id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($title); ?></h1>
    <?php
    $renderer = new \VeciAhorra\Modules\Frontend\Support\ViewRenderer();
    echo $renderer->render('card', [
        'title' => __('Frontend Foundation', 'veciahorra'),
        'text' => $message,
    ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $renderer->render('alert', [
        'type' => 'success',
        'message' => __('La infraestructura base está activa.', 'veciahorra'),
    ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $renderer->render('empty-state', [
        'title' => __('Sin contenido comercial', 'veciahorra'),
        'message' => __('Esta página es una prueba técnica de renderizado.', 'veciahorra'),
    ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $renderer->render('loader', [
        'label' => __('Componente de carga preparado', 'veciahorra'),
        'active' => false,
    ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $renderer->render('button', [
        'label' => __('Botón de ejemplo', 'veciahorra'),
        'type' => 'button',
        'disabled' => true,
    ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
</section>
