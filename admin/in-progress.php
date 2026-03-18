<?php
// admin/in-progress.php - DARK THEME REDESIGN
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

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $issue_id = (int)$_POST['issue_id'];
    $new_status = $_POST['status'];
    $remarks = $_POST['remarks'] ?? '';
    $assigned_to = isset($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
    
    // Get old status for history
    $old_status_query = $db->query("SELECT status, user_id, title FROM issues WHERE issue_id = $issue_id");
    if ($old_status_query && $old_status_query->num_rows > 0) {
        $issue_data = $old_status_query->fetch_assoc();
        $old_status = $issue_data['status'];
        
        // Build update query
        $update_fields = ["status = ?", "updated_at = NOW()"];
        $params = [$new_status];
        $types = "s";
        
        if ($new_status == 'resolved') {
            $update_fields[] = "resolved_at = NOW()";
        }
        
        if ($assigned_to) {
            $update_fields[] = "assigned_to = ?";
            $params[] = $assigned_to;
            $types .= "i";
        }
        
        $params[] = $issue_id;
        $types .= "i";
        
        $update_sql = "UPDATE issues SET " . implode(", ", $update_fields) . " WHERE issue_id = ?";
        $stmt = $db->prepare($update_sql);
        
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                // Log status change in history
                $check_history = $db->query("SHOW TABLES LIKE 'status_history'");
                if ($check_history && $check_history->num_rows > 0) {
                    $history_sql = "INSERT INTO status_history (issue_id, old_status, new_status, changed_by, remarks, assigned_to) 
                                    VALUES (?, ?, ?, ?, ?, ?)";
                    $history_stmt = $db->prepare($history_sql);
                    if ($history_stmt) {
                        $history_stmt->bind_param("issisi", $issue_id, $old_status, $new_status, $currentUser['user_id'], $remarks, $assigned_to);
                        $history_stmt->execute();
                    }
                }
                
                // Create notification for the user
                $check_notifications = $db->query("SHOW TABLES LIKE 'notifications'");
                if ($check_notifications && $check_notifications->num_rows > 0) {
                    $notif_sql = "INSERT INTO notifications (user_id, issue_id, message, type) VALUES (?, ?, ?, 'status_update')";
                    $notif_stmt = $db->prepare($notif_sql);
                    if ($notif_stmt) {
                        $status_text = str_replace('_', ' ', $new_status);
                        $notif_message = "Your issue '{$issue_data['title']}' status changed to " . ucfirst($status_text);
                        $notif_stmt->bind_param("iis", $issue_data['user_id'], $issue_id, $notif_message);
                        $notif_stmt->execute();
                    }
                }
                
                $_SESSION['success_message'] = "Issue #$issue_id updated successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to update status: " . $stmt->error;
            }
        }
    }
    header("Location: in-progress.php");
    exit();
}

// Handle delete issue
if (isset($_GET['delete'])) {
    $issue_id = (int)$_GET['delete'];
    
    // Begin transaction
    $db->begin_transaction();
    
    try {
        // Get image paths before deleting
        $media_query = $db->query("SELECT media_path FROM issue_media WHERE issue_id = $issue_id");
        while($media = $media_query->fetch_assoc()) {
            if (file_exists($media['media_path'])) {
                unlink($media['media_path']);
            }
        }
        
        // Delete comments
        $db->query("DELETE FROM comments WHERE issue_id = $issue_id");
        
        // Delete upvotes
        $db->query("DELETE FROM upvotes WHERE issue_id = $issue_id");
        
        // Delete status history
        $db->query("DELETE FROM status_history WHERE issue_id = $issue_id");
        
        // Delete media records
        $db->query("DELETE FROM issue_media WHERE issue_id = $issue_id");
        
        // Delete the issue
        $result = $db->query("DELETE FROM issues WHERE issue_id = $issue_id");
        
        if ($result) {
            $db->commit();
            $_SESSION['success_message'] = "Issue #$issue_id deleted successfully!";
        } else {
            throw new Exception("Failed to delete issue");
        }
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error_message'] = "Error deleting issue: " . $e->getMessage();
    }
    
    header("Location: in-progress.php");
    exit();
}

