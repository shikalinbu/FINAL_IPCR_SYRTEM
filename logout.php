<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
logoutUser();
header('Location: ' . BASE_URL . '/index.php');
exit;
