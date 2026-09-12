<?php
/**
 * Self-hosted Leaflet integration for the "/map" post command.
 *
 * Leaflet itself is served as a static asset from this same folder:
 *   public/services/map/leaflet.js
 *   public/services/map/leaflet.css   (download this alongside leaflet.js —
 *                                       Leaflet's layout/controls are broken
 *                                       without it, not optional)
 *
 * This file is the PHP-side glue: parsing the "/map lat,lng" command out of
 * a post body, and rendering the map container + init script for it.
 */

define('MAP_LEAFLET_JS',  'services/map/leaflet.js');
define('MAP_LEAFLET_CSS', 'services/map/leaflet.css');
define('MAP_TILE_URL',    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
define('MAP_TILE_ATTRIB', '&copy; OpenStreetMap contributors');

/**
 * Matches a post body that consists ENTIRELY of "/map <lat>,<lng>"
 * (whitespace around the comma is fine, nothing else on the line and
 * no other lines). Anything else — /map appearing mid-sentence, extra
 * text, a place name instead of coordinates — is not a map command and
 * falls through to normal body rendering.
 *
 * Returns [lat, lng] or null.
 */
function parse_map_command(string $body): ?array {
    $body = trim($body);

    if (!preg_match('/^\/map\s+(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/i', $body, $m)) {
        return null;
    }

    $lat = (float)$m[1];
    $lng = (float)$m[2];

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }

    return [$lat, $lng];
}

/**
 * <link>/<script> tags for the self-hosted Leaflet build. Include this
 * once in <head> on any page that might render a /map post (board.php,
 * thread.php).
 */
function render_leaflet_assets(): string {
    return '<link rel="stylesheet" href="' . MAP_LEAFLET_CSS . '">' . "\n"
         . '    <script src="' . MAP_LEAFLET_JS . '"></script>';
}

/**
 * Renders the map container + init script for one post's coordinates.
 * $uid must be unique per rendered instance on the page (caller's job —
 * board.php uses a static counter since several /map posts can share a
 * thread).
 */
function render_map_embed(float $lat, float $lng, string $uid): string {
    $lat_js    = json_encode($lat);
    $lng_js    = json_encode($lng);
    $uid_js    = json_encode($uid);
    $tile_js   = json_encode(MAP_TILE_URL);
    $attrib_js = json_encode(MAP_TILE_ATTRIB);
    $uid_attr  = htmlspecialchars($uid, ENT_QUOTES);

    return <<<HTML
<div class="post-map" id="{$uid_attr}"></div>
<script>
(function() {
    const el = document.getElementById({$uid_js});
    if (!el || typeof L === 'undefined') return;
    const map = L.map(el).setView([{$lat_js}, {$lng_js}], 13);
    L.tileLayer({$tile_js}, {
        maxZoom: 18,
        attribution: {$attrib_js}
    }).addTo(map);
    L.marker([{$lat_js}, {$lng_js}]).addTo(map);
})();
</script>
HTML;
}
