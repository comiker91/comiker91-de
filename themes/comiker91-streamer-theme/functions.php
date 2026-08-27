<?php
if (!defined('ABSPATH')) exit;

define('C91_VERSION', '2.4.1');

function c91_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption','style','script']);
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    register_nav_menus([
        'primary' => __('Hauptmenü', 'comiker91-streamer'),
        'footer'  => __('Footer-Menü', 'comiker91-streamer'),
    ]);
}
add_action('after_setup_theme', 'c91_setup');

function c91_assets() {
    wp_enqueue_style('c91-main', get_template_directory_uri() . '/assets/css/main.css', [], C91_VERSION);
    wp_enqueue_script('c91-main', get_template_directory_uri() . '/assets/js/main.js', [], C91_VERSION, true);
}
add_action('wp_enqueue_scripts', 'c91_assets');

function c91_register_cpts() {
    register_post_type('streamer', [
        'labels' => [
            'name' => 'Streamer', 'singular_name' => 'Streamer', 'add_new_item' => 'Streamer hinzufügen',
            'edit_item' => 'Streamer bearbeiten', 'menu_name' => 'Streamer'
        ],
        'public' => true,
        'menu_icon' => 'dashicons-video-alt3',
        'supports' => ['title','editor','excerpt'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'streamer'],
        'show_in_rest' => true,
    ]);

    register_taxonomy('streamer_category', 'streamer', [
        'labels' => ['name' => 'Streamer-Kategorien', 'singular_name' => 'Streamer-Kategorie'],
        'public' => true,
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'streamer-kategorie'],
    ]);
}
add_action('init', 'c91_register_cpts');

/**
 * Twitch settings
 */
function c91_twitch_defaults() {
    return [
        'client_id' => '',
        'client_secret' => '',
        'owner_login' => 'comiker91',
        'cache_seconds' => 60,
    ];
}

function c91_get_twitch_settings() {
    return wp_parse_args((array) get_option('c91_twitch_settings', []), c91_twitch_defaults());
}

function c91_register_twitch_settings() {
    register_setting('c91_twitch_group', 'c91_twitch_settings', [
        'sanitize_callback' => function($value) {
            $old = c91_get_twitch_settings();
            $out = [];
            $out['client_id'] = sanitize_text_field($value['client_id'] ?? '');
            // Secret nicht leeren, wenn Feld beim Speichern leer bleibt.
            $secret = trim((string)($value['client_secret'] ?? ''));
            $out['client_secret'] = $secret !== '' ? sanitize_text_field($secret) : $old['client_secret'];
            $out['owner_login'] = c91_normalize_twitch_login($value['owner_login'] ?? 'comiker91') ?: 'comiker91';
            $out['cache_seconds'] = min(300, max(30, absint($value['cache_seconds'] ?? 60)));
            delete_transient('c91_twitch_app_token');
            return $out;
        }
    ]);
}
add_action('admin_init', 'c91_register_twitch_settings');

function c91_add_twitch_settings_page() {
    add_theme_page('Twitch Live', 'Twitch Live', 'manage_options', 'c91-twitch', 'c91_render_twitch_settings_page');
}
add_action('admin_menu', 'c91_add_twitch_settings_page');

