<?php

function get_text_palettes(): array {
    static $palettes = null;

    if ($palettes !== null) {
        return $palettes;
    }

    $json = file_get_contents(__DIR__ . '/../assets/misc/rainbows.json');
    $data = json_decode($json ?: '', true);
    $palettes = [];

    foreach (($data ?? []) as $name => $colors) {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name) || !is_array($colors)) {
            continue;
        }

        $valid_colors = array_values(array_filter(
            $colors,
            static fn($color) => is_string($color)
                && preg_match('/^#[0-9a-fA-F]{6}$/', $color)
        ));

        if ($valid_colors) {
            $palettes[$name] = $valid_colors;
        }
    }

    return $palettes;
}

function render_palette_text(string $text, string $palette): string {
    $palettes = get_text_palettes();
    $colors = $palettes[$palette] ?? [];
    if (!$colors) {
        return $text;
    }

    $inner = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $rendered = '';
    $color_index = 0;

    foreach (mb_str_split($inner) as $char) {
        if (preg_match('/^\s$/u', $char)) {
            $rendered .= htmlspecialchars($char, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            continue;
        }

        $color = $colors[$color_index % count($colors)];
        $rendered .= '<span style="color:' . $color . '">'
                  . htmlspecialchars($char, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                  . '</span>';
        $color_index++;
    }

    $class = 'flag-text';
    if ($palette === 'rainbow') {
        $class .= ' rainbow-text';
    } elseif ($palette === 'sapphic') {
        $class .= ' sapphic-text';
    }

    return '<span class="' . $class . '">' . $rendered . '</span>';
}