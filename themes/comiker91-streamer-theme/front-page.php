<?php
get_header();
$twitch_settings = c91_get_twitch_settings();
$owner_login = $twitch_settings['owner_login'] ?: 'comiker91';
$owner_ctx = c91_get_twitch_context([$owner_login]);
$owner = $owner_ctx[$owner_login] ?? ['login'=>$owner_login,'user'=>null,'stream'=>null,'is_live'=>false,'url'=>'https://www.twitch.tv/'.$owner_login];
$owner_user = $owner['user'];
$owner_stream = $owner['stream'];
?>
<section class="hero">
  <div class="hero-glow"></div>
  <div class="shell hero-grid">
    <div class="hero-copy">
      <span class="eyebrow"><?php echo esc_html(get_theme_mod('c91_hero_kicker','STREAM • COMMUNITY • COMITEMENT')); ?></span>
      <h1><?php echo esc_html(get_theme_mod('c91_hero_title','comiker91 – Gaming, Streams & Community')); ?></h1>
      <p><?php echo esc_html(get_theme_mod('c91_hero_text','Streams, Videos, News und spannende Creator aus der Community an einem Ort.')); ?></p>
      <div class="hero-actions">
        <a class="btn primary" href="<?php echo esc_url($owner['url']); ?>" target="_blank" rel="noopener"><?php echo $owner['is_live'] ? 'Jetzt live auf Twitch' : 'Zum Twitch-Kanal'; ?></a>
        <a class="btn ghost" href="#streamer">Streamer entdecken</a>
      </div>
      <div class="mini-socials"><?php c91_social_link('twitch','Twitch'); c91_social_link('youtube','YouTube'); c91_social_link('discord','Discord'); c91_social_link('instagram','Instagram'); c91_social_link('x','X'); c91_social_link('tiktok','TikTok'); c91_social_link('kick','Kick'); ?></div>
    </div>

    <div class="stream-card featured-card <?php echo $owner['is_live'] ? 'is-live' : 'is-offline'; ?>">
      <span class="live-tag <?php echo $owner['is_live'] ? '' : 'offline-tag'; ?>"><?php echo $owner['is_live'] ? '● LIVE' : 'OFFLINE'; ?></span>
      <div class="screen twitch-screen">
        <?php if ($owner['is_live']): ?>
          <iframe class="twitch-player" src="<?php echo esc_url(c91_twitch_player_url($owner_login)); ?>" allowfullscreen loading="lazy" title="<?php echo esc_attr(($owner_user['display_name'] ?? $owner_login) . ' Twitch Stream'); ?>"></iframe>
        <?php elseif (!empty($owner_user['offline_image_url'])): ?>
          <a class="offline-cover" href="<?php echo esc_url($owner['url']); ?>" target="_blank" rel="noopener">
            <img src="<?php echo esc_url($owner_user['offline_image_url']); ?>" alt="<?php echo esc_attr(($owner_user['display_name'] ?? $owner_login) . ' ist aktuell offline'); ?>">
            <span class="offline-overlay"><strong><?php echo esc_html($owner_user['display_name'] ?? $owner_login); ?></strong><small>Aktuell offline · Kanal öffnen →</small></span>
          </a>
        <?php else: ?>
          <div class="screen-content">
            <?php if (!empty($owner_user['profile_image_url'])): ?><img class="screen-avatar" src="<?php echo esc_url($owner_user['profile_image_url']); ?>" alt=""><?php else: ?><span class="screen-logo">C91</span><?php endif; ?>
            <strong><?php echo esc_html($owner_user['display_name'] ?? $owner_login); ?></strong>
            <small>Aktuell offline · Schau später wieder vorbei!</small>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-meta">
        <?php if ($owner['is_live']): ?>
          <span><?php echo esc_html($owner_stream['game_name'] ?: 'Live auf Twitch'); ?> · <?php echo number_format_i18n((int)($owner_stream['viewer_count'] ?? 0)); ?> Zuschauer</span>
        <?php else: ?>
          <span><?php echo esc_html($owner_user['description'] ?? 'Gaming • Just Chatting • Community'); ?></span>
        <?php endif; ?>
        <span>→</span>
      </div>
    </div>
  </div>
</section>

<?php
$home_share_image_id = (int) get_theme_mod('c91_home_share_image', 0);
if ($home_share_image_id):
?>
<section class="home-share-visual" aria-label="comiker91">
  <div class="shell">
    <a class="home-share-visual-frame home-share-visual-link"
       href="<?php echo esc_url(get_theme_mod('c91_twitch_url','https://www.twitch.tv/comiker91')); ?>"
       target="_blank"
       rel="noopener noreferrer"
       aria-label="comiker91 auf Twitch öffnen">
      <?php echo wp_get_attachment_image($home_share_image_id, 'full', false, [
        'class' => 'home-share-visual-image',
        'loading' => 'eager',
        'fetchpriority' => 'high',
        'alt' => 'comiker91 – Gaming, Streams und Community'
      ]); ?>
    </a>
  </div>
