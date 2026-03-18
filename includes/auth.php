<?php
// includes/auth.php - COMPLETE FIXED VERSION
require_once 'db_connection.php';

class Auth {
    
    /**
     * Login user with username/email and password
     */
    public static function login($username, $password) {
        $db = Database::getInstance()->getConnection();
        
        if (!$db) {
            error_log("Auth: Database connection failed");
            return ['success' => false, 'message' => 'Database connection error'];
        }
        
        // Check if users table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'users'");
        if (!$tableCheck || $tableCheck->num_rows == 0) {
            error_log("Auth: Users table does not exist");
            return ['success' => false, 'message' => 'Users table does not exist'];
        }
        
        // Prepare login query
        $sql = "SELECT user_id, username, email, password_hash, full_name, role 
                FROM users WHERE (username = ? OR email = ?) AND is_active = 1";
        
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("Auth: Prepare failed - " . $db->error);
            return ['success' => false, 'message' => 'Database error occurred'];
        }
        
        $stmt->bind_param("ss", $username, $username);
        
        if (!$stmt->execute()) {
            error_log("Auth: Execute failed - " . $stmt->error);
            return ['success' => false, 'message' => 'Login failed'];
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password_hash'])) {
                // Update last login
                self::updateLastLogin($user['user_id']);
                
                // Set session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                
                error_log("Auth: Login successful for user: " . $username);
                
                unset($user['password_hash']);
                return [
                    'success' => true, 
                    'message' => 'Login successful',
                    'user' => $user
                ];
            } else {
                return ['success' => false, 'message' => 'Invalid password'];
            }
        } else {
            return ['success' => false, 'message' => 'User not found or account is inactive'];
        }
    }
    
    /**
     * Update last login time
     */
    private static function updateLastLogin($user_id) {
        $db = Database::getInstance()->getConnection();
        if (!$db) return;
        
        $sql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        }
    }
    
    /**
     * Generate remember me token - FIXED VERSION
     */
    public static function generateRememberToken($user_id) {
        $db = Database::getInstance()->getConnection();
        if (!$db) {
            error_log("generateRememberToken: Database connection failed");
            return '';
        }
        
        // First check if the columns exist
        $checkColumns = $db->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
        if (!$checkColumns || $checkColumns->num_rows == 0) {
            error_log("generateRememberToken: remember_token column does not exist");
            return '';
        }
        
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $sql = "UPDATE users SET remember_token = ?, token_expiry = ? WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        
        if (!$stmt) {
            error_log("generateRememberToken: Prepare failed - " . $db->error);
            return '';
        }
        
        $stmt->bind_param("ssi", $token, $expiry, $user_id);
        
        if ($stmt->execute()) {
            return $token;
        } else {
            error_log("generateRememberToken: Execute failed - " . $stmt->error);
            return '';
        }
    }
    
    /**
     * Login with remember me token
     */
    public static function loginWithToken($token) {
        $db = Database::getInstance()->getConnection();
        if (!$db) return false;
        
        $sql = "SELECT user_id, username, full_name, role FROM users 
                WHERE remember_token = ? AND token_expiry > NOW() AND is_active = 1";
        
        $stmt = $db->prepare($sql);
        if (!$stmt) return false;
        
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    /**
     * Check if current user is admin
     */
    public static function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    /**
     * Get current user's details
     */
    public static function currentUser() {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        $db = Database::getInstance()->getConnection();
        if (!$db) return null;
        
        $stmt = $db->prepare("SELECT user_id, username, email, full_name, phone, role, profile_pic, created_at 
                              FROM users WHERE user_id = ?");
        if (!$stmt) return null;
        
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Logout current user
     */
    public static function logout() {
        // Clear remember me token if exists
        if (isset($_SESSION['user_id'])) {
            self::clearRememberToken($_SESSION['user_id']);
        }
        
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        return true;
    }
    
    /**
     * Clear remember me token
     */
    private static function clearRememberToken($user_id) {
        $db = Database::getInstance()->getConnection();
        if (!$db) return;
        
        // Check if columns exist
        $checkColumns = $db->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
        if (!$checkColumns || $checkColumns->num_rows == 0) {
            return;
        }
        
        $sql = "UPDATE users SET remember_token = NULL, token_expiry = NULL WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        }
    }
    
    /**
     * Require user to be logged in
     */
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . SITE_URL . '/login.php');
            exit();
        }
    }
    
    /**
     * Require user to be admin
     */
    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . SITE_URL . '/index.php');
            exit();
        }
    }
}
?>