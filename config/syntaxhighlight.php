<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Phiki\Adapters\CommonMark\PhikiExtension;

const PHIKI_THEME = 'gruvbox-dark-hard';
# NOTE to self and maybe to contributors: you can use catppuccin, github, mamterial, or whatever sets your mood. Heres a list reference from Phiki themes:
# andromeda, aurora-x, ayu-dark, catppuccin-frappe, catppuccin-latte, catppuccin-macchiato,
# catppuccin-mocha, dark-plus, dracula, dracula-soft, everforest-dark, everforest-light, 
# github-dark-default, github-dark-dimmed, github-dark-high-contrast, github-dark,
# github-light-default, github-light-hight-contrast, github-light, gruvbox-dark-hard (current),
# gruvbox-dark-medium, gruvbox-dark-soft, gruvbox-light-hard, gruvbox-light-medium,
# gruvbox-light-soft, houston, kanagawa-dragon, kanagawa-lotus, kanagawa-wave, laserwave,
# light-plus, material-theme-darker, material-theme-lighter, material-theme-ocean,
# material-theme-palenight, material-theme, min-dark, min-light, monokai, night-owl, nord,
# one-dark-pro, one-light, plastic, poimandres, red, rose-pine-dawn, rose-pine-moon, rose-pine,
# slack-dark, slack-ochin, snazzy-light, solarized-dark, solarized-light, synthwave-84, tokyo-night,
# vesper, vitesse-black, vitesse-dark, vitesse-light

function get_phiki_extension(): PhikiExtension {
    static $extension = null;
    if ($extension) return $extension;

    $extension = new PhikiExtension(PHIKI_THEME);
    return $extension;
}
