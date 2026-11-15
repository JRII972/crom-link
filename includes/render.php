<?php

defined('ABSPATH') || exit;

function crom_link_wrapper(array $extra = []): string
{
    $theme = crom_link_options()['theme'];
    if (! in_array($theme, ['inherit','cupcake','light','dark'], true)) {
        $theme = 'inherit';
    }
    return get_block_wrapper_attributes(['class'=>'crom-block'] + ($theme === 'inherit' ? [] : ['data-theme'=>$theme]) + $extra);
}

function crom_link_empty(string $message): string
{
    return '<div '.crom_link_wrapper().'><p class="p-4 bg-base-200 rounded-box">'.esc_html($message).'</p></div>';
}

function crom_link_miniature_card(array $game, array $attributes): string
{
    ob_start(); ?>
    <a class="card card-side card-sm bg-base-200 shadow-sm overflow-hidden crom-clickable-card crom-game-miniature <?php echo ($attributes['imageCote'] ?? '') === 'droite' ? 'crom-image-right' : ''; ?>" href="<?php echo esc_url($game['url'] ?? ''); ?>" aria-label="Découvrir <?php echo esc_attr($game['nom']); ?>">
        <?php if (! empty($game['image'])) { ?><figure><img src="<?php echo esc_url($game['image']); ?>" alt="<?php echo esc_attr($game['nom']); ?>" loading="lazy"></figure><?php } ?>
        <div class="card-body min-w-0 p-3 gap-1">
            <h3 class="card-title text-sm leading-tight line-clamp-2"><?php echo esc_html($game['nom']); ?></h3>
            <p class="text-xs line-clamp-4"><?php echo esc_html($game['description'] ?? ''); ?></p>
        </div>
    </a>
    <?php return ob_get_clean();
}

function crom_link_game_miniature(array $attributes): string
{
    if (($attributes['mode'] ?? '') !== 'toutes') {
        return crom_link_game($attributes, true);
    }
    $games = crom_link_catalogue();
    if (is_wp_error($games)) {
        return crom_link_empty($games->get_error_message());
    }
    $genres = array_map('absint', (array)($attributes['genres'] ?? []));
    $type = (string)($attributes['typeJeu'] ?? '');
    $games = array_values(array_filter($games, static function (array $game) use ($genres, $type): bool {
        return ($type === '' || ($game['type_code'] ?? '') === $type)
            && (! $genres || array_intersect($genres, array_column($game['genres'] ?? [], 'id')));
    }));
    if (! $games) {
        return crom_link_empty('Aucun jeu disponible pour ces filtres.');
    }
    $uid = wp_unique_id('crom-miniature-carousel-');
    ob_start(); ?>
    <section <?php echo crom_link_wrapper(['data-crom-miniature'=>'true', 'data-crom-carousel'=>'true', 'data-autoplay'=>! empty($attributes['autoplay']) ? 'true' : 'false', 'data-delay'=>(string)min(60, max(3, (int)($attributes['delay'] ?? 6)))]); ?> aria-label="Jeux CROM">
        <div class="carousel w-full rounded-box" id="<?php echo esc_attr($uid); ?>">
            <?php foreach ($games as $game) { ?><div class="carousel-item w-full"><?php echo crom_link_miniature_card($game, $attributes); ?></div><?php } ?>
        </div>
        <?php if (count($games) > 1) { ?>
            <div class="flex flex-wrap items-center justify-center gap-2 mt-2 text-xs">
                <button class="btn btn-xs" type="button" data-crom-prev aria-controls="<?php echo esc_attr($uid); ?>" aria-label="Jeu précédent">←</button>
                <span data-crom-position>1 / <?php echo count($games); ?></span>
                <button class="btn btn-xs" type="button" data-crom-next aria-controls="<?php echo esc_attr($uid); ?>" aria-label="Jeu suivant">→</button>
                <?php if (! empty($attributes['autoplay'])) { ?><button class="btn btn-xs" type="button" data-crom-pause aria-controls="<?php echo esc_attr($uid); ?>" aria-pressed="false">Pause</button><?php } ?>
            </div>
        <?php } ?>
    </section>
    <?php return ob_get_clean();
}

