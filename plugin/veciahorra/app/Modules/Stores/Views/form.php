<?php

$isEdit = isset($store);

?>
<div class="wrap">

    <h1 class="wp-heading-inline">

    <?= $isEdit ? 'Editar Minimarket' : 'Nuevo Minimarket' ?>

</h1>

    <hr class="wp-header-end">

    <form method="post">

      <?php wp_nonce_field('veciahorra_store'); ?>  

        <input
    type="hidden"
    name="action"
    value="<?= $isEdit
        ? 'veciahorra_store_update'
        : 'veciahorra_store_create' ?>">
            <?php if ($isEdit) : ?>

<input
    type="hidden"
    name="id"
    value="<?= esc_attr($store->id) ?>"

<?php endif; ?>

        <table class="form-table">

            <tr>
                <th>
                    <label for="business_name">
                        Nombre Comercial *
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="business_name"
    name="business_name"
    class="regular-text"
    required
    value="<?= esc_attr($store->business_name ?? '') ?>"
                </td>
            </tr>

            <tr>
                <th>
                    <label for="legal_name">
                        Razón Social
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="legal_name"
    name="legal_name"
    class="regular-text"
    value="<?= esc_attr($store->legal_name ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="rut">
                        RUT
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="rut"
    name="rut"
    class="regular-text"
    value="<?= esc_attr($store->rut ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="owner_name">
                        Propietario *
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="owner_name"
    name="owner_name"
    class="regular-text"
    required
    value="<?= esc_attr($store->owner_name ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="email">
                        Correo *
                    </label>
                </th>

                <td>
                    <input
    type="email"
    id="email"
    name="email"
    class="regular-text"
    required
    value="<?= esc_attr($store->email ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="phone">
                        Teléfono
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="phone"
    name="phone"
    class="regular-text"
    value="<?= esc_attr($store->phone ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="mobile">
                        Celular
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="mobile"
    name="mobile"
    class="regular-text"
    value="<?= esc_attr($store->mobile ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="address">
                        Dirección
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="address"
    name="address"
    class="regular-text"
    style="width:500px;"
    value="<?= esc_attr($store->address ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="commune">
                        Comuna
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="commune"
    name="commune"
    class="regular-text"
    value="<?= esc_attr($store->commune ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="city">
                        Ciudad
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="city"
    name="city"
    class="regular-text"
    value="<?= esc_attr($store->city ?? '') ?>">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="region">
                        Región
                    </label>
                </th>

                <td>
                    <input
    type="text"
    id="region"
    name="region"
    class="regular-text"
    value="<?= esc_attr($store->region ?? '') ?>">
                </td>
            </tr>

        </table>

        <?php
submit_button(
    $isEdit
        ? 'Actualizar Minimarket'
        : 'Guardar Minimarket'
);
?>

    </form>

</div>