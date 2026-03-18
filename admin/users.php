<?php
// admin/users.php - DARK THEME REDESIGN
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session only if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

// Require admin login
Auth::requireAdmin();

$currentUser = Auth::currentUser();
$db = getDB();

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Check if user exists
    $check = $db->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "Username or email already exists!";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, phone, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $username, $email, $password_hash, $full_name, $phone, $role, $is_active);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "User added successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to add user: " . $db->error;
        }
    }
    header("Location: users.php");
    exit();
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $user_id = (int)$_POST['user_id'];
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $db->prepare("UPDATE users SET full_name = ?, phone = ?, role = ?, is_active = ? WHERE user_id = ?");
    $stmt->bind_param("sssii", $full_name, $phone, $role, $is_active, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "User updated successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to update user: " . $db->error;
    }
    header("Location: users.php");
    exit();
}

// Handle Delete User
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    
    // Don't allow deleting yourself
    if ($user_id == $currentUser['user_id']) {
        $_SESSION['error_message'] = "You cannot delete your own account!";
    } else {
        // Check if user has issues
        $check = $db->query("SELECT issue_id FROM issues WHERE user_id = $user_id LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $_SESSION['error_message'] = "Cannot delete user - they have reported issues!";
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "User deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to delete user: " . $db->error;
            }
        }
    }
    header("Location: users.php");
    exit();
}

// Toggle user status
if (isset($_GET['toggle'])) {
    $user_id = (int)$_GET['toggle'];
    $db->query("UPDATE users SET is_active = NOT is_active WHERE user_id = $user_id");
    $_SESSION['success_message'] = "User status updated!";
    header("Location: users.php");
    exit();
}

