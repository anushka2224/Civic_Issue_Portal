<?php
// includes/email_config.php - FIXED for Civic Issue Portal
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SMTP Configuration - DO NOT CHANGE THESE
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');

// YOUR GMAIL CREDENTIALS - UPDATE THESE
define('SMTP_USERNAME', 'vivek572166rage@gmail.com'); // Your Gmail
define('SMTP_PASSWORD', 'gturwbvibfulhpru'); // REPLACE with your app password (no spaces)

// Email Settings
define('SMTP_FROM_EMAIL', 'vivek572166rage@gmail.com'); // From email
define('SMTP_FROM_NAME', 'Civic Issue Portal'); // IMPORTANT: Changed to correct project name

// Debug mode (turn off in production)
define('SMTP_DEBUG', false); // Set to true to see detailed errors
?>