function crom_link_game(array $attributes, bool $miniature = false): string
{
    $ranked = ($attributes['mode'] ?? '') === 'classement';
    $query = $ranked ? ['rang'=>min(3,max(1,(int)($attributes['rang'] ?? 1)))] : ['id'=>absint($attributes['jeuId'] ?? 0)];
    if (! $ranked && ! $query['id']) {
        return crom_link_empty('Choisissez un jeu dans les réglages du bloc.');
    }
    $data = crom_link_fetch('jeux', $query);
    if (is_wp_error($data)) {
        return crom_link_empty($data->get_error_message());
    }
    $game = $data['items'][0] ?? null;
    if (! $game) {
        return crom_link_empty('Aucun jeu disponible pour cette sélection.');
    }
    if ($miniature) {
        return '<section '.crom_link_wrapper(['data-crom-miniature'=>'true']).'>'.crom_link_miniature_card($game, $attributes).'</section>';
    }
    ob_start(); ?>
    <section <?php echo crom_link_wrapper(); ?>>
        <a class="card lg:card-side bg-base-200 shadow crom-clickable-card" href="<?php echo esc_url($game['url'] ?? ''); ?>" aria-label="Découvrir <?php echo esc_attr($game['nom']); ?>">
            <?php if (! empty($game['image'])) { ?><figure class="lg:w-1/3 shrink-0"><img class="w-full max-h-80 object-contain" src="<?php echo esc_url($game['image']); ?>" alt="<?php echo esc_attr($game['nom']); ?>" loading="lazy"></figure><?php } ?>
            <div class="card-body">
                <span class="badge badge-outline"><?php echo esc_html($game['type'] ?? ''); ?></span>
                <?php if ($ranked) { ?><span class="badge badge-outline"><?php echo (int)$query['rang']; ?><?php echo $query['rang']===1 ? 'er' : 'e'; ?> du classement · <?php echo (int)($game['nombre_parties'] ?? 0); ?> parties jouées</span><?php } ?>
                <h2 class="card-title text-3xl"><?php echo esc_html($game['nom']); ?></h2>
                <div class="prose max-w-none break-words"><?php echo esc_html($game['description'] ?? ''); ?></div>
            </div>
        </a>
    </section>
    <?php return ob_get_clean();
}

/** Une diapositive de bannière à partir d’un jeu ou d’une partie de l’API. */
function crom_link_cover_slide(array $item, bool $session): array
{
    if ($session) {
        return ['badges'=>array_values(array_filter([$item['type'] ?? '', ! empty($item['reserve_aux_majeurs']) ? '−18' : ''])), 'title'=>(string)$item['nom'],
            'date'=>trim(($item['date_label'] ?? '').' · '.($item['debut'] ?? '')), 'meta'=>trim(($item['jeu'] ?? '').(! empty($item['jeu']) && ! empty($item['mj']) ? ' · ' : '').($item['mj'] ?? '')),
            'description'=>(string)($item['description'] ?? ''), 'image'=>$item['image'] ?? '', 'banner'=>$item['image'] ?? '', 'url'=>$item['url'] ?? '', 'cta'=>'Voir la partie'];
    }
    return ['badges'=>array_slice(array_column($item['genres'] ?? [], 'nom'), 0, 3) ?: array_filter([$item['type'] ?? '']), 'title'=>(string)$item['nom'], 'date'=>'', 'meta'=>(string)($item['type'] ?? ''),
        'description'=>(string)($item['description'] ?? ''), 'image'=>$item['image'] ?? ($item['banniere'] ?? ''), 'banner'=>$item['banniere'] ?? '', 'url'=>$item['url'] ?? '', 'cta'=>'Découvrir ce jeu'];
}

