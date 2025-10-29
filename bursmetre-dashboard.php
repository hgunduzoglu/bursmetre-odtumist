<?php
/*
Plugin Name: Burs Metre Dashboard
Description: Google Sheets verisiyle dinamik bursmetre panosu (progress, grafikler, sıralamalar). Shortcode: [bursmetre-dashboard].
Version: 1.0.0
Author: Hüsamettin Gündüzoğlu
*/

if (!defined('ABSPATH')) exit;

// ---------- Ayarlar (Settings) ----------
function bursmetre_register_settings() {
    register_setting('bursmetre_settings_group', 'bursmetre_options');
    add_settings_section('bursmetre_main_section', 'Google Sheets Bağlantı Ayarları', '__return_false', 'bursmetre_settings');

    add_settings_field('burs_sheet_id', 'Bursmetre Sheet ID', 'bursmetre_field_callback', 'bursmetre_settings', 'bursmetre_main_section', ['key'=>'burs_sheet_id']);
    add_settings_field('burs_gid', 'Bursmetre GID (varsayılan 0)', 'bursmetre_field_callback', 'bursmetre_settings', 'bursmetre_main_section', ['key'=>'burs_gid']);
    add_settings_field('kamp_sheet_id', 'Kampanyalar Sheet ID', 'bursmetre_field_callback', 'bursmetre_settings', 'bursmetre_main_section', ['key'=>'kamp_sheet_id']);
    add_settings_field('kamp_gid', 'Kampanyalar GID (varsayılan 0)', 'bursmetre_field_callback', 'bursmetre_settings', 'bursmetre_main_section', ['key'=>'kamp_gid']);
    add_settings_field('goal_amount', 'Genel Hedef Tutarı (₺)', 'bursmetre_field_callback', 'bursmetre_settings', 'bursmetre_main_section', ['key'=>'goal_amount']);
}
add_action('admin_init', 'bursmetre_register_settings');

function bursmetre_field_callback($args) {
    $options = get_option('bursmetre_options', []);
    $key = $args['key'];
    $val = isset($options[$key]) ? esc_attr($options[$key]) : '';
    echo '<input type="text" name="bursmetre_options['.$key.']" value="'.$val.'" style="width: 420px;" />';
}

function bursmetre_add_settings_page() {
    add_options_page('Burs Metre', 'Burs Metre', 'manage_options', 'bursmetre_settings', 'bursmetre_render_settings_page');
}
add_action('admin_menu', 'bursmetre_add_settings_page');

function bursmetre_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>Burs Metre Ayarları</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('bursmetre_settings_group');
          do_settings_sections('bursmetre_settings');
          submit_button();
        ?>
        <p>Örnek Sheet ID’ler:<br>
        Bursmetre: <code>10SUOLDAKrCbGwui9MsuH6RrjljoYtAt-CRdqPAq1Zn0</code><br>
        Kampanyalar: <code>1X6zi1KzN8WG2zitt_IJNjsOt1qleUmZFlt4g0P_0Tlc</code></p>
      </form>
    </div>
    <?php
}

// ---------- Asset'ler ----------
function bursmetre_enqueue_assets() {
    wp_enqueue_style('bursmetre-style', plugins_url('css/style.css', __FILE__), [], '1.0.0');
    // Chart.js CDN
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    // Ana JS
    wp_enqueue_script('bursmetre-charts', plugins_url('js/charts.js', __FILE__), ['chartjs'], '1.0.0', true);
}
add_action('wp_enqueue_scripts', 'bursmetre_enqueue_assets');

// ---------- Kısa Kod ----------
function bursmetre_dashboard_shortcode($atts) {
    ob_start();
    include plugin_dir_path(__FILE__) . 'includes/fetch-data.php';
    return ob_get_clean();
}
add_shortcode('bursmetre-dashboard', 'bursmetre_dashboard_shortcode');

// ---------- Admin’e özel buton shortcodu (opsiyonel) ----------
function bursmetre_button_shortcode() {
    if (current_user_can('administrator')) {
        return '<a href="/maraton/bursmetre" class="btn btn-red bursmetre-admin-btn">Burs Metre</a>';
    }
    return '';
}
add_shortcode('bursmetre_button', 'bursmetre_button_shortcode');