</section>
<?php endif; ?>


<section class="section streamer-picks" id="streamer">
  <div class="shell">
    <div class="streamer-picks-head">
      <span class="eyebrow">COMIKER91 EMPFIEHLT</span>
      <h2>Streamer, die einen Blick wert sind</h2>
      <p>Eine kleine persönliche Auswahl von Streamern, die ich selbst gerne schaue und euch gerne weiterempfehle.</p>
    </div>
    <?php
      $q = new WP_Query(['post_type'=>'streamer','posts_per_page'=>4,'post_status'=>'publish','orderby'=>'menu_order date','order'=>'ASC']);
      $streamer_posts = $q->posts;
      $logins = array_map(fn($p) => c91_get_streamer_login($p->ID), $streamer_posts);
      $contexts = c91_get_twitch_context($logins);
      usort($streamer_posts, function($a,$b) use ($contexts) {
          $la = c91_get_streamer_login($a->ID); $lb = c91_get_streamer_login($b->ID);
          $alive = !empty($contexts[$la]['is_live']); $blive = !empty($contexts[$lb]['is_live']);
          if ($alive !== $blive) return $alive ? -1 : 1;
          return ($a->menu_order <=> $b->menu_order) ?: strcasecmp($a->post_title, $b->post_title);
      });
    ?>
    <div class="streamer-picks-list">
      <?php if($streamer_posts): foreach($streamer_posts as $post): setup_postdata($post);
        $login = c91_get_streamer_login();
        $ctx = $contexts[$login] ?? null;
        $user = $ctx['user'] ?? null;
        $live = !empty($ctx['is_live']);
        $display = !empty($user['display_name']) ? $user['display_name'] : get_the_title();
        $url = !empty($ctx['url']) ? $ctx['url'] : 'https://www.twitch.tv/'.rawurlencode($login);
      ?>
        <div class="streamer-pick <?php echo $live ? 'is-live' : ''; ?>">
          <a class="streamer-pick-avatar" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($display.' auf Twitch öffnen'); ?>">
            <?php if(!empty($user['profile_image_url'])): ?>
              <img src="<?php echo esc_url($user['profile_image_url']); ?>" alt="<?php echo esc_attr($display); ?>" loading="lazy">
            <?php else: ?>
              <span class="streamer-pick-fallback"><?php echo esc_html(mb_strtoupper(mb_substr($display,0,1))); ?></span>
            <?php endif; ?>
          </a>
          <a class="streamer-pick-name" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($display); ?></a>
          <a class="streamer-pick-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">Auf Twitch ansehen →</a>
          <?php if($live): ?>
            <a class="streamer-pick-live" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><span></span> LIVE – jetzt ansehen</a>
          <?php endif; ?>
        </div>
      <?php endforeach; wp_reset_postdata(); else: ?>
        <div class="empty-card">Meine Streamer-Empfehlungen folgen bald.</div>
      <?php endif; ?>
    </div>
  </div>
</section>


<section class="section home-intro-section">
  <div class="shell">
    <div class="home-section-head centered">
      <span class="eyebrow">WILLKOMMEN BEI COMIKER91</span>
      <h2>Streams, Gaming und ein bisschen Internet-Chaos.</h2>
      <p>comiker91 ist mein Platz für Twitch, Gaming, aktuelle Beiträge und Menschen aus der Community. Hier findest du nicht nur den Stream, sondern auch News, Empfehlungen und die Projekte, an denen ich nebenbei arbeite.</p>
    </div>

    <div class="home-topic-grid">
      <article class="home-topic-card">
        <span class="home-topic-icon" aria-hidden="true">▶</span>
        <h3>Streams & Gaming</h3>
        <p>Live auf Twitch, Games die gerade Spaß machen und alles, was im Stream sonst noch passiert.</p>
        <a href="<?php echo esc_url($owner['url']); ?>" target="_blank" rel="noopener">Twitch öffnen →</a>
      </article>
      <article class="home-topic-card">
        <span class="home-topic-icon" aria-hidden="true">✦</span>
        <h3>News & Beiträge</h3>
        <p>Updates rund um comiker91, Gaming, Streams und Themen, die mehr als einen kurzen Chat-Kommentar verdient haben.</p>
        <a href="<?php echo esc_url(home_url('/news/')); ?>">Zu den News →</a>
      </article>
      <article class="home-topic-card">
        <span class="home-topic-icon" aria-hidden="true">☺</span>
        <h3>Community</h3>
        <p>Streamer-Empfehlungen, gemeinsame Runden und ein Discord für alle, die gerne mitreden oder mitspielen möchten.</p>
        <?php if($discord = get_theme_mod('c91_discord_url')): ?><a href="<?php echo esc_url($discord); ?>" target="_blank" rel="noopener">Zum Discord →</a><?php endif; ?>
      </article>
    </div>
  </div>