function crom_link_cover(array $attributes): string
{
    $mode = (string)($attributes['mode'] ?? 'jeu');
    $gallery = ($attributes['presentation'] ?? '') === 'galerie';
    $sessions = in_array($mode, ['parties','evenement'], true);
    $slider = $mode === 'toutes' || $sessions;
    $slides = [];
    if ($sessions) {
        // Visuels des jeux des prochaines parties ouvertes, ou des parties d’un événement.
        $query = ['mode'=>'prochaines', 'limite'=>min(12, max(1, (int)($attributes['limite'] ?? 6))), 'ouvertes'=>1];
        if ($mode === 'evenement') {
            $ref = crom_link_event_ref((string)($attributes['source'] ?? 'prochain'), absint($attributes['evenementId'] ?? 0), true);
            if (is_wp_error($ref)) {
                return crom_link_empty($ref->get_error_message());
            }
            $query = ['mode'=>'evenement', 'evenement'=>$ref ?: 'prochain_parties', 'limite'=>$query['limite']];
        }
        $data = crom_link_fetch('parties', $query);
        if (is_wp_error($data)) {
            return crom_link_empty($data->get_error_message());
        }
        foreach ($data['items'] as $item) {
            if (! empty($item['image'])) {
                $slides[] = crom_link_cover_slide($item, true);
            }
        }
    } else {
        if ($slider) {
            $games = crom_link_catalogue();
            if (is_wp_error($games)) {
                return crom_link_empty($games->get_error_message());
            }
            $type = (string)($attributes['typeJeu'] ?? '');
            $genres = array_map('absint', (array)($attributes['genres'] ?? []));
            $games = array_values(array_filter($games, static function ($game) use ($type, $genres, $gallery): bool {
                return (! empty($game['banniere']) || ($gallery && ! empty($game['image'])))
                    && ($type === '' || ($game['type_code'] ?? '') === $type)
                    && (! $genres || array_intersect($genres, array_column($game['genres'] ?? [], 'id')));
            }));
        } else {
            $id = absint($attributes['jeuId'] ?? 0);
            if (! $id) {
                return crom_link_empty('Choisissez un jeu pour sa bannière.');
            }
            $data = crom_link_fetch('jeux', ['id'=>$id] + ($gallery ? [] : ['bannieres'=>1]));
            if (is_wp_error($data)) {
                return crom_link_empty($data->get_error_message());
            }
            $games = $data['items'];
        }
        foreach ($games as $game) {
            $slides[] = crom_link_cover_slide($game, false);
        }
    }
    if (! $gallery) {
        $slides = array_values(array_filter($slides, static fn (array $slide): bool => $slide['banner'] !== ''));
    }
    if (! $slides) {
        return crom_link_empty('Aucune bannière disponible pour cette sélection.');
    }
    $uid = wp_unique_id('crom-cover-');
    $height = min(800,max(200,(int)($attributes['hauteur'] ?? 400)));
    $custom = trim((string)($attributes['titre'] ?? ''));
    $text = trim((string)($attributes['texte'] ?? ''));
    $minimal = $custom === '' && $text === '';
    $count = count($slides);
    ob_start(); ?>
    <section <?php echo crom_link_wrapper(['data-crom-carousel'=>$slider ? 'true' : 'false', 'data-autoplay'=>! empty($attributes['autoplay']) ? 'true' : 'false', 'data-delay'=>(string)min(20,max(3,(int)($attributes['delay'] ?? 6))), 'style'=>'--crom-cover-height:'.$height.'px']); ?> aria-label="Bannières des jeux CROM">
        <div id="<?php echo esc_attr($uid); ?>" class="carousel w-full rounded-box">
            <?php foreach ($slides as $index=>$slide) {
                $label = esc_attr('Découvrir '.$slide['title'].' — '.($index+1).' sur '.$count);
                if ($gallery) { ?>
                <a class="carousel-item w-full crom-cover-slide crom-cover-gallery crom-clickable-card bg-base-200 text-base-content" href="<?php echo esc_url($slide['url']); ?>" aria-label="<?php echo $label; ?>">
                    <div class="flex w-full flex-col-reverse md:flex-row">
                        <div class="flex min-w-0 flex-1 flex-col justify-center gap-2 p-6 md:w-3/5 md:p-10">
                            <div class="flex flex-wrap items-center gap-2"><?php foreach ($slide['badges'] as $badge) { ?><span class="badge badge-secondary badge-sm"><?php echo esc_html($badge); ?></span><?php } ?></div>
                            <?php if ($custom !== '') { ?><h2 class="text-xl font-bold text-primary md:text-3xl"><?php echo esc_html($custom); ?></h2><?php } ?>
                            <?php if ($slide['date'] !== '') { ?><p class="text-sm"><?php echo esc_html($slide['date']); ?></p><?php } ?>
                            <?php if ($slide['meta'] !== '') { ?><p class="text-sm font-semibold"><?php echo esc_html($slide['meta']); ?></p><?php } ?>
                            <?php if ($text !== '') { ?><p><?php echo esc_html($text); ?></p><?php } elseif ($slide['description'] !== '') { ?><p class="line-clamp-3 text-xs text-base-content/80 md:text-base"><?php echo esc_html($slide['description']); ?></p><?php } ?>
                        </div>
                        <?php if ($slide['image'] !== '') { ?><div class="relative h-36 shrink-0 bg-base-300 md:h-auto md:w-2/5"><img class="absolute inset-0 h-full w-full object-cover" src="<?php echo esc_url($slide['image']); ?>" alt="<?php echo esc_attr($slide['title']); ?>" loading="<?php echo $index===0 ? 'eager' : 'lazy'; ?>"></div><?php } ?>
                    </div>
                </a>
            <?php } else { ?>
                <a class="carousel-item w-full card image-full crom-cover-slide crom-clickable-card <?php echo $minimal ? 'crom-cover-minimal' : ''; ?>" href="<?php echo esc_url($slide['url']); ?>" aria-label="<?php echo $label; ?>">
                    <figure><img class="w-full h-full object-cover" src="<?php echo esc_url($slide['banner']); ?>" alt="Bannière de <?php echo esc_attr($slide['title']); ?>" loading="<?php echo $index===0 ? 'eager' : 'lazy'; ?>"></figure>
                    <div class="card-body <?php echo $minimal ? 'justify-end items-start' : 'justify-center'; ?>">
                        <?php if ($custom !== '') { ?><h2 class="card-title text-3xl"><?php echo esc_html($custom); ?></h2><?php } ?>
                        <?php if ($text !== '') { ?><p><?php echo esc_html($text); ?></p><?php } ?>
                        <?php if (! $minimal) { ?><div class="card-actions"><span class="btn"><?php echo esc_html($slide['cta']); ?></span></div><?php } ?>
                    </div>
                </a>
            <?php } } ?>
        </div>
        <?php if ($slider && $count>1) { ?>
            <div class="flex flex-wrap items-center justify-center gap-3 mt-3">
                <button class="btn btn-sm" type="button" data-crom-prev aria-controls="<?php echo esc_attr($uid); ?>" aria-label="Bannière précédente">←</button>
                <span data-crom-position>1 / <?php echo $count; ?></span>
                <button class="btn btn-sm" type="button" data-crom-next aria-controls="<?php echo esc_attr($uid); ?>" aria-label="Bannière suivante">→</button>
                <?php if (! empty($attributes['autoplay'])) { ?><button class="btn btn-sm" type="button" data-crom-pause aria-controls="<?php echo esc_attr($uid); ?>" aria-pressed="false">Pause</button><?php } ?>
            </div>
        <?php } ?>
    </section>
    <?php return ob_get_clean();
}

