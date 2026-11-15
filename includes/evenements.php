<?php

defined('ABSPATH') || exit;

/** Dates et horaires : une ligne par plage quand l’événement s’étend sur plusieurs jours. */
function crom_link_event_when(array $event): string
{
    $plages = (array)($event['plages'] ?? []);
    if (count($plages) <= 1) {
        $plage = $plages[0] ?? null;
        return (string)($event['date_label'] ?? '').($plage ? ' · '.$plage['debut'].'–'.$plage['fin'] : '');
    }
    return (string)($event['date_label'] ?? '');
}

function crom_link_event_badges(array $event): string
{
    $html = '<span class="badge badge-outline">'.esc_html($event['type'] ?? '').'</span>';
    if (! empty($event['en_cours'])) {
        $html .= ' <span class="badge badge-success">En cours</span>';
    }
    if (! empty($event['payant'])) {
        $html .= ' <span class="badge badge-warning">Payant</span>';
    }
    return $html;
}

function crom_link_event_paylink(array $event, string $label = 'Réserver sur HelloAsso', string $class = 'btn btn-primary'): string
{
    if (empty($event['lien_paiement'])) {
        return '';
    }
    return '<a class="'.esc_attr($class).'" href="'.esc_url($event['lien_paiement']).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
}

/** Fiche de l’événement en cours, sinon du prochain, ou de celui choisi. */
function crom_link_event_block(array $attributes): string
{
    if (($attributes['source'] ?? '') === 'futurs') {
        return crom_link_events_list($attributes);
    }
    $event = crom_link_event($attributes);
    if (is_wp_error($event)) {
        return crom_link_empty($event->get_error_message());
    }
    return crom_link_events_html($event ? [$event] : [], $attributes);
}

/** Liste des événements à venir (en cours inclus). */
function crom_link_events_list(array $attributes): string
{
    $data = crom_link_fetch('evenements', ['mode'=>'futurs', 'limite'=>min(50, max(1, (int)($attributes['limite'] ?? 6)))]);
    if (is_wp_error($data)) {
        return crom_link_empty($data->get_error_message());
    }
    return crom_link_events_html($data['items'], $attributes);
}

