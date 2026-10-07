<?php
session_start();
if (!($_SESSION['admin'] ?? false)) {
    header('Location: login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Healtcheck</title>
</head>
<body>
    <center>
        <p style="font-size:50px;color:red;">🚧 !!!On construction!!! 🚧</p>
    </center>
</body>
</html>