<?php
// includes/config.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'civic_portal');

// Site Configuration - MAKE SURE THIS IS CORRECT
define('SITE_NAME', 'Civic Issue Portal');
define('SITE_URL', 'http://localhost/Civic%20Issue%20Portal'); // ← THIS MUST BE CORRECT

// File Upload Settings
define('MAX_FILE_SIZE', 5242880);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif']);

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');
?>