<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function captchav2_generate(): array {
    $token = bin2hex(random_bytes(8));

    $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ123456789';
    $length = 6;
    $code   = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $_SESSION['captchav2'][$token] = [
        'answer'     => $code,
        'expires_at' => time() + 600,
        'used'       => false,
    ];

    foreach ($_SESSION['captchav2'] ?? [] as $t => $d) {
        if ($d['expires_at'] < time() || $d['used']) {
            unset($_SESSION['captchav2'][$t]);
        }
    }

    return ['token' => $token, 'code' => $code];
}

function captchav2_verify(string $token, string $answer): bool {
    if (!isset($_SESSION['captchav2'][$token])) return false;
    $data = $_SESSION['captchav2'][$token];
    $_SESSION['captchav2'][$token]['used'] = true;
    if ($data['used'])              return false;
    if ($data['expires_at'] < time()) return false;
    return strtoupper(trim($answer)) === $data['answer'];
}

function captchav2_render(string $code): void {
    $w   = 180;
    $h   = 60;
    $img = imagecreatetruecolor($w, $h);

    $bg      = imagecolorallocate($img, 20,  20,  20);   // dark bg
    $noise_c = imagecolorallocate($img, 50,  50,  50);   // grid/noise
    $orange  = imagecolorallocate($img, 255, 102, 0);

    imagefill($img, 0, 0, $bg);

    for ($i = 0; $i < 300; $i++) {
        imagesetpixel($img,
            random_int(0, $w - 1),
            random_int(0, $h - 1),
            $noise_c
        );
    }

    for ($i = 0; $i < 5; $i++) {
        $lc = imagecolorallocatealpha($img,
            random_int(60, 120),
            random_int(60, 120),
            random_int(60, 120),
            80
        );
        imageline($img,
            random_int(0, $w), random_int(0, $h),
            random_int(0, $w), random_int(0, $h),
            $lc
        );
    }

    $char_w  = (int)($w / (strlen($code) + 1));
    $font    = __DIR__ . '/../public/assets/font/captcha_font.ttf';

    // Fallback — if no TTF font, use built-in GD font.
    $has_ttf = file_exists($font) && function_exists('imagettftext');

    for ($i = 0; $i < strlen($code); $i++) {
        $char  = $code[$i];
        $angle = random_int(-28, 28);
        $size  = random_int(20, 26);
        $x     = $char_w * $i + random_int(8, 14);
        $y     = random_int(35, 48);

        $r   = min(255, 255 + random_int(-30, 0));
        $g   = min(255, 102 + random_int(-20, 40));
        $b   = min(255,   0 + random_int(0, 30));
        $col = imagecolorallocate($img, $r, $g, $b);

        if ($has_ttf) {
            imagettftext($img, $size, $angle, $x, $y, $col, $font, $char);
        } else {
            imagestring($img, 5, $x, (int)($h / 3), $char, $col);
        }
    }

    $distorted = imagecreatetruecolor($w, $h);
    $bg2       = imagecolorallocate($distorted, 20, 20, 20);
    imagefill($distorted, 0, 0, $bg2);

    $amp  = random_int(2, 4);   // wave amplitude — keep subtle
    $freq = random_int(2, 4);   // wave frequency

    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            $sx = (int)($x + $amp * sin($y / $freq));
            $sy = (int)($y + $amp * cos($x / $freq));
            if ($sx >= 0 && $sx < $w && $sy >= 0 && $sy < $h) {
                $col = imagecolorat($img, $sx, $sy);
                imagesetpixel($distorted, $x, $y, $col);
            }
        }
    }

    imagedestroy($img);

    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');
    imagepng($distorted);
    imagedestroy($distorted);
}
