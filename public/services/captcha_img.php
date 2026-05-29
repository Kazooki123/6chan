<?php
session_start();
require_once '../../config/captchav2.php';

$token = $_GET['token'] ?? '';
if (!isset($_SESSION['captchav2'][$token])) {
    http_response_code(404);
    exit;
}

$code = $_SESSION['captchav2'][$token]['answer'];
captchav2_render($code);
