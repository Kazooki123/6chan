<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

use Polar\Polar;
use Polar\Models\Components\CheckoutCreate;

$token  = $_ENV['POLAR_TOKEN'] ?: '';
$isProd = ($_ENV['APP_ENV'] === 'production');

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

$successUrl = $baseUrl . '/../index.php?checkout=success&checkout_id={CHECKOUT_ID}';
$returnUrl  = $baseUrl . '/../index.php';

$productId = $_GET['product_id'] ?? $_ENV['POLAR_PRODUCT_ID'];

$checkoutUrl = null;
$embedUrl    = null;
$error       = null;

if ($productId) {
    try {
        $caBundle = getenv('SSL_CERT_FILE')
            ?: (is_file('/etc/ssl/certs/ca-certificates.crt')
                ? '/etc/ssl/certs/ca-certificates.crt'
                : (is_file('/etc/pki/tls/certs/ca-bundle.crt')
                    ? '/etc/pki/tls/certs/ca-bundle.crt'
                    : true));

        $httpClient = new \GuzzleHttp\Client([
            'verify' => $caBundle
        ]);
        
        $sdk = Polar::builder()
            ->setClient($httpClient)
            ->setServer('sandbox')
            ->setSecurity($token)
            ->setServerURL('https://sandbox-api.polar.sh')
            ->build();

        $request = new CheckoutCreate(
            products:    [$productId],
            successUrl:  $successUrl,
            returnUrl:   $returnUrl,
            embedOrigin: $baseUrl,
        );

        $response = $sdk->checkouts->create(request: $request);

        if ($response->checkout !== null) {
            $embedUrl    = $response->checkout->url;
            $checkoutUrl = $response->checkout->url;
        } else {
            $error = 'Polar returned an empty checkout response.';
        }
    } catch (Throwable $e) {
        $error = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
} else {
    $error = 'No product_id supplied. Pass ?product_id=&lt;your-polar-product-id&gt; in the URL.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>6chan - Checkout</title>
  <link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
  <link rel="stylesheet" href="../style.css">
  <script src="https://unpkg.com/@polar-sh/checkout@0/dist/embed.global.js"
          defer data-auto-init></script>
  <style>
    html, body {
      height: 100%;
      background: var(--cbg);
      color: var(--text);
      font-family: 'MapleMono', monospace;
      font-size: 15px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }

    .page {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
      background:
        radial-gradient(ellipse 60% 50% at 50% -10%, rgba(108,99,255,.18) 0%, transparent 70%),
        var(--cbg);
    }
  </style>
</head>
<body>
<div class="page">
  <div class="card">
    <div class="card-header">
      <span class="logo-dot"></span>
      <span class="card-title">Complete your purchase</span>
    </div>

    <div class="card-body">
      <?php if ($error): ?>
        <div class="error-box">
          <div class="error-label">Error</div>
          <div class="error-msg"><?= $error ?></div>
        </div>

      <?php elseif ($embedUrl): ?>
        <div class="spinner-wrap" id="loadingState">
          <div class="spinner"></div>
          <span>Preparing checkout…</span>
        </div>

        <div class="embed-wrapper" id="polarWrap" style="display:none;">
          <div data-polar-checkout="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>

        <div class="fallback" id="fallbackRow" style="display:none;">
          <a class="btn btn-primary" href="<?= htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') ?>">
            Open checkout →
          </a>
        </div>

        <script>
          (function () {
            var TIMEOUT_MS = 8000;
            var wrap    = document.getElementById('polarWrap');
            var loading = document.getElementById('loadingState');
            var fallback = document.getElementById('fallbackRow');

            function reveal() {
              loading.style.display = 'none';
              wrap.style.display    = 'block';
            }

            function showFallback() {
              loading.style.display = 'none';
              fallback.style.display = 'block';
            }

            window.addEventListener('message', function (e) {
              if (e.data && (e.data.type === 'polar:loaded' || e.data.event === 'loaded')) {
                reveal();
              }
            });

            var timer = setTimeout(reveal, TIMEOUT_MS);
          }());
        </script>

      <?php endif; ?>
    </div>

    <div class="card-footer">
      <span class="footer-note">Secured by <strong>[Polar]</strong> &amp; [Stripe]</span>
      <a class="back-link" href="../index.php">[←] Back to home</a> 
    </div>
  </div>
</div>
</body>
</html>
