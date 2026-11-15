<?php

defined('ABSPATH') || exit;

add_action('admin_init', function (): void {
    register_setting('crom_link', 'crom_link_options', ['type'=>'array', 'sanitize_callback'=>static function ($input): array {
        $old = crom_link_options();
        if (! is_array($input)) {
            return $old;
        }
        $url = esc_url_raw(trim((string)($input['url'] ?? '')), ['http','https']);
        $parsed = wp_parse_url($url);
        $isPath = str_starts_with($url, '/') && ! str_starts_with($url, '//');
        if (! $url || ! is_array($parsed) || (! $isPath && (empty($parsed['host']) || ! isset($parsed['scheme']))) || str_contains($url, '\\') || (isset($parsed['user']) || isset($parsed['pass'])) || isset($parsed['query']) || isset($parsed['fragment'])) {
            add_settings_error('crom_link_options', 'crom_url', 'Indiquez l’URL racine de CROM, par exemple https://association.fr/crom ou /crom.');
            return $old;
        }
        return ['url'=>$url === '/' ? '/' : untrailingslashit($url), 'cache'=>min(300, max(15, absint($input['cache'] ?? 60))),
            'theme'=>in_array($input['theme'] ?? '', ['inherit','cupcake','light','dark'], true) ? $input['theme'] : 'inherit'];
    }]);
});
add_action('admin_menu', function (): void {
    add_options_page('Blocs CROM', 'Blocs CROM', 'manage_options', 'crom-link', 'crom_link_settings');
});
function crom_link_settings(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }
    wp_enqueue_style('crom-link');
    $options = crom_link_options();
    ?>
    <div class="wrap"><h1>Blocs CROM</h1><p>Connectez les blocs au catalogue et au calendrier public de votre site CROM.</p>
        <form method="post" action="options.php" class="crom-block" data-theme="light">
            <?php settings_fields('crom_link'); ?>
            <table class="form-table table"><tbody>
                <tr><th><label for="crom-url">Adresse de CROM</label></th><td><input class="input w-full" id="crom-url" name="crom_link_options[url]" type="text" required aria-describedby="crom-url-help" value="<?php echo esc_attr($options['url']); ?>"><p id="crom-url-help">URL complète (https://association.fr/crom) ou chemin sur ce domaine (/crom), sans /api.</p></td></tr>
                <tr><th><label for="crom-cache">Actualisation</label></th><td><input class="input" id="crom-cache" name="crom_link_options[cache]" type="number" min="15" max="300" value="<?php echo (int)$options['cache']; ?>"> secondes (15 à 300).</td></tr>
                <tr><th><label for="crom-theme">Apparence des blocs</label></th><td><select class="select" id="crom-theme" name="crom_link_options[theme]">
                    <?php foreach (['inherit'=>'Apparence du site (WindPress)', 'cupcake'=>'CROM — tons doux', 'light'=>'Clair', 'dark'=>'Sombre'] as $value=>$label) { ?><option value="<?php echo esc_attr($value); ?>" <?php selected($options['theme'], $value); ?>><?php echo esc_html($label); ?></option><?php } ?>
                </select></td></tr>
            </tbody></table>
            <?php submit_button('Enregistrer la connexion'); ?>
        </form>
        <?php $status = crom_link_fetch('jeux', ['page'=>1]); ?>
        <p><?php echo is_wp_error($status) ? 'Connexion indisponible. Vérifiez l’adresse de CROM et que sa version dispose de l’API publique.' : 'Connexion établie : '.(int)($status['total'] ?? 0).' jeux disponibles.'; ?></p>
        <p>Dans l’éditeur de pages, ajoutez les blocs de la catégorie CROM : Bannière de jeu, Jeu, Parties ou Événement. Le classement compte les parties déjà jouées, hors annulations, sur tout l’historique.</p>
        <?php // Aide à l’insertion des informations d’événement dans les textes WordPress. ?>
        <h2>Shortcodes d’événement</h2>
        <p>Insérez ces balises dans un paragraphe ou un titre : elles affichent l’événement en cours, sinon le prochain. Les textes reprennent l’apparence du bloc qui les contient. Sans événement, la balise n’affiche rien.</p>
        <div class="overflow-x-auto"><table class="table"><thead><tr><th>Balise</th><th>Information</th></tr></thead><tbody>
            <?php foreach ([
                'date'=>'Date ou période de l’événement',
                'description'=>'Description complète en texte simple, utilisable dans une phrase',
                'texte'=>'Texte public avec sa mise en forme ; à placer seul dans un bloc Shortcode',
            ] as $field=>$label) { ?><tr><td><code><?php echo esc_html('[crom_evenement_'.$field.']'); ?></code></td><td><?php echo esc_html($label); ?></td></tr><?php } ?>
        </tbody></table></div>
        <p>Exemple : <code>Rendez-vous [crom_evenement_date].</code></p>
        <p>Pour choisir un événement précis : <code>[crom_evenement_date evenement="42"]</code>. L’option <code>evenement</code> est disponible sur les trois balises.</p>
    </div>
    <?php
}
