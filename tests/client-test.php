<?php
// Tests hors ligne du client et des rendus, à lancer avec : php tests/client-test.php.
define('ABSPATH', __DIR__);
$cache = []; $calls = 0; $offline = false;
$fixtures = null; $eventFixtures = null;
$options = []; $home = 'https://association.fr/site'; $requestedUrl = ''; $actions = []; $sanitize = null;
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function home_url() { global $home; return $home; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function get_option($key,$default) { global $options; return $options ?: $default; }
function get_transient($key) { global $cache; return $cache[$key] ?? false; }
function set_transient($key,$value,$ttl) { global $cache; $cache[$key] = $value; }
function add_query_arg($query,$url) { return $url.'?'.http_build_query($query); }
function wp_parse_url($value) { return parse_url($value); }
function wp_remote_get($url,$args) {
    global $calls,$offline,$fixtures,$requestedUrl,$eventFixtures; $calls++; $requestedUrl = $url;
    if ($offline) { return new WP_Error('offline','Impossible'); }
    if ($eventFixtures !== null && str_contains($url, '/evenements?')) {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $items = $eventFixtures[$query['mode'] ?? 'prochain'] ?? [];
        return ['response'=>['code'=>200], 'body'=>json_encode(['version'=>1, 'items'=>$items])];
    }
    if ($fixtures !== null) { return ['response'=>['code'=>200], 'body'=>json_encode(['version'=>1, 'total'=>count($fixtures), 'items'=>$fixtures])]; }
    return ['response'=>['code'=>200],'body'=>json_encode(['version'=>1,'total'=>1,'items'=>[['id'=>1,'nom'=>'<script>alert(1)</script>','description'=>'<img src=x onerror=alert(1)>','url'=>'javascript:alert(1)','image'=>'javascript:alert(1)']]])];
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function get_block_wrapper_attributes($attributes) { return implode(' ', array_map(fn($key) => $key.'="'.esc_attr($attributes[$key]).'"', array_keys($attributes))); }
function absint($v) { return abs((int)$v); }
function wp_enqueue_script($handle) {}
function wp_unique_id($prefix) { static $id=0; return $prefix.(++$id); }
function esc_html($v) { return htmlspecialchars((string)$v,ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return preg_match('#^(?:https?://|/(?!/))#',$v) ? htmlspecialchars($v,ENT_QUOTES) : ''; }
function esc_url_raw($v, $protocols) { return preg_match('#^[a-z]+:#i', $v) && !preg_match('#^https?://#i', $v) ? '' : $v; }
function shortcode_atts($defaults, $attributes, $tag = '') { return array_replace($defaults, array_intersect_key($attributes, $defaults)); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_kses_post($value) { return strip_tags($value, '<p><strong><em><a>'); }
function add_action($name, $callback) { global $actions; $actions[$name] = $callback; }
function register_setting($group, $name, $args) { global $sanitize; $sanitize = $args['sanitize_callback']; }
function add_settings_error($setting, $code, $message) {}
require __DIR__.'/../includes/client.php';
require __DIR__.'/../includes/render.php';
require __DIR__.'/../includes/evenements.php';
require __DIR__.'/../includes/settings.php';
function check($value,$message) { if (! $value) { throw new RuntimeException($message); } }
$actions['admin_init']();
foreach (['/crom', '/crom/', 'https://autre.fr/crom/'] as $address) {
    $saved = $sanitize(['url'=>$address]);
    check($saved['url'] === rtrim($address, '/'), 'Adresse refusée ou modifiée');
}
foreach (['//evil.example/crom', '/crom?x=1', '/crom#fragment', '/\\evil.example', 'javascript:alert(1)', 'https://user:pass@example.fr/crom'] as $address) {
    check($sanitize(['url'=>$address]) === crom_link_options(), 'Adresse invalide acceptée');
}
$options = ['url'=>'/crom'];
check(crom_link_base_url() === 'https://association.fr/crom', 'Chemin résolu sous /site');
check(crom_link_reference('https://association.fr/crom/partie?id=12') === ['mode'=>'partie', 'id'=>12], 'Lien absolu avec configuration relative');
check(is_wp_error(crom_link_reference('https://evil.example/crom/partie?id=12')), 'Domaine externe accepté');
$home = 'http://localhost:8080/wordpress';
crom_link_fetch('jeux');
check($requestedUrl === 'http://localhost:8080/crom/vitrine/v1/jeux?', 'URL API ou port incorrect');
$options = ['url'=>'https://autre.fr/application'];
check(crom_link_base_url() === 'https://autre.fr/application', 'URL distante modifiée');
$home = 'https://association.fr/site'; $options = []; $cache = []; $calls = 0;
check(crom_link_reference('https://association.fr/crom/partie?id=12')===['mode'=>'partie','id'=>12], 'Lien de partie');
check(crom_link_reference('https://association.fr/crom/activite?id=42')===['mode'=>'campagne','id'=>42], 'Lien de campagne');
foreach (['https://evil.example/crom/partie?id=12','https://association.fr/crom/admin?id=1','https://association.fr/crom/partie?id[]=12','javascript:alert(1)','/crom/partie?id=0'] as $url) {
    check(is_wp_error(crom_link_reference($url)), 'Lien dangereux accepté');
}
crom_link_fetch('jeux'); crom_link_fetch('jeux');
check($calls===1,'Cache non utilisé');
$html=crom_link_game(['jeuId'=>1]);
check(! str_contains($html,'<script>') && ! str_contains($html,'javascript:'),'Injection HTML');
$fixtures = [
    ['id'=>1,'nom'=>'Fantasy RPG','type_code'=>'JDR','genres'=>[['id'=>10,'nom'=>'Fantasy']], 'banniere'=>'https://association.fr/fantasy.webp'],
    ['id'=>2,'nom'=>'Horror RPG','type_code'=>'JDR','genres'=>[['id'=>20,'nom'=>'Horreur']], 'banniere'=>'https://association.fr/horror.webp'],
    ['id'=>3,'nom'=>'Fantasy boardgame','type_code'=>'JEU_DE_SOCIETE','genres'=>[['id'=>10,'nom'=>'Fantasy']], 'banniere'=>'https://association.fr/board.webp'],
    ['id'=>4,'nom'=>'Missing banner','type_code'=>'JDR','genres'=>[['id'=>10,'nom'=>'Fantasy']]],
];
$cache=[];
$html=crom_link_cover(['mode'=>'toutes','typeJeu'=>'JDR','genres'=>[10]]);
check(str_contains($html,'Fantasy RPG') && !str_contains($html,'Horror RPG') && !str_contains($html,'Fantasy boardgame') && !str_contains($html,'Missing banner'),'Filtres combinés');
check(str_contains($html,'justify-end items-start') && !str_contains($html,'<h2') && !str_contains($html,'Découvrir ce jeu'),'Titre minimal');
$html=crom_link_cover(['mode'=>'toutes','genres'=>[10,20],'titre'=>'Nos jeux']);
check(substr_count($html,'carousel-item')===3 && str_contains($html,'text-3xl') && str_contains($html,'Découvrir ce jeu'),'Genres multiples et titre personnalisé');
check(str_contains(crom_link_cover(['mode'=>'toutes','genres'=>[999]]),'Aucune bannière'),'Filtre sans résultats');
$html=crom_link_cover(['mode'=>'toutes','texte'=>'Bienvenue']);
check(str_contains($html,'justify-center') && str_contains($html,'Bienvenue'),'Commentaire personnalisé');
$cache=[];
$html=crom_link_game_miniature(['mode'=>'toutes','typeJeu'=>'JDR','genres'=>[10],'autoplay'=>true,'delay'=>12]);
check(str_contains($html,'Fantasy RPG') && !str_contains($html,'Horror RPG') && !str_contains($html,'Fantasy boardgame') && str_contains($html,'Missing banner'),'Filtres miniature non combinés');
check(str_contains($html,'data-crom-miniature="true"') && str_contains($html,'data-autoplay="true"') && str_contains($html,'data-delay="12"'),'Réglages défilement miniature');
$html=crom_link_game_miniature(['mode'=>'toutes','genres'=>[10,20],'autoplay'=>false,'delay'=>100]);
check(str_contains($html,'data-autoplay="false"') && str_contains($html,'data-delay="60"') && !str_contains($html,'data-crom-pause'),'Défilement désactivé ou durée non bornée');
check(substr_count($html,'class="carousel-item w-full"')===4,'Genres multiples miniature');
check(str_contains(crom_link_game_miniature(['mode'=>'toutes','genres'=>[999]]),'Aucun jeu disponible pour ces filtres'),'Filtres miniature sans résultat');
$cache=[];
$fixtures=[['id'=>1,'nom'=>'Jeu miniature','image'=>'https://association.fr/jeu.webp','banniere'=>'https://association.fr/banniere.webp','url'=>'https://association.fr/crom/jeu?id=1','description'=>'<script>description</script>']];
$html=crom_link_game_miniature(['jeuId'=>1,'imageCote'=>'droite']);
check(str_contains($html,'crom-game-miniature crom-image-right') && str_contains($html,'/jeu.webp') && !str_contains($html,'/banniere.webp'),'Miniature : mauvais côté ou mauvaise image');
check(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'Description miniature non échappée');
$html=crom_link_game_miniature(['jeuId'=>1,'imageCote'=>'gauche']);
check(!str_contains($html,'crom-image-right'),'Image gauche non respectée');
$cache=[];
$fixtures=[['id'=>12,'nom'=>'Partie test','type'=>'Campagne Fermée','jeu'=>'Cthulhu','mj'=>'MJ test','date'=>'2026-11-06','date_label'=>'vendredi 6 novembre 2026','debut'=>'14:00','fin'=>'18:00','lieu'=>'Lieu test','inscrits'=>2,'capacite'=>5,'etat'=>'Ouverte','description'=>'Texte <img src=x onerror=alert(1)>','image'=>'https://association.fr/jeu.webp','url'=>'https://association.fr/crom/partie?id=12']];
$html=crom_link_sessions_compact(['mode'=>'lien','lien'=>'/crom/partie?id=12','presentation'=>'table']);
check(str_contains($html,'crom-session-compact') && !str_contains($html,'<table'),'Présentation compacte non imposée');
check(str_contains($html,'Cmp F') && str_contains($html,'badge-error'),'Badge campagne fermée');
check(str_contains($html,'method="dialog"') && str_contains($html,'Lieu test') && str_contains($html,'2 / 5 joueurs'),'Modal incomplète');
check(!str_contains($html,'<img src=x') && str_contains($html,'&lt;img'),'Description modal non échappée');
preg_match('/data-crom-details="([^"]+)"/', $html, $match);
check(isset($match[1]) && str_contains($html,'id="'.$match[1].'"'),'Bouton non relié à sa modal');
check(!str_contains(crom_link_sessions_compact(['mode'=>'lien','lien'=>'/crom/partie?id=12']),'id="'.$match[1].'"'),'Identifiants modal dupliqués');
$cache=[]; $offline=true;
check(is_wp_error(crom_link_fetch('parties')), 'Indisponibilité externe');
check(str_contains(crom_link_game(['jeuId'=>1]),'temporairement indisponibles'),'Erreur sans message lisible');
echo "Liens, cache, miniatures, cartes compactes, modals, échappement et panne réseau : OK\n";

$offline = false; $cache = [];
$event = ['id'=>4, 'nom'=>'Festival <script>test</script> & jeux', 'en_cours'=>true,
    'date_label'=>'samedi 14 novembre 2026', 'type'=>'Jeux de société',
    'lieu'=>'Salle <b>commune</b>', 'adresse'=>'12 rue du Jeu',
    'url'=>'https://association.fr/crom/evenement/4', 'lien_paiement'=>'https://www.helloasso.com/test',
    'description_html'=>'<p>Un <strong>festival</strong> ouvert.</p>',
    'plages'=>[['date'=>'2026-11-14', 'date_label'=>'samedi 14 novembre', 'debut'=>'10:00', 'fin'=>'18:00']]];
$next = array_replace($event, ['id'=>5, 'nom'=>'Le prochain', 'en_cours'=>false]);
$eventFixtures = ['en_cours'=>[$event], 'prochain'=>[$next], 'id'=>[$next]];
check(crom_link_event([])['id'] === 4, 'L’événement en cours doit avoir priorité');
check(crom_link_event(['source'=>'selection', 'evenementId'=>5])['id'] === 5, 'La sélection explicite doit être respectée');
check(crom_link_event_shortcode([], null, 'crom_evenement_date') === $event['date_label'], 'Date incorrecte');
check(crom_link_event_shortcode([], null, 'crom_evenement_description') === 'Un festival ouvert.', 'Description non utilisable en ligne');
check(crom_link_event_shortcode([], null, 'crom_evenement_texte') === $event['description_html'], 'Texte public sans mise en forme');
check(crom_link_event_shortcode(['evenement'=>'5'], null, 'crom_evenement_date') === $next['date_label'], 'Événement précis du shortcode ignoré');
check(crom_link_event_shortcode(['evenement'=>'invalide'], null, 'crom_evenement_date') === '', 'Sélection invalide remplacée silencieusement');
check(crom_link_event_shortcode([], null, 'crom_evenement_titre') === '', 'Ancien shortcode encore pris en charge');
$eventFixtures['en_cours'] = []; $cache = [];
check(crom_link_event([])['id'] === 5, 'Sans événement en cours : afficher le prochain');
check(crom_link_event_shortcode([], null, 'crom_evenement_date') === $next['date_label'], 'Date du prochain événement incorrecte');
$eventFixtures['prochain'] = []; $cache = [];
check(crom_link_event_shortcode([], null, 'crom_evenement_date') === '', 'Absence d’événement : laisser le texte vide');
$offline = true; $cache = [];
check(crom_link_event_shortcode([], null, 'crom_evenement_date') === '', 'Panne API : ne pas insérer de message d’erreur dans une phrase');
echo "Événement en cours ou prochain, sélection explicite, shortcodes et échappement : OK\n";

$offline = false; $cache = []; $eventFixtures = ['futurs'=>[$event, $next]];
$html = crom_link_events_list(['titre'=>'']);
check(!str_contains($html, '<h2') && str_contains($html, 'crom-cards-grid'), 'Liste : titre automatique ou grille non adaptative');
check(str_contains(crom_link_events_list(['titre'=>'Nos rendez-vous']), '>Nos rendez-vous</h2>'), 'Titre personnalisé de liste ignoré');
$fixtures = [['id'=>12, 'nom'=>'Partie test', 'date'=>'2026-11-14', 'date_label'=>'samedi 14 novembre', 'debut'=>'10:00', 'fin'=>'18:00', 'url'=>'https://association.fr/crom/partie?id=12', 'type'=>'One-shot', 'etat'=>'Ouverte']];
$html = crom_link_event_sessions(['titre'=>'']);
check(!str_contains($html, '<h2') && str_contains($html, 'crom-cards-grid'), 'Parties : titre automatique ou grille non adaptative');
check(str_contains(crom_link_event_sessions(['titre'=>'Nos parties']), '>Nos parties</h2>'), 'Titre personnalisé de parties ignoré');
$offline = false; $cache = [];
$eventFixtures = ['en_cours'=>[$event], 'prochain'=>[$next], 'futurs'=>[$event, $next], 'id'=>[$next]];
foreach (['carte', 'liste'] as $presentation) {
    $html = crom_link_event_block(['source'=>'futurs', 'presentation'=>$presentation]);
    check(str_contains($html, esc_html($event['nom'])) && str_contains($html, $next['nom']), 'Bloc Événement : liste des prochains incomplète');
    check(str_contains($html, $presentation === 'liste' ? '<ul' : 'crom-cards-grid'), 'Présentation du bloc Événement ignorée');
    $html = crom_link_event_block(['source'=>'selection', 'evenementId'=>5, 'presentation'=>$presentation]);
    check(str_contains($html, $next['nom']) && !str_contains($html, esc_html($event['nom'])), 'Événement choisi non isolé');
    $html = crom_link_event_block(['source'=>'en_cours', 'presentation'=>$presentation]);
    check(str_contains($html, esc_html($event['nom'])) && !str_contains($html, $next['nom']), 'Événement en cours non isolé');
}
$eventFixtures['en_cours'] = []; $cache = [];
check(!str_contains(crom_link_event_block(['source'=>'en_cours']), $next['nom']), 'Le mode en cours seul affiche un événement futur');
check(str_contains(crom_link_event_block(['source'=>'prochain']), $next['nom']), 'Le mode automatique ne passe plus au prochain événement');
echo "Bloc Événement unique : prochains, choisi, en cours et automatique en liste ou cartes : OK\n";

$eventFixtures = ['futurs'=>[$event]]; $cache = [];
$html = crom_link_events_list(['categories'=>['3', 0, 5, 3, 'x']]);
check(str_contains($requestedUrl, 'categories=3%2C5'), 'Filtre de catégories non transmis à CROM');
crom_link_events_list([]);
check(!str_contains($requestedUrl, 'categories='), 'Filtre de catégories ajouté sans sélection');
check(str_contains(crom_link_event_badges(['type'=>'Tournoi', 'categorie'=>['id'=>3, 'nom'=>'<b>Soirée</b>']]), '&lt;b&gt;Soirée&lt;/b&gt;'), 'Catégorie absente ou non échappée');
echo "Filtre des événements par catégories : OK\n";

// Destination unique : validation, tous les rendus et retour immédiat aux liens d’origine.
$options = [];
check(crom_link_destination_url('https://association.fr/crom/jeu/1') === 'https://association.fr/crom/jeu/1', 'Remplacement activé par défaut');
foreach (['/contact/', '/contact/?source=crom&vue=jeu#message', 'https://autre.fr/attente?source=crom#infos'] as $destination) {
    $saved = $sanitize(['url'=>'/crom', 'override_enabled'=>'1', 'override_url'=>$destination]);
    check($saved['override_enabled'] === true && $saved['override_url'] === $destination, 'Destination temporaire altérée');
}
$options = ['url'=>'/crom', 'override_enabled'=>true, 'override_url'=>'/contact/'];
foreach (['', '//evil.example', '/\\evil.example', 'javascript:alert(1)', 'data:text/html,test', 'ftp://example.org', 'https://user:pass@example.org', 'https://', 'attente', "https://example.org/\ncontact", ['url'=>'/contact/']] as $destination) {
    check($sanitize(['url'=>'/crom', 'override_enabled'=>'1', 'override_url'=>$destination]) === crom_link_options(), 'Destination invalide enregistrée');
}
$saved = $sanitize(['url'=>'/crom', 'override_url'=>'/contact/']);
check($saved['override_enabled'] === false && $saved['override_url'] === '/contact/', 'Désactivation : destination perdue ou remplacement encore actif');
$options = ['override_enabled'=>true, 'override_url'=>'javascript:alert(1)'];
check(crom_link_destination_url('/crom/jeu/1') === '/crom/jeu/1', 'Ancienne configuration invalide utilisée au rendu');
$options = []; $cache = []; $offline = false;
$record = ['id'=>12, 'nom'=>'Jeu et partie de test', 'type'=>'One-shot', 'type_code'=>'JDR', 'jeu'=>'Jeu de test',
    'date'=>'2026-11-14', 'date_label'=>'samedi 14 novembre', 'debut'=>'10:00', 'fin'=>'18:00', 'etat'=>'Ouverte',
    'image'=>'https://association.fr/crom/images/test.webp', 'banniere'=>'https://association.fr/crom/images/banner.webp',
    'url'=>'https://association.fr/crom/partie/12'];
$fixtures = [$record];
$eventFixtures = ['en_cours'=>[$event], 'prochain'=>[$next], 'futurs'=>[$event, $next], 'id'=>[$event]];
$initial = crom_link_game(['jeuId'=>12]);
$initialCalls = $calls;
foreach (['https://association.fr/attente?source=crom&vue=jeu#message', '/contact/?source=crom&vue=jeu#message'] as $destination) {
    $options = ['override_enabled'=>true, 'override_url'=>$destination];
    $game = crom_link_game(['jeuId'=>12]);
    check($calls === $initialCalls, 'Le changement de destination invalide le cache API');
    check(str_contains($game, 'href="'.esc_url($destination).'"'), 'Données en cache : destination non remplacée');
    $renders = [
        $game,
        crom_link_game_miniature(['jeuId'=>12]),
        crom_link_cover(['jeuId'=>12]),
        crom_link_cover(['mode'=>'toutes', 'presentation'=>'galerie']),
        crom_link_cover(['mode'=>'parties']),
        crom_link_cover(['mode'=>'evenement']),
        crom_link_event_games(['source'=>'selection', 'evenementId'=>4]),
        crom_link_event_sessions(['source'=>'selection', 'evenementId'=>4]),
    ];
    foreach (['cartes', 'grande', 'table', 'compacte'] as $presentation) {
        $renders[] = crom_link_sessions(['presentation'=>$presentation]);
        $renders[] = crom_link_sessions(['mode'=>'lien', 'lien'=>'/crom/campagne/12', 'presentation'=>$presentation]);
    }
    foreach (['carte', 'liste'] as $presentation) {
        $renders[] = crom_link_events_html([$event], ['presentation'=>$presentation]);
        $renders[] = crom_link_events_list(['presentation'=>$presentation]);
    }
    foreach ($renders as $index=>$html) {
        preg_match_all('/href="([^"]*)"/', $html, $links);
        check(count($links[1]) > 0, 'Rendu sans liens : '.$index);
        foreach ($links[1] as $link) {
            check(in_array($link, [esc_url($destination), esc_url($event['lien_paiement'])], true), 'Lien CROM non remplacé dans le rendu '.$index);
        }
    }
    check(str_contains($game, 'src="'.$record['image'].'"'), 'Image remplacée par la destination temporaire');
    check(crom_link_event_shortcode([], null, 'crom_evenement_url') === esc_url($destination), 'URL shortcode non remplacée');
    check(str_contains(crom_link_event_paylink($event), 'href="'.$event['lien_paiement'].'"'), 'Paiement HelloAsso remplacé');
    check(crom_link_reference('/crom/campagne/12') === ['mode'=>'campagne', 'id'=>12], 'Lecture des liens de sélection modifiée');
    check(str_starts_with($requestedUrl, 'https://association.fr/crom/vitrine/v1/'), 'API redirigée vers la page temporaire');
    $initialCalls = $calls;
}
$options = ['override_enabled'=>false, 'override_url'=>'/contact/'];
check(crom_link_game(['jeuId'=>12]) === $initial, 'Désactivation : les liens d’origine ne sont pas restaurés depuis le cache');
check($calls === $initialCalls, 'Désactivation : appel API superflu');
echo "Destination unique : validation, rendus, shortcodes, médias, HelloAsso, API et désactivation : OK\n";

// Le bloc Événement conserve les catégories et statuts, mais masque le type.
$options = [];
$badgedEvent = array_replace($event, ['type'=>'TYPE_EVENEMENT_MASQUE', 'categorie'=>['nom'=>'Convention'], 'en_cours'=>true, 'payant'=>true]);
foreach (['carte', 'liste'] as $presentation) {
    $html = crom_link_events_html([$badgedEvent], ['presentation'=>$presentation]);
    check(str_contains($html, 'Convention'), 'Catégorie d’événement supprimée');
    check(!str_contains($html, 'TYPE_EVENEMENT_MASQUE'), 'Type d’événement encore affiché en '.$presentation);
    check(str_contains($html, 'badge-success') && str_contains($html, 'En cours'), 'Badge En cours supprimé');
    check(str_contains($html, 'badge-warning') && str_contains($html, 'Payant'), 'Badge Payant supprimé');
}
check(crom_link_event_badges(['type'=>'TYPE_EVENEMENT_MASQUE']) === '', 'Badge vide ajouté à un événement sans statut');
echo "Événements : type masqué, statuts En cours et Payant conservés : OK\n";