function crom_link_sessions_compact(array $attributes): string
{
    return crom_link_sessions(array_replace($attributes, ['presentation'=>'compacte']));
}

function crom_link_sessions(array $attributes): string
{
    $mode = in_array($attributes['mode'] ?? '', ['prochaines','weekend','lien','evenement'], true) ? $attributes['mode'] : 'prochaines';
    $query = ['mode'=>$mode, 'limite'=>min(50,max(1,(int)($attributes['limite'] ?? 6))), 'ouvertes'=>$mode==='prochaines' || ! empty($attributes['ouvertes']) ? 1 : 0];
    // Filtre optionnel : seules les parties liées à l’événement choisi (le prochain à parties, ou un événement précis).
    $choice = $mode === 'evenement' ? (string)($attributes['source'] ?? 'prochain') : (string)($attributes['evenement'] ?? '');
    $event = crom_link_event_ref($choice, absint($attributes['evenementId'] ?? 0), true);
    if (is_wp_error($event)) {
        return crom_link_empty($event->get_error_message());
    }
    if ($event !== '') {
        $query['evenement'] = $event;
    } elseif ($mode === 'evenement') {
        return crom_link_empty('Choisissez un événement dans les réglages du bloc.');
    }
    if ($mode==='lien') {
        $reference = crom_link_reference((string)($attributes['lien'] ?? ''));
        if (is_wp_error($reference)) {
            return crom_link_empty($reference->get_error_message());
        }
        $query = array_replace($query,$reference);
    }
    $data = crom_link_fetch('parties',$query);
    if (is_wp_error($data)) {
        return crom_link_empty($data->get_error_message());
    }
    $items = $data['items'];
    $table = ($attributes['presentation'] ?? '')==='table';
    $wide = ($attributes['presentation'] ?? '')==='grande';
    $compact = ($attributes['presentation'] ?? '')==='compacte';
    $height = (int)($attributes['hauteurCarte'] ?? 320);
    if (! in_array($height, [280,320,400,480], true)) {
        $height = 320;
    }
    $title = trim((string)($attributes['titre'] ?? ''));
    ob_start(); ?>
    <section <?php echo crom_link_wrapper($compact ? ['data-crom-compact'=>'true'] : []); ?>>
        <?php if ($title !== '') { ?><h2 class="text-2xl font-bold mb-4"><?php echo esc_html($title); ?></h2><?php } ?>
        <?php if (! $items) { ?><p>Aucune partie disponible pour cette sélection.</p><?php } elseif ($table) { ?>
            <div class="overflow-x-auto"><table class="table table-zebra"><?php if ($title !== '') { ?><caption class="sr-only"><?php echo esc_html($title); ?></caption><?php } ?>
                <thead><tr><th scope="col">Partie</th><th scope="col">Date et horaires</th><th scope="col">MJ</th><th scope="col">Lieu</th><th scope="col">Joueurs</th><th scope="col">Détails</th></tr></thead>
                <tbody><?php foreach ($items as $item) { ?><tr>
                    <th scope="row"><?php echo esc_html($item['nom']); ?><?php if (! empty($item['reserve_aux_majeurs'])) { ?><span class="badge badge-warning badge-outline" title="Réservé aux personnes majeures">+18</span><?php } ?><span class="block text-sm font-normal"><?php echo esc_html($item['jeu'] ?? ''); ?></span></th>
                    <td><?php echo esc_html($item['date_label'].' · '.$item['debut'].'–'.$item['fin']); ?></td><td><?php echo esc_html($item['mj'] ?? ''); ?></td><td><?php echo esc_html($item['lieu_court'] ?? $item['lieu'] ?? ''); ?></td>
                    <td><?php echo esc_html(crom_link_players($item)); ?></td><td><a class="btn btn-sm" href="<?php echo esc_url($item['url']); ?>" aria-label="Voir <?php echo esc_attr($item['nom']); ?>">Voir</a></td>
                </tr><?php } ?></tbody>
            </table></div>
        <?php } elseif ($compact) { ?>
            <div class="flex flex-wrap gap-4">
                <?php foreach ($items as $item) { echo crom_link_session_compact_card($item); } ?>
            </div>
        <?php } else { ?>
            <?php
            $groups = [];
            foreach ($items as $item) {
                $groups[in_array($mode, ['weekend','evenement'], true) ? $item['date_label'] : ''][] = $item;
            }
            foreach ($groups as $day => $sessions) {
                if ($day !== '') { ?><h3 class="font-semibold text-xl mt-6 mb-3"><?php echo esc_html($day); ?></h3><?php } ?>
            <div class="<?php echo $wide ? 'grid gap-4 min-w-0' : 'crom-cards-grid'; ?>">
                <?php foreach ($sessions as $item) { ?>
                    <a class="card bg-base-200 shadow-sm overflow-hidden crom-clickable-card <?php echo $wide ? 'sm:card-side crom-partie-wide' : ''; ?>" href="<?php echo esc_url($item['url']); ?>" aria-label="Voir la partie <?php echo esc_attr($item['nom']); ?>"<?php if ($wide) { ?> style="--crom-partie-height:<?php echo $height; ?>px"<?php } ?>>
                        <?php if (! empty($item['image'])) { ?><figure class="<?php echo $wide ? 'sm:w-1/3 shrink-0' : ''; ?>"><img class="w-full h-52 object-cover" src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['jeu'] ?: $item['nom']); ?>" loading="lazy"></figure><?php } ?>
                        <div class="card-body min-w-0">
                            <?php if ($wide) { ?><div class="crom-partie-wide-content"><?php } ?>
                            <div class="flex flex-wrap gap-2"><span class="badge badge-outline"><?php echo esc_html($item['type']); ?></span><?php if (! empty($item['reserve_aux_majeurs'])) { ?><span class="badge badge-warning badge-outline" title="Réservé aux personnes majeures">+18</span><?php } ?><span><?php echo esc_html($item['jeu'] ?? ''); ?></span></div>
                            <h3 class="card-title"><?php echo esc_html($item['nom']); ?></h3>
                            <time datetime="<?php echo esc_attr($item['date']); ?>"><?php echo esc_html($item['date_label'].' · '.$item['debut'].'–'.$item['fin']); ?></time>
                            <p><?php echo esc_html('MJ : '.($item['mj'] ?? 'Non renseigné').' · '.($item['lieu'] ?? 'Lieu non renseigné')); ?></p>
                            <?php if ($wide && ! empty($item['description'])) { ?><p><?php echo esc_html($item['description']); ?></p><?php } ?>
                            <?php if ($wide) { ?></div><?php } ?>
                            <div class="card-actions items-center justify-between"><span><?php echo esc_html(crom_link_players($item)); ?> · <?php echo esc_html($item['etat']); ?></span><span class="btn btn-sm">Voir sur CROM</span></div>
                        </div>
                    </a>
                <?php } ?>
            </div>
            <?php } ?>
        <?php } ?>
    </section>
    <?php return ob_get_clean();
}

