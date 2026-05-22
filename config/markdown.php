<?php
require_once __DIR__ . '/../vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\MarkdownConverter;

function get_markdown_converter(): MarkDownConverter {
    static $converter = null;
    if ($converter) return $converter;

    $config = [
        'html_input'         => 'strip', 
        'allow_unsafe_links' => false,
        'max_nesting_level'  => 10,  // Prevents deep nested bomb attacks
    ];

    $env = new Environment($config);
    $env->addExtension(new CommonMarkCoreExtension());
    $env->addExtension(new StrikethroughExtension());
    $env->addExtension(new TableExtension());
    $env->addExtension(new AutolinkExtension());

    $converter = new MarkdownConverter($env);
    return $converter;
}

function get_purifier(): HTMLPurifier {
    static $purifier = null;
    if ($purifier) return $purifier;

    $config = HTMLPurifier_Config::createDefault();

    $cache = __DIR__ . '/../cache/htmlpurifier';
    if (!is_dir($cache)) mkdir($cache, 0755, true);
    $config->set('Cache.SerializerPath', $cache);

    $config->set('HTML.Allowed',
        'p,br,strong,b,em,i,del,s,code,pre,blockquote,' .
        'ul,ol,li,a[href|title],img[src|alt|title|width|height],' .
        'h1,h2,h3,h4,h5,h6,table,thead,tbody,tr,th,td,hr,span'
    );
    
    $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
    $config->set('URI.DisableExternalResources', true);

    $purifier = new HTMLPurifier($config);
    return $purifier;
}

function render_markdown(string $text): string {
    $converter = get_markdown_converter();
    $purifier  = get_purifier();

    $html  = $converter->convert($text)->getContent();
    $clean = $purifier->purify($html);

    return $clean;
}

?>