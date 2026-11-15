<?php

defined('ABSPATH') || exit;

function crom_link_options(): array
{
    $defaults = ['url'=>untrailingslashit(preg_replace('#/site/?$#', '/crom', home_url())), 'cache'=>60, 'theme'=>'inherit'];
    return array_replace($defaults, (array)get_option('crom_link_options', []));
}

/** Les chemins absolus sont relatifs au domaine, même si WordPress est dans /site. */
function crom_link_base_url(): string
{
    $url = crom_link_options()['url'];
    if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
        $home = wp_parse_url(home_url());
        $url = $home['scheme'].'://'.$home['host'].(isset($home['port']) ? ':'.$home['port'] : '').$url;
    }
    return untrailingslashit($url);
}

/** L’URL distante est configurée par un administrateur, jamais fournie par un bloc. */
function crom_link_fetch(string $resource, array $query = []): array|WP_Error
{
    if (! in_array($resource, ['jeux','parties','evenements','categories'], true)) {
        return new WP_Error('crom_resource', 'Ressource inconnue.');
    }
    $options = crom_link_options();
    $url = add_query_arg($query, crom_link_base_url().'/vitrine/v1/'.$resource);
    $key = 'crom_link_'.hash('sha256', $url);
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached;
    }
    $response = wp_remote_get($url, ['timeout'=>5, 'redirection'=>0, 'limit_response_size'=>1048576, 'headers'=>['Accept'=>'application/json']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $error = new WP_Error('crom_unavailable', 'Les données CROM sont temporairement indisponibles.');
        set_transient($key, $error, 15);
        return $error;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! isset($data['items']) || ! is_array($data['items'])) {
        return new WP_Error('crom_format', 'Réponse CROM invalide.');
    }
    set_transient($key, $data, min(300, max(15, (int)$options['cache'])));
    return $data;
}

function crom_link_catalogue(): array|WP_Error
{
    $games = [];
    for ($page = 1; $page <= 10; $page++) {
        $data = crom_link_fetch('jeux', ['page'=>$page]);
        if (is_wp_error($data)) {
            return $data;
        }
        $games = array_merge($games, $data['items']);
        if (count($games) >= ($data['total'] ?? 0) || count($data['items']) < 100) {
            break;
        }
    }
    return $games;
}

/** Un lien sert uniquement à extraire un identifiant ; il n’est jamais téléchargé. */
function crom_link_reference(string $link): array|WP_Error
{
    $parsed = wp_parse_url(trim($link));
    $base = wp_parse_url(crom_link_base_url());
    if (! is_array($parsed) || isset($parsed['user']) || isset($parsed['pass']) || (isset($parsed['scheme']) && ! in_array(strtolower($parsed['scheme']), ['http','https'], true))
        || (isset($parsed['host']) && strcasecmp($parsed['host'], $base['host'] ?? '') !== 0)
        || (isset($parsed['port']) && (int)$parsed['port'] !== (int)($base['port'] ?? (($base['scheme'] ?? '') === 'https' ? 443 : 80)))) {
        return new WP_Error('crom_link', 'Collez un lien de partie ou de campagne provenant de votre site CROM.');
    }
    $path = $parsed['path'] ?? '';
    $prefix = rtrim($base['path'] ?? '', '/');
    if ($prefix && str_starts_with($path, $prefix.'/')) {
        $path = substr($path, strlen($prefix));
    }
    parse_str($parsed['query'] ?? '', $query);
    if (! preg_match('#^/(partie|activite|campagne)(?:/(\d+))?/?$#', $path, $match)) {
        return new WP_Error('crom_link', 'Le lien doit désigner une partie ou une campagne CROM.');
    }
    $id = $match[2] ?? ($query['id'] ?? null);
    if (! is_scalar($id) || ! ctype_digit((string)$id) || (int)$id < 1) {
        return new WP_Error('crom_link', 'Ce lien ne contient pas d’identifiant valide.');
    }
    return ['mode'=>$match[1] === 'partie' ? 'partie' : 'campagne', 'id'=>(int)$id];
}

/** Désigne l’événement ciblé par un filtre : '' (aucun), 'prochain_parties', 'prochain' ou un identifiant. */
function crom_link_event_ref(string $choice, int $id, bool $withSessions = false): string|WP_Error
{
    if ($choice === 'selection') {
        return $id > 0 ? (string)$id : new WP_Error('crom_event', 'Choisissez un événement dans les réglages du bloc.');
    }
    return $choice === 'prochain' ? ($withSessions ? 'prochain_parties' : 'prochain') : '';
}

/**
 * Série d'événements récurrents (ex. « Week-end FSV ») : le prochain événement dont le nom contient
 * l'attribut « serie » devient l'événement choisi, sans modifier la page chaque mois.
 */
function crom_link_with_serie(array $attributes): array|WP_Error
{
    $serie = trim((string)($attributes['serie'] ?? ''));
    if ($serie === '') {
        return $attributes;
    }
    $data = crom_link_fetch('evenements', ['mode'=>'prochain', 'nom'=>$serie]);
    if (is_wp_error($data)) {
        return $data;
    }
    $id = (int)($data['items'][0]['id'] ?? 0);
    return $id > 0 ? array_replace($attributes, ['source'=>'selection', 'evenementId'=>$id]) : new WP_Error('crom_serie', 'Prochaine date à annoncer.');
}

/** Événement unique : celui en cours, sinon le prochain, ou celui choisi explicitement. */
function crom_link_event(array $attributes): array|WP_Error|null
{
    $attributes = crom_link_with_serie($attributes);
    if (is_wp_error($attributes)) {
        return $attributes;
    }
    $ref = crom_link_event_ref((string)($attributes['source'] ?? 'prochain'), absint($attributes['evenementId'] ?? 0));
    if (is_wp_error($ref)) {
        return $ref;
    }
    if (($attributes['source'] ?? '') === 'en_cours') {
        $data = crom_link_fetch('evenements', ['mode'=>'en_cours', 'limite'=>1]);
    } elseif ($ref === 'prochain' || $ref === '') {
        $data = crom_link_fetch('evenements', ['mode'=>'en_cours', 'limite'=>1]);
        if (is_wp_error($data) || ! empty($data['items'])) {
            return is_wp_error($data) ? $data : $data['items'][0];
        }
        $data = crom_link_fetch('evenements', ['mode'=>'prochain']);
    } else {
        $data = crom_link_fetch('evenements', ['mode'=>'id', 'id'=>$ref]);
    }
    return is_wp_error($data) ? $data : ($data['items'][0] ?? null);
}

function crom_link_events_catalogue(): array|WP_Error
{
    $data = crom_link_fetch('evenements', ['mode'=>'futurs', 'limite'=>50]);
    return is_wp_error($data) ? $data : $data['items'];
}

/** Catégories d’événements disponibles pour le filtre des blocs. */
function crom_link_categories_catalogue(): array|WP_Error
{
    $data = crom_link_fetch('categories');
    return is_wp_error($data) ? $data : $data['items'];
}

/** Identifiants de catégories valides, au format attendu par l’API (« 1,2 »). */
function crom_link_categories_query(array $attributes): string
{
    return implode(',', array_unique(array_filter(array_map('absint', (array)($attributes['categories'] ?? [])))));
}
