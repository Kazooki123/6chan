<?php
/**
 * Generates an `.htpasswd` entry using PHP's built-in
 * bcrypt hashing, no need to install apache2-utils just for the
 * `htpasswd` command. Apache 2.4+ (with mod_authn_file, which is
 * enabled by default) reads the $2y$ bcrypt hashes natively.
 *
 * Usage (run this on the terminal, NOT through a browser):
 *   php genhtpasswd.php <username> <path-to-htpasswd>
 *
 * Example:
 *   php genhtpasswd.php admin /var/www/config/admin.htpasswd
 *
 * It'll prompt you for the password (hidden input where the terminal
 * supports it) and append the new line to the file, creating it if
 * it doesn't exist yet. Run it again with the same username to update
 * that user's password - it replaces the existing line rather than
 * duplicating it.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php genhtpasswd.php <username> <path-to-htpasswd>\n");
    exit(1);
}

$username = trim($argv[1]);
$filepath = $argv[2];

if ($username === '' || str_contains($username, ':')) {
    fwrite(STDERR, "Invalid username.\n");
    exit(1);
}

fwrite(STDOUT, "Password for '$username': ");
if (stripos(PHP_OS, 'WIN') === false) {
    shell_exec('stty -echo');
}
$password = trim(fgets(STDIN));
if (stripos(PHP_OS, 'WIN') === false) {
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

if ($password === '') {
    fwrite(STDERR, "Password cannot be empty.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$new_line = "$username:$hash";

$existing_lines = [];
if (file_exists($filepath)) {
    $existing_lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $existing_lines = array_filter(
        $existing_lines,
        fn($line) => !str_starts_with($line, "$username:")
    );
}

$existing_lines[] = $new_line;

$dir = dirname($filepath);
if (!is_dir($dir)) {
    fwrite(STDERR, "Directory does not exist: $dir\n");
    exit(1);
}

file_put_contents($filepath, implode("\n", $existing_lines) . "\n");
chmod($filepath, 0600); // readable/writable by owner only

fwrite(STDOUT, "Wrote credentials for '$username' to $filepath\n");
fwrite(STDOUT, "Make sure this file is OUTSIDE 'public/' - it should never be reachable by URL.\n");
