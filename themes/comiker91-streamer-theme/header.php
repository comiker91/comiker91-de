<?php require_once get_template_directory() . '/inc/seo.php'; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo esc_url(get_template_directory_uri() . '/assets/css/enhancements.css?ver=' . C91_VERSION); ?>">
<?php wp_head(); ?>
<link rel="stylesheet" href="<?php echo esc_url(get_template_directory_uri() . '/assets/css/mobile-fix.css?ver=2.4.2'); ?>" media="(max-width: 900px)">
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
  <div class="shell header-inner">
    <a class="brand brand-logo-link" href="<?php echo esc_url(home_url('/')); ?>" aria-label="comiker91 Startseite">
      <img class="brand-logo-image" src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/logoComiker.png'); ?>" alt="comiker91 Logo" width="auto" height="auto">
      <span class="brand-copy"><strong>comiker91</strong><small>Gaming · Streams · Community.</small></span>
    </a>
    <button class="menu-toggle" aria-label="Menü öffnen" aria-expanded="false">☰</button>
    <nav class="main-nav" aria-label="Hauptnavigation">
      <?php wp_nav_menu(['theme_location'=>'primary','container'=>false,'fallback_cb'=>'c91_fallback_menu']); ?>
    </nav>
    <?php $twitch = get_theme_mod('c91_twitch_url','https://www.twitch.tv/comiker91'); if($twitch): ?>
      <a class="live-button" href="<?php echo esc_url($twitch); ?>" target="_blank" rel="noopener noreferrer"><span class="pulse"></span> Twitch</a>
    <?php endif; ?>
  </div>
</header>
<main id="main-content">
