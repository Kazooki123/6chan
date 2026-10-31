<?php

// TODO: Make removebg's model (u2net) available for general users (with limit) under the `/rmbg` command.
/**
 * # The Why's:
 * 
 * 1. By the end of this year the website "remove.bg" famous for helping users remove image * background with ease is shutting down since it has been acquired by canvas. This means 
 * users are forced to use canvas or use an alternative that's probably going to hit their 
 * ass with a paywall.
 *
 * 2. The command provides an easy use of removing the users given image background, since * remove.bg uses u2net (AFAIK), its the same ingredient being used here.
 * 
 * "Would 6chan be a better alternative?"
 * 
 * "better" sounds like a stretch, personally this is available for 6chan users who want to
 * remove photo backgrounds on the spot without using third party softwares or tools, its
 * a miscellaneous feature as well if you'd like it (^^ゞ..
 */
require_once __DIR__ . '/../../vendor/autoload.php';



