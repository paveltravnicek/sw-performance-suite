<?php
/**
 * Plugin Name: Zlepšení výkonu webu
 * Description: Univerzální výkonový plugin pro WordPress: bezpečné odlehčení frontendu, defer JS, preload stylů a fontů, resource hints, LCP featured image a volitelný smart prefetch.
 * Version: 1.0
 * Author: Smart Websites
 * Author URI: https://smart-websites.cz
 * Update URI: https://github.com/paveltravnicek/sw-performance-suite/
 * Text Domain: sw-performance-suite
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SW_Performance_Suite {
    const VERSION = '1.0';
    const OPTION_KEY = 'swps_settings';
    const MENU_SLUG = 'sw-performance-suite';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);

        add_action('init', [$this, 'bootstrap_runtime'], 1);
    }

    public function activate() {
        $defaults = $this->get_default_settings();
        $current = get_option(self::OPTION_KEY, []);

        if (!is_array($current)) {
            $current = [];
        }

        update_option(self::OPTION_KEY, wp_parse_args($current, $defaults));
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . self::MENU_SLUG)) . '">Nastavení</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_admin_page() {
        add_options_page(
            'Zlepšení výkonu webu',
            'Zlepšení výkonu webu',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'swps-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'swps-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            [],
            self::VERSION,
            true
        );
    }

    public function register_settings() {
        register_setting(
            'swps_settings_group',
            self::OPTION_KEY,
            [$this, 'sanitize_settings']
        );
    }

    public function sanitize_settings($input) {
        $defaults = $this->get_default_settings();
        $current  = $this->get_settings();
        $output   = $defaults;

        $checkboxes = [
            'enabled',
            'disable_for_logged_in',
            'emoji_oembed_off',
            'dashicons_off',
            'heartbeat_off',
            'defer_js',
            'jquery_to_footer',
            'preload_styles',
            'preload_fonts',
            'lcp_featured_image',
            'resource_hints',
            'smart_prefetch',
            'transition_preloader',
        ];

        foreach ($checkboxes as $key) {
            $output[$key] = !empty($input[$key]) ? '1' : '0';
        }

        $output['defer_exclude_handles'] = $this->sanitize_textarea_lines($input['defer_exclude_handles'] ?? $defaults['defer_exclude_handles']);
        $output['preload_style_handles'] = $this->sanitize_textarea_lines($input['preload_style_handles'] ?? $defaults['preload_style_handles']);
        $output['preload_font_urls']     = $this->sanitize_url_lines($input['preload_font_urls'] ?? $defaults['preload_font_urls']);
        $output['resource_hint_urls']    = $this->sanitize_url_lines($input['resource_hint_urls'] ?? $defaults['resource_hint_urls']);
        $output['excluded_paths']        = $this->sanitize_path_lines($input['excluded_paths'] ?? '');

        if ($output['transition_preloader'] === '1' && $output['smart_prefetch'] !== '1') {
            $output['transition_preloader'] = '0';
        }

        // Preserve unknown future keys if any.
        $output = array_merge($current, $output);

        add_settings_error('swps_messages', 'swps_saved', 'Nastavení bylo uloženo.', 'updated');

        return $output;
    }

    private function sanitize_textarea_lines($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $lines = array_map('sanitize_key', $lines);
        $lines = array_filter($lines, static function($line) {
            return $line !== '';
        });
        $lines = array_values(array_unique($lines));
        return implode("\n", $lines);
    }

    private function sanitize_url_lines($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $clean = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $url = esc_url_raw($line);
            if ($url !== '') {
                $clean[] = $url;
            }
        }

        $clean = array_values(array_unique($clean));
        return implode("\n", $clean);
    }

    private function sanitize_path_lines($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $clean = [];

        foreach ($lines as $line) {
            $line = trim(wp_unslash($line));
            if ($line === '') {
                continue;
            }

            $line = '/' . ltrim($line, '/');
            $line = preg_replace('#/+#', '/', $line);
            $clean[] = sanitize_text_field($line);
        }

        $clean = array_values(array_unique($clean));
        return implode("\n", $clean);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        ?>
        <div class="wrap swps-admin-wrap">
            <div class="swps-shell">
                <div class="swps-hero">
                    <div class="swps-hero__content">
                        <span class="swps-badge"><?php echo esc_html__('Smart Websites', 'sw-performance-suite'); ?></span>
                        <h1><?php echo esc_html__('Zlepšení výkonu webu', 'sw-performance-suite'); ?></h1>
                        <p><?php echo esc_html__('Univerzální výkonový plugin pro WordPress s opatrně zvolenými moduly. Každou optimalizaci lze samostatně zapnout nebo vypnout.', 'sw-performance-suite'); ?></p>
                    </div>
                    <div class="swps-hero__meta">
                        <div class="swps-stat">
                            <strong><?php echo esc_html(self::VERSION); ?></strong>
                            <span><?php echo esc_html__('Verze pluginu', 'sw-performance-suite'); ?></span>
                        </div>
                    </div>
                </div>

                <?php settings_errors('swps_messages'); ?>

                <form method="post" action="options.php" class="swps-form">
                    <?php settings_fields('swps_settings_group'); ?>

                    <div class="swps-grid swps-grid-top">
                        <section class="swps-card">
                            <h2>Základ pluginu</h2>
                            <p class="swps-muted">Doporučuji ponechat plugin zapnutý a jednotlivé moduly ladit podle konkrétního webu.</p>

                            <?php $this->render_toggle('enabled', 'Plugin aktivní', 'Globální hlavní vypínač. Když je vypnutý, plugin nic neprovádí.'); ?>
                            <?php $this->render_toggle('disable_for_logged_in', 'Nevykonávat pro přihlášené uživatele', 'Hodí se hlavně na webech s buildery, členstvím nebo náročnější administrací na frontendu.'); ?>
                        </section>

                        <section class="swps-card">
                            <h2>Výjimky podle URL</h2>
                            <p class="swps-muted">Na uvedených cestách se neprovede žádná optimalizace. Každou cestu zadej na samostatný řádek.</p>
                            <?php $this->render_textarea('excluded_paths', 'Vyloučené cesty', "/kosik/\n/pokladna/\n/muj-ucet/", 'Např. pro checkout, účet, rezervační formuláře nebo problematické landing pages.'); ?>
                        </section>
                    </div>

                    <div class="swps-accordion" data-swps-accordion>
                        <?php $this->render_accordion_section('frontend-cleanup', 'Odlehčení frontendu', function() use ($settings) {
                            $this->render_toggle('emoji_oembed_off', 'Vypnout emoji a oEmbed na frontendu', 'Odstraní zbytečné frontend skripty a odkazy související s emoji a oEmbed.');
                            $this->render_toggle('dashicons_off', 'Vypnout Dashicons pro nepřihlášené', 'Na běžném veřejném webu obvykle nejsou potřeba.');
                            $this->render_toggle('heartbeat_off', 'Vypnout Heartbeat na frontendu', 'Může lehce snížit zátěž, ale pokud nějaký plugin Heartbeat na frontendu potřebuje, nech tuto volbu vypnutou.');
                        }); ?>

                        <?php $this->render_accordion_section('scripts', 'Skripty a pořadí načítání', function() use ($settings) {
                            $this->render_toggle('defer_js', 'Přidávat defer ne-kritickým skriptům', 'Zrychlí vykreslení, ale u citlivých webů může být potřeba doplnit výjimky podle handle.');
                            $this->render_toggle('jquery_to_footer', 'Přesunout jQuery do footeru', 'Doporučuji zapínat jen pokud víš, že web s tím nemá problém. Toto je jedna z citlivějších optimalizací.');
                            $this->render_textarea('defer_exclude_handles', 'Výjimky z deferu (script handles)', "jquery\njquery-core\njquery-migrate\nwp-polyfill\nforminator-frontend\nforminator-form\ngoogle-recaptcha\nrecaptcha\nforminator-google-recaptcha", 'Každý handle na nový řádek. Používej wordpressové handly skriptů, ne URL adresy.');
                        }); ?>

                        <?php $this->render_accordion_section('styles', 'Styly, fonty a externí zdroje', function() use ($settings) {
                            $this->render_toggle('preload_styles', 'Preload vybraných CSS souborů', 'Používá onload swap. Preloaduj jen opravdu důležité styly, jinak se může efekt ztratit.');
                            $this->render_textarea('preload_style_handles', 'CSS handly pro preload', 'picostrap-style', 'Každý handle na nový řádek. Když handle na webu neexistuje, nic se nestane.');
                            $this->render_toggle('preload_fonts', 'Preload vybraných WOFF2 fontů', 'Vhodné jen pro fonty, které se opravdu použijí above the fold.');
                            $this->render_textarea('preload_font_urls', 'URL fontů pro preload', '', 'Každou URL na nový řádek. Ideálně same-origin WOFF2 soubory.');
                            $this->render_toggle('resource_hints', 'Přidat preconnect / dns-prefetch', 'Vhodné pro CDN, analytiku nebo fonty z externí domény.');
                            $this->render_textarea('resource_hint_urls', 'Domény / URL pro resource hints', '', 'Např. https://optimize-v2.b-cdn.net');
                        }); ?>

                        <?php $this->render_accordion_section('images', 'Obrázky a LCP', function() use ($settings) {
                            $this->render_toggle('lcp_featured_image', 'Upřednostnit featured image na single/front page', 'U náhledového obrázku odebere lazy loading a přidá fetchpriority="high" a decoding="async".');
                        }); ?>

                        <?php $this->render_accordion_section('navigation', 'Navigace a smart prefetch', function() use ($settings) {
                            $this->render_toggle('smart_prefetch', 'Prefetch interních odkazů při hoveru nebo tapnutí', 'Pomáhá zrychlit přechod mezi stránkami. Ignoruje togglery, kotvy a externí odkazy.');
                            $this->render_toggle('transition_preloader', 'Přechodový overlay při navigaci', 'Zobrazí jednoduchý overlay spinner při přechodu na jinou interní stránku. Funguje jen pokud je zapnutý smart prefetch modul.');
                        }); ?>
                    </div>

                    <section class="swps-card swps-submit-card">
                        <div>
                            <h2>Uložit nastavení</h2>
                            <p class="swps-muted">Začni opatrně. Nejbezpečnější bývá nejdřív odlehčení frontendu a až potom defer, preloady a zásahy do navigace.</p>
                        </div>
                        <?php submit_button('Uložit změny', 'primary', 'submit', false); ?>
                    </section>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_toggle($key, $label, $help = '') {
        $settings = $this->get_settings();
        $checked = !empty($settings[$key]) ? 'checked' : '';
        ?>
        <label class="swps-toggle-row">
            <span class="swps-toggle-copy">
                <strong><?php echo esc_html($label); ?></strong>
                <?php if ($help !== '') : ?>
                    <small><?php echo esc_html($help); ?></small>
                <?php endif; ?>
            </span>
            <span class="swps-switch">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY . '[' . $key . ']'); ?>" value="1" <?php echo $checked; ?>>
                <span class="swps-slider"></span>
            </span>
        </label>
        <?php
    }

    private function render_textarea($key, $label, $placeholder = '', $help = '') {
        $settings = $this->get_settings();
        ?>
        <div class="swps-field">
            <label for="swps-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
            <textarea id="swps-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY . '[' . $key . ']'); ?>" rows="5" placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea($settings[$key] ?? ''); ?></textarea>
            <?php if ($help !== '') : ?>
                <p class="description"><?php echo esc_html($help); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_accordion_section($id, $title, callable $content_callback) {
        ?>
        <section class="swps-card swps-accordion-item">
            <button type="button" class="swps-accordion-trigger" aria-expanded="false" aria-controls="swps-panel-<?php echo esc_attr($id); ?>" id="swps-trigger-<?php echo esc_attr($id); ?>">
                <span><?php echo esc_html($title); ?></span>
                <span class="swps-accordion-icon" aria-hidden="true"></span>
            </button>
            <div class="swps-accordion-panel" id="swps-panel-<?php echo esc_attr($id); ?>" role="region" aria-labelledby="swps-trigger-<?php echo esc_attr($id); ?>" hidden>
                <div class="swps-accordion-content">
                    <?php $content_callback(); ?>
                </div>
            </div>
        </section>
        <?php
    }

    public function bootstrap_runtime() {
        if (!$this->should_run()) {
            return;
        }

        $settings = $this->get_settings();

        if ($settings['emoji_oembed_off'] === '1') {
            add_action('init', [$this, 'disable_emoji_oembed']);
        }

        if ($settings['dashicons_off'] === '1') {
            add_action('wp_enqueue_scripts', [$this, 'disable_dashicons_for_visitors'], 100);
        }

        if ($settings['heartbeat_off'] === '1') {
            add_action('init', [$this, 'disable_heartbeat_frontend']);
        }

        if ($settings['jquery_to_footer'] === '1') {
            add_action('wp_default_scripts', [$this, 'move_jquery_to_footer']);
        }

        if ($settings['defer_js'] === '1') {
            add_filter('script_loader_tag', [$this, 'add_defer_to_scripts'], 10, 3);
        }

        if ($settings['preload_styles'] === '1') {
            add_filter('style_loader_tag', [$this, 'preload_selected_styles'], 10, 4);
        }

        if ($settings['preload_fonts'] === '1') {
            add_action('wp_head', [$this, 'output_font_preloads'], 1);
        }

        if ($settings['resource_hints'] === '1') {
            add_filter('wp_resource_hints', [$this, 'add_resource_hints'], 10, 2);
        }

        if ($settings['lcp_featured_image'] === '1') {
            add_filter('post_thumbnail_html', [$this, 'optimize_featured_image_lcp'], 10, 2);
        }

        if ($settings['smart_prefetch'] === '1') {
            add_action('wp_footer', [$this, 'output_smart_prefetch_script'], 100);

            if ($settings['transition_preloader'] === '1') {
                add_action('wp_head', [$this, 'output_preloader_css'], 1);
                add_action('wp_body_open', [$this, 'output_preloader_markup']);
            }
        }
    }

    private function should_run() {
        $settings = $this->get_settings();

        if ($settings['enabled'] !== '1') {
            return false;
        }

        if (is_admin()) {
            return false;
        }

        if (wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed()) {
            return false;
        }

        if ($settings['disable_for_logged_in'] === '1' && is_user_logged_in()) {
            return false;
        }

        if ($this->is_excluded_path()) {
            return false;
        }

        return true;
    }

    private function is_excluded_path() {
        $settings = $this->get_settings();
        $paths = $this->explode_lines($settings['excluded_paths']);

        if (empty($paths)) {
            return false;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        $request_uri = '/' . ltrim((string) $request_uri, '/');
        $request_uri = preg_replace('#/+#', '/', $request_uri);

        foreach ($paths as $path) {
            if ($path === $request_uri) {
                return true;
            }

            if ($path !== '/' && strpos($request_uri, trailingslashit($path)) === 0) {
                return true;
            }
        }

        return false;
    }

    public function disable_emoji_oembed() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        add_filter('embed_oembed_discover', '__return_false');
    }

    public function disable_dashicons_for_visitors() {
        if (!is_user_logged_in()) {
            wp_deregister_style('dashicons');
        }
    }

    public function disable_heartbeat_frontend() {
        wp_deregister_script('heartbeat');
    }

    public function move_jquery_to_footer($scripts) {
        if (!is_object($scripts) || !isset($scripts->registered)) {
            return;
        }

        foreach (['jquery', 'jquery-core', 'jquery-migrate'] as $handle) {
            if (isset($scripts->registered[$handle])) {
                $scripts->registered[$handle]->extra['group'] = 1;
            }
        }
    }

    public function add_defer_to_scripts($tag, $handle, $src) {
        if (strpos($tag, ' src=') === false || strpos($tag, ' defer') !== false) {
            return $tag;
        }

        $excluded = $this->explode_lines($this->get_settings()['defer_exclude_handles']);
        if (in_array($handle, $excluded, true)) {
            return $tag;
        }

        return str_replace(' src=', ' defer src=', $tag);
    }

    public function preload_selected_styles($html, $handle, $href, $media) {
        $handles = $this->explode_lines($this->get_settings()['preload_style_handles']);
        if (!in_array($handle, $handles, true)) {
            return $html;
        }

        return sprintf(
            '<link rel="preload" as="style" href="%1$s" onload="this.onload=null;this.rel=\'stylesheet\'"><noscript><link rel="stylesheet" href="%1$s"></noscript>',
            esc_url($href)
        );
    }

    public function output_font_preloads() {
        $urls = $this->explode_lines($this->get_settings()['preload_font_urls']);
        if (empty($urls)) {
            return;
        }

        foreach ($urls as $url) {
            echo '<link rel="preload" href="' . esc_url($url) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        }
    }

    public function add_resource_hints($urls, $relation_type) {
        if ($relation_type !== 'preconnect' && $relation_type !== 'dns-prefetch') {
            return $urls;
        }

        $custom_urls = $this->explode_lines($this->get_settings()['resource_hint_urls']);
        if (empty($custom_urls)) {
            return $urls;
        }

        $urls = array_merge($urls, $custom_urls);
        return array_values(array_unique($urls));
    }

    public function optimize_featured_image_lcp($html, $post_id) {
        if (!(is_singular() || is_front_page())) {
            return $html;
        }

        $html = preg_replace('/\sloading=("|\')lazy\1/i', '', $html);

        if (stripos($html, 'fetchpriority=') === false) {
            $html = str_replace('<img ', '<img fetchpriority="high" decoding="async" ', $html);
        }

        return $html;
    }

    public function output_preloader_css() {
        echo '<style id="swps-preloader-css">'
            . '.swps-preloader{position:fixed;inset:0;opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:99999;background:rgba(255,255,255,.9)}'
            . '.swps-preloader::after{content:"";position:absolute;top:50%;left:50%;width:48px;height:48px;margin:-24px 0 0 -24px;border-radius:50%;border:4px solid currentColor;border-top-color:transparent;animation:swps-spin 1s linear infinite}'
            . 'html.swps-loading .swps-preloader{opacity:1;pointer-events:auto}'
            . '@keyframes swps-spin{to{transform:rotate(360deg)}}'
            . '@media (prefers-color-scheme: dark){.swps-preloader{background:rgba(0,0,0,.55)}}'
            . '</style>';
    }

    public function output_preloader_markup() {
        echo '<div class="swps-preloader" aria-hidden="true"></div>';
    }

    public function output_smart_prefetch_script() {
        $show_overlay = $this->get_settings()['transition_preloader'] === '1' ? 'true' : 'false';
        ?>
        <script id="swps-smart-prefetch">
        (function(){
            var showOverlay = <?php echo esc_js($show_overlay); ?>;
            var prefetched = new Set();
            var overlayFallbackTimer = null;

            function isModifiedEvent(e){
                return !!(e && (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey));
            }

            function sameOrigin(url){
                try {
                    var parsed = new URL(url, location.href);
                    return parsed.origin === location.origin;
                } catch (error) {
                    return false;
                }
            }

            function isUIToggler(link){
                if (!link) return false;
                var href = (link.getAttribute('href') || '').trim().toLowerCase();
                return link.matches('[data-bs-toggle], .dropdown-toggle, [aria-haspopup="true"][aria-expanded], [role="button"]')
                    || href === ''
                    || href === '#'
                    || href.indexOf('javascript:') === 0;
            }

            function isInternalNavigationLink(link){
                if (!link || link.tagName !== 'A') return false;
                if (link.hasAttribute('data-swps-ignore')) return false;
                if (link.target || link.hasAttribute('download')) return false;
                if (link.hash) return false;
                if (!sameOrigin(link.href)) return false;
                if (isUIToggler(link)) return false;
                return true;
            }

            function prefetch(url){
                try {
                    var parsed = new URL(url, location.href);
                    if (parsed.origin !== location.origin) return;
                    if (prefetched.has(parsed.href)) return;
                    var node = document.createElement('link');
                    node.rel = 'prefetch';
                    node.href = parsed.href;
                    document.head.appendChild(node);
                    prefetched.add(parsed.href);
                } catch (error) {}
            }

            function handleIntent(event){
                var link = event.target && event.target.closest ? event.target.closest('a') : null;
                if (isInternalNavigationLink(link)) {
                    prefetch(link.href);
                }
            }

            ['mouseover', 'mousedown', 'touchstart'].forEach(function(eventName){
                document.addEventListener(eventName, handleIntent, {passive:true});
            });

            document.addEventListener('click', function(event){
                if (!showOverlay || isModifiedEvent(event)) return;
                var link = event.target && event.target.closest ? event.target.closest('a') : null;
                if (!isInternalNavigationLink(link)) return;
                if (event.defaultPrevented) return;

                document.documentElement.classList.add('swps-loading');

                if (overlayFallbackTimer) {
                    clearTimeout(overlayFallbackTimer);
                }

                overlayFallbackTimer = setTimeout(function(){
                    document.documentElement.classList.remove('swps-loading');
                }, 1500);
            }, {capture:false});

            window.addEventListener('load', function(){
                setTimeout(function(){
                    document.documentElement.classList.remove('swps-loading');
                }, 100);
            });
        })();
        </script>
        <?php
    }

    private function explode_lines($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, static function($line) {
            return $line !== '';
        });
        return array_values(array_unique($lines));
    }

    private function get_default_settings() {
        return [
            'enabled' => '1',
            'disable_for_logged_in' => '0',
            'emoji_oembed_off' => '1',
            'dashicons_off' => '1',
            'heartbeat_off' => '0',
            'defer_js' => '0',
            'jquery_to_footer' => '0',
            'preload_styles' => '0',
            'preload_fonts' => '0',
            'lcp_featured_image' => '0',
            'resource_hints' => '0',
            'smart_prefetch' => '0',
            'transition_preloader' => '0',
            'defer_exclude_handles' => implode("\n", [
                'jquery',
                'jquery-core',
                'jquery-migrate',
                'wp-polyfill',
                'forminator-frontend',
                'forminator-form',
                'google-recaptcha',
                'recaptcha',
                'forminator-google-recaptcha',
            ]),
            'preload_style_handles' => '',
            'preload_font_urls' => '',
            'resource_hint_urls' => '',
            'excluded_paths' => '',
        ];
    }

    private function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return wp_parse_args($saved, $this->get_default_settings());
    }
}

SW_Performance_Suite::instance();
