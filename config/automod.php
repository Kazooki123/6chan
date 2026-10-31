<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Codewithkyrian\Transformers\Transformers;
use function Codewithkyrian\Transformers\Pipelines\pipeline;
use Codewithkyrian\Transformers\Utils\ImageDriver;

const NSFW_MODEL_ID        = 'onnx-community/nsfw_image_detection-ONNX';
const NSFW_CONFIDENCE_FLAG = 0.75;

function automod_check(string $text, PDO $db): array {
    $rules = $db->query(
        "SELECT * FROM automod_rules ORDER BY rule_type"
    )->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'action' => 'allow',
        'reason' => null,
    ];

    foreach ($rules as $rule) {
        $matched = false;

        if ($rule['is_regex']) {
            $matched = (bool)preg_match('/' . $rule['pattern'] . '/iu', $text);
        } else {
            $matched = str_contains(mb_strtolower($text), mb_strtolower($rule['pattern']));
        }

        if (!$matched) continue;

        if ($rule['rule_type'] === 'block') {
            return ['action' => 'block', 'reason' => $rule['pattern']];
        }

        if ($rule['rule_type'] === 'flag') {
            $result = ['action' => 'flag', 'reason' => $rule['pattern']];
        }
    }
    return $result;
}

/**
 * Runs the NSFW image classifier (Falconsai/nsfw_image_detection, as a
 * pre-converted ONNX model — TransformersPHP only runs ONNX, it cannot
 * load the original .safetensors directly) against an uploaded image.
 *
 * Models cache to models/nsfw_image_detector/ (git-ignored — see repo
 * notes), downloaded automatically on first use.
 *
 * Returns the SAME ['action'=>'allow'|'flag', 'reason'=>...] shape as
 * automod_check() for text, so post.php can treat both uniformly.
 *
 * DELIBERATELY NEVER returns 'block' — a classifier can be wrong, and
 * outright blocking an upload gives the poster no recourse. 'flag'
 * sends it to admin/modqueue.php, where a mod actually decides:
 * delete it, or tag it NSFW and let it stand.
 */
function automod_check_image(string $local_path): array {
    static $classifier = null;
    static $init_failed = false;

    if ($init_failed) {
        return ['action' => 'allow', 'reason' => null];
    }
    
    try {
        if ($classifier === null) {
            Transformers::setup()
                ->setCacheDir(__DIR__ . '/../models/nsfw_image_detector');

            $classifier = pipeline('image-classification', NSFW_MODEL_ID, quantized: false);
        }
        $predictions = $classifier($local_path);
    } catch (\Throwable $e) {
        if ($classifier === null) {
            $init_failed = true;
        }
        error_log('[automod] NSFW classifier failed: ' . $e->getMessage());
        return ['action' => 'allow', 'reason' => null]; // fail open — a model error should never block a legitimate upload
    }

    foreach ($predictions as $pred) {
        if (($pred['label'] ?? '') === 'nsfw' && ($pred['score'] ?? 0) >= NSFW_CONFIDENCE_FLAG) {
            $confidence = round($pred['score'] * 100);
            return [
                'action' => 'flag',
                'reason' => "AI flagged image as NSFW ({$confidence}% confidence)",
            ];
        }
    }

    return ['action' => 'allow', 'reason' => null];
}