// Get filter parameters
$status_filter = 'in_progress'; // Force in-progress status
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$assigned_filter = isset($_GET['assigned']) ? $_GET['assigned'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$where = ["i.status = 'in_progress'"]; // Only in-progress issues
$params = [];
$types = "";

if ($category_filter > 0) {
    $where[] = "i.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

if ($priority_filter && $priority_filter != 'all') {
    $where[] = "i.priority = ?";
    $params[] = $priority_filter;
    $types .= "s";
}

if ($assigned_filter === 'unassigned') {
    $where[] = "i.assigned_to IS NULL";
} elseif ($assigned_filter === 'assigned') {
    $where[] = "i.assigned_to IS NOT NULL";
} elseif ($assigned_filter > 0) {
    $where[] = "i.assigned_to = ?";
    $params[] = $assigned_filter;
    $types .= "i";
}

if (!empty($search)) {
    $where[] = "(i.title LIKE ? OR i.description LIKE ? OR i.location_address LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

if (!empty($date_from)) {
    $where[] = "DATE(i.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where[] = "DATE(i.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM issues i $where_clause";
$count_stmt = $db->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// Get in-progress issues with details
$sql = "SELECT i.*, 
               u.username, u.full_name as reporter_name,
               a.username as assigned_name, a.full_name as assigned_fullname,
               c.category_name,
               DATEDIFF(NOW(), i.updated_at) as days_in_progress,
               (SELECT COUNT(*) FROM comments WHERE issue_id = i.issue_id) as comment_count,
               (SELECT COUNT(*) FROM upvotes WHERE issue_id = i.issue_id) as upvote_count,
               (SELECT COUNT(*) FROM issue_media WHERE issue_id = i.issue_id) as media_count
        FROM issues i 
        LEFT JOIN users u ON i.user_id = u.user_id 
        LEFT JOIN users a ON i.assigned_to = a.user_id 
        LEFT JOIN categories c ON i.category_id = c.category_id 
        $where_clause 
        ORDER BY 
            CASE 
                WHEN i.priority = 'urgent' THEN 1
                WHEN i.priority = 'high' THEN 2
                WHEN i.priority = 'medium' THEN 3
                WHEN i.priority = 'low' THEN 4
            END,
            i.updated_at DESC
        LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
$params_paginated = array_merge($params, [$per_page, $offset]);
$types_paginated = $types . "ii";
$stmt->bind_param($types_paginated, ...$params_paginated);
$stmt->execute();
$issues = $stmt->get_result();

// Get categories for filter
$categories = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY category_name");

// Get admins and staff for assignment
$staff = $db->query("SELECT user_id, username, full_name FROM users WHERE role IN ('admin', 'staff') ORDER BY full_name");

// Get statistics for in-progress issues
$stats = [];

// Total in-progress
$stats['total'] = $total;

// By priority
$priority_result = $db->query("
    SELECT priority, COUNT(*) as count 
    FROM issues 
    WHERE status = 'in_progress' 
    GROUP BY priority
");
$priority_counts = [
    'urgent' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0
];
if ($priority_result) {
    while($row = $priority_result->fetch_assoc()) {
        $priority_counts[$row['priority']] = $row['count'];
    }
}

// By assignee
$assigned_count = $db->query("SELECT COUNT(*) as count FROM issues WHERE status = 'in_progress' AND assigned_to IS NOT NULL")->fetch_assoc()['count'];
$unassigned_count = $db->query("SELECT COUNT(*) as count FROM issues WHERE status = 'in_progress' AND assigned_to IS NULL")->fetch_assoc()['count'];

// Average time in progress
$avg_time = $db->query("SELECT AVG(DATEDIFF(NOW(), updated_at)) as avg_days FROM issues WHERE status = 'in_progress'")->fetch_assoc()['avg_days'];

// Helper function for time ago
function timeAgo($datetime) {
    if (!$datetime) return 'Unknown';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

// Helper function for priority badge
function getPriorityBadge($priority) {
    switch($priority) {
        case 'urgent':
            return '<span class="priority-badge priority-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>';
        case 'high':
            return '<span class="priority-badge priority-high"><i class="fas fa-arrow-up"></i> High</span>';
        case 'medium':
            return '<span class="priority-badge priority-medium"><i class="fas fa-minus"></i> Medium</span>';
        case 'low':
            return '<span class="priority-badge priority-low"><i class="fas fa-arrow-down"></i> Low</span>';
        default:
            return '<span class="priority-badge">Unknown</span>';
    }
}

// Helper function for progress time badge
function getProgressTimeBadge($days) {
    if ($days <= 1) {
        return '<span class="progress-badge progress-new"><i class="fas fa-clock"></i> Just started</span>';
    } elseif ($days <= 3) {
        return '<span class="progress-badge progress-normal"><i class="fas fa-spinner"></i> ' . $days . ' days</span>';
    } elseif ($days <= 7) {
        return '<span class="progress-badge progress-warning"><i class="fas fa-exclamation-triangle"></i> ' . $days . ' days</span>';
    } else {
        return '<span class="progress-badge progress-critical"><i class="fas fa-hourglass-end"></i> ' . $days . ' days</span>';
    }
}

// Helper function for status badge
function getStatusBadge($status) {
    switch($status) {
        case 'reported':
            return '<span class="status-badge status-reported"><i class="fas fa-clock"></i> Reported</span>';
        case 'in_progress':
            return '<span class="status-badge status-in_progress"><i class="fas fa-spinner fa-pulse"></i> In Progress</span>';
        case 'resolved':
            return '<span class="status-badge status-resolved"><i class="fas fa-check-circle"></i> Resolved</span>';
        case 'canceled':
            return '<span class="status-badge status-canceled"><i class="fas fa-ban"></i> Canceled</span>';
        default:
            return '<span class="status-badge">' . ucfirst($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In Progress Issues - Admin Panel - Civic Issue Portal</title>
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
            border-left-color: var(--info);
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
            background: linear-gradient(135deg, var(--info), var(--gold), var(--saffron));
            transform: translateX(-100%);
            transition: transform 0.5s;
        }

        .stat-card:hover::before {
            transform: translateX(0);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--info);
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
            color: var(--info);
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

        /* Filter Section */
        .filter-section {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            border-radius: 1.2rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .filter-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gold);
        }

        .filter-title i {
            color: var(--info);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.3rem;
            font-weight: 500;
        }

        .filter-group label i {
            color: var(--gold);
            margin-right: 0.3rem;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.7rem 1rem;
            background: var(--darker-card);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 0.8rem;
            font-size: 0.95rem;
            transition: all 0.3s;
            color: var(--text-light);
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .filter-group select option {
            background: var(--darker-card);
            color: var(--text-light);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
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

        .btn-info {
            background: linear-gradient(135deg, var(--info), #60a5fa);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.5);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #34d399);
            color: var(--dark-bg);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }

        .btn-secondary {
            background: var(--darker-card);
            color: var(--text-light);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .btn-secondary:hover {
            background: var(--dark-card);
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
        }

        /* Active Filters */
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
        }

        .filter-tag {
            background: var(--darker-card);
            color: var(--text-light);
            padding: 0.3rem 1rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .filter-tag i {
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.3s;
        }

        .filter-tag i:hover {
            color: var(--danger);
        }

        /* Issues Table */
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
            color: var(--info);
            animation: spin 2s linear infinite;
        }

        .export-btn {
            background: var(--darker-card);
            color: var(--text-light);
            padding: 0.6rem 1.2rem;
            border-radius: 2rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .export-btn:hover {
            background: var(--dark-card);
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-2px);
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

        .issue-id {
            font-weight: 600;
            color: var(--info);
        }

        .issue-title {
            font-weight: 500;
            color: var(--text-light);
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .issue-meta {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .issue-meta i {
            width: 16px;
            color: var(--gold);
        }

        .priority-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .priority-urgent {
            background: #7f1d1d;
            color: white;
            border: 1px solid #ef4444;
        }

        .priority-high {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .priority-medium {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid #f59e0b;
        }

        .priority-low {
            background: rgba(75, 85, 99, 0.2);
            color: #9ca3af;
            border: 1px solid #6b7280;
        }

        .progress-badge {
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .progress-new {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid #10b981;
        }

        .progress-normal {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            border: 1px solid #3b82f6;
        }

        .progress-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid #f59e0b;
        }

        .progress-critical {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .assigned-badge {
            padding: 0.2rem 0.6rem;
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            border-radius: 1rem;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }

        .stats-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            background: rgba(212, 175, 55, 0.1);
            padding: 0.2rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .stats-badge i {
            color: var(--gold);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .action-btn:hover {
            transform: scale(1.1);
        }

        .action-btn.view {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            border: 1px solid #3b82f6;
        }

        .action-btn.view:hover {
            background: #3b82f6;
            color: white;
        }

        .action-btn.edit {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid #f59e0b;
        }

        .action-btn.edit:hover {
            background: #f59e0b;
            color: white;
        }

        .action-btn.assign {
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            border: 1px solid var(--gold);
        }

        .action-btn.assign:hover {
            background: var(--gold);
            color: var(--dark-bg);
        }

        .action-btn.delete {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .action-btn.delete:hover {
            background: #ef4444;
            color: white;
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
            color: var(--info);
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

        .form-select option {
            background: var(--darker-card);
            color: var(--text-light);
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
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
            border-radius: 10px;
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
            background: linear-gradient(135deg, var(--info), #60a5fa);
            color: white;
            border-color: transparent;
        }

        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
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
            background: linear-gradient(135deg, var(--info), #60a5fa);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
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

            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .action-buttons {
                justify-content: center;
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

            .issue-title {
                max-width: 150px;
            }

            .pagination {
                gap: 0.3rem;
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
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="manage-issues.php" class="menu-item">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>All Issues</span>
                </a>
                <a href="pending.php" class="menu-item">
                    <i class="fas fa-clock"></i>
                    <span>Pending</span>
                </a>
                <a href="in-progress.php" class="menu-item active">
                    <i class="fas fa-spinner fa-pulse"></i>
                    <span>In Progress</span>
                    <span class="badge"><?php echo $stats['total']; ?></span>
                </a>
                <a href="resolved.php" class="menu-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Resolved</span>
                </a>
                <a href="categories.php" class="menu-item">
                    <i class="fas fa-tags"></i>
                    <span>Categories</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                <a href="../index.php" class="menu-item">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Site</span>
                </a>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1><i class="fas fa-spinner fa-pulse" style="color: var(--info);"></i> In Progress Issues</h1>
                    <p>Issues currently being worked on by staff</p>
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
                <div class="stat-card" onclick="window.location.href='in-progress.php'">
                    <div class="stat-header">
                        <span class="stat-title">Total In Progress</span>
                        <div class="stat-icon">
                            <i class="fas fa-spinner fa-pulse"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-tasks"></i> Being worked on
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='?priority=urgent'">
                    <div class="stat-header">
                        <span class="stat-title">Urgent Priority</span>
                        <div class="stat-icon" style="color: var(--danger);">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $priority_counts['urgent']; ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-clock"></i> Need attention
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='?assigned=assigned'">
                    <div class="stat-header">
                        <span class="stat-title">Assigned</span>
                        <div class="stat-icon" style="color: var(--success);">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $assigned_count; ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-users"></i> <?php echo $unassigned_count; ?> unassigned
                    </div>
                </div>

                <div class="stat-card" onclick="showAvgTimeInfo()">
                    <div class="stat-header">
                        <span class="stat-title">Avg. Progress Time</span>
                        <div class="stat-icon" style="color: var(--warning);">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo round($avg_time ?? 0); ?>d</div>
                    <div class="stat-trend">
                        <i class="fas fa-calendar"></i> Average days
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <div class="filter-title">
                        <i class="fas fa-filter"></i> Filter In Progress Issues
                    </div>
                    <a href="in-progress.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-undo"></i> Reset Filters
                    </a>
                </div>

                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" name="search" placeholder="Search issues..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-tag"></i> Category</label>
                            <select name="category">
                                <option value="0">All Categories</option>
                                <?php if ($categories): ?>
                                    <?php $categories->data_seek(0); ?>
                                    <?php while($cat = $categories->fetch_assoc()): ?>
                                        <option value="<?php echo $cat['category_id']; ?>" 
                                            <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-exclamation-triangle"></i> Priority</label>
                            <select name="priority">
                                <option value="all">All Priorities</option>
                                <option value="urgent" <?php echo $priority_filter == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-user-check"></i> Assigned To</label>
                            <select name="assigned">
                                <option value="">All</option>
                                <option value="unassigned" <?php echo $assigned_filter == 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                <option value="assigned" <?php echo $assigned_filter == 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <?php if ($staff): ?>
                                    <?php $staff->data_seek(0); ?>
                                    <?php while($user = $staff->fetch_assoc()): ?>
                                        <option value="<?php echo $user['user_id']; ?>" 
                                            <?php echo $assigned_filter == $user['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date From</label>
                            <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date To</label>
                            <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Active Filters -->
                <?php 
                $has_filters = !empty($search) || $category_filter > 0 || ($priority_filter && $priority_filter != 'all') || 
                              !empty($assigned_filter) || !empty($date_from) || !empty($date_to);
                
                if ($has_filters): 
                ?>
                <div class="active-filters">
                    <span class="filter-tag">
                        <i class="fas fa-filter"></i> Active Filters:
                    </span>
                    
                    <?php if (!empty($search)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-search"></i> "<?php echo htmlspecialchars($search); ?>"
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['search']);
                                echo http_build_query($params);
                            ?>"><i class="fas fa-times"></i></a>
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($category_filter > 0): 
                        $cat_result = $db->query("SELECT category_name FROM categories WHERE category_id = $category_filter");
                        $cat_name = $cat_result->fetch_assoc()['category_name'];
                    ?>
                        <span class="filter-tag">
                            <i class="fas fa-tag"></i> <?php echo $cat_name; ?>
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['category']);
                                echo http_build_query($params);
                            ?>"><i class="fas fa-times"></i></a>
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($priority_filter && $priority_filter != 'all'): ?>
                        <span class="filter-tag">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo ucfirst($priority_filter); ?>
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['priority']);
                                echo http_build_query($params);
                            ?>"><i class="fas fa-times"></i></a>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($assigned_filter) && $assigned_filter != 'all'): ?>
                        <span class="filter-tag">
                            <i class="fas fa-user-check"></i> <?php echo $assigned_filter == 'unassigned' ? 'Unassigned' : ($assigned_filter == 'assigned' ? 'Assigned' : 'Specific'); ?>
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['assigned']);
                                echo http_build_query($params);
                            ?>"><i class="fas fa-times"></i></a>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Issues Table -->
            <div class="table-section">
                <div class="section-header">
                    <h2><i class="fas fa-spinner fa-pulse"></i> In Progress Issues List</h2>
                    <a href="#" class="export-btn" onclick="exportToCSV()">
                        <i class="fas fa-download"></i> Export CSV
                    </a>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Issue Details</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Progress</th>
                                <th>Stats</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($issues && $issues->num_rows > 0): ?>
                                <?php while($issue = $issues->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="issue-id">#<?php echo str_pad($issue['issue_id'], 4, '0', STR_PAD_LEFT); ?></span>
                                        </td>
                                        <td>
                                            <div class="issue-title" title="<?php echo htmlspecialchars($issue['title']); ?>">
                                                <?php echo htmlspecialchars(substr($issue['title'], 0, 50)) . (strlen($issue['title']) > 50 ? '...' : ''); ?>
                                            </div>
                                            <div class="issue-meta">
                                                <span><i class="fas fa-user"></i> <?php echo $issue['is_anonymous'] ? 'Anonymous' : htmlspecialchars($issue['reporter_name'] ?? $issue['username'] ?? 'Unknown'); ?></span>
                                                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(substr($issue['location_address'], 0, 30)) . (strlen($issue['location_address']) > 30 ? '...' : ''); ?></span>
                                                <span><i class="fas fa-calendar"></i> Started: <?php echo timeAgo($issue['updated_at']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($issue['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <?php echo getPriorityBadge($issue['priority']); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($issue['assigned_name'])): ?>
                                                <span class="assigned-badge">
                                                    <i class="fas fa-user-check"></i>
                                                    <?php echo htmlspecialchars($issue['assigned_fullname'] ?? $issue['assigned_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo getProgressTimeBadge($issue['days_in_progress'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.3rem; flex-wrap: wrap;">
                                                <span class="stats-badge" title="Comments">
                                                    <i class="far fa-comment"></i> <?php echo $issue['comment_count']; ?>
                                                </span>
                                                <span class="stats-badge" title="Upvotes">
                                                    <i class="far fa-thumbs-up"></i> <?php echo $issue['upvote_count']; ?>
                                                </span>
                                                <span class="stats-badge" title="Media">
                                                    <i class="far fa-image"></i> <?php echo $issue['media_count']; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="../issue-details.php?id=<?php echo $issue['issue_id']; ?>" class="action-btn view" target="_blank" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button class="action-btn edit" onclick="openStatusModal(<?php echo $issue['issue_id']; ?>, '<?php echo $issue['status']; ?>', '<?php echo addslashes($issue['title']); ?>', '<?php echo $issue['assigned_to']; ?>')" title="Update Status">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="action-btn assign" onclick="openAssignModal(<?php echo $issue['issue_id']; ?>, '<?php echo addslashes($issue['title']); ?>', '<?php echo $issue['assigned_to']; ?>')" title="Reassign">
                                                    <i class="fas fa-user-tag"></i>
                                                </button>
                                                <a href="?delete=<?php echo $issue['issue_id']; ?>" class="action-btn delete" title="Delete Issue" onclick="return confirm('⚠️ Delete Issue #<?php echo $issue['issue_id']; ?>?\n\nThis will permanently remove:\n- The issue\n- All comments\n- All upvotes\n- All media files\n\nThis action CANNOT be undone!')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3rem;">
                                        <div class="empty-state">
                                            <i class="fas fa-check-circle"></i>
                                            <h3>No In Progress Issues</h3>
                                            <p>No issues are currently being worked on.</p>
                                            <a href="pending.php" class="btn btn-primary" style="margin-top: 1rem;">
                                                <i class="fas fa-clock"></i> View Pending Issues
                                            </a>
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
                <a href="../report-issue.php" class="quick-action-item">
                    <i class="fas fa-plus-circle"></i> Add New Issue
                </a>
                <a href="#" class="quick-action-item" onclick="exportToCSV()">
                    <i class="fas fa-file-export"></i> Export to CSV
                </a>
                <a href="#" class="quick-action-item" onclick="bulkAssign()">
                    <i class="fas fa-tasks"></i> Bulk Assign
                </a>
                <a href="#" class="quick-action-item" onclick="showKeyboardShortcuts()">
                    <i class="fas fa-keyboard"></i> Shortcuts
                </a>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Issue Status</h3>
                <span class="close-modal" onclick="closeStatusModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="issue_id" id="modal_issue_id">
                <input type="hidden" name="update_status" value="1">
                
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Issue</label>
                    <p id="modal_issue_title" style="font-weight: 500; padding: 0.8rem; background: var(--darker-card); border-radius: 0.5rem; border: 1px solid rgba(212,175,55,0.2);"></p>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> New Status</label>
                    <select name="status" class="form-select" required>
                        <option value="resolved">✅ Resolved</option>
                        <option value="reported">⏳ Move to Pending</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Assign To</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">Unassigned</option>
                        <?php if ($staff): ?>
                            <?php $staff->data_seek(0); ?>
                            <?php while($user = $staff->fetch_assoc()): ?>
                                <option value="<?php echo $user['user_id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Remarks (Optional)</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Add any comments about this status change..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-save"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-tag"></i> Reassign Issue</h3>
                <span class="close-modal" onclick="closeAssignModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="issue_id" id="assign_issue_id">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="status" value="in_progress">
                
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Issue</label>
                    <p id="assign_issue_title" style="font-weight: 500; padding: 0.8rem; background: var(--darker-card); border-radius: 0.5rem; border: 1px solid rgba(212,175,55,0.2);"></p>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Assign To</label>
                    <select name="assigned_to" class="form-select" required>
                        <option value="">Select Staff Member</option>
                        <?php if ($staff): ?>
                            <?php $staff->data_seek(0); ?>
                            <?php while($user = $staff->fetch_assoc()): ?>
                                <option value="<?php echo $user['user_id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Remarks (Optional)</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Add reassignment notes..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-check"></i> Reassign
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
                        <td class="shortcut-key"><kbd>Ctrl + R</kbd></td>
                        <td class="shortcut-desc">Reset all filters</td>
                    </tr>
                    <tr>
                        <td class="shortcut-key"><kbd>Ctrl + E</kbd></td>
                        <td class="shortcut-desc">Export to CSV</td>
                    </tr>
                    <tr>
                        <td class="shortcut-key"><kbd>Ctrl + N</kbd></td>
                        <td class="shortcut-desc">Add new issue</td>
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

        // Modal Functions
        function openStatusModal(issueId, currentStatus, issueTitle, assignedTo) {
            document.getElementById('modal_issue_id').value = issueId;
            document.getElementById('modal_issue_title').textContent = issueTitle;
            
            let statusSelect = document.querySelector('#statusModal select[name="status"]');
            statusSelect.value = 'resolved'; // Default to resolved for in-progress issues
            
            let assignSelect = document.querySelector('#statusModal select[name="assigned_to"]');
            if (assignSelect && assignedTo && assignedTo !== 'null' && assignedTo !== '') {
                assignSelect.value = assignedTo;
            }
            
            document.getElementById('statusModal').classList.add('active');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }

        function openAssignModal(issueId, issueTitle, assignedTo) {
            document.getElementById('assign_issue_id').value = issueId;
            document.getElementById('assign_issue_title').textContent = issueTitle;
            
            let assignSelect = document.querySelector('#assignModal select[name="assigned_to"]');
            if (assignSelect && assignedTo && assignedTo !== 'null' && assignedTo !== '') {
                assignSelect.value = assignedTo;
            }
            
            document.getElementById('assignModal').classList.add('active');
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('active');
        }

        function showKeyboardShortcuts() {
            document.getElementById('shortcutsModal').classList.add('active');
        }

        function closeShortcutsModal() {
            document.getElementById('shortcutsModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const statusModal = document.getElementById('statusModal');
            const assignModal = document.getElementById('assignModal');
            const shortcutsModal = document.getElementById('shortcutsModal');
            
            if (event.target == statusModal) {
                closeStatusModal();
            }
            if (event.target == assignModal) {
                closeAssignModal();
            }
            if (event.target == shortcutsModal) {
                closeShortcutsModal();
            }
        }

        // Notifications
        function toggleNotifications() {
            showInfoModal('📬 Notifications', 'You have 3 unread notifications.\n\nNotification features coming soon!');
        }

        // Show average time info
        function showAvgTimeInfo() {
            showInfoModal('⏱️ Average Progress Time', 
                'Current average: <?php echo round($avg_time ?? 0); ?> days\n\n' +
                'This is the average time issues spend in "In Progress" status.\n\n' +
                '• < 3 days: Good\n' +
                '• 3-7 days: Normal\n' +
                '• > 7 days: Needs attention'
            );
        }

        // Export to CSV
        function exportToCSV() {
            let table = document.querySelector('table');
            if (!table) return;
            
            let rows = table.querySelectorAll('tr');
            let csv = [];
            
            // Get headers
            let headers = [];
            table.querySelectorAll('th').forEach(th => {
                if (th.textContent !== 'Actions') {
                    headers.push(th.textContent);
                }
            });
            csv.push(headers.join(','));
            
            // Get data rows
            rows.forEach((row, index) => {
                if (index === 0) return;
                let rowData = [];
                let cols = row.querySelectorAll('td');
                
                for (let i = 0; i < cols.length - 1; i++) { // Skip actions column
                    let text = cols[i].textContent.replace(/,/g, ';').replace(/\n/g, ' ').trim();
                    rowData.push('"' + text + '"');
                }
                csv.push(rowData.join(','));
            });
            
            let csvContent = csv.join('\n');
            let blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' }); // Add BOM for UTF-8
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'in_progress_issues_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
            
            showToast('Export started! ' + (csv.length - 1) + ' issues exported.', 'success');
        }

        // Bulk assign (placeholder)
        function bulkAssign() {
            showInfoModal('📋 Bulk Assign', 'Bulk assign feature coming soon!\n\nYou will be able to:\n• Select multiple issues\n• Assign to staff members\n• Update status in bulk');
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
            
            // Ctrl + R - Reset filters
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.href = 'in-progress.php';
            }
            
            // Ctrl + E - Export CSV
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
            }
            
            // Ctrl + N - New issue
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = '../report-issue.php';
            }
            
            // ? or Ctrl+K - Show shortcuts
            if (e.key === '?' || (e.ctrlKey && e.key === 'k')) {
                e.preventDefault();
                showKeyboardShortcuts();
            }
            
            // Esc - Close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    if (modal.id !== 'statusModal' && modal.id !== 'assignModal' && modal.id !== 'shortcutsModal') {
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
        document.querySelectorAll('.issue-title').forEach(el => {
            if (el.scrollWidth > el.clientWidth) {
                el.setAttribute('title', el.textContent);
            }
        });
    </script>
</body>
</html>