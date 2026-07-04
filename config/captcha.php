<?php
session_start();

function captcha_generate(): array {
    $type = rand(0, 2);

    if ($type === 0) {
        $a        = rand(1, 30);
        $b        = rand(1, 40);
        $question = "What is {$a} x {$b}?";
        $answer   = (string)($a * $b);
    } elseif ($type === 1) {
        $words    = ['apple', 'river', 'cloud', 'stone', 'flame',
                      'grace', 'light', 'north', 'quiet', 'spark',
                      'duck', 'spoon', 'pirate', 'anime', 'quad',
                      'hydro', 'fork', 'blast', 'rhythm', 'typo'];
        shuffle($words);
        $picked   = array_slice($words, 0, 4);
        $pos      = rand(1, 4);
        $ordinals = ['1st', '2nd', '3rd', '4th'];
        $question = "Type the {$ordinals[$pos-1]} word: " . implode(' ', $picked);
        $answer   = strtolower($picked[$pos-1]);
    } else {
        $words    = ['board', 'image', 'chan', 'posts', 'reply',
                    'thread', 'pixels', 'forum', 'digital', 'orange',
                    'beast', 'water', 'solid', 'plasma', 'plants', 'book',
                    'think', 'play', 'journal', 'vicious', 'artificial'];
        $word     = $words[array_rand($words)];
        $question = "Type <strong>" . htmlspecialchars($word) . "</strong> backwards";
        $answer   = strrev($word);
    }

    $token = bin2hex(random_bytes(8));
    $_SESSION['captcha'][$token] = [
        'answer'     => $answer,
        'expires_at' => time() + 600,
        'used'       => false,
    ];

    foreach ($_SESSION['captcha'] ?? [] as $t => $data) {
        if ($data['expires_at'] < time() || $data['used']) {
            unset($_SESSION['captcha'][$t]);
        }
    }

    return ['question' => $question, 'token' => $token];
}

function captcha_verify(string $token, string $answer): bool {
    if (!isset($_SESSION['captcha'][$token])) return false;

    $data = $_SESSION['captcha'][$token];

    $_SESSION['captcha'][$token]['used'] = true;

    if ($data['used'])       return false;
    if ($data['expires_at'] < time()) return false;

    return strtolower(trim($answer)) === $data['answer'];
}