// Reset password
if (isset($_GET['reset_password'])) {
    $user_id = (int)$_GET['reset_password'];
    $new_password = bin2hex(random_bytes(4)); // Generate random 8 char password
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    $stmt->bind_param("si", $password_hash, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Password reset successfully! New password: $new_password";
    } else {
        $_SESSION['error_message'] = "Failed to reset password";
    }
    header("Location: users.php");
    exit();
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

if (!empty($role_filter)) {
    $where[] = "role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

if ($status_filter !== '') {
    $where[] = "is_active = ?";
    $params[] = $status_filter;
    $types .= "i";
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM users $where_clause";
$count_stmt = $db->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// Get users
$sql = "SELECT * FROM users $where_clause ORDER BY user_id DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$params_paginated = array_merge($params, [$per_page, $offset]);
$types_paginated = $types . "ii";
$stmt->bind_param($types_paginated, ...$params_paginated);
$stmt->execute();
$users = $stmt->get_result();

// Get statistics
$stats = [];
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN role = 'citizen' THEN 1 ELSE 0 END) as citizens,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
    FROM users";
$stats_result = $db->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
}

// Helper function for role badge
function getRoleBadge($role) {
    switch($role) {
        case 'admin':
            return '<span class="role-badge role-admin"><i class="fas fa-shield-alt"></i> Admin</span>';
        case 'citizen':
            return '<span class="role-badge role-citizen"><i class="fas fa-user"></i> Citizen</span>';
        default:
            return '<span class="role-badge">' . ucfirst($role) . '</span>';
    }
}

// Helper function for status badge
function getStatusBadge($is_active) {
    if ($is_active) {
        return '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>';
    } else {
        return '<span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Panel - Civic Issue Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700;800&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #c49a1f;
            --primary-dark: #9e7c1a;
            --primary-light: #e6b422;
            --secondary: #8B4513;
            --accent: #5D3A1A;
            --saffron: #FF9933;
            --green: #138808;
            --navy: #000080;
            --dark-bg: #0a0806;
            --dark-card: #1a130e;
            --darker-card: #231f1a;
            --text-light: #f0e6d8;
            --text-muted: #c0b0a0;
            --gold: #d4af37;
            --success: #10b981;
            --success-dark: #059669;
            --success-light: #34d399;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --shadow-sm: 0 4px 6px rgba(0,0,0,0.3);
            --shadow-md: 0 10px 20px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.6);
            --shadow-xl: 0 30px 60px rgba(0,0,0,0.8);
            --glow: 0 0 30px rgba(212, 175, 55, 0.3);
            --glow-strong: 0 0 50px rgba(212, 175, 55, 0.6);
            --sidebar-width: 280px;
        }

        body {
            font-family: 'Space Grotesk', sans-serif;
            background: var(--dark-bg);
            color: var(--text-light);
            overflow-x: hidden;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background: var(--darker-card);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--saffron), var(--gold));
        }

        /* Dynamic Animated Background */
        .dynamic-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.15;
            animation: orbFloat 20s infinite ease-in-out;
        }

        .orb1 {
            width: 600px;
            height: 600px;
            background: var(--saffron);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }

        .orb2 {
            width: 500px;
            height: 500px;
            background: var(--gold);
            bottom: -150px;
            right: -150px;
            animation-delay: -5s;
        }

        .orb3 {
            width: 400px;
            height: 400px;
            background: var(--green);
            top: 40%;
            right: 10%;
            animation-delay: -2s;
        }

        .orb4 {
            width: 350px;
            height: 350px;
            background: var(--navy);
            bottom: 20%;
            left: 5%;
            animation-delay: -7s;
        }

        @keyframes orbFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(100px, 100px) scale(1.2); }
            66% { transform: translate(-50px, 50px) scale(0.9); }
        }

        /* Floating Particles */
        .particles {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(212, 175, 55, 0.1) 0%, transparent 5%),
                radial-gradient(circle at 80% 70%, rgba(255, 153, 51, 0.1) 0%, transparent 5%),
                radial-gradient(circle at 40% 80%, rgba(19, 136, 8, 0.1) 0%, transparent 5%);
            background-size: 200px 200px;
            animation: particleDrift 30s linear infinite;
        }

        @keyframes particleDrift {
            0% { background-position: 0 0; }
            100% { background-position: 200px 200px; }
        }

        /* Floating Indian Motifs */
        .floating-motifs {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .motif {
            position: absolute;
            font-family: 'Playfair Display', serif;
            font-size: 15rem;
            color: rgba(212, 175, 55, 0.03);
            animation: motifFloat 30s infinite linear;
            user-select: none;
        }

        .motif1 {
            top: 10%;
            left: 5%;
            transform: rotate(-15deg);
            animation-duration: 40s;
        }

        .motif2 {
            bottom: 15%;
            right: 8%;
            transform: rotate(25deg);
            animation-duration: 35s;
            animation-delay: -5s;
        }

        @keyframes motifFloat {
            0% { transform: rotate(0deg) translateY(0); }
            50% { transform: rotate(5deg) translateY(-20px); }
            100% { transform: rotate(0deg) translateY(0); }
        }

        /* Grid Pattern */
        .grid-pattern {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(212, 175, 55, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(212, 175, 55, 0.02) 1px, transparent 1px);
            background-size: 80px 80px;
            animation: gridMove 40s linear infinite;
        }

        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(80px, 80px); }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
                box-shadow: var(--glow);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-5px);
            }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .animate-slideIn {
            animation: slideIn 0.5s ease-out;
        }

        .animate-slideUp {
            animation: slideUp 0.5s ease-out;
        }

        .animate-scaleIn {
            animation: scaleIn 0.5s ease-out;
        }

        .animate-pulse {
            animation: pulse 2s infinite;
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

        .animate-spin {
            animation: spin 1s linear infinite;
        }

        .stagger-1 { animation-delay: 0.1s; }
        .stagger-2 { animation-delay: 0.2s; }
        .stagger-3 { animation-delay: 0.3s; }
        .stagger-4 { animation-delay: 0.4s; }
        .stagger-5 { animation-delay: 0.5s; }

        /* Admin Wrapper */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Modern Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--darker-card) 0%, var(--dark-card) 100%);
            border-right: 1px solid rgba(212, 175, 55, 0.2);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-lg);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            animation: slideIn 0.5s ease-out;
        }

        .sidebar-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .sidebar-header p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .sidebar-menu {
            padding: 2rem 0;
        }

        .menu-item {
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            margin: 0.5rem 0;
            animation: slideIn 0.5s ease-out;
            animation-fill-mode: both;
        }

        .menu-item:nth-child(1) { animation-delay: 0.1s; }
        .menu-item:nth-child(2) { animation-delay: 0.15s; }
        .menu-item:nth-child(3) { animation-delay: 0.2s; }
        .menu-item:nth-child(4) { animation-delay: 0.25s; }
        .menu-item:nth-child(5) { animation-delay: 0.3s; }
        .menu-item:nth-child(6) { animation-delay: 0.35s; }
        .menu-item:nth-child(7) { animation-delay: 0.4s; }
        .menu-item:nth-child(8) { animation-delay: 0.45s; }
        .menu-item:nth-child(9) { animation-delay: 0.5s; }

        .menu-item:hover {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold);
            border-left-color: var(--gold);
            transform: translateX(5px);
        }

        .menu-item.active {
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            border-left-color: var(--gold);
        }

        .menu-item i {
            width: 24px;
            font-size: 1.2rem;
        }

        .badge {
            background: var(--danger);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-left: auto;
            animation: pulse 2s infinite;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 2rem;
            position: relative;
            z-index: 10;
        }

        /* Top Bar */
        .top-bar {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            padding: 1.5rem 2rem;
            border-radius: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideUp 0.5s ease-out;
        }

        .page-title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.3rem;
        }

        .page-title p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .notifications {
            position: relative;
            cursor: pointer;
        }

        .notifications i {
            font-size: 1.3rem;
            color: var(--text-muted);
            transition: color 0.3s;
        }

        .notifications:hover i {
            color: var(--gold);
        }

        .notif-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem;
            background: var(--darker-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 2rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-dropdown:hover {
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-bg);
            font-weight: 600;
        }

        /* Navigation Bar */
        .nav-bar {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            background: var(--darker-card);
            padding: 0.3rem;
            border-radius: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            margin-right: 1rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            transition: all 0.3s;
            font-weight: 500;
        }

        .nav-link:hover {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold);
            transform: translateY(-2px);
        }

        .back-to-site {
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            color: var(--dark-bg) !important;
        }

        .back-to-site:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--glow-strong);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            border-radius: 1.2rem;
            padding: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            animation: scaleIn 0.5s ease-out;
            animation-fill-mode: both;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--gold), var(--saffron), var(--green));
            transform: translateX(-100%);
            transition: transform 0.5s;
        }

        .stat-card:hover::before {
            transform: translateX(0);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.15s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .stat-card:nth-child(4) { animation-delay: 0.25s; }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            color: var(--text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 0.3rem;
        }

        .stat-trend {
            font-size: 0.85rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .stat-trend i {
            color: var(--success);
        }

        /* Action Bar */
        .action-bar {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            border-radius: 1.2rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--darker-card);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            flex: 1;
            max-width: 400px;
        }

        .search-box i {
            color: var(--gold);
        }

        .search-box input {
            border: none;
            background: none;
            outline: none;
            width: 100%;
            font-size: 0.95rem;
            color: var(--text-light);
        }

        .search-box input::placeholder {
            color: var(--text-muted);
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 0.5rem 2rem 0.5rem 1rem;
            background: var(--darker-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 2rem;
            color: var(--text-light);
            font-size: 0.95rem;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23d4af37' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
        }

        .filter-select option {
            background: var(--darker-card);
            color: var(--text-light);
        }

        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            color: var(--dark-bg);
            box-shadow: var(--glow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow-strong);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: var(--dark-bg);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }

        .btn-outline {
            background: var(--darker-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            color: var(--text-light);
        }

        .btn-outline:hover {
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-2px);
        }

        /* Users Table */
        .table-section {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            animation: slideUp 0.5s ease-out 0.4s both;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--gold);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 i {
            color: var(--gold);
        }

        .add-btn {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: var(--dark-bg);
            padding: 0.7rem 1.5rem;
            border-radius: 2rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 1rem;
            background: var(--darker-card);
            color: var(--gold);
            font-weight: 600;
            font-size: 0.9rem;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            color: var(--text-light);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(212, 175, 55, 0.05);
        }

        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .user-avatar-sm {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-bg);
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: var(--gold);
        }

        .user-email {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .role-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .role-admin {
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            border: 1px solid var(--gold);
        }

        .role-citizen {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid #10b981;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid #10b981;
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            background: var(--darker-card);
            color: var(--text-muted);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .action-btn:hover {
            transform: scale(1.1);
        }

        .action-btn.edit:hover {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border-color: #f59e0b;
        }

        .action-btn.toggle:hover {
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            border-color: var(--gold);
        }

        .action-btn.reset:hover {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            border-color: #3b82f6;
        }

        .action-btn.delete:hover {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border-color: #ef4444;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 0.5rem;
            background: var(--darker-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: var(--gold);
            border-color: var(--gold);
            color: var(--dark-bg);
            transform: translateY(-2px);
        }

        .page-link.active {
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            color: var(--dark-bg);
            border-color: transparent;
        }

        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 8, 6, 0.95);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--dark-card);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 2rem;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: scaleIn 0.3s ease-out;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding-bottom: 1rem;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            color: var(--gold);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-header h3 i {
            color: var(--gold);
        }

        .close-modal {
            font-size: 2rem;
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--gold);
            font-weight: 500;
        }

        .form-group label i {
            color: var(--gold);
            margin-right: 0.3rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.8rem 1rem;
            background: var(--darker-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 1rem;
            font-size: 0.95rem;
            transition: all 0.3s;
            color: var(--text-light);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .form-control[readonly] {
            background: var(--dark-card);
            opacity: 0.7;
            cursor: not-allowed;
        }

        .form-select option {
            background: var(--darker-card);
            color: var(--text-light);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-group input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--gold);
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }

        small {
            color: var(--text-muted) !important;
            font-size: 0.8rem;
            margin-top: 0.3rem;
            display: block;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--dark-card);
            border: 1px solid rgba(212, 175, 55, 0.3);
            padding: 1rem 1.5rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 1rem;
            transform: translateX(400px);
            transition: transform 0.3s;
            z-index: 9999;
            border-left: 4px solid;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            border-left-color: var(--success);
        }

        .toast.error {
            border-left-color: var(--danger);
        }

        .toast.info {
            border-left-color: var(--info);
        }

        .toast i.success { color: var(--success); }
        .toast i.error { color: var(--danger); }
        .toast i.info { color: var(--info); }

        /* Quick Actions */
        .quick-actions {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 999;
        }

        .quick-actions-btn {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-bg);
            font-size: 1.5rem;
            cursor: pointer;
            box-shadow: var(--glow-strong);
            transition: all 0.3s;
            animation: float 3s ease-in-out infinite;
        }

        .quick-actions-btn:hover {
            transform: scale(1.1) rotate(90deg);
        }

        .quick-actions-menu {
            position: absolute;
            bottom: 70px;
            right: 0;
            background: var(--dark-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 1rem;
            box-shadow: var(--shadow-lg);
            padding: 0.5rem 0;
            min-width: 220px;
            display: none;
            animation: slideUp 0.3s ease;
        }

        .quick-actions:hover .quick-actions-menu {
            display: block;
        }

        .quick-action-item {
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s;
        }

        .quick-action-item:hover {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold);
            transform: translateX(5px);
        }

        .quick-action-item i {
            width: 20px;
            color: var(--gold);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--darker-card);
            border-radius: 1rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gold);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--gold);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-muted);
        }

        /* Keyboard Shortcuts */
        kbd {
            background: var(--darker-card);
            border: 1px solid var(--gold);
            border-radius: 4px;
            color: var(--gold);
            padding: 0.2rem 0.5rem;
            font-size: 0.85rem;
            font-family: monospace;
        }

        .shortcuts-table {
            width: 100%;
            border-collapse: collapse;
        }

        .shortcuts-table td {
            padding: 0.8rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
        }

        .shortcuts-table tr:last-child td {
            border-bottom: none;
        }

        .shortcut-key {
            color: var(--gold);
            font-weight: 600;
            width: 120px;
        }

        .shortcut-desc {
            color: var(--text-light);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                max-width: 100%;
            }

            .filter-group {
                justify-content: space-between;
            }

            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }

            .user-info-cell {
                flex-direction: column;
                align-items: flex-start;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .user-info {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .nav-bar {
                margin-right: 0;
                margin-bottom: 1rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-group {
                flex-direction: column;
                width: 100%;
            }

            .filter-select {
                width: 100%;
            }

            .page-link {
                min-width: 35px;
                height: 35px;
            }

            .quick-actions {
                bottom: 1rem;
                right: 1rem;
            }

            .quick-actions-btn {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Dynamic Animated Background -->
    <div class="dynamic-bg">
        <div class="bg-orb orb1"></div>
        <div class="bg-orb orb2"></div>
        <div class="bg-orb orb3"></div>
        <div class="bg-orb orb4"></div>
        <div class="particles"></div>
        <div class="floating-motifs">
            <div class="motif motif1">ॐ</div>
            <div class="motif motif2">🕉️</div>
        </div>
        <div class="grid-pattern"></div>
    </div>

    <div class="admin-wrapper">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Civic<span style="color: var(--saffron);">Issue</span>Portal</h2>
                <p>Admin Panel</p>
            </div>
            
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="manage-issues.php" class="menu-item">
                    <i class="fas fa-exclamation-triangle"></i> All Issues
                </a>
                <a href="pending.php" class="menu-item">
                    <i class="fas fa-clock"></i> Pending
                </a>
                <a href="in-progress.php" class="menu-item">
                    <i class="fas fa-spinner fa-pulse"></i> In Progress
                </a>
                <a href="resolved.php" class="menu-item">
                    <i class="fas fa-check-circle"></i> Resolved
                </a>
                <a href="categories.php" class="menu-item">
                    <i class="fas fa-tags"></i> Categories
                </a>
                <a href="users.php" class="menu-item active">
                    <i class="fas fa-users"></i> Users
                    <span class="badge"><?php echo $stats['total']; ?></span>
                </a>
                <a href="../index.php" class="menu-item">
                    <i class="fas fa-arrow-left"></i> Back to Site
                </a>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1><i class="fas fa-users" style="color: var(--gold);"></i> Manage Users</h1>
                    <p>View and manage all registered users</p>
                </div>
                <div style="display: flex; align-items: center; gap: 2rem;">
                    <!-- Navigation Bar -->
                    <div class="nav-bar">
                        <a href="../index.php" class="nav-link back-to-site">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="../view-issues.php" class="nav-link">
                            <i class="fas fa-list"></i> Issues
                        </a>
                        <a href="../report-issue.php" class="nav-link">
                            <i class="fas fa-plus-circle"></i> Report
                        </a>
                    </div>

                    <div class="user-info">
                        <div class="notifications" onclick="toggleNotifications()">
                            <i class="fas fa-bell"></i>
                            <span class="notif-count">3</span>
                        </div>
                        <div class="user-dropdown">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($currentUser['full_name'] ?? $currentUser['username'], 0, 1)); ?>
                            </div>
                            <div>
                                <strong style="color: var(--gold);"><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?></strong>
                                <p style="font-size: 0.8rem; color: var(--text-muted);">Administrator</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='users.php'">
                    <div class="stat-header">
                        <span class="stat-title">Total Users</span>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up" style="color: var(--success);"></i> +<?php echo $stats['today']; ?> today
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='?role=citizen'">
                    <div class="stat-header">
                        <span class="stat-title">Citizens</span>
                        <div class="stat-icon" style="color: var(--success);">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['citizens']); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-user-check"></i> Regular users
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='?role=admin'">
                    <div class="stat-header">
                        <span class="stat-title">Admins</span>
                        <div class="stat-icon" style="color: var(--gold);">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['admins']); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-shield-alt"></i> Administrators
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=1'">
                    <div class="stat-header">
                        <span class="stat-title">Active Users</span>
                        <div class="stat-icon" style="color: var(--success);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-circle" style="color: var(--success);"></i> <?php echo $stats['inactive']; ?> inactive
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <form method="GET" style="flex: 1; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search users by name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <select name="role" class="filter-select">
                            <option value="">All Roles</option>
                            <option value="citizen" <?php echo $role_filter == 'citizen' ? 'selected' : ''; ?>>Citizen</option>
                            <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                        
                        <select name="status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        
                        <a href="users.php" class="btn btn-outline">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="table-section">
                <div class="section-header">
                    <h2><i class="fas fa-users"></i> User List</h2>
                    <button class="add-btn" onclick="openAddModal()">
                        <i class="fas fa-plus-circle"></i> Add New User
                    </button>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php while($user = $users->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info-cell">
                                                <div class="user-avatar-sm">
                                                    <?php echo strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <span class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
                                                    <span class="user-email">@<?php echo htmlspecialchars($user['username']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div><i class="fas fa-envelope" style="color: var(--gold); width: 16px;"></i> <?php echo htmlspecialchars($user['email']); ?></div>
                                                <?php if (!empty($user['phone'])): ?>
                                                    <div style="margin-top: 0.3rem;"><i class="fas fa-phone" style="color: var(--gold); width: 16px;"></i> <?php echo htmlspecialchars($user['phone']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo getRoleBadge($user['role']); ?>
                                        </td>
                                        <td>
                                            <?php echo getStatusBadge($user['is_active']); ?>
                                        </td>
                                        <td>
                                            <i class="far fa-calendar-alt" style="color: var(--gold);"></i>
                                            <?php echo date('d M Y', strtotime($user['created_at'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <i class="far fa-clock" style="color: var(--gold);"></i>
                                                <?php echo date('d M Y', strtotime($user['last_login'])); ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn edit" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <a href="?toggle=<?php echo $user['user_id']; ?>" class="action-btn toggle" title="Toggle Status">
                                                    <i class="fas <?php echo $user['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                                                </a>
                                                
                                                <a href="?reset_password=<?php echo $user['user_id']; ?>" class="action-btn reset" title="Reset Password" onclick="return confirm('Reset password for this user? New password will be shown.')">
                                                    <i class="fas fa-key"></i>
                                                </a>
                                                
                                                <?php if ($user['user_id'] != $currentUser['user_id']): ?>
                                                    <a href="?delete=<?php echo $user['user_id']; ?>" 
                                                       class="action-btn delete" 
                                                       title="Delete"
                                                       onclick="return confirm('⚠️ Delete User?\n\nAre you sure you want to delete this user?\n\nThis action cannot be undone.')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 3rem;">
                                        <div class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <h3>No Users Found</h3>
                                            <p>No users match your search criteria.</p>
                                            <button class="btn btn-primary" onclick="openAddModal()" style="margin-top: 1rem;">
                                                <i class="fas fa-plus-circle"></i> Add New User
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                        $query_params = $_GET;
                        unset($query_params['page']);
                        $query_string = http_build_query($query_params);
                        $query_prefix = $query_string ? '&' . $query_string : '';
                        ?>
                        
                        <a href="?page=<?php echo max(1, $page-1); ?><?php echo $query_prefix; ?>" 
                           class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        
                        if ($start > 1) {
                            echo '<a href="?page=1' . $query_prefix . '" class="page-link">1</a>';
                            if ($start > 2) {
                                echo '<span class="page-link disabled">...</span>';
                            }
                        }
                        
                        for ($i = $start; $i <= $end; $i++) {
                            echo '<a href="?page=' . $i . $query_prefix . '" 
                                      class="page-link ' . ($i == $page ? 'active' : '') . '">' . $i . '</a>';
                        }
                        
                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) {
                                echo '<span class="page-link disabled">...</span>';
                            }
                            echo '<a href="?page=' . $total_pages . $query_prefix . '" 
                                      class="page-link">' . $total_pages . '</a>';
                        }
                        ?>

                        <a href="?page=<?php echo min($total_pages, $page+1); ?><?php echo $query_prefix; ?>" 
                           class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="quick-actions-btn">
                <i class="fas fa-plus"></i>
            </div>
            <div class="quick-actions-menu">
                <a href="#" class="quick-action-item" onclick="openAddModal()">
                    <i class="fas fa-user-plus"></i> Add User
                </a>
                <a href="#" class="quick-action-item" onclick="exportToCSV()">
                    <i class="fas fa-file-export"></i> Export Users
                </a>
                <a href="#" class="quick-action-item" onclick="showKeyboardShortcuts()">
                    <i class="fas fa-keyboard"></i> Shortcuts
                </a>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <span class="close-modal" onclick="closeAddModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username *</label>
                    <input type="text" name="username" class="form-control" required placeholder="johndoe">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email *</label>
                    <input type="email" name="email" class="form-control" required placeholder="user@example.com">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password *</label>
                    <input type="text" name="password" class="form-control" value="password123" required>
                    <small>Default: password123</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Full Name</label>
                    <input type="text" name="full_name" class="form-control" placeholder="John Doe">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+91 98765 43210">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-shield-alt"></i> Role</label>
                    <select name="role" class="form-select">
                        <option value="citizen">Citizen</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="is_active" checked>
                        <label for="is_active">Active Account</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-success">
                        <i class="fas fa-save"></i> Add User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <span class="close-modal" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="edit_id">
                <input type="hidden" name="edit_user" value="1">
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username</label>
                    <input type="text" id="edit_username" class="form-control" readonly disabled>
                    <small>Username cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" id="edit_email" class="form-control" readonly disabled>
                    <small>Email cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Full Name</label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-shield-alt"></i> Role</label>
                    <select name="role" id="edit_role" class="form-select">
                        <option value="citizen">Citizen</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="edit_active" value="1">
                        <label for="edit_active">Active Account</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Keyboard Shortcuts Modal -->
    <div id="shortcutsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-keyboard"></i> Keyboard Shortcuts</h3>
                <span class="close-modal" onclick="closeShortcutsModal()">&times;</span>
            </div>
            <div style="padding: 1rem;">
                <table class="shortcuts-table">
                    <tr>
                        <td class="shortcut-key"><kbd>Ctrl + F</kbd></td>
                        <td class="shortcut-desc">Focus search box</td>
                    </tr>
                    <tr>
                        <td class="shortcut-key"><kbd>Ctrl + N</kbd></td>
                        <td class="shortcut-desc">Add new user</td>
                    </tr>
                    <tr>
                        <td class="shortcut-key"><kbd>Ctrl + E</kbd></td>
                        <td class="shortcut-desc">Export to CSV</td>
                    </tr>
                    <tr>
                        <td class="shortcut-key"><kbd>?</kbd> or <kbd>Ctrl + K</kbd></td>
                        <td class="shortcut-desc">Show this help</td>
                    </tr>
                    <tr>
                        <td class="shortcut-key"><kbd>Esc</kbd></td>
                        <td class="shortcut-desc">Close modal</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <i id="toastIcon" class="fas fa-info-circle"></i>
        <span id="toastMessage"></span>
    </div>

    <script>
        // Mobile menu toggle
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }

        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        function editUser(user) {
            document.getElementById('edit_id').value = user.user_id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_full_name').value = user.full_name || '';
            document.getElementById('edit_phone').value = user.phone || '';
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_active').checked = user.is_active == 1;
            
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function showKeyboardShortcuts() {
            document.getElementById('shortcutsModal').classList.add('active');
        }

        function closeShortcutsModal() {
            document.getElementById('shortcutsModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            const shortcutsModal = document.getElementById('shortcutsModal');
            
            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
            if (event.target == shortcutsModal) {
                closeShortcutsModal();
            }
        }

        // Notifications
        function toggleNotifications() {
            showInfoModal('📬 Notifications', 'You have 3 unread notifications.\n\nNotification features coming soon!');
        }

        // Export to CSV
        function exportToCSV() {
            let users = [];
            <?php 
            $export_query = $db->query("SELECT * FROM users ORDER BY user_id DESC");
            $export_data = [];
            while($row = $export_query->fetch_assoc()) {
                $export_data[] = $row;
            }
            echo json_encode($export_data);
            ?>
            
            let csv = 'Username,Full Name,Email,Phone,Role,Status,Joined\n';
            users.forEach(user => {
                let status = user.is_active ? 'Active' : 'Inactive';
                let joined = new Date(user.created_at).toLocaleDateString('en-IN');
                csv += '"' + user.username + '","' + (user.full_name || '') + '","' + user.email + '","' + (user.phone || '') + '","' + user.role + '","' + status + '","' + joined + '"\n';
            });
            
            let blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'users_export_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
            
            showToast('Export started! ' + users.length + ' users exported.', 'success');
        }

        // Show info modal
        function showInfoModal(title, message) {
            const modal = document.createElement('div');
            modal.className = 'modal active';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>${title}</h3>
                        <span class="close-modal" onclick="this.closest('.modal').remove()">&times;</span>
                    </div>
                    <div style="color: var(--text-light); white-space: pre-line; padding: 1rem;">${message}</div>
                </div>
            `;
            document.body.appendChild(modal);
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            const msg = document.getElementById('toastMessage');
            
            toast.className = 'toast show ' + type;
            
            switch(type) {
                case 'success':
                    icon.className = 'fas fa-check-circle success';
                    break;
                case 'error':
                    icon.className = 'fas fa-exclamation-circle error';
                    break;
                default:
                    icon.className = 'fas fa-info-circle info';
            }
            
            msg.textContent = message;
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Check for session messages
        <?php if (isset($_SESSION['success_message'])): ?>
            showToast('<?php echo $_SESSION['success_message']; ?>', 'success');
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            showToast('<?php echo $_SESSION['error_message']; ?>', 'error');
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F - Focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
            
            // Ctrl + N - New user
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openAddModal();
            }
            
            // Ctrl + E - Export CSV
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
            }
            
            // ? or Ctrl+K - Show shortcuts
            if (e.key === '?' || (e.ctrlKey && e.key === 'k')) {
                e.preventDefault();
                showKeyboardShortcuts();
            }
            
            // Esc - Close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    if (modal.id !== 'addModal' && modal.id !== 'editModal' && modal.id !== 'shortcutsModal') {
                        modal.remove();
                    } else {
                        modal.classList.remove('active');
                    }
                });
            }
        });

        // Add mobile menu button
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth <= 1024) {
                const menuBtn = document.createElement('div');
                menuBtn.style.cssText = `
                    position: fixed;
                    top: 1rem;
                    left: 1rem;
                    z-index: 1001;
                    background: linear-gradient(135deg, var(--gold), var(--saffron));
                    color: var(--dark-bg);
                    width: 45px;
                    height: 45px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    box-shadow: var(--glow-strong);
                    animation: float 3s ease-in-out infinite;
                `;
                menuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                menuBtn.onclick = toggleSidebar;
                document.body.appendChild(menuBtn);
            }
        });

        // Smooth scroll to top on page change
        document.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!this.classList.contains('disabled')) {
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Animate numbers on load
        document.addEventListener('DOMContentLoaded', function() {
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const value = parseInt(stat.innerText.replace(/[^0-9]/g, ''));
                if (!isNaN(value) && value > 0) {
                    let current = 0;
                    const increment = Math.ceil(value / 50);
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= value) {
                            stat.innerText = stat.innerText.replace(/[0-9]+/, value);
                            clearInterval(timer);
                        } else {
                            stat.innerText = stat.innerText.replace(/[0-9]+/, current);
                        }
                    }, 20);
                }
            });
        });

        // Tooltip for long text
        document.querySelectorAll('.user-name').forEach(el => {
            if (el.scrollWidth > el.clientWidth) {
                el.setAttribute('title', el.textContent);
            }
        });
    </script>
</body>
</html>