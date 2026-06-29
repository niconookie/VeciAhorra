<div class="wrap">

<?php
require dirname(__DIR__, 3) . '/Admin/Views/flash.php';
?>

<h1 class="wp-heading-inline">
    Minimarkets
</h1>

<a
    href="<?= esc_url(admin_url('admin.php?page=veciahorra-store-create')); ?>"
    class="page-title-action">

    + Nuevo Minimarket

</a>

<hr class="wp-header-end">

<form method="get">

    <?php
    $currentStatus = sanitize_text_field(
        $_GET['status'] ?? ''
    );
    ?>

    <input
        type="hidden"
        name="page"
        value="veciahorra-stores">

    <input
        type="hidden"
        name="status"
        value="<?= esc_attr($currentStatus); ?>"
    >

    <?php
    $statuses = [
        ''          => 'Todos',
        'pending'   => 'Pending',
        'active'    => 'Active',
        'inactive'  => 'Inactive',
        'rejected'  => 'Rejected',
    ];

    echo '<ul class="subsubsub">';

    $links = [];

    foreach ($statuses as $value => $label) {

        $url = add_query_arg(
            [
                'page'   => 'veciahorra-stores',
                'status' => $value,
                's'      => $_GET['s'] ?? '',
            ],
            admin_url('admin.php')
        );

        $class = ($currentStatus === $value)
            ? ' class="current"'
            : '';

        $links[] = sprintf(
            '<li><a href="%s"%s>%s</a></li>',
            esc_url($url),
            $class,
            esc_html($label)
        );
    }

    echo implode(' | ', $links);

    echo '</ul>';

    $table->search_box(
        'Buscar minimarket',
        'veciahorra-stores'
    );

    $table->display();
    ?>

</form>

</div>
