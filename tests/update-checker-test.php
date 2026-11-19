<?php
// Test hors ligne du paquet : php tests/update-checker-test.php [répertoire du plugin].
define('ABSPATH', __DIR__);
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function add_filter($name, $callback, $priority = 10, $args = 1) {}
function is_wp_error($value) { return false; }
function verify($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$root = $argv[1] ?? dirname(__DIR__);
require $root.'/plugin-update-checker/plugin-update-checker.php';
verify(class_exists('Parsedown'), 'Parsedown absent du paquet de mise à jour');
verify(class_exists('PucReadmeParser'), 'PucReadmeParser absent du paquet de mise à jour');

class ReleaseFixtureApi extends \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitHubApi
{
    public array $assets = [];
    protected function api($url, $queryParams = [])
    {
        return (object) [
            'tag_name'=>'v1.8.2', 'zipball_url'=>'https://example.org/source.zip',
            'created_at'=>'2026-11-19T00:00:00Z', 'body'=>"## Correctif\n\n**Mises à jour** rétablies.",
            'assets'=>$this->assets, 'draft'=>false, 'prerelease'=>false,
        ];
    }
}
$api = new ReleaseFixtureApi('https://github.com/JRII972/crom-link/');
$api->enableReleaseAssets('/^crom-link-[0-9.]+\.zip$/', $api::REQUIRE_RELEASE_ASSETS);
$api->assets = [(object) ['name'=>'crom-link-1.8.2.zip', 'browser_download_url'=>'https://example.org/crom-link-1.8.2.zip', 'download_count'=>0]];
$release = $api->getLatestRelease();
verify($release->version === '1.8.2', 'Version de release incorrecte');
verify(str_contains($release->changelog, '<strong>Mises à jour</strong>'), 'Notes de release Markdown non converties');
verify($release->downloadUrl === 'https://example.org/crom-link-1.8.2.zip', 'Archive installable non sélectionnée');
$api->assets = [];
verify($api->getLatestRelease() === null, 'Archive source incomplète proposée sans ZIP installable');
echo "Mise à jour : dépendances, notes Markdown et archive installable : OK\n";