function c91_render_twitch_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = c91_get_twitch_settings();
    $api_ok = c91_twitch_is_configured();
    ?>
    <div class="wrap">
      <h1>Twitch Live</h1>
      <p>Einmal Client-ID und Client-Secret hinterlegen. Streamer benötigen danach nur noch ihren Twitch-Namen.</p>
      <form method="post" action="options.php">
        <?php settings_fields('c91_twitch_group'); ?>
        <table class="form-table" role="presentation">
          <tr><th><label for="c91_client_id">Client ID</label></th><td><input class="regular-text" id="c91_client_id" name="c91_twitch_settings[client_id]" value="<?php echo esc_attr($s['client_id']); ?>" autocomplete="off"></td></tr>
          <tr><th><label for="c91_client_secret">Client Secret</label></th><td><input class="regular-text" type="password" id="c91_client_secret" name="c91_twitch_settings[client_secret]" value="" autocomplete="new-password" placeholder="<?php echo $s['client_secret'] ? 'Gespeichert – leer lassen zum Beibehalten' : 'Client Secret'; ?>"><p class="description">Wird nur serverseitig gespeichert und niemals im Frontend ausgegeben.</p></td></tr>
          <tr><th><label for="c91_owner_login">Dein Twitch-Name</label></th><td><input class="regular-text" id="c91_owner_login" name="c91_twitch_settings[owner_login]" value="<?php echo esc_attr($s['owner_login']); ?>"><p class="description">z. B. comiker91 – steuert den großen Player auf der Startseite.</p></td></tr>
          <tr><th><label for="c91_cache_seconds">Live-Cache</label></th><td><input type="number" min="30" max="300" id="c91_cache_seconds" name="c91_twitch_settings[cache_seconds]" value="<?php echo esc_attr($s['cache_seconds']); ?>"> Sekunden</td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <p><strong>Status:</strong> <?php echo $api_ok ? '<span style="color:#15803d">API-Zugang konfiguriert</span>' : '<span style="color:#b45309">Client ID / Secret fehlen noch</span>'; ?></p>
      <p><strong>OAuth Redirect URL in der Twitch Console:</strong> <code><?php echo esc_html(home_url('/')); ?></code><br><span class="description">Für die aktuelle Live-/Profil-Abfrage wird der Client-Credentials-Flow verwendet; diese Redirect-URL wird dabei technisch nicht aufgerufen.</span></p>
    </div>
    <?php
}

function c91_twitch_is_configured() {
    $s = c91_get_twitch_settings();
    return !empty($s['client_id']) && !empty($s['client_secret']);
}

function c91_normalize_twitch_login($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('~twitch\.tv/([^/?#]+)~i', $value, $m)) $value = $m[1];
    $value = ltrim($value, '@');
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9_]/', '', $value);
}

function c91_get_twitch_token() {
    if (!c91_twitch_is_configured()) return new WP_Error('c91_twitch_config', 'Twitch API ist noch nicht konfiguriert.');
    $cached = get_transient('c91_twitch_app_token');
    if ($cached) return $cached;

    $s = c91_get_twitch_settings();
    $res = wp_remote_post('https://id.twitch.tv/oauth2/token', [
        'timeout' => 12,
        'body' => [
            'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'],
            'grant_type' => 'client_credentials',
        ],
    ]);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code !== 200 || empty($json['access_token'])) return new WP_Error('c91_twitch_token', 'Twitch Token konnte nicht geladen werden.');
    $ttl = max(300, absint($json['expires_in'] ?? 3600) - 120);
    set_transient('c91_twitch_app_token', $json['access_token'], $ttl);
    return $json['access_token'];
}

function c91_twitch_request($endpoint, array $query = []) {
    $token = c91_get_twitch_token();
    if (is_wp_error($token)) return $token;
    $s = c91_get_twitch_settings();
    $url = 'https://api.twitch.tv/helix/' . ltrim($endpoint, '/');
    if ($query) $url = add_query_arg($query, $url);
    $res = wp_remote_get($url, [
        'timeout' => 12,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Client-Id' => $s['client_id'],
        ],
    ]);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code === 401) delete_transient('c91_twitch_app_token');
    if ($code < 200 || $code >= 300) return new WP_Error('c91_twitch_api', 'Twitch API Fehler ' . $code);
    return $json['data'] ?? [];
}