</section>

<section class="section alt home-news-section">
  <div class="shell">
    <div class="home-news-heading">
      <div>
        <span class="eyebrow">AUS MEINEM FEED</span>
        <h2>Neueste News</h2>
        <p>Neue Beiträge, Updates und Geschichten rund um Gaming, Streams und comiker91.</p>
      </div>
      <a class="btn ghost" href="<?php echo esc_url(home_url('/news/')); ?>">Alle News →</a>
    </div>
    <div class="home-news-grid">
      <?php $n=new WP_Query(['post_type'=>'post','posts_per_page'=>4,'post_status'=>'publish']); if($n->have_posts()): while($n->have_posts()): $n->the_post(); ?>
        <article class="home-news-card">
          <a class="home-news-media" href="<?php the_permalink(); ?>">
            <?php if(has_post_thumbnail()): the_post_thumbnail('medium_large'); else: ?><span>NEWS</span><?php endif; ?>
          </a>
          <div class="home-news-body">
            <span class="meta"><?php echo esc_html(get_the_date('d.m.Y')); ?> • <?php echo esc_html(c91_reading_time()); ?> Min.</span>
            <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
            <p><?php echo esc_html(wp_trim_words(get_the_excerpt() ?: wp_strip_all_tags(get_the_content()), 18)); ?></p>
            <a class="home-news-more" href="<?php the_permalink(); ?>">Weiterlesen →</a>
          </div>
        </article>
      <?php endwhile; wp_reset_postdata(); else: ?>
        <div class="empty-card">Hier gibt es bald neue Beiträge.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section comitement-section">
  <div class="shell">
    <div class="comitement-feature">
      <div class="comitement-feature-copy">
        <span class="eyebrow">TEIL VON COMITEMENT</span>
        <h2>comiker91 ist ein Projekt von Comitement.</h2>
        <p><?php echo esc_html(get_theme_mod('c91_comitement_text','Comitement verbindet meine Websites, Inhalte und digitalen Projekte unter einem gemeinsamen Dach. comiker91 ist dabei der persönliche Bereich für Streams, Gaming und Community.')); ?></p>
        <p class="comitement-secondary">Neben comiker91 entstehen unter Comitement weitere Projekte mit unterschiedlichen Schwerpunkten – von Streaming-Technik bis zu ausführlichen Tests und Ratgebern.</p>
        <?php if($cu=get_theme_mod('c91_comitement_url')): ?><a class="btn primary" href="<?php echo esc_url($cu); ?>" target="_blank" rel="noopener">Mehr über Comitement →</a><?php endif; ?>
      </div>
      <div class="comitement-projects">
        <span class="comitement-projects-label">Weitere Projekte</span>
        <a class="comitement-project-card" href="https://casinotester.eu/" target="_blank" rel="noopener">
          <strong>CasinoTester.eu</strong><span>Tests, Ratgeber & Glücksspiel-News</span><b>↗</b>
        </a>
        <a class="comitement-project-card" href="https://streamtechnik.de/" target="_blank" rel="noopener">
          <strong>Streamtechnik.de</strong><span>Streaming-Hardware, Technik & Reviews</span><b>↗</b>
        </a>
        <a class="comitement-project-card" href="https://cashback-test.de/" target="_blank" rel="noopener">
          <strong>Cashback-Test.de</strong><span>Cashback-Anbieter im Vergleich</span><b>↗</b>
        </a>
        <a class="comitement-project-card" href="https://freecash-erfahrung.de/" target="_blank" rel="noopener">
          <strong>Freecash-Erfahrung.de</strong><span>Erfahrungen, Tests & Guides zu Freecash</span><b>↗</b>
        </a>
      </div>
    </div>
  </div>
</section>

<section class="section discord-collab-section">
  <div class="shell">
    <div class="discord-collab-card">
      <div class="discord-collab-icon" aria-hidden="true">#</div>
      <div class="discord-collab-copy">
        <span class="eyebrow">ZUSAMMEN SPIELEN?</span>
        <h2>Du möchtest mit comiker91 streamen oder spielen?</h2>
        <p>Du bist selbst Creator, hast eine Idee für einen gemeinsamen Stream oder möchtest einfach bei einer Runde dabei sein? Meld dich am besten direkt auf Discord.</p>
      </div>
      <?php if($discord = get_theme_mod('c91_discord_url')): ?>
        <a class="btn primary discord-cta" href="<?php echo esc_url($discord); ?>" target="_blank" rel="noopener noreferrer">Auf Discord melden →</a>
      <?php else: ?>
        <span class="discord-missing">Discord-Link folgt.</span>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php get_footer(); ?>