function crom_link_players(array $item): string
{
    return (int)($item['inscrits'] ?? 0).' / '.(($item['capacite'] ?? 0) ? (int)$item['capacite'] : '∞').' joueurs';
}

function crom_link_session_compact_card(array $item): string
{
    $uid = wp_unique_id('crom-session-details-');
    $type = (string)($item['type'] ?? 'Partie');
    [$badge, $color] = match ($type) {
        'Campagne Fermée' => ['Cmp F', 'badge-error'],
        'Campagne Ouverte' => ['Cmp O', 'badge-success'],
        'One-Shot', 'Oneshot', 'One-shot' => ['One-shot', 'badge-warning'],
        default => [$type, 'badge-info'],
    };
    ob_start(); ?>
    <article class="card card-side card-sm bg-base-200 shadow-sm overflow-hidden w-full min-w-0 min-h-44 crom-session-compact">
        <?php if (! empty($item['image'])) { ?><figure class="hidden md:block relative w-1/4 shrink-0 self-stretch"><a class="absolute inset-0" href="<?php echo esc_url($item['url']); ?>"><img class="h-full w-full object-cover" src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['jeu'] ?? $item['nom']); ?>" loading="lazy"></a></figure><?php } ?>
        <div class="card-body min-w-0 p-3 gap-1 justify-between">
            <div class="flex flex-wrap items-center gap-1 text-xs text-base-content/70"><span class="badge badge-outline badge-xs <?php echo esc_attr($color); ?>" title="<?php echo esc_attr($type); ?>"><?php echo esc_html($badge); ?></span><span class="truncate"><?php echo esc_html($item['jeu'] ?? ''); ?></span></div>
            <h3 class="card-title text-sm leading-tight line-clamp-2"><a class="link-hover" href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['nom']); ?></a></h3>
            <div class="font-medium text-xs truncate"><?php echo esc_html($item['mj'] ?? 'MJ non renseigné'); ?></div>
            <button type="button" class="flex flex-wrap items-center gap-1 text-left" data-crom-details="<?php echo esc_attr($uid); ?>" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($uid); ?>" aria-label="Informations complémentaires pour <?php echo esc_attr($item['nom']); ?>"><time class="badge badge-soft badge-sm" datetime="<?php echo esc_attr($item['date']); ?>"><?php echo esc_html($item['date_label'].' · '.$item['debut'].'–'.$item['fin']); ?></time><span class="badge badge-ghost badge-sm">Détails</span></button>
            <div class="card-actions text-xs font-medium mt-1"><span class="badge badge-outline badge-xs"><?php echo esc_html($item['etat'] ?? ''); ?></span><a class="link-hover" href="<?php echo esc_url($item['url']); ?>">Voir sur CROM</a></div>
        </div>
        <dialog id="<?php echo esc_attr($uid); ?>" class="modal modal-middle" aria-labelledby="<?php echo esc_attr($uid); ?>-title">
            <div class="modal-box w-11/12 max-w-2xl">
                <p class="text-sm text-base-content/70"><?php echo esc_html($type.' · '.($item['jeu'] ?? '')); ?></p>
                <h2 id="<?php echo esc_attr($uid); ?>-title" class="text-lg font-bold"><?php echo esc_html($item['nom']); ?></h2>
                <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><dt class="font-semibold">Date et horaire</dt><dd><?php echo esc_html($item['date_label'].' · '.$item['debut'].'–'.$item['fin']); ?></dd></div>
                    <div><dt class="font-semibold">Lieu</dt><dd><?php echo esc_html($item['lieu'] ?? 'Non renseigné'); ?></dd></div>
                    <div><dt class="font-semibold">Places</dt><dd><?php echo esc_html(crom_link_players($item)); ?></dd></div>
                    <div><dt class="font-semibold">Maître du jeu</dt><dd><?php echo esc_html($item['mj'] ?? 'Non renseigné'); ?></dd></div>
                </dl>
                <?php if (! empty($item['description'])) { ?><section class="mt-5"><h3 class="font-semibold">À propos</h3><p class="mt-1 text-sm break-words"><?php echo esc_html($item['description']); ?></p></section><?php } ?>
                <div class="modal-action"><a class="btn btn-sm" href="<?php echo esc_url($item['url']); ?>">Voir sur CROM</a><form method="dialog"><button class="btn btn-sm">Fermer</button></form></div>
            </div>
            <form method="dialog" class="modal-backdrop"><button>Fermer</button></form>
        </dialog>
    </article>
    <?php return ob_get_clean();
}