function c91_twitch_get_users(array $logins) {
    $logins = array_values(array_unique(array_filter(array_map('c91_normalize_twitch_login', $logins))));
    if (!$logins) return [];
    $out = [];
    $missing = [];
    foreach ($logins as $login) {
        $cached = get_transient('c91_tw_user_' . md5($login));
        if (is_array($cached)) $out[$login] = $cached;
        else $missing[] = $login;
    }
    foreach (array_chunk($missing, 100) as $chunk) {
        $query = [];
        // add_query_arg kann Arrays als login[0] serialisieren; Helix erwartet wiederholte login-Parameter.
        $token = c91_get_twitch_token();
        if (is_wp_error($token)) break;
        $s = c91_get_twitch_settings();
        $url = 'https://api.twitch.tv/helix/users?' . implode('&', array_map(fn($l) => 'login=' . rawurlencode($l), $chunk));
        $res = wp_remote_get($url, ['timeout'=>12,'headers'=>['Authorization'=>'Bearer '.$token,'Client-Id'=>$s['client_id']]]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $data = json_decode(wp_remote_retrieve_body($res), true)['data'] ?? [];
        foreach ($data as $u) {
            $key = c91_normalize_twitch_login($u['login'] ?? '');
            if (!$key) continue;
            $out[$key] = $u;
            set_transient('c91_tw_user_' . md5($key), $u, 12 * HOUR_IN_SECONDS);
        }
    }
    return $out;
}

function c91_twitch_get_streams(array $logins) {
    $logins = array_values(array_unique(array_filter(array_map('c91_normalize_twitch_login', $logins))));
    if (!$logins || !c91_twitch_is_configured()) return [];
    sort($logins);
    $s = c91_get_twitch_settings();
    $cache_key = 'c91_tw_streams_' . md5(implode('|', $logins));
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $out = [];
    foreach (array_chunk($logins, 100) as $chunk) {
        $token = c91_get_twitch_token();
        if (is_wp_error($token)) break;
        $url = 'https://api.twitch.tv/helix/streams?' . implode('&', array_map(fn($l) => 'user_login=' . rawurlencode($l), $chunk));
        $res = wp_remote_get($url, ['timeout'=>12,'headers'=>['Authorization'=>'Bearer '.$token,'Client-Id'=>$s['client_id']]]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $data = json_decode(wp_remote_retrieve_body($res), true)['data'] ?? [];
        foreach ($data as $stream) {
            $key = c91_normalize_twitch_login($stream['user_login'] ?? $stream['user_name'] ?? '');
            if ($key) $out[$key] = $stream;
        }
    }
    set_transient($cache_key, $out, absint($s['cache_seconds']));
    return $out;
}

function c91_get_streamer_login($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $stored = c91_normalize_twitch_login(get_post_meta($post_id, '_c91_twitch_login', true));
    // WordPress creates an "Auto Draft" before the editor is filled out. Older theme
    // versions could accidentally persist that temporary title as the Twitch login.
    $invalid = ['automatischerentwurf','auto-draft','auto_draft','automatic-draft'];
    if ($stored && !in_array($stored, $invalid, true)) return $stored;
    return c91_normalize_twitch_login(get_the_title($post_id));
}

function c91_get_twitch_context(array $logins) {
    $users = c91_twitch_get_users($logins);
    $streams = c91_twitch_get_streams($logins);
    $result = [];
    foreach ($logins as $raw) {
        $login = c91_normalize_twitch_login($raw);
        if (!$login) continue;
        $result[$login] = [
            'login' => $login,
            'user' => $users[$login] ?? null,
            'stream' => $streams[$login] ?? null,
            'is_live' => isset($streams[$login]),
            'url' => 'https://www.twitch.tv/' . rawurlencode($login),
        ];
    }
    return $result;
}

function c91_twitch_thumbnail_url($stream, $width = 640, $height = 360) {
    if (empty($stream['thumbnail_url'])) return '';
    return str_replace(['{width}','{height}'], [(string)$width,(string)$height], $stream['thumbnail_url']);
}

function c91_twitch_parent_host() {
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    return $host ?: ($_SERVER['HTTP_HOST'] ?? 'comiker91.de');
}

function c91_twitch_player_url($login) {
    return add_query_arg([
        'channel' => c91_normalize_twitch_login($login),
        'parent' => c91_twitch_parent_host(),
        'autoplay' => 'false',
        'muted' => 'false',
    ], 'https://player.twitch.tv/');
}

/** Streamer editor: only Twitch name is required. */
function c91_add_streamer_meta_boxes() {
    add_meta_box('c91_streamer_twitch', 'Twitch-Verknüpfung', 'c91_streamer_meta_box', 'streamer', 'side', 'high');
}
add_action('add_meta_boxes', 'c91_add_streamer_meta_boxes');

function c91_streamer_meta_box($post) {
    wp_nonce_field('c91_streamer_meta', 'c91_streamer_meta_nonce');
    $login = c91_get_streamer_login($post->ID);
    echo '<p><label for="c91_twitch_login"><strong>Twitch-Name</strong></label></p>';
    echo '<input type="text" id="c91_twitch_login" style="width:100%" name="c91_twitch_login" value="'.esc_attr($login).'" placeholder="z. B. gronkh">';
    echo '<p class="description">Nur den aktuellen Twitch-Namen eintragen. Profilbild, Link und Live-Status werden automatisch geladen.</p>';
    if ($login) echo '<p><a href="'.esc_url('https://www.twitch.tv/'.$login).'" target="_blank" rel="noopener">Twitch-Kanal öffnen ↗</a></p>';
}

function c91_save_streamer_meta($post_id) {
    if (!isset($_POST['c91_streamer_meta_nonce']) || !wp_verify_nonce($_POST['c91_streamer_meta_nonce'], 'c91_streamer_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;
    // Only save when the Twitch field was actually submitted. Never derive it from
    // WordPress' temporary Auto-Draft title.
    if (!array_key_exists('c91_twitch_login', $_POST)) return;
    $login = c91_normalize_twitch_login(wp_unslash($_POST['c91_twitch_login']));
    if ($login) update_post_meta($post_id, '_c91_twitch_login', $login);
    else delete_post_meta($post_id, '_c91_twitch_login');
}
add_action('save_post_streamer', 'c91_save_streamer_meta');

function c91_customize($wp_customize) {
    $wp_customize->add_section('c91_home', ['title' => 'comiker91 Startseite', 'priority' => 30]);
    $settings = [
        'hero_kicker' => ['Hero Kicker', 'STREAM • COMMUNITY • COMITEMENT'],
        'hero_title' => ['Hero Titel', 'comiker91 – Gaming, Streams & Community'],
        'hero_text' => ['Hero Text', 'Streams, Videos, News und spannende Creator aus der Community an einem Ort.'],
        'twitch_url' => ['Twitch URL', 'https://www.twitch.tv/comiker91'],
        'youtube_url' => ['YouTube URL', ''],
        'discord_url' => ['Discord URL', ''],
        'instagram_url' => ['Instagram URL', ''],
        'x_url' => ['X / Twitter URL', ''],
        'tiktok_url' => ['TikTok URL', ''],
        'kick_url' => ['Kick URL', ''],
        'facebook_url' => ['Facebook URL', ''],
        'comitement_url' => ['Comitement URL', ''],
        'comitement_text' => ['Comitement Beschreibung', 'Comitement verbindet meine Websites, Inhalte und digitalen Projekte unter einem gemeinsamen Dach. comiker91 ist dabei der persönliche Bereich für Streams, Gaming und Community.'],
    ];
    foreach ($settings as $id => [$label, $default]) {
        $wp_customize->add_setting('c91_'.$id, ['default' => $default, 'sanitize_callback' => str_contains($id, 'url') ? 'esc_url_raw' : 'sanitize_text_field']);
        $wp_customize->add_control('c91_'.$id, ['section' => 'c91_home', 'label' => $label, 'type' => str_contains($id, 'text') ? 'textarea' : 'text']);
    }

    $wp_customize->add_setting('c91_home_share_image', [
        'default' => 0,
        'sanitize_callback' => 'absint',
    ]);
    $wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, 'c91_home_share_image', [
        'section' => 'c91_home',
        'label' => 'Startseiten- / Social-Bild',
        'description' => 'Wird auf der Startseite angezeigt und als Vorschaubild beim Teilen der Startseite verwendet.',
        'mime_type' => 'image',
    ]));
}
add_action('customize_register', 'c91_customize');


function c91_home_share_image_url($size = 'full') {
    $id = (int) get_theme_mod('c91_home_share_image', 0);
    if (!$id) return '';
    $image = wp_get_attachment_image_src($id, $size);
    return $image ? $image[0] : '';
}

/**
 * Social preview metadata for the homepage.
 * Uses the selected homepage image so Discord/WhatsApp/Facebook/X previews have artwork.
 */
function c91_home_social_meta() {
    if (!is_front_page()) return;

    $image = c91_home_share_image_url('full');
    if (!$image) return;

    $title = wp_get_document_title();
    $description = get_theme_mod('c91_hero_text', 'Streams, Videos, News und spannende Creator aus der Community an einem Ort.');
    $url = home_url('/');

    echo "\n<!-- comiker91 homepage social preview -->\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
}
add_action('wp_head', 'c91_home_social_meta', 5);

function c91_social_icon($key) {
    $icons = [
        'twitch' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 2h17v13l-5 5h-4l-3 3H6v-3H2V6l2-4zm2 2-2 3v11h4v2l2-2h6l3-3V4H6zm4 3h2v6h-2V7zm5 0h2v6h-2V7z"/></svg>',
        'youtube' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 12s0-4-.5-6a3 3 0 0 0-2-2C18.5 3.5 12 3.5 12 3.5S5.5 3.5 3.5 4a3 3 0 0 0-2 2C1 8 1 12 1 12s0 4 .5 6a3 3 0 0 0 2 2c2 .5 8.5.5 8.5.5s6.5 0 8.5-.5a3 3 0 0 0 2-2c.5-2 .5-6 .5-6zM9.5 16V8l7 4-7 4z"/></svg>',
        'discord' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 5.3A17 17 0 0 0 15.4 4l-.5 1a15 15 0 0 0-5.8 0L8.6 4a17 17 0 0 0-4.1 1.3C1.9 9.2 1.2 13 1.5 16.8A17 17 0 0 0 6.6 19l1.2-1.7-1.8-.9.4-.3c3.5 1.6 7.6 1.6 11.1 0l.4.3-1.8.9 1.2 1.7a17 17 0 0 0 5.1-2.2c.4-4.4-.7-8.1-2.9-11.5zM8.3 14.8c-1 0-1.9-1-1.9-2.3s.8-2.3 1.9-2.3 1.9 1 1.9 2.3-.9 2.3-1.9 2.3zm7.4 0c-1 0-1.9-1-1.9-2.3s.8-2.3 1.9-2.3 1.9 1 1.9 2.3-.9 2.3-1.9 2.3z"/></svg>',
        'instagram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7zm11 1.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5zM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>',
        'x' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.9 2H22l-6.8 7.8L23 22h-6.1l-4.8-6.2L6.7 22H3.6l7-8L3 2h6.2l4.3 5.7L18.9 2zm-1.1 17.9h1.7L8.2 4H6.4l11.4 15.9z"/></svg>',
        'tiktok' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2h3c.3 2.2 1.6 3.7 4 4v3a9 9 0 0 1-4-1.2V16a6 6 0 1 1-6-6h1v3a3 3 0 1 0 2 3V2z"/></svg>',
        'kick' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h6v6h2V7h2V5h2V3h6v6h-2v2h-2v2h2v2h2v6h-6v-2h-2v-2h-2v4H3V3z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8h4V3h-4c-4 0-6 2.4-6 6v3H4v5h4v7h5v-7h4l1-5h-5V9c0-.7.3-1 1-1z"/></svg>',
    ];
    return $icons[$key] ?? '<span aria-hidden="true">↗</span>';
}

function c91_social_link($key, $label, $icon_only = false) {
    $defaults = [
        'twitch'    => 'https://www.twitch.tv/comiker91',
        'youtube'   => 'https://www.youtube.com/@comiker91',
        'discord'   => 'https://discord.gg/Z3FnAhrWgk',
        'instagram' => 'https://www.instagram.com/comiker91',
    ];
    $url = get_theme_mod('c91_'.$key.'_url', $defaults[$key] ?? '');
    if (!$url) return;

    $class = $icon_only ? 'social-icon-button' : 'social-pill';
    echo '<a class="'.esc_attr($class).' social-'.esc_attr($key).'" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr($label).'" title="'.esc_attr($label).'">';
    echo c91_social_icon($key);
    if (!$icon_only) echo '<span>'.esc_html($label).'</span>';
    echo '</a>';
}

function c91_handle_streamer_suggestion() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['c91_suggest_streamer'])) return;
    if (!isset($_POST['c91_suggest_nonce']) || !wp_verify_nonce($_POST['c91_suggest_nonce'], 'c91_suggest_streamer')) return;

    $name = c91_normalize_twitch_login($_POST['streamer_name'] ?? '');
    $why  = sanitize_textarea_field($_POST['streamer_why'] ?? '');
    $mail = sanitize_email($_POST['your_email'] ?? '');
    if (!$name) {
        wp_safe_redirect(add_query_arg('suggest', 'missing', wp_get_referer() ?: home_url('/')));
        exit;
    }

    $post_id = wp_insert_post([
        'post_type' => 'streamer',
        'post_status' => 'pending',
        'post_title' => $name,
        'post_content' => $why,
    ]);
    if ($post_id && !is_wp_error($post_id)) {
        update_post_meta($post_id, '_c91_twitch_login', $name);
        update_post_meta($post_id, '_c91_submitter_email', $mail);
    }
    wp_safe_redirect(add_query_arg('suggest', 'success', wp_get_referer() ?: home_url('/')));
    exit;
}
add_action('template_redirect', 'c91_handle_streamer_suggestion');


