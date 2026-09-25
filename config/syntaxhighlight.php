<?php
// TODO: Finish the syntaz highlighter for code blocks
require_once __DIR__ . '/../vendor/autoload.php';

const EXTENSIONS = [
    'php' => 'php', 'phtml' => 'php',
    'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
    'ts' => 'typescript', 'tsx' => 'tsx', 'jsx' => 'jsx',
    'py' => 'python',
    'rb' => 'ruby',
    'go' => 'go',
    'rs' => 'rust',
    'c' => 'c', 'h' => 'c',
    'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp', 'hpp' => 'cpp', 'hh' => 'cpp',
    'java' => 'java',
    'cs' => 'csharp',
    'sh' => 'bash', 'bash' => 'bash',
    'sql' => 'sql',
    'yml' => 'yaml', 'yaml' => 'yaml',
    'json' => 'json',
    'md' => 'markdown', 'markdown' => 'markdown',
    'html' => 'html', 'htm' => 'html',
    'css' => 'css',
    'xml' => 'xml',
    'toml' => 'toml',
    'ini' => 'ini',
    'diff' => 'diff', 'patch' => 'diff',
];
