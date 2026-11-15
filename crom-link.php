<?php
/**
 * Plugin Name: CROM — Jeux et parties
 * Description: Bannières, jeux classés, prochaines parties et événements de CROM dans l’éditeur de blocs.
 * Version: 1.7.3
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: windpress
 * Text Domain: crom-link
 */

defined('ABSPATH') || exit;
define('CROM_LINK_FILE', __FILE__);
require_once __DIR__.'/includes/client.php';
require_once __DIR__.'/includes/render.php';
require_once __DIR__.'/includes/evenements.php';
require_once __DIR__.'/includes/settings.php';
require_once __DIR__.'/includes/page-templates.php';

// Mises à jour automatiques depuis les releases GitHub du plugin.
require_once __DIR__.'/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker('https://github.com/JRII972/crom-link/', CROM_LINK_FILE, 'crom-link')
    ->getVcsApi()->enableReleaseAssets();

// Keep the site presentation available while the WindPress CSS cache is absent.
add_action('wp_enqueue_scripts', static function (): void {
    $themePath = __DIR__.'/assets/site-theme.css';
    wp_enqueue_style('crom-site-theme', plugin_dir_url(CROM_LINK_FILE).'assets/site-theme.css', [], (string) filemtime($themePath));

    $uploads = wp_upload_dir();
    foreach (['accueil-lbdr-windpress.css', 'lbdr-pages-windpress.css'] as $stylesheet) {
        $path = $uploads['basedir'].'/windpress/data/'.$stylesheet;
        if (! is_readable($path)) {
            continue;
        }
        wp_enqueue_style('crom-site-'.str_replace('.css', '', $stylesheet), $uploads['baseurl'].'/windpress/data/'.$stylesheet, ['crom-site-theme'], (string) filemtime($path));
    }
});