/**
 * Make legacy posts nicer: old standalone .webm links become responsive HTML5 video players.
 * This is especially useful for posts imported from the previous comiker91 theme.
 */
function c91_render_legacy_webm_video($content) {
    if (is_admin() || !is_singular('post')) return $content;

    // WordPress may render a raw URL as a paragraph or clickable link.
    $patterns = [
        '~<p>\s*<a[^>]+href=["\']([^"\']+\.webm(?:\?[^"\']*)?)["\'][^>]*>.*?</a>\s*</p>~is',
        '~<p>\s*(https?://[^\s<]+\.webm(?:\?[^\s<]*)?)\s*</p>~is',
    ];

    foreach ($patterns as $pattern) {
        $content = preg_replace_callback($pattern, function($m) {
            $url = esc_url($m[1]);
            return '<div class="article-video"><video controls playsinline preload="metadata"><source src="'.$url.'" type="video/webm">Dein Browser unterstützt dieses Videoformat nicht.</video></div>';
        }, $content);
    }
    return $content;
}
add_filter('the_content', 'c91_render_legacy_webm_video', 20);

function c91_reading_time() {
    $words = str_word_count(wp_strip_all_tags(get_the_content()));
    return max(1, (int) ceil($words / 220));
}

/**
 * Native /news/ hub.
 * Works without creating a WordPress page and supports /news/page/2/.
 */
