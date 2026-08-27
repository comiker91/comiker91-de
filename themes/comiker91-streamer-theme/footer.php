</main>
<footer class="site-footer">
  <div class="shell footer-grid">
    <div>
      <a class="brand footer-brand brand-logo-link" href="<?php echo esc_url(home_url('/')); ?>" aria-label="comiker91 Startseite">
        <img class="brand-logo-image footer-logo-image" src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/logoComiker.png'); ?>" alt="comiker91">
        <span class="brand-copy"><strong>comiker91</strong><small>Gaming. Streams. Community.</small></span>
      </a>
      <p class="muted">Streams, Gaming, News und Creator aus der Community – alles rund um comiker91 und Comitement.</p>
    </div>
    <div><h3>Navigation</h3><?php wp_nav_menu(['theme_location'=>'footer','container'=>false,'fallback_cb'=>'c91_footer_fallback']); ?></div>
    <div class="footer-social-column">
      <h3>Socials</h3>
      <div class="social-icon-row">
        <?php c91_social_link('twitch','Twitch',true); ?>
        <?php c91_social_link('youtube','YouTube',true); ?>
        <?php c91_social_link('discord','Discord',true); ?>
        <?php c91_social_link('instagram','Instagram',true); ?>
        <?php c91_social_link('x','X / Twitter',true); ?>
        <?php c91_social_link('tiktok','TikTok',true); ?>
        <?php c91_social_link('kick','Kick',true); ?>
        <?php c91_social_link('facebook','Facebook',true); ?>
      </div>
    </div>
  </div>
  <div class="shell footer-bottom"><span>© <?php echo esc_html(date('Y')); ?> comiker91 / Comitement</span><span>Live. Gaming. Community.</span></div>
</footer>
<?php wp_footer(); ?>
</body></html>
<?php
