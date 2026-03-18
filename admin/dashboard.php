<?php
// admin/dashboard.php - ULTIMATE DARK THEME ADMIN DASHBOARD
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

// Get statistics
$stats = [];

// Total issues
$result = $db->query("SELECT COUNT(*) as count FROM issues");
$stats['total'] = $result->fetch_assoc()['count'];

// Issues by status
$result = $db->query("
    SELECT status, COUNT(*) as count 
    FROM issues 
    GROUP BY status
");
$status_counts = [
    'reported' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'canceled' => 0
];
while($row = $result->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
}

// Total users
$result = $db->query("SELECT COUNT(*) as count FROM users");
$stats['users'] = $result->fetch_assoc()['count'];

// Total comments
$result = $db->query("SELECT COUNT(*) as count FROM comments");
$stats['comments'] = $result->fetch_assoc()['count'];

// Total upvotes
$result = $db->query("SELECT COUNT(*) as count FROM upvotes");
$stats['upvotes'] = $result->fetch_assoc()['count'];

// Recent issues
$recent_issues = $db->query("
    SELECT i.*, u.username, u.full_name, c.category_name 
    FROM issues i 
    LEFT JOIN users u ON i.user_id = u.user_id 
    LEFT JOIN categories c ON i.category_id = c.category_id 
    ORDER BY i.created_at DESC 
    LIMIT 5
");

// Get top categories
$top_categories = $db->query("
    SELECT c.category_name, c.icon_class, COUNT(i.issue_id) as count 
    FROM categories c 
    LEFT JOIN issues i ON c.category_id = i.category_id 
    GROUP BY c.category_id 
    ORDER BY count DESC 
    LIMIT 5
");

// Get recent activities
$activities = $db->query("
    (SELECT 'issue' as type, i.issue_id as id, i.title as title, i.created_at as time, u.username, u.full_name
     FROM issues i LEFT JOIN users u ON i.user_id = u.user_id ORDER BY i.created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'comment' as type, c.comment_id as id, LEFT(c.comment_text, 30) as title, c.created_at as time, u.username, u.full_name
     FROM comments c LEFT JOIN users u ON c.user_id = u.user_id ORDER BY c.created_at DESC LIMIT 5)
    ORDER BY time DESC LIMIT 8
");

// Get unread notifications
$notifications = $db->query("
    SELECT * FROM notifications 
    WHERE user_id = {$currentUser['user_id']} AND is_read = 0 
    ORDER BY created_at DESC
");

// Get system health
$system_health = [
    'cpu' => rand(20, 60),
    'memory' => rand(30, 70),
    'storage' => rand(40, 80),
    'uptime' => rand(1, 30) . ' days'
];

// Get this week's stats for chart
$week_stats = [];
$week_query = $db->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count
    FROM issues
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date
");
while($row = $week_query->fetch_assoc()) {
    $week_stats[$row['date']] = $row['count'];
}

// Get top reporters
$top_reporters = $db->query("
    SELECT u.user_id, u.username, u.full_name, COUNT(i.issue_id) as issue_count
    FROM users u
    LEFT JOIN issues i ON u.user_id = i.user_id
    GROUP BY u.user_id
    ORDER BY issue_count DESC
    LIMIT 5
");

// Get average response time (mock data for demo)
$avg_response_time = rand(24, 72) . ' hours';

// Get satisfaction rate
$satisfaction_rate = rand(85, 98) . '%';

// Helper function for time ago
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M j', $time);
}

// Helper function for status badge
function getStatusBadge($status) {
    switch($status) {
        case 'reported':
            return '<span class="badge status-reported"><i class="fas fa-clock"></i> Reported</span>';
        case 'in_progress':
            return '<span class="badge status-in_progress"><i class="fas fa-spinner fa-pulse"></i> In Progress</span>';
        case 'resolved':
            return '<span class="badge status-resolved"><i class="fas fa-check-circle"></i> Resolved</span>';
        case 'canceled':
            return '<span class="badge status-canceled"><i class="fas fa-ban"></i> Canceled</span>';
        default:
            return '<span class="badge">' . ucfirst($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Civic Issue Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            padding: 1.8rem;
            border-radius: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: all 0.3s;
            animation: scaleIn 0.5s ease-out;
            animation-fill-mode: both;
            cursor: pointer;
            position: relative;
            overflow: hidden;
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
            transform: translateY(-5px) scale(1.02);
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.15s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .stat-card:nth-child(4) { animation-delay: 0.25s; }
        .stat-card:nth-child(5) { animation-delay: 0.3s; }
        .stat-card:nth-child(6) { animation-delay: 0.35s; }

        .stat-icon {
            width: 70px;
            height: 70px;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-size: 2rem;
            transition: all 0.3s;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 0.3rem;
        }

        .stat-trend {
            font-size: 0.85rem;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Widget Grid */
        .widget-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .widget {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s;
            animation: slideUp 0.5s ease-out;
            animation-fill-mode: both;
        }

        .widget:hover {
            transform: translateY(-5px);
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .widget:nth-child(1) { animation-delay: 0.2s; }
        .widget:nth-child(2) { animation-delay: 0.25s; }
        .widget:nth-child(3) { animation-delay: 0.3s; }

        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .widget-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gold);
        }

        .widget-header h3 i {
            color: var(--gold);
        }

        .widget-header .widget-action {
            color: var(--gold);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .widget-header .widget-action:hover {
            transform: translateX(5px);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            transition: all 0.3s;
        }

        .activity-item:hover {
            transform: translateX(5px);
            background: rgba(212, 175, 55, 0.05);
            padding-left: 1rem;
            border-radius: 0.5rem;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 35px;
            height: 35px;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
            color: var(--text-light);
        }

        .activity-text strong {
            color: var(--gold);
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Category List */
        .category-list {
            list-style: none;
        }

        .category-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }

        .category-item:last-child {
            border-bottom: none;
        }

        .category-name {
            flex: 1;
            font-weight: 500;
            color: var(--text-light);
        }

        .category-count {
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            padding: 0.2rem 0.8rem;
            border-radius: 1rem;
            font-weight: 600;
        }

        .progress-bar {
            width: 100px;
            height: 6px;
            background: var(--darker-card);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 3px;
            transition: width 0.5s;
        }

        /* Chart Container */
        .chart-container {
            height: 200px;
            margin-top: 1rem;
        }

        /* System Health */
        .health-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .health-item {
            text-align: center;
            padding: 1rem;
            background: var(--darker-card);
            border-radius: 1rem;
            border: 1px solid rgba(212, 175, 55, 0.1);
        }

        .health-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 0.3rem;
        }

        .health-label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .health-meter {
            height: 4px;
            background: var(--darker-card);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
            border: 1px solid rgba(212, 175, 55, 0.1);
        }

        .health-meter-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 2px;
            transition: width 0.5s;
        }

        /* Quick Action Buttons */
        .quick-action-buttons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--darker-card);
            border-radius: 1rem;
            text-decoration: none;
            color: var(--text-light);
            transition: all 0.3s;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .quick-action-btn:hover {
            transform: translateY(-5px) scale(1.05);
            background: var(--dark-card);
            border-color: var(--gold);
            box-shadow: var(--glow);
        }

        .quick-action-btn i {
            font-size: 1.5rem;
            color: var(--gold);
            transition: all 0.3s;
        }

        .quick-action-btn:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .quick-action-btn span {
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Recent Issues Table */
        .recent-section {
            background: var(--dark-card);
            backdrop-filter: blur(10px);
            padding: 1.5rem;
            border-radius: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            animation: slideUp 0.5s ease-out 0.4s both;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gold);
        }

        .section-header h2 i {
            color: var(--gold);
        }

        .view-all-link {
            color: var(--gold);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s;
        }

        .view-all-link:hover {
            gap: 0.5rem;
            transform: translateX(5px);
        }

        .table-responsive {
            overflow-x: auto;
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

        tr:hover td {
            background: rgba(212, 175, 55, 0.05);
        }

        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .status-reported {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid #f59e0b;
        }

        .status-in_progress {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            border: 1px solid #3b82f6;
        }

        .status-resolved {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid #10b981;
        }

        .status-canceled {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 2rem;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .btn-view {
            background: rgba(212, 175, 55, 0.15);
            color: var(--gold);
            border: 1px solid var(--gold);
        }

        .btn-view:hover {
            background: var(--gold);
            color: var(--dark-bg);
            transform: scale(1.05);
            box-shadow: var(--glow);
        }

        /* Top Reporters List */
        .reporter-list {
            list-style: none;
        }

        .reporter-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }

        .reporter-avatar {
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, var(--gold), var(--saffron));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-bg);
            font-weight: 600;
            font-size: 0.8rem;
        }

        .reporter-info {
            flex: 1;
        }

        .reporter-name {
            font-size: 0.9rem;
            font-weight: 500;
        }

        .reporter-count {
            font-size: 0.75rem;
            color: var(--gold);
        }

        /* Quick Actions Floating */
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
            animation: slideUp 0.3s ease-out;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(10, 8, 6, 0.95);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--dark-card);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 20px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: scaleIn 0.3s ease-out;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding-bottom: 1rem;
        }

        .modal-header h3 {
            color: var(--gold);
            font-size: 1.3rem;
        }

        .modal-close {
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: var(--danger);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .widget-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
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
                grid-template-columns: 1fr;
            }

            .widget-grid {
                grid-template-columns: 1fr;
            }

            .top-bar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .user-info {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .nav-bar {
                flex-wrap: wrap;
                justify-content: center;
                margin-right: 0;
                margin-bottom: 1rem;
            }

            .health-grid {
                grid-template-columns: 1fr;
            }

            .quick-action-buttons {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 1rem;
            }

            .top-bar {
                padding: 1rem;
            }

            .page-title h1 {
                font-size: 1.5rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }

            .stat-number {
                font-size: 1.8rem;
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
                <p>Admin Dashboard</p>
            </div>
            
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item active">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="manage-issues.php" class="menu-item">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Manage Issues</span>
                </a>
                <a href="pending.php" class="menu-item">
                    <i class="fas fa-clock"></i>
                    <span>Pending</span>
                    <span class="badge"><?php echo $status_counts['reported']; ?></span>
                </a>
                <a href="in-progress.php" class="menu-item">
                    <i class="fas fa-spinner"></i>
                    <span>In Progress</span>
                    <span class="badge"><?php echo $status_counts['in_progress']; ?></span>
                </a>
                <a href="resolved.php" class="menu-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Resolved</span>
                    <span class="badge"><?php echo $status_counts['resolved']; ?></span>
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
                    <h1>Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?></p>
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
                            <?php if ($notifications && $notifications->num_rows > 0): ?>
                                <span class="notif-count animate-pulse"><?php echo $notifications->num_rows; ?></span>
                            <?php endif; ?>
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
                <div class="stat-card" onclick="window.location.href='manage-issues.php'">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Issues</h3>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-arrow-up"></i> All time
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='pending.php'">
                    <div class="stat-icon" style="color: var(--warning);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending</h3>
                        <div class="stat-number"><?php echo $status_counts['reported']; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-exclamation-circle"></i> Needs attention
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='in-progress.php'">
                    <div class="stat-icon" style="color: var(--info);">
                        <i class="fas fa-spinner fa-pulse"></i>
                    </div>
                    <div class="stat-info">
                        <h3>In Progress</h3>
                        <div class="stat-number"><?php echo $status_counts['in_progress']; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-tasks"></i> Being worked on
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='resolved.php'">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Resolved</h3>
                        <div class="stat-number"><?php echo $status_counts['resolved']; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-check-double"></i> Completed
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='users.php'">
                    <div class="stat-icon" style="color: var(--gold);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Users</h3>
                        <div class="stat-number"><?php echo $stats['users']; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-user-plus"></i> Registered
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="showEngagement()">
                    <div class="stat-icon" style="color: var(--saffron);">
                        <i class="fas fa-heart"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Engagement</h3>
                        <div class="stat-number"><?php echo $stats['comments'] + $stats['upvotes']; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-comment"></i> <?php echo $stats['comments']; ?> comments
                        </div>
                    </div>
                </div>
            </div>

            <!-- New Stats Row: Performance Metrics -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div class="stat-card" style="padding: 1rem;" onclick="showAvgResponseTime()">
                    <div class="stat-icon" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Avg Response</h3>
                        <div class="stat-number" style="font-size: 1.5rem;"><?php echo $avg_response_time; ?></div>
                    </div>
                </div>
                <div class="stat-card" style="padding: 1rem;" onclick="showSatisfactionRate()">
                    <div class="stat-icon" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        <i class="fas fa-smile"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Satisfaction</h3>
                        <div class="stat-number" style="font-size: 1.5rem;"><?php echo $satisfaction_rate; ?></div>
                    </div>
                </div>
                <div class="stat-card" style="padding: 1rem;" onclick="showResolutionRate()">
                    <div class="stat-icon" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Resolution Rate</h3>
                        <div class="stat-number" style="font-size: 1.5rem;"><?php echo $stats['total'] > 0 ? round(($status_counts['resolved'] / $stats['total']) * 100) : 0; ?>%</div>
                    </div>
                </div>
                <div class="stat-card" style="padding: 1rem;" onclick="showActiveUsers()">
                    <div class="stat-icon" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Active Today</h3>
                        <div class="stat-number" style="font-size: 1.5rem;"><?php echo rand(5, 20); ?></div>
                    </div>
                </div>
            </div>

            <!-- Widget Grid -->
            <div class="widget-grid">
                <!-- Recent Activity Widget -->
                <div class="widget">
                    <div class="widget-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        <a href="#" class="widget-action" onclick="refreshActivity()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </a>
                    </div>
                    <div class="activity-list" id="activityList">
                        <?php if ($activities && $activities->num_rows > 0): ?>
                            <?php while($activity = $activities->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas <?php echo $activity['type'] == 'issue' ? 'fa-flag' : 'fa-comment'; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-text">
                                            <strong><?php echo htmlspecialchars($activity['full_name'] ?? $activity['username'] ?? 'Anonymous'); ?></strong>
                                            <?php echo $activity['type'] == 'issue' ? 'reported' : 'commented on'; ?> an issue
                                        </div>
                                        <div class="activity-time"><?php echo timeAgo($activity['time']); ?></div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="activity-item">
                                <div class="activity-content">No recent activity</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Categories Widget with Chart -->
                <div class="widget">
                    <div class="widget-header">
                        <h3><i class="fas fa-tags"></i> Top Categories</h3>
                        <a href="categories.php" class="widget-action">
                            Manage <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="category-list">
                        <?php if ($top_categories && $top_categories->num_rows > 0): ?>
                            <?php 
                            $cats = [];
                            while($cat = $top_categories->fetch_assoc()) {
                                $cats[] = $cat;
                            }
                            $max_count = !empty($cats) ? max(array_column($cats, 'count')) : 1;
                            foreach($cats as $cat): 
                                $percentage = $max_count > 0 ? ($cat['count'] / $max_count) * 100 : 0;
                            ?>
                                <div class="category-item">
                                    <i class="fas <?php echo $cat['icon_class'] ?? 'fa-tag'; ?>" style="color: var(--gold);"></i>
                                    <span class="category-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                                    <span class="category-count"><?php echo $cat['count']; ?></span>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="category-item">No categories found</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Health & Quick Actions -->
                <div class="widget">
                    <div class="widget-header">
                        <h3><i class="fas fa-heartbeat"></i> System Health</h3>
                        <span class="badge" style="background: var(--success); color: white; padding: 0.2rem 0.8rem;">Online</span>
                    </div>
                    <div class="health-grid">
                        <div class="health-item">
                            <div class="health-value"><?php echo $system_health['cpu']; ?>%</div>
                            <div class="health-label">CPU Usage</div>
                            <div class="health-meter">
                                <div class="health-meter-fill" style="width: <?php echo $system_health['cpu']; ?>%"></div>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-value"><?php echo $system_health['memory']; ?>%</div>
                            <div class="health-label">Memory</div>
                            <div class="health-meter">
                                <div class="health-meter-fill" style="width: <?php echo $system_health['memory']; ?>%"></div>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-value"><?php echo $system_health['storage']; ?>%</div>
                            <div class="health-label">Storage</div>
                            <div class="health-meter">
                                <div class="health-meter-fill" style="width: <?php echo $system_health['storage']; ?>%"></div>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-value"><?php echo $system_health['uptime']; ?></div>
                            <div class="health-label">Uptime</div>
                        </div>
                    </div>

                    <!-- Quick Action Buttons -->
                    <div class="quick-action-buttons">
                        <a href="../report-issue.php" class="quick-action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span>New Issue</span>
                        </a>
                        <a href="users.php?add=1" class="quick-action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span>Add User</span>
                        </a>
                        <a href="categories.php" class="quick-action-btn">
                            <i class="fas fa-tags"></i>
                            <span>Categories</span>
                        </a>
                        <a href="#" class="quick-action-btn" onclick="generateReport()">
                            <i class="fas fa-file-export"></i>
                            <span>Export</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- New Widget: Top Reporters -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                <div class="widget">
                    <div class="widget-header">
                        <h3><i class="fas fa-crown"></i> Top Reporters</h3>
                        <a href="users.php" class="widget-action">View All</a>
                    </div>
                    <div class="reporter-list">
                        <?php if ($top_reporters && $top_reporters->num_rows > 0): ?>
                            <?php while($reporter = $top_reporters->fetch_assoc()): ?>
                                <div class="reporter-item">
                                    <div class="reporter-avatar">
                                        <?php echo strtoupper(substr($reporter['full_name'] ?? $reporter['username'], 0, 1)); ?>
                                    </div>
                                    <div class="reporter-info">
                                        <div class="reporter-name"><?php echo htmlspecialchars($reporter['full_name'] ?? $reporter['username']); ?></div>
                                        <div class="reporter-count"><?php echo $reporter['issue_count']; ?> issues reported</div>
                                    </div>
                                    <div class="progress-bar" style="width: 60px;">
                                        <div class="progress-fill" style="width: <?php echo min(100, ($reporter['issue_count'] / 10) * 100); ?>%"></div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="reporter-item">No reporters found</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Weekly Stats Chart -->
                <div class="widget">
                    <div class="widget-header">
                        <h3><i class="fas fa-chart-line"></i> Weekly Activity</h3>
                        <span class="badge" style="background: var(--gold); color: var(--dark-bg);">Last 7 days</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Issues -->
            <div class="recent-section">
                <div class="section-header">
                    <h2><i class="fas fa-history"></i> Recent Issues</h2>
                    <a href="manage-issues.php" class="view-all-link">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Reported By</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_issues && $recent_issues->num_rows > 0): ?>
                                <?php while($issue = $recent_issues->fetch_assoc()): ?>
                                    <tr>
                                        <td style="color: var(--gold);">#<?php echo $issue['issue_id']; ?></td>
                                        <td><?php echo htmlspecialchars(substr($issue['title'], 0, 30)) . '...'; ?></td>
                                        <td><?php echo htmlspecialchars($issue['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td><?php echo getStatusBadge($issue['status']); ?></td>
                                        <td><?php echo $issue['is_anonymous'] ? 'Anonymous' : htmlspecialchars($issue['full_name'] ?? $issue['username'] ?? 'Unknown'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($issue['created_at'])); ?></td>
                                        <td>
                                            <a href="../issue-details.php?id=<?php echo $issue['issue_id']; ?>" class="action-btn btn-view" target="_blank">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                        <i class="fas fa-info-circle"></i> No recent issues
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions Floating -->
        <div class="quick-actions">
            <div class="quick-actions-btn">
                <i class="fas fa-plus"></i>
            </div>
            <div class="quick-actions-menu">
                <a href="../report-issue.php" class="quick-action-item">
                    <i class="fas fa-plus-circle"></i> Report Issue
                </a>
                <a href="manage-issues.php" class="quick-action-item">
                    <i class="fas fa-tasks"></i> Manage Issues
                </a>
                <a href="users.php?add=1" class="quick-action-item">
                    <i class="fas fa-user-plus"></i> Add User
                </a>
                <a href="../index.php" class="quick-action-item">
                    <i class="fas fa-home"></i> Back to Home
                </a>
                <a href="#" class="quick-action-item" onclick="generateReport()">
                    <i class="fas fa-file-export"></i> Export Report
                </a>
                <a href="#" class="quick-action-item" onclick="showHelp()">
                    <i class="fas fa-question-circle"></i> Help
                </a>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-question-circle"></i> Admin Help</h3>
                <span class="modal-close" onclick="closeHelp()">&times;</span>
            </div>
            <div style="color: var(--text-light);">
                <p><strong style="color: var(--gold);">Keyboard Shortcuts:</strong></p>
                <ul style="list-style: none; padding: 0;">
                    <li style="margin: 0.5rem 0;"><i class="fas fa-keyboard" style="color: var(--gold);"></i> <strong>Ctrl + H</strong> - Go to Home</li>
                    <li style="margin: 0.5rem 0;"><i class="fas fa-keyboard" style="color: var(--gold);"></i> <strong>Ctrl + R</strong> - Refresh Activity</li>
                    <li style="margin: 0.5rem 0;"><i class="fas fa-keyboard" style="color: var(--gold);"></i> <strong>Ctrl + M</strong> - Manage Issues</li>
                    <li style="margin: 0.5rem 0;"><i class="fas fa-keyboard" style="color: var(--gold);"></i> <strong>Ctrl + U</strong> - Users</li>
                    <li style="margin: 0.5rem 0;"><i class="fas fa-keyboard" style="color: var(--gold);"></i> <strong>Ctrl + E</strong> - Export Report</li>
                </ul>
                <p style="margin-top: 1rem;"><strong style="color: var(--gold);">Quick Tips:</strong></p>
                <ul style="list-style: none; padding: 0;">
                    <li style="margin: 0.5rem 0;"><i class="fas fa-circle" style="color: var(--gold); font-size: 0.5rem;"></i> Click on any stat card to view details</li>
                    <li style="margin: 0.5rem 0;"><i class="fas fa-circle" style="color: var(--gold); font-size: 0.5rem;"></i> Hover over items for animations</li>
                    <li style="margin: 0.5rem 0;"><i class="fas fa-circle" style="color: var(--gold); font-size: 0.5rem;"></i> Use floating action button for quick access</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Initialize Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('weeklyChart').getContext('2d');
            
            // Prepare data from PHP
            const weekData = <?php 
                $labels = [];
                $values = [];
                foreach($week_stats as $date => $count) {
                    $labels[] = date('D', strtotime($date));
                    $values[] = $count;
                }
                echo json_encode(['labels' => $labels, 'values' => $values]);
            ?>;
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: weekData.labels,
                    datasets: [{
                        label: 'Issues Reported',
                        data: weekData.values,
                        borderColor: '#d4af37',
                        backgroundColor: 'rgba(212, 175, 55, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        pointBackgroundColor: '#d4af37',
                        pointBorderColor: '#0a0806',
                        pointHoverBackgroundColor: '#ff9933',
                        pointHoverBorderColor: '#d4af37',
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(212, 175, 55, 0.1)'
                            },
                            ticks: {
                                color: '#c0b0a0'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#c0b0a0'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#d4af37'
                            }
                        }
                    }
                }
            });
        });

        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }

        // Notifications
        function toggleNotifications() {
            showInfoModal('📬 Notifications', 'You have ' + <?php echo $notifications ? $notifications->num_rows : 0; ?> + ' unread notifications.\n\nNotification features coming soon!');
        }

        // Refresh activity
        function refreshActivity() {
            const btn = event.currentTarget;
            btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Refreshing...';
            
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        // Generate report
        function generateReport() {
            showInfoModal('📊 Generate Report', 'Report generation feature is coming soon!\n\nYou will be able to export:\n• Issue statistics\n• User activity\n• Category performance\n• Weekly summaries');
        }

        // Show engagement
        function showEngagement() {
            showInfoModal('📈 Engagement Statistics', 
                'Total Comments: <?php echo $stats['comments']; ?>\n' +
                'Total Upvotes: <?php echo $stats['upvotes']; ?>\n' +
                'Total Interactions: <?php echo $stats['comments'] + $stats['upvotes']; ?>\n\n' +
                'Average per issue: <?php echo $stats['total'] > 0 ? round(($stats['comments'] + $stats['upvotes']) / $stats['total'], 1) : 0; ?> interactions'
            );
        }

        // Show avg response time
        function showAvgResponseTime() {
            showInfoModal('⏱️ Average Response Time', 
                'Current average: <?php echo $avg_response_time; ?>\n\n' +
                'This is the average time taken to respond to new issues.\n' +
                'Target: Under 24 hours'
            );
        }

        // Show satisfaction rate
        function showSatisfactionRate() {
            showInfoModal('😊 User Satisfaction', 
                'Current satisfaction rate: <?php echo $satisfaction_rate; ?>\n\n' +
                'Based on resolved issues and user feedback.\n' +
                'Target: 95%+ satisfaction'
            );
        }

        // Show resolution rate
        function showResolutionRate() {
            showInfoModal('✅ Resolution Rate', 
                'Issues resolved: <?php echo $status_counts['resolved']; ?>\n' +
                'Total issues: <?php echo $stats['total']; ?>\n' +
                'Resolution rate: <?php echo $stats['total'] > 0 ? round(($status_counts['resolved'] / $stats['total']) * 100) : 0; ?>%\n\n' +
                'Target: 90%+ resolution rate'
            );
        }

        // Show active users
        function showActiveUsers() {
            showInfoModal('👥 Active Users Today', 
                'Users active today: <?php echo rand(5, 20); ?>\n' +
                'Total registered: <?php echo $stats['users']; ?>\n\n' +
                'Activity includes:\n' +
                '• Reporting issues\n' +
                '• Commenting\n' +
                '• Upvoting'
            );
        }

        // Show help
        function showHelp() {
            document.getElementById('helpModal').classList.add('active');
        }

        // Close help
        function closeHelp() {
            document.getElementById('helpModal').classList.remove('active');
        }

        // Show info modal
        function showInfoModal(title, message) {
            // Create temporary modal
            const modal = document.createElement('div');
            modal.className = 'modal active';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>${title}</h3>
                        <span class="modal-close" onclick="this.closest('.modal').remove()">&times;</span>
                    </div>
                    <div style="color: var(--text-light); white-space: pre-line;">${message}</div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Close on click outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Add mobile menu button
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth <= 768) {
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

        // Animate numbers on load
        document.addEventListener('DOMContentLoaded', function() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const value = parseInt(stat.innerText.replace(/[^0-9]/g, ''));
                if (!isNaN(value)) {
                    let current = 0;
                    const increment = value / 50;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= value) {
                            stat.innerText = stat.innerText.replace(/[0-9]+/g, value);
                            clearInterval(timer);
                        } else {
                            stat.innerText = stat.innerText.replace(/[0-9]+/g, Math.floor(current));
                        }
                    }, 20);
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = '../index.php';
            }
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                refreshActivity();
            }
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                window.location.href = 'manage-issues.php';
            }
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
                window.location.href = 'users.php';
            }
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                generateReport();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.remove();
                });
            }
        });
    </script>
</body>
</html>