add_action('init', function (): void {
    $base = plugin_dir_url(CROM_LINK_FILE).'assets/';
    wp_register_script('crom-link-editor', $base.'editor.js', ['wp-blocks', 'wp-data', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-api-fetch'], '1.7.3', true);
    wp_register_script('crom-link-front', $base.'front.js', [], '1.7.3', true);
    wp_register_style('crom-link', $base.'style.css', [], '1.7.3');
    wp_register_style('crom-link-editor', $base.'editor.css', [], '1.7.3');
    $common = ['api_version'=>3, 'editor_script'=>'crom-link-editor', 'editor_style'=>'crom-link-editor', 'style'=>'crom-link', 'supports'=>['html'=>false, 'align'=>['wide','full']]];
    $schema = static fn ($type, $default) => ['type'=>$type, 'default'=>$default];
    register_block_type('crom/cover', $common + ['render_callback'=>'crom_link_cover', 'view_script'=>'crom-link-front', 'attributes'=>[
        'mode'=>$schema('string','jeu'), 'jeuId'=>$schema('number',0), 'titre'=>$schema('string',''), 'texte'=>$schema('string',''),
        'autoplay'=>$schema('boolean',true), 'delay'=>$schema('number',6), 'hauteur'=>$schema('number',400), 'typeJeu'=>$schema('string',''), 'genres'=>$schema('array',[]),
        'presentation'=>$schema('string','plein'), 'limite'=>$schema('number',6), 'source'=>$schema('string','prochain'), 'evenementId'=>$schema('number',0),
    ]]);
    register_block_type('crom/jeu', $common + ['render_callback'=>'crom_link_game', 'attributes'=>[
        'mode'=>$schema('string','choisi'), 'jeuId'=>$schema('number',0), 'rang'=>$schema('number',1),
    ]]);
    register_block_type('crom/parties', $common + ['render_callback'=>'crom_link_sessions', 'attributes'=>[
        'mode'=>$schema('string','prochaines'), 'lien'=>$schema('string',''), 'presentation'=>$schema('string','carte'),
        'limite'=>$schema('number',6), 'ouvertes'=>$schema('boolean',false), 'hauteurCarte'=>$schema('number',320),
        'evenement'=>$schema('string',''), 'evenementId'=>$schema('number',0), 'titre'=>$schema('string',''),
    ]]);
    register_block_type('crom/jeu-miniature', $common + ['render_callback'=>'crom_link_game_miniature', 'view_script'=>'crom-link-front', 'attributes'=>[
        'mode'=>$schema('string','choisi'), 'jeuId'=>$schema('number',0), 'rang'=>$schema('number',1),
        'imageCote'=>$schema('string','gauche'),
        'typeJeu'=>$schema('string',''), 'genres'=>['type'=>'array', 'items'=>['type'=>'integer'], 'default'=>[]],
        'autoplay'=>$schema('boolean',true), 'delay'=>$schema('number',6),
    ]]);
    register_block_type('crom/parties-compactes', $common + ['render_callback'=>'crom_link_sessions_compact', 'view_script'=>'crom-link-front', 'attributes'=>[
        'mode'=>$schema('string','prochaines'), 'lien'=>$schema('string',''), 'limite'=>$schema('number',6), 'ouvertes'=>$schema('boolean',false),
        'evenement'=>$schema('string',''), 'evenementId'=>$schema('number',0), 'titre'=>$schema('string','')
    ]]);
    // Blocs événements : « source » vaut prochain ou selection (avec evenementId).
    $event = ['source'=>$schema('string','prochain'), 'evenementId'=>$schema('number',0), 'serie'=>$schema('string','')];
    $eventDisplay = $event + ['description'=>$schema('boolean',true), 'presentation'=>$schema('string','carte'), 'limite'=>$schema('number',6), 'titre'=>$schema('string','')];
    register_block_type('crom/evenement', $common + ['render_callback'=>'crom_link_event_block', 'attributes'=>$eventDisplay]);
    // Les blocs déjà enregistrés deviennent des fiches ; une seule entrée est proposée à l’insertion.
    foreach (['crom/evenement-banniere', 'crom/evenement-en-cours'] as $name) {
        register_block_type($name, array_replace($common, ['supports'=>['html'=>false, 'inserter'=>false]]) + [
            'render_callback'=>'crom_link_event_block', 'attributes'=>$eventDisplay,
        ]);
    }
    register_block_type('crom/evenements-liste', array_replace($common, ['supports'=>['html'=>false, 'inserter'=>false]]) + ['render_callback'=>'crom_link_events_list', 'attributes'=>[
        'limite'=>$schema('number',6), 'presentation'=>$schema('string','carte'), 'titre'=>$schema('string',''),
    ]]);
    register_block_type('crom/evenement-parties', $common + ['render_callback'=>'crom_link_event_sessions', 'attributes'=>$event + [
        'presentation'=>$schema('string','carte'), 'limite'=>$schema('number',12), 'ouvertes'=>$schema('boolean',false), 'hauteurCarte'=>$schema('number',320), 'titre'=>$schema('string',''),
    ]]);
    register_block_type('crom/evenement-jeux', $common + ['render_callback'=>'crom_link_event_games', 'attributes'=>$event + [
        'limite'=>$schema('number',12), 'titre'=>$schema('string',''), 'imageCote'=>$schema('string','gauche'),
    ]]);
    register_block_type('crom/evenement-helloasso', $common + ['render_callback'=>'crom_link_event_button', 'attributes'=>$event + ['libelle'=>$schema('string','Réserver sur HelloAsso')]]);
    register_block_type('crom/evenement-texte', $common + ['render_callback'=>'crom_link_event_text', 'attributes'=>$event + ['titre'=>$schema('boolean',false)]]);
    // Sans identifiant : événement en cours, sinon prochain. Exemple : [crom_evenement_date evenement="42"].
    foreach (['nom', 'date', 'jour', 'mois', 'lieu', 'url', 'type', 'resume', 'description', 'texte'] as $field) {
        add_shortcode('crom_evenement_'.$field, 'crom_link_event_shortcode');
    }
});
add_filter('block_categories_all', function (array $categories): array {
    $categories[] = ['slug'=>'crom', 'title'=>'CROM'];
    return $categories;
});
add_action('rest_api_init', function (): void {
    register_rest_route('crom-link/v1', '/jeux', ['methods'=>'GET', 'permission_callback'=>static fn () => current_user_can('edit_posts'),
        'callback'=>static function () {
            $games = crom_link_catalogue();
            return is_wp_error($games) ? new WP_Error('crom_unavailable', 'Le catalogue CROM est indisponible.', ['status'=>503]) : array_map(static fn ($g) => ['id'=>(int)$g['id'], 'nom'=>(string)$g['nom'], 'type_code'=>$g['type_code'] ?? '', 'genres'=>$g['genres'] ?? []], $games);
        }]);
    register_rest_route('crom-link/v1', '/evenements', ['methods'=>'GET', 'permission_callback'=>static fn () => current_user_can('edit_posts'),
        'callback'=>static function () {
            $events = crom_link_events_catalogue();
            return is_wp_error($events) ? new WP_Error('crom_unavailable', 'Les événements CROM sont indisponibles.', ['status'=>503]) : array_map(static fn ($e) => ['id'=>(int)$e['id'], 'nom'=>(string)$e['nom'], 'date_label'=>(string)($e['date_label'] ?? ''), 'type_code'=>(string)($e['type_code'] ?? '')], $events);
        }]);
});

// Les classes des rendus dynamiques sont aussi présentes dans le cache compilé WindPress.
add_filter('f!windpress/core/cache:compile.providers', function (array $providers): array {
    $providers[] = ['id'=>'crom-link', 'name'=>'Blocs CROM', 'description'=>'Classes des bannières, jeux, parties et événements CROM.', 'enabled'=>true, 'type'=>'plugin',
        'callback'=>static function (): array {
            $contents = [];
            foreach (['includes/render.php', 'includes/evenements.php', 'includes/settings.php'] as $file) {
                $contents[] = ['name'=>'crom-link/'.$file, 'content'=>file_get_contents(__DIR__.'/'.$file)];
            }
            return $contents;
        }];
    return $providers;
});
