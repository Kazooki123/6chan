<?php
session_start();
require_once '../../config/captchav2.php';
header('Content-Type: application/json');
$captcha = captchav2_generate();
echo json_encode(['token' => $captcha['token']]);
