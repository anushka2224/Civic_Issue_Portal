<?php
// includes/email_helper.php - COMPLETE WITH PASSWORD RESET FUNCTION
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if PHPMailer exists
$phpmailer_path = __DIR__ . '/../PHPMailer/PHPMailer.php';
if (!file_exists($phpmailer_path)) {
    // Create a fallback class
    class EmailHelper {
        public static function sendWelcomeEmail($userEmail, $userName, $loginUrl) {
            error_log("PHPMailer not found - email not sent to $userEmail");
            return ['success' => false, 'message' => 'PHPMailer not installed'];
        }
        
        public static function sendStatusUpdateEmail($userEmail, $userName, $subject, $message) {
            error_log("PHPMailer not found - status email not sent to $userEmail");
            return ['success' => false, 'message' => 'PHPMailer not installed'];
        }
        
        public static function sendPasswordResetEmail($userEmail, $userName, $resetLink) {
            error_log("PHPMailer not found - reset email not sent to $userEmail");
            return ['success' => false, 'message' => 'PHPMailer not installed'];
        }
    }
    return;
}

require_once $phpmailer_path;
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailHelper {
    
    /**
     * Send Welcome Email
     */
    public static function sendWelcomeEmail($userEmail, $userName, $loginUrl) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Welcome to Civic Issue Portal!';
            
            // HTML Email Template
            $mail->Body = self::getWelcomeTemplate($userName, $loginUrl);
            
            $mail->send();
            error_log("Welcome email sent to: $userEmail");
            return ['success' => true, 'message' => 'Email sent successfully'];
            
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            return ['success' => false, 'message' => $mail->ErrorInfo];
        }
    }
    
    /**
     * Send Status Update Email
     */
    public static function sendStatusUpdateEmail($userEmail, $userName, $subject, $htmlContent) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($userEmail, $userName);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlContent;
            
            $mail->send();
            error_log("Status email sent to: $userEmail");
            return ['success' => true, 'message' => 'Email sent'];
            
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            return ['success' => false, 'message' => $mail->ErrorInfo];
        }
    }
    
    /**
     * Send Password Reset Email - NEW FUNCTION
     */
    public static function sendPasswordResetEmail($userEmail, $userName, $resetLink) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($userEmail, $userName);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request - ' . SMTP_FROM_NAME;
            
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                    .content { padding: 30px; }
                    .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 25px; margin: 20px 0; }
                    .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; border-top: 1px solid #eee; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>🏛️ " . SMTP_FROM_NAME . "</h1>
                        <p>Password Reset Request</p>
                    </div>
                    <div class='content'>
                        <h2>Hello " . htmlspecialchars($userName) . ",</h2>
                        <p>We received a request to reset your password. Click the button below:</p>
                        <div style='text-align: center;'>
                            <a href='" . $resetLink . "' class='button'>Reset Password</a>
                        </div>
                        <p>If you didn't request this, ignore this email.</p>
                        <p>This link expires in 1 hour for security reasons.</p>
                    </div>
                    <div class='footer'>
                        <p>© " . date('Y') . " " . SMTP_FROM_NAME . ". All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->send();
            error_log("Password reset email sent to: $userEmail");
            return ['success' => true, 'message' => 'Reset email sent'];
            
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            return ['success' => false, 'message' => $mail->ErrorInfo];
        }
    }
    
    /**
     * Get Welcome Email Template
     */
    private static function getWelcomeTemplate($userName, $loginUrl) {
        $siteName = SMTP_FROM_NAME;
        $year = date('Y');
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { padding: 30px; }
                .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 25px; margin: 20px 0; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; border-top: 1px solid #eee; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🏛️ $siteName</h1>
                    <p>Welcome to our community</p>
                </div>
                <div class="content">
                    <h2>Hello $userName,</h2>
                    <p>Thank you for registering! Your account has been successfully created.</p>
                    <div style="text-align: center;">
                        <a href="$loginUrl" class="button">🔐 Login to Your Account</a>
                    </div>
                </div>
                <div class="footer">
                    <p>© $year $siteName. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}
?>