/** Présentation commune aux événements automatiques, choisis et aux listes. */
function crom_link_events_html(array $events, array $attributes): string
{
    $table = ($attributes['presentation'] ?? '') === 'liste';
    $title = trim((string)($attributes['titre'] ?? ''));
    ob_start(); ?>
    <section <?php echo crom_link_wrapper(); ?> aria-label="Événements">
        <?php if ($title !== '') { ?><h2 class="text-2xl font-bold mb-4"><?php echo esc_html($title); ?></h2><?php } ?>
        <?php if (! $events) { ?><p class="p-4 bg-base-200 rounded-box">Aucun événement disponible pour cette sélection.</p><?php } elseif ($table) { ?>
            <ul class="list bg-base-200 rounded-box shadow-sm">
                <?php foreach ($events as $event) { ?>
                    <li class="flex flex-wrap items-center gap-3 p-4">
                        <div class="min-w-0 flex-1 basis-48"><a class="font-semibold link-hover" href="<?php echo esc_url($event['url'] ?? ''); ?>"><?php echo esc_html($event['nom']); ?></a>
                            <div class="text-sm"><time datetime="<?php echo esc_attr($event['debut'] ?? ''); ?>"><?php echo esc_html(crom_link_event_when($event)); ?></time><?php if (! empty($event['lieu'])) { ?> · <?php echo esc_html($event['lieu']); } ?></div></div>
                        <div class="flex flex-wrap gap-1"><?php echo crom_link_event_badges($event); ?></div>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <div class="crom-cards-grid crom-events-grid">
                <?php foreach ($events as $event) { ?>
                    <article class="card card-side card-sm bg-base-200 shadow-sm overflow-hidden crom-event-card">
                        <?php if (! empty($event['image'])) { ?><figure><img class="w-full h-full object-cover" src="<?php echo esc_url($event['image']); ?>" alt="<?php echo esc_attr($event['nom']); ?>" loading="lazy"></figure><?php } ?>
                        <div class="card-body min-w-0 gap-2 p-4">
                            <div class="flex flex-wrap gap-2"><?php echo crom_link_event_badges($event); ?></div>
                            <h3 class="card-title text-lg line-clamp-2"><a class="link-hover" href="<?php echo esc_url($event['url'] ?? ''); ?>"><?php echo esc_html($event['nom']); ?></a></h3>
                            <p class="text-sm"><time datetime="<?php echo esc_attr($event['debut'] ?? ''); ?>"><?php echo esc_html(crom_link_event_when($event)); ?></time><?php if (! empty($event['lieu'])) { ?> · <?php echo esc_html($event['lieu']); } ?></p>
                            <?php if (! empty($attributes['description']) && ! empty($event['description'])) { ?><p class="text-sm line-clamp-2"><?php echo esc_html($event['description']); ?></p><?php } ?>
                            <div class="card-actions"><a class="btn btn-sm" href="<?php echo esc_url($event['url'] ?? ''); ?>">Voir sur CROM</a><?php echo crom_link_event_paylink($event, 'Réserver sur HelloAsso', 'btn btn-sm btn-primary'); ?></div>
                        </div>
                    </article>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
    <?php return ob_get_clean();
}

/** Parties liées à un événement : les cartes et présentations de crom/parties. */
function crom_link_event_sessions(array $attributes): string
{
    $attributes = crom_link_with_serie($attributes);
    if (is_wp_error($attributes)) {
        // Série sans prochaine date : seul le bloc événement l’annonce.
        return $attributes->get_error_code() === 'crom_serie' ? '' : crom_link_empty($attributes->get_error_message());
    }
    return crom_link_sessions(array_replace($attributes, ['mode'=>'evenement']));
}

function crom_link_event_games(array $attributes): string
{
    $attributes = crom_link_with_serie($attributes);
    if (is_wp_error($attributes)) {
        // Série sans prochaine date : seul le bloc événement l’annonce.
        return $attributes->get_error_code() === 'crom_serie' ? '' : crom_link_empty($attributes->get_error_message());
    }
    $ref = crom_link_event_ref((string)($attributes['source'] ?? 'prochain'), absint($attributes['evenementId'] ?? 0), true);
    if (is_wp_error($ref)) {
        return crom_link_empty($ref->get_error_message());
    }
    $data = crom_link_fetch('jeux', ['evenement'=>$ref ?: 'prochain_parties']);
    if (is_wp_error($data)) {
        return crom_link_empty($data->get_error_message());
    }
    $games = array_slice($data['items'], 0, min(48, max(1, (int)($attributes['limite'] ?? 12))));
    $title = trim((string)($attributes['titre'] ?? ''));
    if (! $games) {
        return crom_link_empty('Aucun jeu annoncé pour cet événement.');
    }
    ob_start(); ?>
    <section <?php echo crom_link_wrapper(); ?> aria-label="Jeux de l’événement">
        <?php if ($title !== '') { ?><h2 class="text-2xl font-bold mb-4"><?php echo esc_html($title); ?></h2><?php } ?>
        <div class="flex flex-wrap gap-4"><?php foreach ($games as $game) { echo crom_link_miniature_card($game, $attributes); } ?></div>
    </section>
    <?php return ob_get_clean();
}

function crom_link_event_button(array $attributes): string
{
    $event = crom_link_event($attributes);
    if (is_wp_error($event)) {
        // Série sans prochaine date : seul le bloc événement l’annonce.
        return $event->get_error_code() === 'crom_serie' ? '' : crom_link_empty($event->get_error_message());
    }
    $label = trim((string)($attributes['libelle'] ?? '')) ?: 'Réserver sur HelloAsso';
    $button = $event ? crom_link_event_paylink($event, $label, 'btn btn-primary btn-lg') : '';
    return $button === '' ? '' : '<div '.crom_link_wrapper().'>'.$button.'</div>';
}

/** Description publique formatée ; le HTML provient de CROM mais est filtré une seconde fois. */
function crom_link_event_text(array $attributes): string
{
    $event = crom_link_event($attributes);
    if (is_wp_error($event)) {
        // Série sans prochaine date : seul le bloc événement l’annonce.
        return $event->get_error_code() === 'crom_serie' ? '' : crom_link_empty($event->get_error_message());
    }
    if (! $event || trim((string)($event['description_html'] ?? '')) === '') {
        return '';
    }
    $title = ! empty($attributes['titre']) ? '<h2 class="text-2xl font-bold mb-2">'.esc_html($event['nom']).'</h2>' : '';
    return '<section '.crom_link_wrapper().' aria-label="Présentation de l’événement">'.$title.'<div class="crom-event-text">'.wp_kses_post($event['description_html']).'</div></section>';
}

/** Champs dynamiques sans conteneur, pour les paragraphes et titres WordPress. */
function crom_link_event_shortcode(array|string $attributes, ?string $content = null, string $tag = ''): string
{
    $attributes = shortcode_atts(['evenement'=>'', 'serie'=>'', 'defaut'=>''], (array)$attributes, $tag);
    $id = absint($attributes['evenement']);
    $event = crom_link_event(['source'=>$attributes['evenement'] === '' ? 'prochain' : 'selection', 'evenementId'=>$id, 'serie'=>$attributes['serie']]);
    if (is_wp_error($event) || ! $event) {
        return esc_html($attributes['defaut']);
    }

    $debut = date_create((string)($event['debut'] ?? ''));
    $fin = date_create((string)($event['fin'] ?? ''));
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    // Jour(s) et mois pour les encarts : « 28–29 » et « novembre 2026 ».
    $jour = $debut ? $debut->format('j').($fin && $fin->format('Y-m') === $debut->format('Y-m') && $fin->format('j') !== $debut->format('j') ? '–'.$fin->format('j') : '') : '';
    $moisAnnee = $debut ? $mois[(int)$debut->format('n') - 1].' '.$debut->format('Y') : '';
    return match ($tag) {
        'crom_evenement_nom' => esc_html($event['nom'] ?? ''),
        'crom_evenement_jour' => esc_html($jour),
        'crom_evenement_mois' => esc_html($moisAnnee),
        'crom_evenement_lieu' => esc_html($event['lieu'] ?? ''),
        'crom_evenement_url' => esc_url($event['url'] ?? ''),
        'crom_evenement_type' => esc_html($event['type'] ?? ''),
        'crom_evenement_resume' => esc_html(wp_trim_words(wp_strip_all_tags($event['description'] ?? ''), 32)),
        'crom_evenement_date' => esc_html($event['date_label'] ?? ''),
        'crom_evenement_description' => esc_html(wp_strip_all_tags($event['description_html'] ?? $event['description'] ?? '')),
        'crom_evenement_texte' => wp_kses_post($event['description_html'] ?? ''),
        default => '',
    };
}
