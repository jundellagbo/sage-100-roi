<?php

// This ensures /my-account/ always opens Orders, not Dashboard.
add_action('template_redirect', function () {
    if (is_account_page() && is_user_logged_in() && !is_wc_endpoint_url()) {
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }
});

add_filter('woocommerce_account_menu_items', function ($items) {
    // New menu item
    $new_item = [
        'ordering-portal' => 'Ordering Portal',
    ];
    // Insert it at the top
    return $new_item + $items;
});

add_filter('woocommerce_get_endpoint_url', function ($url, $endpoint, $value, $permalink) {
    if ($endpoint === 'ordering-portal') {
        return home_url('/');
    }
    return $url;
}, 10, 4);

// Remove “Dashboard” from My Account menu
add_filter('woocommerce_account_menu_items', function ($items) {
    unset($items['dashboard']);
    return $items;
}, 99);

// --- Draft product categories: allow draft status and hide them on the frontend ---

if (!defined('SAGE_PRODUCT_CAT_STATUS_KEY')) {
    define('SAGE_PRODUCT_CAT_STATUS_KEY', '_product_cat_status');
}

// Admin: add Status field when creating a product category
add_action('product_cat_add_form_fields', function () {
    ?>
    <div class="form-field">
        <label for="product_cat_status"><?php esc_html_e('Status', 'sage-100-roi'); ?></label>
        <select name="product_cat_status" id="product_cat_status">
            <option value="publish"><?php esc_html_e('Published', 'sage-100-roi'); ?></option>
            <option value="draft"><?php esc_html_e('Draft', 'sage-100-roi'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Draft categories are hidden from the shop and category lists.', 'sage-100-roi'); ?></p>
    </div>
    <?php
});

// Admin: add Status field when editing a product category
add_action('product_cat_edit_form_fields', function ($term) {
    $status = get_term_meta($term->term_id, SAGE_PRODUCT_CAT_STATUS_KEY, true) ?: 'publish';
    ?>
    <tr class="form-field">
        <th scope="row"><label for="product_cat_status"><?php esc_html_e('Status', 'sage-100-roi'); ?></label></th>
        <td>
            <select name="product_cat_status" id="product_cat_status">
                <option value="publish" <?php selected($status, 'publish'); ?>><?php esc_html_e('Published', 'sage-100-roi'); ?></option>
                <option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('Draft', 'sage-100-roi'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Draft categories are hidden from the shop and category lists.', 'sage-100-roi'); ?></p>
        </td>
    </tr>
    <?php
});

// Admin: save status when creating a product category
add_action('created_product_cat', function ($term_id) {
    if (!empty($_POST['product_cat_status']) && in_array($_POST['product_cat_status'], ['publish', 'draft'], true)) {
        update_term_meta($term_id, SAGE_PRODUCT_CAT_STATUS_KEY, sanitize_text_field($_POST['product_cat_status']));
    }
});

// Admin: save status when editing a product category
add_action('edited_product_cat', function ($term_id) {
    if (!empty($_POST['product_cat_status']) && in_array($_POST['product_cat_status'], ['publish', 'draft'], true)) {
        update_term_meta($term_id, SAGE_PRODUCT_CAT_STATUS_KEY, sanitize_text_field($_POST['product_cat_status']));
    }
});

// Admin: add Status column to product category list
add_filter('manage_edit-product_cat_columns', function ($columns) {
    $columns['cat_status'] = __('Status', 'sage-100-roi');
    return $columns;
});

add_filter('manage_product_cat_custom_column', function ($content, $column_name, $term_id) {
    if ($column_name === 'cat_status') {
        $status = get_term_meta($term_id, SAGE_PRODUCT_CAT_STATUS_KEY, true) ?: 'publish';
        $content = $status === 'draft' ? '<span style="color:#b32d2e;">' . esc_html__('Draft', 'sage-100-roi') . '</span>' : esc_html__('Published', 'sage-100-roi');
    }
    return $content;
}, 10, 3);

// Frontend: exclude draft categories from get_terms (menus, widgets, layered nav, etc.)
add_filter('get_terms_args', function ($args, $taxonomies) {
    if (is_admin() || !in_array('product_cat', (array) $taxonomies, true)) {
        return $args;
    }
    $args['meta_query'] = [
        'relation' => 'OR',
        ['key' => SAGE_PRODUCT_CAT_STATUS_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => SAGE_PRODUCT_CAT_STATUS_KEY, 'value' => 'draft', 'compare' => '!='],
    ];
    return $args;
}, 10, 2);

// Frontend: 404 when visiting a draft category archive URL
add_action('template_redirect', function () {
    if (!is_product_taxonomy() || !is_tax('product_cat')) {
        return;
    }
    $term = get_queried_object();
    if ($term && get_term_meta($term->term_id, SAGE_PRODUCT_CAT_STATUS_KEY, true) === 'draft') {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}, 5);


