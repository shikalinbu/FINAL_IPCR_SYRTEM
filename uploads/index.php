<?php
// Prevent direct browsing of uploads folder
header('HTTP/1.0 403 Forbidden');
echo 'Access denied.';
exit;