function c91_news_rewrite_rules() {
    add_rewrite_rule('^news/?$', 'index.php?c91_news=1', 'top');
    add_rewrite_rule('^news/page/([0-9]+)/?$', 'index.php?c91_news=1&paged=$matches[1]', 'top');
}
add_action('init', 'c91_news_rewrite_rules', 20);

function c91_news_query_vars($vars) {
    $vars[] = 'c91_news';
    return $vars;
}
add_filter('query_vars', 'c91_news_query_vars');

function c91_news_template($template) {
    if ((int) get_query_var('c91_news') === 1) {
        $news_template = get_template_directory() . '/news.php';
        if (file_exists($news_template)) return $news_template;
    }
    return $template;
}
add_filter('template_include', 'c91_news_template');

function c91_news_document_title($title) {
    if ((int) get_query_var('c91_news') === 1) {
        return 'News – comiker91';
    }
    return $title;
}
add_filter('pre_get_document_title', 'c91_news_document_title');

function c91_flush_theme_rewrites() {
    c91_news_rewrite_rules();
    flush_rewrite_rules();
}
add_action('after_switch_theme', 'c91_flush_theme_rewrites');

function c91_fallback_menu() {
    echo '<ul><li><a href="'.esc_url(home_url('/')).'">Home</a></li><li><a href="'.esc_url(home_url('/news/')).'">News</a></li></ul>';
}
function c91_footer_fallback() {
    echo '<ul><li><a href="'.esc_url(home_url('/impressum/')).'">Impressum</a></li><li><a href="'.esc_url(home_url('/datenschutz/')).'">Datenschutz</a></li></ul>';
}


/** Keep the public top navigation focused: Streamer recommendations live on the homepage. */
function c91_hide_streamer_from_primary_menu($items, $args) {
    if (empty($args->theme_location) || $args->theme_location !== 'primary') return $items;
    $archive = get_post_type_archive_link('streamer');
    foreach ($items as $key => $item) {
        $url = isset($item->url) ? untrailingslashit($item->url) : '';
        $is_archive = $archive && $url === untrailingslashit($archive);
        $is_streamer_object = isset($item->object) && $item->object === 'streamer';
        if ($is_archive || $is_streamer_object || mb_strtolower(trim($item->title ?? '')) === 'streamer') unset($items[$key]);
    }
    return $items;
}
add_filter('wp_nav_menu_objects', 'c91_hide_streamer_from_primary_menu', 10, 2);

add_filter( 'xmlrpc_enabled', '__return_false' );
