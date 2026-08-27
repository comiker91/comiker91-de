<?php
get_header();
$posts = [];
if (have_posts()) { while(have_posts()) { the_post(); $posts[] = get_post(); } }
$logins = array_map(fn($p)=>c91_get_streamer_login($p->ID), $posts);
$contexts = c91_get_twitch_context($logins);
usort($posts, function($a,$b) use($contexts){
    $la=c91_get_streamer_login($a->ID); $lb=c91_get_streamer_login($b->ID);
    $alive=!empty($contexts[$la]['is_live']); $blive=!empty($contexts[$lb]['is_live']);
    if($alive!==$blive) return $alive?-1:1;
    return strcasecmp($a->post_title,$b->post_title);
});
?>
<section class="page-hero"><div class="shell"><span class="eyebrow">COMMUNITY</span><h1>Streamer entdecken</h1><p>Entdecke spannende Creator aus der Community und sieh direkt, wer gerade live ist.</p></div></section>
<section class="section"><div class="shell"><div class="card-grid streamer-grid">
<?php if($posts): foreach($posts as $post): setup_postdata($post); $login=c91_get_streamer_login(); $ctx=$contexts[$login]??null; $user=$ctx['user']??null; $stream=$ctx['stream']??null; $live=!empty($ctx['is_live']); ?>
<article class="streamer-card <?php echo $live?'is-live':''; ?>">
<a class="thumb" href="<?php echo esc_url($ctx['url']??'https://www.twitch.tv/'.$login); ?>" target="_blank" rel="noopener">
<?php if($live && ($thumb=c91_twitch_thumbnail_url($stream,640,360))): ?><img src="<?php echo esc_url($thumb); ?>" alt="">
<?php elseif(!empty($user['profile_image_url'])): ?><div class="profile-thumb"><img src="<?php echo esc_url($user['profile_image_url']); ?>" alt=""></div>
<?php else: ?><div class="placeholder"><?php echo esc_html(mb_substr(get_the_title(),0,1)); ?></div><?php endif; ?>
<?php if($live): ?><span class="thumb-live">LIVE</span><?php endif; ?>
</a>
<div class="streamer-body"><span class="status-dot <?php echo $live?'live':'offline'; ?>"></span><h2><a href="<?php echo esc_url($ctx['url']??'https://www.twitch.tv/'.$login); ?>" target="_blank" rel="noopener"><?php echo esc_html($user['display_name']??get_the_title()); ?></a></h2><p><?php echo $live?esc_html(($stream['game_name']?:'Live').' · '.number_format_i18n((int)($stream['viewer_count']??0)).' Zuschauer'):esc_html(wp_trim_words($user['description']??get_the_excerpt()?:'Twitch Creator',20)); ?></p><a class="text-link" href="<?php echo esc_url($ctx['url']??'https://www.twitch.tv/'.$login); ?>" target="_blank" rel="noopener"><?php echo $live?'Stream ansehen →':'Twitch öffnen →'; ?></a></div>
</article>
<?php endforeach; wp_reset_postdata(); else: ?><p>Noch keine Streamer vorhanden.</p><?php endif; ?>
</div><div class="pagination"><?php the_posts_pagination(); ?></div></div></section>
<?php get_footer(); ?>
