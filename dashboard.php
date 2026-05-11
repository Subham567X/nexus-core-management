<?php
require_once 'api/config/session.php';
session_start();

require_once 'api/config/database.php';
use App\Config\Database;

$dbClass = new Database();
$db      = $dbClass->getConnection();

// If no super admin exists, redirect to owner setup
$hasOwner = $db->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active'")->fetchColumn();
if (!$hasOwner) {
    header('Location: setup.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? null;
$username  = $_SESSION['username'] ?? '';

// If role is missing from session, re-fetch from DB and repair the session
if (!$user_role) {
    $rs = $db->prepare("SELECT role, username FROM users WHERE id = ? AND status = 'active'");
    $rs->execute([$user_id]);
    $dbUser = $rs->fetch(PDO::FETCH_ASSOC);
    if ($dbUser) {
        $user_role              = $dbUser['role'];
        $username               = $dbUser['username'];
        $_SESSION['role']     = $user_role;
        $_SESSION['username'] = $username;
    } else {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$totalUsers       = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeProds      = $db->query("SELECT COUNT(*) FROM productions WHERE status IN ('active','planning')")->fetchColumn();
$pendingApprovals = $db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$totalUploads     = $db->query("SELECT COUNT(*) FROM work_submissions")->fetchColumn();

$pendingMembers = [];
if (in_array($user_role, ['super_admin', 'sub_admin'])) {
    $stmt = $db->query("SELECT id, username, full_name, role, status, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC");
    $pendingMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$allStaff = [];
if ($user_role === 'super_admin') {
    $stmt = $db->query("SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.team_id, u.created_at, t.team_name, t.department FROM users u LEFT JOIN teams t ON u.team_id = t.id WHERE u.role != 'super_admin' ORDER BY u.role, u.created_at DESC");
    $allStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'sub_admin') {
    $stmt = $db->query("SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.team_id, u.created_at, t.team_name, t.department FROM users u LEFT JOIN teams t ON u.team_id = t.id WHERE u.role IN ('user','team_moderator') ORDER BY u.role, u.created_at DESC");
    $allStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Split staff by status for the Member Access Control tabs
$activeStaff     = array_values(array_filter($allStaff, fn($s) => $s['status'] === 'active'));
$restrictedStaff = array_values(array_filter($allStaff, fn($s) => in_array($s['status'], ['suspended', 'banned'])));

// Team Moderator: fetch their own team members
$modTeamMembers = [];
$modTeamInfo    = null;
if ($user_role === 'team_moderator') {
    try {
        $tmInfoStmt = $db->prepare("SELECT t.id, t.team_name, t.department FROM teams t JOIN users u ON u.team_id = t.id WHERE u.id = ?");
        $tmInfoStmt->execute([$user_id]);
        $modTeamInfo = $tmInfoStmt->fetch(PDO::FETCH_ASSOC);
        if ($modTeamInfo) {
            $tmMembersStmt = $db->prepare("SELECT u.id, u.username, u.full_name, u.email, u.status, u.created_at FROM users u WHERE u.team_id = ? AND u.role = 'user' ORDER BY u.full_name");
            $tmMembersStmt->execute([$modTeamInfo['id']]);
            $modTeamMembers = $tmMembersStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

$teamsDirectory = [];
if (in_array($user_role, ['super_admin', 'sub_admin'])) {
    $teamsDirectory = $db->query("SELECT t.id, t.department, t.team_name, t.allowed_extensions, COUNT(u.id) as member_count FROM teams t LEFT JOIN users u ON u.team_id = t.id AND u.status = 'active' AND u.role = 'user' GROUP BY t.id ORDER BY t.department, t.team_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch current user profile for settings panel
$currentUser = $db->prepare("SELECT full_name, email, phone, age FROM users WHERE id = ?");
$currentUser->execute([$user_id]);
$userProfile = $currentUser->fetch(PDO::FETCH_ASSOC);

// Fetch notifications
$notifications = [];
// Pending approvals (admins)
if (in_array($user_role, ['super_admin', 'sub_admin'])) {
    foreach ($pendingMembers as $pm) {
        $notifications[] = [
            'type'    => 'approval',
            'icon'    => 'user-check',
            'color'   => 'var(--neon-warning)',
            'title'   => 'New Clearance Request',
            'detail'  => htmlspecialchars($pm['full_name']) . ' is awaiting access approval.',
            'time'    => $pm['created_at'],
        ];
    }
}
// Recent work submissions (admins + moderators)
if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])) {
    try {
        $recentSubs = $db->query("
            SELECT ws.work_description, ws.submitted_at, u.username, p.name as prod_name
            FROM work_submissions ws
            JOIN users u ON ws.user_id = u.id
            JOIN productions p ON ws.production_id = p.id
            WHERE ws.status = 'pending_review'
            ORDER BY ws.submitted_at DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recentSubs as $sub) {
            $notifications[] = [
                'type'   => 'submission',
                'icon'   => 'upload-cloud',
                'color'  => 'var(--neon-cyan)',
                'title'  => 'Work Submitted — ' . htmlspecialchars($sub['prod_name']),
                'detail' => htmlspecialchars($sub['username']) . ' submitted work for review.',
                'time'   => $sub['submitted_at'],
            ];
        }
    } catch (Exception $e) {}
}
// Own recent activity for regular users
if ($user_role === 'user') {
    try {
        $myActivity = $db->prepare("
            SELECT action_type, details, created_at FROM security_logs
            WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
        ");
        $myActivity->execute([$user_id]);
        foreach ($myActivity->fetchAll(PDO::FETCH_ASSOC) as $log) {
            $notifications[] = [
                'type'   => 'activity',
                'icon'   => 'activity',
                'color'  => 'var(--neon-success)',
                'title'  => ucwords(str_replace('_', ' ', $log['action_type'])),
                'detail' => $log['details'] ?: 'System event recorded.',
                'time'   => $log['created_at'],
            ];
        }
    } catch (Exception $e) {}
}
// Sort notifications by time desc and limit to 8
usort($notifications, fn($a,$b) => strcmp($b['time'], $a['time']));
$notifications = array_slice($notifications, 0, 8);

$productions = $db->query("SELECT id, name, progress, status FROM productions ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

$submissions = [];
try {
    $submissions = $db->query("
        SELECT ws.id, ws.work_description, ws.status, ws.submitted_at, ws.progress_percentage,
               ws.file_path, ws.drive_link, ws.feedback, u.username, p.name as prod_name,
               COALESCE(t.team_name,'—') as team_name
        FROM work_submissions ws
        JOIN users u ON ws.user_id = u.id
        JOIN productions p ON ws.production_id = p.id
        LEFT JOIN teams t ON ws.team_id = t.id
        ORDER BY ws.submitted_at DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Full submission history for the current logged-in user
$mySubmissions = [];
try {
    $mySubStmt = $db->prepare("
        SELECT ws.id, ws.work_description, ws.status, ws.submitted_at, ws.progress_percentage,
               ws.file_path, ws.drive_link, ws.feedback, p.name as prod_name,
               COALESCE(t.team_name,'—') as team_name
        FROM work_submissions ws
        JOIN productions p ON ws.production_id = p.id
        LEFT JOIN teams t ON ws.team_id = t.id
        WHERE ws.user_id = ?
        ORDER BY ws.submitted_at DESC LIMIT 100
    ");
    $mySubStmt->execute([$user_id]);
    $mySubmissions = $mySubStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// My Assigned Tasks
$myTasks = [];
try {
    $taskStmt = $db->prepare("
        SELECT t.id, t.title, t.description, t.status, t.deadline, t.created_at,
               p.name as prod_name, tm.team_name, tm.department
        FROM tasks t
        LEFT JOIN productions p ON t.production_id = p.id
        LEFT JOIN teams tm ON t.team_id = tm.id
        WHERE t.assigned_to = ?
        ORDER BY
            CASE t.status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 WHEN 'on_hold' THEN 2 ELSE 3 END,
            t.deadline ASC NULLS LAST,
            t.created_at DESC
    ");
    $taskStmt->execute([$user_id]);
    $myTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Current user's team_id for chat
$myTeamId   = null;
$myTeamName = null;
try {
    $trow = $db->prepare("SELECT u.team_id, tm.team_name FROM users u LEFT JOIN teams tm ON u.team_id=tm.id WHERE u.id=?");
    $trow->execute([$user_id]);
    $tdata = $trow->fetch(PDO::FETCH_ASSOC);
    $myTeamId   = $tdata['team_id']   ?? null;
    $myTeamName = $tdata['team_name'] ?? null;
} catch (Exception $e) {}

$securityLogs = [];
if ($user_role === 'super_admin') {
    $securityLogs = $db->query("
        SELECT sl.action_type, sl.ip_address, sl.details, sl.created_at,
               COALESCE(u.username, 'system') as username, COALESCE(u.role, '') as user_role
        FROM security_logs sl
        LEFT JOIN users u ON sl.user_id = u.id
        ORDER BY sl.created_at DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$fullActivityLogs = [];
$activityStats = ['total' => 0, 'logins' => 0, 'logouts' => 0, 'other' => 0];
$activityActionTypes = [];
if ($user_role === 'super_admin') {
    $fullActivityLogs = $db->query("
        SELECT sl.action_type, sl.ip_address, sl.details, sl.created_at,
               COALESCE(u.username, 'system') as username,
               COALESCE(u.role, '') as user_role,
               COALESCE(u.full_name, '') as full_name
        FROM security_logs sl
        LEFT JOIN users u ON sl.user_id = u.id
        ORDER BY sl.created_at DESC LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);

    try {
        $statsRows = $db->query("SELECT action_type, COUNT(*) as cnt FROM security_logs GROUP BY action_type")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($statsRows as $sr) {
            $activityStats['total'] += $sr['cnt'];
            if (in_array($sr['action_type'], ['user_login', 'admin_login'])) {
                $activityStats['logins'] += $sr['cnt'];
            } elseif ($sr['action_type'] === 'user_logout') {
                $activityStats['logouts'] += $sr['cnt'];
            } else {
                $activityStats['other'] += $sr['cnt'];
            }
            $activityActionTypes[] = $sr['action_type'];
        }
        sort($activityActionTypes);
    } catch (Exception $e) {}
}

$activityLogs = [];
if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])) {
    $activityLogs = $db->query("
        SELECT sl.action_type, sl.ip_address, sl.details, sl.created_at,
               COALESCE(u.username, 'system') as username
        FROM security_logs sl
        LEFT JOIN users u ON sl.user_id = u.id
        WHERE sl.action_type IN ('user_login', 'admin_login', 'user_logout')
        ORDER BY sl.created_at DESC LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$allTeams = $db->query("SELECT id, department, team_name FROM teams ORDER BY department, team_name")->fetchAll(PDO::FETCH_ASSOC);

// Full productions list for management panel
$allProductionsFull = [];
if (in_array($user_role, ['super_admin', 'sub_admin'])) {
    try {
        $allProductionsFull = $db->query("SELECT id, name, description, status, progress, created_at FROM productions ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Work submissions for review panel
$reviewSubmissions = [];
$modTeamId = null;
if (in_array($user_role, ['super_admin', 'sub_admin'])) {
    try {
        $reviewSubmissions = $db->query("SELECT ws.id, ws.work_description, ws.status, ws.submitted_at, ws.progress_percentage, ws.file_path, ws.screenshot_path, ws.feedback, ws.drive_link, u.username, u.full_name, p.name as prod_name, COALESCE(t.team_name,'—') as team_name FROM work_submissions ws JOIN users u ON ws.user_id=u.id JOIN productions p ON ws.production_id=p.id LEFT JOIN teams t ON ws.team_id=t.id ORDER BY ws.submitted_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
} elseif ($user_role === 'team_moderator') {
    $modTeamStmt = $db->prepare("SELECT team_id FROM users WHERE id=?");
    $modTeamStmt->execute([$user_id]);
    $modTeamId = $modTeamStmt->fetchColumn();
    if ($modTeamId) {
        try {
            $modSubStmt = $db->prepare("SELECT ws.id, ws.work_description, ws.status, ws.submitted_at, ws.progress_percentage, ws.file_path, ws.screenshot_path, ws.feedback, ws.drive_link, u.username, u.full_name, p.name as prod_name, COALESCE(t.team_name,'—') as team_name FROM work_submissions ws JOIN users u ON ws.user_id=u.id JOIN productions p ON ws.production_id=p.id LEFT JOIN teams t ON ws.team_id=t.id WHERE ws.team_id=? ORDER BY ws.submitted_at DESC LIMIT 100");
            $modSubStmt->execute([$modTeamId]);
            $reviewSubmissions = $modSubStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

// Admin-broadcast notifications for the current user
$adminNotifications = [];
try {
    $anStmt = $db->query("
        SELECT an.id, an.title, an.message, an.target_roles, an.created_at,
               COALESCE(u.full_name, u.username) as sender_name, u.role as sender_role
        FROM admin_notifications an
        JOIN users u ON an.sender_id = u.id
        ORDER BY an.created_at DESC LIMIT 20
    ");
    foreach ($anStmt->fetchAll(PDO::FETCH_ASSOC) as $an) {
        $targets = explode(',', $an['target_roles']);
        $show = false;
        if (in_array('all', $targets)) $show = true;
        elseif ($user_role === 'user'           && in_array('user',            $targets)) $show = true;
        elseif ($user_role === 'sub_admin'      && (in_array('sub_admin', $targets) || in_array('staff', $targets))) $show = true;
        elseif ($user_role === 'team_moderator' && (in_array('team_moderator', $targets) || in_array('staff', $targets))) $show = true;
        elseif ($user_role === 'super_admin') $show = true;
        if ($show) $adminNotifications[] = $an;
    }
} catch (Exception $e) {}

// Contact messages — admins see messages sent to them
$contactMessages  = [];
$unreadContactCount = 0;
if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])) {
    try {
        $cmStmt = $db->query("
            SELECT cm.id, cm.subject, cm.message, cm.status, cm.created_at, cm.sender_role,
                   COALESCE(u.full_name, u.username) as sender_name
            FROM contact_messages cm
            JOIN users u ON cm.sender_id = u.id
            ORDER BY cm.created_at DESC LIMIT 30
        ");
        foreach ($cmStmt->fetchAll(PDO::FETCH_ASSOC) as $cm) {
            $show = false;
            // User messages → super_admin + sub_admin see them
            if ($cm['sender_role'] === 'user' && in_array($user_role, ['super_admin','sub_admin'])) $show = true;
            // Team moderator messages → super_admin sees them
            if ($cm['sender_role'] === 'team_moderator' && $user_role === 'super_admin') $show = true;
            // Sub admin messages → super_admin sees them
            if ($cm['sender_role'] === 'sub_admin' && $user_role === 'super_admin') $show = true;
            if ($show) {
                $contactMessages[] = $cm;
                if ($cm['status'] === 'unread') $unreadContactCount++;
            }
        }
    } catch (Exception $e) {}
}

// Merge admin broadcast notifications into the main notification feed
foreach ($adminNotifications as $an) {
    $senderLabel = htmlspecialchars($an['sender_name']) . ' [' . strtoupper(str_replace('_',' ',$an['sender_role'])) . ']';
    $notifications[] = [
        'type'   => 'broadcast',
        'icon'   => 'megaphone',
        'color'  => 'var(--neon-purple)',
        'title'  => htmlspecialchars($an['title']),
        'detail' => 'From ' . $senderLabel . ': ' . htmlspecialchars(substr($an['message'],0,80)) . (strlen($an['message'])>80?'…':''),
        'time'   => $an['created_at'],
    ];
}
// Re-sort after adding broadcasts
usort($notifications, fn($a,$b) => strcmp($b['time'], $a['time']));
$notifications = array_slice($notifications, 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus Core | Production Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;900&family=Inter:wght@400;500;600&family=Share+Tech+Mono&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;900&family=Inter:wght@400;500;600&family=Share+Tech+Mono&display=swap"></noscript>
    <link rel="stylesheet" href="css/dashboard.css">
    <script src="js/vendor/lucide.min.js"></script>
</head>
<body>

<!-- ══════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════ -->
<nav class="sidebar">
    <div class="sidebar-header">
        <i data-lucide="film" class="logo-icon"></i>
        <h2>NEXUS CORE</h2>
        <p>Studio Management System</p>
        <div class="sys-status">
            <span class="sys-dot"></span>
            ALL SYSTEMS NOMINAL
        </div>
    </div>

    <div class="sidebar-menu">
        <a href="dashboard.php" class="menu-item active">
            <i data-lucide="layout-dashboard"></i> Main Dashboard
        </a>

        <div class="menu-section">WORK AREA</div>
        <a href="#" class="menu-item" id="btn-work-update">
            <i data-lucide="upload-cloud"></i> Work Update Panel
        </a>
        <a href="#" class="menu-item" id="btn-assigned-tasks">
            <i data-lucide="check-square"></i> My Assigned Tasks
        </a>
        <a href="#" class="menu-item" id="btn-my-submissions">
            <i data-lucide="clipboard-list"></i> My Submissions
        </a>
        <a href="#" class="menu-item" id="btn-team-collab">
            <i data-lucide="message-square"></i> Team Collaboration
        </a>
        <a href="#" class="menu-item" id="btn-contact-us">
            <i data-lucide="mail"></i> Contact Us / Help
        </a>

        <div class="menu-section">DEPARTMENTS</div>
        <div class="menu-dropdown">
            <a href="#" class="menu-item dropdown-toggle">
                <i data-lucide="book-open"></i> Pre-Production
                <i data-lucide="chevron-down" class="chevron"></i>
            </a>
            <div class="dropdown-content">
                <a href="#" data-dept-item="Story Development">Story Development</a>
                <a href="#" data-dept-item="Storyboarding">Storyboarding</a>
                <a href="#" data-dept-item="Concept Art">Concept Art</a>
                <a href="#" data-dept-item="Character Design">Character Design</a>
                <a href="#" data-dept-item="Research &amp; Dev">Research &amp; Dev</a>
                <a href="#" data-dept-item="Voice Planning">Voice Planning</a>
            </div>
        </div>
        <div class="menu-dropdown">
            <a href="#" class="menu-item dropdown-toggle">
                <i data-lucide="box"></i> 3D Production
                <i data-lucide="chevron-down" class="chevron"></i>
            </a>
            <div class="dropdown-content">
                <a href="#" data-dept-item="3D Modeling">3D Modeling</a>
                <a href="#" data-dept-item="Texturing">Texturing</a>
                <a href="#" data-dept-item="Rigging">Rigging</a>
                <a href="#" data-dept-item="Animation">Animation</a>
                <a href="#" data-dept-item="VFX">VFX</a>
                <a href="#" data-dept-item="Rendering Farm">Rendering Farm</a>
            </div>
        </div>
        <div class="menu-dropdown">
            <a href="#" class="menu-item dropdown-toggle">
                <i data-lucide="scissors"></i> Post-Production
                <i data-lucide="chevron-down" class="chevron"></i>
            </a>
            <div class="dropdown-content">
                <a href="#" data-dept-item="Compositing">Compositing</a>
                <a href="#" data-dept-item="Video Editing">Video Editing</a>
                <a href="#" data-dept-item="Color Grading">Color Grading</a>
                <a href="#" data-dept-item="Sound Design">Sound Design</a>
                <a href="#" data-dept-item="Final Mastering">Final Mastering</a>
            </div>
        </div>

        <div class="menu-section">ADMINISTRATION</div>
        <?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
        <a href="#" class="menu-item" id="btn-activity-log" style="color:var(--neon-success);">
            <i data-lucide="activity"></i> Login/Logout Activity
        </a>
        <?php endif; ?>
        <?php if ($user_role === 'super_admin'): ?>
        <a href="#" class="menu-item" id="btn-full-activity-log" style="color:var(--neon-error);">
            <i data-lucide="scroll-text"></i> Full Activity Log
        </a>
        <a href="#" class="menu-item security-btn" id="btn-cybersecurity">
            <i data-lucide="shield-alert"></i> Cybersecurity Logs
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
        <a href="#" class="menu-item admin-btn" id="btn-member-control" style="position:relative;">
            <i data-lucide="users-round"></i> Member Access Control
            <?php if ($pendingApprovals > 0 && in_array($user_role, ['super_admin','sub_admin'])): ?>
            <span class="badge" style="position:static;margin-left:auto;"><?= $pendingApprovals ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if ($user_role === 'super_admin'): ?>
        <a href="#" class="menu-item" id="btn-create-admin" style="color:var(--neon-purple);">
            <i data-lucide="user-cog"></i> Create Admin Account
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
        <a href="#" class="menu-item" id="btn-staff-management" style="color:var(--neon-error);">
            <i data-lucide="shield-minus"></i> Staff Management
        </a>
        <a href="#" class="menu-item" id="btn-production-mgmt" style="color:var(--neon-purple);">
            <i data-lucide="clapperboard"></i> Production Management
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
        <a href="#" class="menu-item" id="btn-work-review" style="color:var(--neon-success);">
            <i data-lucide="clipboard-check"></i> Work Review Panel
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
        <a href="#" class="menu-item" id="btn-teams-directory" style="color:var(--neon-cyan);">
            <i data-lucide="layout-list"></i> Teams Directory
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['super_admin','sub_admin','team_moderator'])): ?>
        <a href="#" class="menu-item" id="btn-send-notification" style="color:var(--neon-warning);">
            <i data-lucide="megaphone"></i> Send Notification
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['super_admin','sub_admin'])): ?>
        <a href="#" class="menu-item" id="btn-contact-messages" style="color:var(--neon-cyan);position:relative;">
            <i data-lucide="inbox"></i> Contact Messages
            <?php if ($unreadContactCount > 0): ?>
            <span class="badge" style="position:static;margin-left:auto;"><?= $unreadContactCount ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-info">
            <div class="details">
                <span class="name"><?= htmlspecialchars($username) ?></span>
                <span class="role"><?= strtoupper(str_replace('_', ' ', $user_role)) ?></span>
            </div>
        </div>
        <button class="logout-btn" onclick="window.location.href='api/auth/logout.php'" title="Logout">
            <i data-lucide="log-out"></i>
        </button>
    </div>
</nav>

<!-- ══════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════ -->
<main class="main-content">
    <header class="top-header">
        <div class="search-bar">
            <i data-lucide="search"></i>
            <input type="text" placeholder="Search productions, tasks, members...">
        </div>
        <div class="header-actions" style="position:relative;">

            <!-- Notification Button -->
            <div class="hdr-dropdown-wrap">
                <button class="icon-btn" id="btn-notif" title="Notifications">
                    <i data-lucide="bell"></i>
                    <?php if (count($notifications) > 0): ?>
                    <span class="badge"><?= count($notifications) ?></span>
                    <?php endif; ?>
                </button>

                <!-- Notification Panel -->
                <div class="hdr-dropdown" id="panel-notif">
                    <div class="hdr-dropdown-header">
                        <span><i data-lucide="bell" style="width:13px;height:13px;vertical-align:middle;margin-right:6px;"></i>NOTIFICATIONS</span>
                        <span style="font-size:10px;color:var(--neon-cyan);"><?= count($notifications) ?> NEW</span>
                    </div>
                    <div class="hdr-dropdown-body">
                        <?php if (empty($notifications)): ?>
                        <div class="notif-empty">
                            <i data-lucide="check-circle" style="width:28px;height:28px;color:var(--neon-success);display:block;margin:0 auto 8px;"></i>
                            All systems clear. No new alerts.
                        </div>
                        <?php else: ?>
                        <?php foreach ($notifications as $n): ?>
                        <div class="notif-item">
                            <div class="notif-icon" style="background:<?= $n['color'] ?>1a;border-color:<?= $n['color'] ?>33;">
                                <i data-lucide="<?= $n['icon'] ?>" style="width:13px;height:13px;color:<?= $n['color'] ?>;"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title"><?= $n['title'] ?></div>
                                <div class="notif-detail"><?= $n['detail'] ?></div>
                                <div class="notif-time"><?= date('M d, H:i', strtotime($n['time'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (in_array($user_role, ['super_admin','sub_admin']) && $pendingApprovals > 0): ?>
                    <div class="hdr-dropdown-footer">
                        <a href="#" id="notif-goto-members" style="color:var(--neon-cyan);font-size:10px;letter-spacing:1px;text-decoration:none;">
                            VIEW <?= $pendingApprovals ?> PENDING APPROVALS →
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Settings Button -->
            <div class="hdr-dropdown-wrap">
                <button class="icon-btn" id="btn-settings" title="Settings">
                    <i data-lucide="settings"></i>
                </button>

                <!-- Settings Panel -->
                <div class="hdr-dropdown" id="panel-settings" style="width:320px;right:0;">
                    <div class="hdr-dropdown-header">
                        <span><i data-lucide="settings" style="width:13px;height:13px;vertical-align:middle;margin-right:6px;"></i>SETTINGS</span>
                    </div>
                    <div class="hdr-dropdown-body" style="padding:0;">

                        <!-- Profile Card -->
                        <div style="padding:16px;border-bottom:1px solid rgba(0,240,255,0.06);">
                            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                                <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,rgba(0,240,255,.15),rgba(168,85,247,.15));border:1px solid rgba(0,240,255,.2);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">👤</div>
                                <div>
                                    <div style="font-size:13px;font-weight:600;color:var(--text-main);"><?= htmlspecialchars($userProfile['full_name'] ?? $username) ?></div>
                                    <div style="font-size:10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;letter-spacing:1px;"><?= strtoupper(str_replace('_',' ',$user_role)) ?></div>
                                    <div style="font-size:10px;color:var(--text-muted);margin-top:1px;"><?= htmlspecialchars($userProfile['email'] ?? '') ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Change Password -->
                        <div style="padding:14px 16px;border-bottom:1px solid rgba(0,240,255,0.06);">
                            <div style="font-size:10px;color:var(--text-muted);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;font-family:'Share Tech Mono',monospace;">Change Password</div>
                            <div id="settings-msg" class="alert" style="margin-bottom:8px;display:none;"></div>
                            <form id="form-change-password">
                                <div style="margin-bottom:8px;">
                                    <input type="password" name="current_password" placeholder="Current password" style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(0,240,255,0.1);color:var(--text-main);padding:8px 11px;border-radius:6px;font-size:12px;outline:none;font-family:'Inter',sans-serif;">
                                </div>
                                <div style="margin-bottom:8px;">
                                    <input type="password" name="new_password" placeholder="New password (min 8 chars)" style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(0,240,255,0.1);color:var(--text-main);padding:8px 11px;border-radius:6px;font-size:12px;outline:none;font-family:'Inter',sans-serif;">
                                </div>
                                <div style="margin-bottom:10px;">
                                    <input type="password" name="confirm_password" placeholder="Confirm new password" style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(0,240,255,0.1);color:var(--text-main);padding:8px 11px;border-radius:6px;font-size:12px;outline:none;font-family:'Inter',sans-serif;">
                                </div>
                                <button type="submit" class="action-btn" style="width:100%;justify-content:center;padding:9px;font-size:10px;letter-spacing:1.5px;">
                                    <i data-lucide="lock" style="width:12px;height:12px;"></i> UPDATE PASSWORD
                                </button>
                            </form>
                        </div>

                        <!-- Display Preferences -->
                        <div style="padding:14px 16px;border-bottom:1px solid rgba(0,240,255,0.06);">
                            <div style="font-size:10px;color:var(--text-muted);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;font-family:'Share Tech Mono',monospace;">Display</div>
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                                <span style="font-size:12px;color:var(--text-main);">Compact Sidebar</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="toggle-compact-sidebar">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-size:12px;color:var(--text-main);">N.A.V.I Always Visible</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="toggle-navi-visible" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <!-- System Info -->
                        <div style="padding:12px 16px;">
                            <div style="font-size:9.5px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;line-height:1.9;">
                                <div>USER ID: <span style="color:var(--neon-cyan);">#<?= $user_id ?></span></div>
                                <div>HANDLE: <span style="color:var(--neon-cyan);"><?= htmlspecialchars($username) ?></span></div>
                                <div>CLEARANCE: <span style="color:var(--neon-purple);"><?= strtoupper(str_replace('_',' ',$user_role)) ?></span></div>
                                <div>SESSION: <span style="color:var(--neon-success);">ACTIVE</span></div>
                            </div>
                            <a href="api/auth/logout.php" style="display:block;margin-top:12px;padding:8px;text-align:center;background:rgba(255,45,85,.08);border:1px solid rgba(255,45,85,.2);border-radius:6px;color:var(--neon-error);font-size:10px;letter-spacing:1.5px;text-decoration:none;font-family:'Share Tech Mono',monospace;transition:all .2s;">
                                ⏻ TERMINATE SESSION
                            </a>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </header>

    <div class="dashboard-wrapper">

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h1>Welcome to Nexus Core Command</h1>
                <p>System operating at optimal efficiency &mdash; Operator: <strong style="color:var(--neon-cyan);"><?= htmlspecialchars($username) ?></strong> &nbsp;|&nbsp; Clearance: <strong style="color:var(--neon-warning);"><?= strtoupper(str_replace('_',' ',$user_role)) ?></strong></p>
            </div>
            <?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
            <div style="display:flex;gap:10px;flex-shrink:0;">
                <button class="action-btn primary" id="btn-new-prod">
                    <i data-lucide="plus"></i> New Production
                </button>
                <?php if ($user_role === 'super_admin'): ?>
                <button class="action-btn" id="btn-new-team" style="border-color:var(--neon-warning);color:var(--neon-warning);">
                    <i data-lucide="users"></i> New Team
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i data-lucide="users"></i></div>
                <div class="stat-details">
                    <h3>Registered Members</h3>
                    <p class="value"><?= $totalUsers ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(168,85,247,.08);border-color:rgba(168,85,247,.2);">
                    <i data-lucide="clapperboard" style="color:var(--neon-purple);"></i>
                </div>
                <div class="stat-details">
                    <h3>Active Productions</h3>
                    <p class="value" style="color:var(--neon-purple);"><?= $activeProds ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(0,255,136,.08);border-color:rgba(0,255,136,.2);">
                    <i data-lucide="upload-cloud" style="color:var(--neon-success);"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Uploads</h3>
                    <p class="value" style="color:var(--neon-success);"><?= $totalUploads ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2);">
                    <i data-lucide="shield" style="color:var(--neon-warning);"></i>
                </div>
                <div class="stat-details">
                    <h3>Pending Approvals</h3>
                    <p class="value" style="color:var(--neon-warning);"><?= $pendingApprovals ?></p>
                </div>
            </div>
        </div>

        <!-- ── Live System Monitor (Super Admin only) ─────────────── -->
        <?php if ($user_role === 'super_admin'): ?>
        <div id="live-stats-widget" style="margin-bottom:20px;border:1px solid rgba(0,240,255,.18);border-radius:10px;background:rgba(0,240,255,.03);overflow:hidden;">

            <!-- Widget Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 18px;background:rgba(0,240,255,.05);border-bottom:1px solid rgba(0,240,255,.1);">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span id="lsw-pulse" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--neon-success);box-shadow:0 0 6px var(--neon-success);animation:lswPulse 1.4s ease-in-out infinite;"></span>
                    <span style="font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:3px;color:var(--neon-cyan);text-transform:uppercase;">Live System Monitor</span>
                    <span style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);letter-spacing:1px;">— Super Admin Clearance</span>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);">Updated: <span id="lsw-last-update">—</span></span>
                    <span style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);">Next in <span id="lsw-countdown" style="color:var(--neon-cyan);">30</span>s</span>
                    <button id="lsw-refresh-btn" style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1px;background:none;border:1px solid rgba(0,240,255,.3);color:var(--neon-cyan);border-radius:4px;padding:3px 10px;cursor:pointer;text-transform:uppercase;" onclick="lswFetch()">
                        <i data-lucide="refresh-cw" style="width:10px;height:10px;vertical-align:middle;margin-right:3px;"></i>Refresh
                    </button>
                </div>
            </div>

            <!-- Stat Tiles -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0;border-bottom:1px solid rgba(255,255,255,.04);">

                <div class="lsw-tile" style="padding:14px 18px;border-right:1px solid rgba(255,255,255,.05);">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i data-lucide="wifi" style="width:12px;height:12px;color:var(--neon-success);"></i>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;">Online Now</span>
                    </div>
                    <p id="lsw-online" style="margin:0;font-family:'Share Tech Mono',monospace;font-size:26px;font-weight:700;color:var(--neon-success);line-height:1;">—</p>
                    <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">active last 15 min</p>
                </div>

                <div class="lsw-tile" style="padding:14px 18px;border-right:1px solid rgba(255,255,255,.05);">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i data-lucide="user-check" style="width:12px;height:12px;color:var(--neon-warning);"></i>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;">Pending Approvals</span>
                    </div>
                    <p id="lsw-pending" style="margin:0;font-family:'Share Tech Mono',monospace;font-size:26px;font-weight:700;color:var(--neon-warning);line-height:1;">—</p>
                    <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">awaiting clearance</p>
                </div>

                <div class="lsw-tile" style="padding:14px 18px;border-right:1px solid rgba(255,255,255,.05);">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i data-lucide="activity" style="width:12px;height:12px;color:var(--neon-cyan);"></i>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;">Activity (1h)</span>
                    </div>
                    <p id="lsw-activity" style="margin:0;font-family:'Share Tech Mono',monospace;font-size:26px;font-weight:700;color:var(--neon-cyan);line-height:1;">—</p>
                    <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">system events</p>
                </div>

                <div class="lsw-tile" style="padding:14px 18px;border-right:1px solid rgba(255,255,255,.05);">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i data-lucide="clock" style="width:12px;height:12px;color:var(--neon-purple);"></i>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;">Pending Work</span>
                    </div>
                    <p id="lsw-work" style="margin:0;font-family:'Share Tech Mono',monospace;font-size:26px;font-weight:700;color:var(--neon-purple);line-height:1;">—</p>
                    <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">submissions to review</p>
                </div>

                <div class="lsw-tile" style="padding:14px 18px;border-right:1px solid rgba(255,255,255,.05);">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i data-lucide="users" style="width:12px;height:12px;color:var(--neon-success);"></i>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;">Active Members</span>
                    </div>
                    <p id="lsw-members" style="margin:0;font-family:'Share Tech Mono',monospace;font-size:26px;font-weight:700;color:var(--neon-success);line-height:1;">—</p>
                    <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">staff &amp; moderators</p>
                </div>

                <div class="lsw-tile" style="padding:14px 18px;">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i data-lucide="user-plus" style="width:12px;height:12px;color:var(--neon-warning);"></i>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;">New Today</span>
                    </div>
                    <p id="lsw-new" style="margin:0;font-family:'Share Tech Mono',monospace;font-size:26px;font-weight:700;color:var(--neon-warning);line-height:1;">—</p>
                    <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">registrations</p>
                </div>

            </div>

            <!-- Mini Activity Feed -->
            <div style="padding:10px 18px;">
                <p style="margin:0 0 8px;font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--text-muted);text-transform:uppercase;">Recent System Events</p>
                <div id="lsw-feed" style="display:flex;flex-direction:column;gap:4px;">
                    <p style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);">Loading…</p>
                </div>
            </div>

        </div>

        <style>
        @keyframes lswPulse {
            0%,100% { opacity:1; box-shadow:0 0 6px var(--neon-success); }
            50%      { opacity:.4; box-shadow:0 0 2px var(--neon-success); }
        }
        #lsw-refresh-btn:hover { background:rgba(0,240,255,.08); }
        </style>
        <?php endif; ?>

        <!-- Login/Logout Activity Strip (admins only) -->
        <?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
        <div class="panel" style="margin-bottom:20px;">
            <div class="panel-header">
                <h3><i data-lucide="activity" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;color:var(--neon-success);"></i>Login &amp; Logout Activity</h3>
                <button class="text-btn" id="btn-activity-log" style="color:var(--neon-success);">View All</button>
            </div>
            <div style="overflow-x:auto;">
                <?php if (empty($activityLogs)): ?>
                    <p style="color:var(--text-muted);font-size:13px;font-family:'Share Tech Mono',monospace;padding:20px 0;text-align:center;">No login or logout events recorded yet.</p>
                <?php else: ?>
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">TIME</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">USER</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">EVENT</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">IP ADDRESS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($activityLogs, 0, 8) as $log):
                        $isLogin  = in_array($log['action_type'], ['user_login', 'admin_login']);
                        $isLogout = $log['action_type'] === 'user_logout';
                        $color    = $isLogin ? 'var(--neon-success)' : ($isLogout ? 'var(--neon-error)' : 'var(--neon-warning)');
                        $icon     = $isLogin ? '▶ LOGIN' : ($isLogout ? '◀ LOGOUT' : strtoupper($log['action_type']));
                        $isAdmin  = $log['action_type'] === 'admin_login';
                    ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                        <td style="padding:9px 10px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:11px;"><?= date('M d H:i', strtotime($log['created_at'])) ?></td>
                        <td style="padding:9px 10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($log['username']) ?><?= $isAdmin ? ' <span style="color:var(--neon-warning);font-size:9px;">[ADMIN]</span>' : '' ?></td>
                        <td style="padding:9px 10px;"><span style="color:<?= $color ?>;font-family:'Share Tech Mono',monospace;font-size:11px;font-weight:bold;"><?= $icon ?></span></td>
                        <td style="padding:9px 10px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:11px;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Production Progress -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Production Pipeline</h3>
                    <?php if (in_array($user_role, ['super_admin','sub_admin'])): ?>
                    <button class="text-btn" id="btn-new-prod-2">+ New</button>
                    <?php endif; ?>
                </div>
                <div class="progress-list">
                    <?php if (empty($productions)): ?>
                        <p style="color:var(--text-muted);font-size:13px;font-family:'Share Tech Mono',monospace;padding:20px 0;text-align:center;">
                            No productions initialized yet.
                        </p>
                    <?php else: ?>
                        <?php foreach ($productions as $prod): ?>
                        <div class="progress-item">
                            <div class="prog-info">
                                <span><?= htmlspecialchars($prod['name']) ?></span>
                                <span style="color:var(--neon-cyan);"><?= $prod['progress'] ?>%</span>
                            </div>
                            <div class="prog-bar-container">
                                <div class="prog-bar" style="width:<?= $prod['progress'] ?>%;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Submissions -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Recent Submissions</h3>
                    <button class="text-btn" id="btn-work-update-2">Submit Work</button>
                </div>
                <div class="submission-list">
                    <?php if (empty($submissions)): ?>
                        <p style="color:var(--text-muted);font-size:13px;font-family:'Share Tech Mono',monospace;padding:20px 0;text-align:center;">
                            No work submissions recorded.
                        </p>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): ?>
                        <div class="submission-item">
                            <div class="sub-icon"><i data-lucide="file-check"></i></div>
                            <div class="sub-details">
                                <h4><?= htmlspecialchars(substr($sub['work_description'], 0, 40)) ?><?= strlen($sub['work_description']) > 40 ? '…' : '' ?></h4>
                                <p><?= htmlspecialchars($sub['prod_name']) ?> &bull; <?= htmlspecialchars($sub['username']) ?></p>
                            </div>
                            <div class="sub-status status-<?= strtolower($sub['status']) ?>">
                                <?= ucfirst(str_replace('_', ' ', $sub['status'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- ══════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════ -->

<!-- 1. Work Update Panel -->
<div id="modal-work-update" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="upload-cloud" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Submit Work Update</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="work-alert" class="alert"></div>
            <form id="form-work-update">
                <input type="hidden" name="action" value="submit_work">
                <div class="input-group">
                    <label>Your Full Name <span style="color:var(--neon-error);">*</span></label>
                    <input type="text" name="submitter_name" required placeholder="Enter your full name first..." style="font-size:13px;">
                </div>
                <div class="input-group">
                    <label>Production</label>
                    <select name="production_id" required>
                        <option value="" disabled selected>Select production...</option>
                        <?php
                        $allProds = $db->query("SELECT id, name FROM productions WHERE status IN ('active','planning') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($allProds as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Your Team</label>
                    <select name="team_id" required>
                        <option value="" disabled selected>Select team...</option>
                        <?php foreach ($allTeams as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['department'] . ' › ' . $t['team_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Work Description</label>
                    <textarea name="description" rows="3" required placeholder="Describe what you've completed..."></textarea>
                </div>
                <div class="input-group">
                    <label>Progress Percentage (0–100)</label>
                    <input type="number" name="progress" min="0" max="100" required placeholder="75">
                </div>
                <div class="input-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <i data-lucide="hard-drive" style="width:13px;height:13px;color:var(--neon-cyan);"></i>
                        Google Drive Link
                        <span style="color:var(--text-muted);font-size:11px;">(optional)</span>
                    </label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="url" name="drive_link" placeholder="Paste your Google Drive file/folder link..." style="font-size:12px;flex:1;">
                        <a href="https://drive.google.com/drive/folders/1bzlSzavLLiti1H1B7G2iHzkUJy2NyvIr?usp=sharing" target="_blank" rel="noopener" title="Open shared Drive folder" style="display:inline-flex;align-items:center;gap:5px;padding:8px 12px;background:rgba(0,240,255,0.08);border:1px solid rgba(0,240,255,0.3);border-radius:6px;color:var(--neon-cyan);font-size:11px;font-family:'Share Tech Mono',monospace;text-decoration:none;white-space:nowrap;transition:background .2s;" onmouseover="this.style.background='rgba(0,240,255,0.18)'" onmouseout="this.style.background='rgba(0,240,255,0.08)'">
                            <i data-lucide="folder-open" style="width:13px;height:13px;"></i> Open Drive
                        </a>
                    </div>
                    <p style="font-size:10px;color:var(--text-muted);margin-top:5px;font-family:'Share Tech Mono',monospace;">
                        Upload your work to the shared Drive folder, then paste the link here.
                    </p>
                </div>
                <div class="input-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <i data-lucide="upload" style="width:13px;height:13px;color:var(--neon-cyan);"></i>
                        Upload Work File
                        <span style="color:var(--text-muted);font-size:11px;">(optional)</span>
                    </label>
                    <input type="file" name="work_file" style="padding:8px;">
                </div>
                <button type="submit" class="action-btn">
                    <i data-lucide="send" style="width:14px;height:14px;"></i>
                    Submit for Review
                </button>
            </form>
        </div>
    </div>
</div>

<!-- 2. Assigned Tasks -->
<div id="modal-assigned-tasks" class="modal-overlay">
    <div class="modal-content" style="max-width:720px;">
        <div class="modal-header">
            <div>
                <h2><i data-lucide="check-square" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>My Assigned Tasks</h2>
                <p style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:1px;margin-top:3px;"><?= count($myTasks) ?> TASK<?= count($myTasks) !== 1 ? 'S' : '' ?> ASSIGNED</p>
            </div>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <?php if (empty($myTasks)): ?>
            <div style="text-align:center;padding:60px 20px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">
                <i data-lucide="inbox" style="width:40px;height:40px;display:block;margin:0 auto 16px;opacity:.25;"></i>
                <p style="margin:0;font-size:13px;">No tasks assigned yet.</p>
                <p style="margin:8px 0 0;font-size:11px;opacity:.6;">Check back with your Team Moderator.</p>
            </div>
            <?php else: ?>
            <!-- Status filter tabs -->
            <div style="display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap;">
                <?php
                $tStatuses = ['all'=>['label'=>'All','color'=>'var(--text-muted)'],'in_progress'=>['label'=>'In Progress','color'=>'var(--neon-cyan)'],'pending'=>['label'=>'Pending','color'=>'var(--neon-warning)'],'on_hold'=>['label'=>'On Hold','color'=>'var(--neon-purple)'],'completed'=>['label'=>'Completed','color'=>'var(--neon-success)']];
                foreach ($tStatuses as $k => $ts): ?>
                <button class="task-tab action-btn <?= $k==='all'?'active':'' ?>" data-tstatus="<?= $k ?>"
                    style="padding:4px 12px;font-size:10px;<?= $k!=='all'?'border-color:'.$ts['color'].';color:'.$ts['color'].';':'' ?>"
                    onclick="filterTasks('<?= $k ?>')">
                    <?= $ts['label'] ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div id="tasks-list" style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($myTasks as $tk):
                $tkColors  = ['in_progress'=>'var(--neon-cyan)','pending'=>'var(--neon-warning)','on_hold'=>'var(--neon-purple)','completed'=>'var(--neon-success)'];
                $tkLabels  = ['in_progress'=>'In Progress','pending'=>'Pending','on_hold'=>'On Hold','completed'=>'Completed'];
                $tkBgs     = ['in_progress'=>'rgba(0,240,255,0.03)','pending'=>'rgba(245,158,11,0.03)','on_hold'=>'rgba(168,85,247,0.03)','completed'=>'rgba(0,255,136,0.03)'];
                $tkBorders = ['in_progress'=>'rgba(0,240,255,0.15)','pending'=>'rgba(245,158,11,0.15)','on_hold'=>'rgba(168,85,247,0.15)','completed'=>'rgba(0,255,136,0.15)'];
                $tkColor   = $tkColors[$tk['status']] ?? 'var(--text-muted)';
                $tkLabel   = $tkLabels[$tk['status']] ?? ucwords(str_replace('_',' ',$tk['status']));
                $tkBg      = $tkBgs[$tk['status']]   ?? 'rgba(255,255,255,0.02)';
                $tkBorder  = $tkBorders[$tk['status']] ?? 'rgba(255,255,255,0.07)';
                $isOverdue = $tk['deadline'] && $tk['status'] !== 'completed' && strtotime($tk['deadline']) < time();
                $daysLeft  = $tk['deadline'] ? ceil((strtotime($tk['deadline']) - time()) / 86400) : null;
            ?>
            <div class="task-card" data-tstatus="<?= htmlspecialchars($tk['status']) ?>"
                 style="background:<?= $tkBg ?>;border:1px solid <?= $tkBorder ?>;border-radius:8px;padding:16px 18px;transition:border-color .2s;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                    <div style="flex:1;">
                        <p style="margin:0 0 4px;font-size:14px;color:var(--text-main);font-weight:600;"><?= htmlspecialchars($tk['title']) ?></p>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <span style="font-size:10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($tk['prod_name'] ?? '—') ?></span>
                            <span style="color:var(--text-muted);font-size:10px;">›</span>
                            <span style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($tk['team_name'] ?? '—') ?></span>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                        <span style="padding:3px 10px;border:1px solid <?= $tkColor ?>;color:<?= $tkColor ?>;border-radius:20px;font-size:10px;font-family:'Share Tech Mono',monospace;font-weight:700;white-space:nowrap;"><?= $tkLabel ?></span>
                        <?php if ($tk['deadline']): ?>
                        <span style="font-size:10px;font-family:'Share Tech Mono',monospace;color:<?= $isOverdue ? 'var(--neon-error)' : ($daysLeft <= 3 ? 'var(--neon-warning)' : 'var(--text-muted)') ?>;">
                            <?= $isOverdue ? '⚠ OVERDUE · ' : '' ?><?= date('M d, Y', strtotime($tk['deadline'])) ?>
                            <?php if (!$isOverdue && $daysLeft !== null && $tk['status'] !== 'completed'): ?>
                            <span style="opacity:.7;">(<?= $daysLeft > 0 ? $daysLeft.'d left' : 'due today' ?>)</span>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($tk['description']): ?>
                <p style="margin:0 0 12px;font-size:12px;color:var(--text-muted);line-height:1.6;"><?= htmlspecialchars($tk['description']) ?></p>
                <?php endif; ?>
                <!-- Status updater -->
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:8px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.05);">
                    <span style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;margin-right:4px;">UPDATE STATUS:</span>
                    <?php foreach (['in_progress'=>'In Progress','pending'=>'Pending','on_hold'=>'On Hold','completed'=>'Completed'] as $sv => $sl): ?>
                    <button onclick="updateTaskStatus(<?= $tk['id'] ?>, '<?= $sv ?>', this)"
                        style="padding:3px 10px;font-size:10px;font-family:'Share Tech Mono',monospace;border-radius:4px;cursor:pointer;transition:.15s;
                               background:<?= $tk['status']===$sv?$tkColor:'transparent' ?>;
                               color:<?= $tk['status']===$sv?'#000':$tkColors[$sv]??'var(--text-muted)' ?>;
                               border:1px solid <?= $tkColors[$sv]??'rgba(255,255,255,.2)' ?>;">
                        <?= $sl ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 3. My Submissions History -->
<div id="modal-my-submissions" class="modal-overlay">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-cyan);text-shadow:0 0 10px rgba(0,240,255,.3);">
                <i data-lucide="clipboard-list" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                My Submission History
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <?php if (empty($mySubmissions)): ?>
            <div style="text-align:center;padding:50px 20px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">
                <i data-lucide="inbox" style="width:36px;height:36px;display:block;margin:0 auto 14px;opacity:.3;"></i>
                No submissions yet. Use the Work Update Panel to submit your work.
            </div>
            <?php else: ?>
            <!-- Status filter tabs -->
            <div style="display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap;">
                <?php
                $msStatuses = ['all'=>'All','pending_review'=>'Pending','approved'=>'Approved','needs_fix'=>'Needs Fix','featured'=>'Featured','rejected'=>'Rejected'];
                $msColors   = ['all'=>'var(--text-muted)','pending_review'=>'var(--neon-warning)','approved'=>'var(--neon-success)','needs_fix'=>'var(--neon-cyan)','featured'=>'var(--neon-purple)','rejected'=>'var(--neon-error)'];
                foreach ($msStatuses as $k => $label): ?>
                <button class="ms-tab action-btn <?= $k==='all'?'active':'' ?>" data-status="<?= $k ?>"
                    style="padding:4px 12px;font-size:10px;<?= $k!=='all'?'border-color:'.$msColors[$k].';color:'.$msColors[$k].';':'' ?>"
                    onclick="filterMySubmissions('<?= $k ?>')">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>
            <!-- Submission cards -->
            <div id="my-submissions-list" style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($mySubmissions as $ms):
                $msColorMap = ['approved'=>'var(--neon-success)','rejected'=>'var(--neon-error)','featured'=>'var(--neon-purple)','needs_fix'=>'var(--neon-cyan)','pending_review'=>'var(--neon-warning)'];
                $msLabelMap = ['approved'=>'Approved','rejected'=>'Rejected','featured'=>'Featured','needs_fix'=>'Needs Fix','pending_review'=>'Pending Review'];
                $msColor    = $msColorMap[$ms['status']] ?? 'var(--text-muted)';
                $msLabel    = $msLabelMap[$ms['status']] ?? ucwords(str_replace('_',' ',$ms['status']));
                $msHasFile  = !empty($ms['file_path']);
                $msFileExt  = $msHasFile ? strtoupper(pathinfo($ms['file_path'], PATHINFO_EXTENSION)) : '';
                $msBgColor  = $ms['status'] === 'needs_fix' ? 'rgba(0,240,255,0.03)' : ($ms['status'] === 'approved' ? 'rgba(0,255,136,0.03)' : ($ms['status'] === 'rejected' ? 'rgba(239,68,68,0.03)' : ($ms['status'] === 'featured' ? 'rgba(168,85,247,0.03)' : 'rgba(255,255,255,0.02)')));
                $msBorderColor = $ms['status'] === 'needs_fix' ? 'rgba(0,240,255,0.15)' : ($ms['status'] === 'approved' ? 'rgba(0,255,136,0.15)' : ($ms['status'] === 'rejected' ? 'rgba(239,68,68,0.15)' : ($ms['status'] === 'featured' ? 'rgba(168,85,247,0.15)' : 'rgba(255,255,255,0.07)')));
            ?>
            <div class="ms-card" data-status="<?= htmlspecialchars($ms['status']) ?>"
                 style="background:<?= $msBgColor ?>;border:1px solid <?= $msBorderColor ?>;border-radius:8px;padding:16px 18px;">
                <!-- Top row: meta + status -->
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                    <div style="flex:1;">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                            <span style="font-size:11px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-weight:700;"><?= htmlspecialchars($ms['prod_name']) ?></span>
                            <span style="color:var(--text-muted);font-size:10px;">›</span>
                            <span style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($ms['team_name']) ?></span>
                            <span style="color:var(--text-muted);font-size:10px;">·</span>
                            <span style="font-size:10px;color:var(--text-muted);"><?= date('M d, Y  H:i', strtotime($ms['submitted_at'])) ?></span>
                        </div>
                    </div>
                    <span style="padding:3px 10px;border:1px solid <?= $msColor ?>;color:<?= $msColor ?>;border-radius:20px;font-size:10px;font-family:'Share Tech Mono',monospace;font-weight:700;white-space:nowrap;"><?= $msLabel ?></span>
                </div>

                <!-- Description -->
                <p style="margin:0 0 12px;font-size:13px;color:var(--text-main);line-height:1.7;white-space:pre-wrap;"><?= htmlspecialchars($ms['work_description']) ?></p>

                <!-- Progress bar -->
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px;">
                        <span style="color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:1px;text-transform:uppercase;">Progress Reported</span>
                        <span style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-weight:700;"><?= $ms['progress_percentage'] ?>%</span>
                    </div>
                    <div style="background:rgba(255,255,255,0.06);border-radius:4px;height:6px;overflow:hidden;">
                        <div style="background:<?= $msColor ?>;height:100%;width:<?= $ms['progress_percentage'] ?>%;border-radius:4px;transition:width .5s ease;"></div>
                    </div>
                </div>

                <!-- Bottom row: file + feedback -->
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                    <?php if ($msHasFile): ?>
                    <a href="api/admin/download_work.php?id=<?= $ms['id'] ?>" download
                       style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border:1px solid var(--neon-success);color:var(--neon-success);border-radius:4px;text-decoration:none;font-size:11px;font-family:'Share Tech Mono',monospace;white-space:nowrap;">
                       <i data-lucide="download" style="width:11px;height:11px;"></i> Download <?= $msFileExt ?>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($ms['feedback'])): ?>
                    <div style="flex:1;min-width:200px;background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.25);border-radius:6px;padding:10px 14px;">
                        <p style="margin:0 0 4px;font-size:9px;letter-spacing:2px;color:var(--neon-warning);text-transform:uppercase;font-family:'Share Tech Mono',monospace;">
                            <i data-lucide="message-circle" style="width:10px;height:10px;vertical-align:middle;margin-right:4px;"></i>
                            Reviewer Feedback
                        </p>
                        <p style="margin:0;font-size:13px;color:var(--text-main);line-height:1.6;"><?= htmlspecialchars($ms['feedback']) ?></p>
                    </div>
                    <?php elseif ($ms['status'] === 'pending_review'): ?>
                    <div style="flex:1;min-width:200px;background:rgba(255,255,255,0.02);border:1px dashed rgba(255,255,255,0.1);border-radius:6px;padding:10px 14px;">
                        <p style="margin:0;font-size:12px;color:var(--text-muted);font-style:italic;">Awaiting review by your team moderator or admin.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 4. Team Collaboration -->
<div id="modal-team-collab" class="modal-overlay">
    <div class="modal-content" style="max-width:680px;height:80vh;display:flex;flex-direction:column;">
        <div class="modal-header" style="flex-shrink:0;">
            <div>
                <h2><i data-lucide="message-square" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Team Collaboration</h2>
                <p style="font-size:10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;letter-spacing:1px;margin-top:3px;">
                    <?= $myTeamName ? htmlspecialchars($myTeamName) . ' CHANNEL' : 'GENERAL CHANNEL' ?>
                    <span id="chat-online-dot" style="display:inline-block;width:6px;height:6px;background:var(--neon-success);border-radius:50%;margin-left:6px;vertical-align:middle;"></span>
                    <span style="color:var(--text-muted);margin-left:4px;">LIVE</span>
                </p>
            </div>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body" style="flex:1;display:flex;flex-direction:column;padding:0;overflow:hidden;">
            <!-- Message list -->
            <div id="chat-messages"
                 style="flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;">
                <div style="text-align:center;padding:30px 0;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:12px;" id="chat-loading">
                    <i data-lucide="loader" style="width:18px;height:18px;display:block;margin:0 auto 8px;animation:spin 1s linear infinite;"></i>
                    Loading messages...
                </div>
            </div>
            <!-- Composer -->
            <div style="flex-shrink:0;padding:12px 16px;border-top:1px solid rgba(255,255,255,0.07);background:rgba(0,0,0,0.2);">
                <form id="chat-form" style="display:flex;gap:8px;align-items:flex-end;">
                    <textarea id="chat-input" rows="1" maxlength="2000"
                        placeholder="Send a message to your team..."
                        style="flex:1;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text-main);padding:10px 14px;font-size:13px;resize:none;line-height:1.5;font-family:inherit;max-height:120px;overflow-y:auto;"
                        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChatMessage();}"></textarea>
                    <button type="button" onclick="sendChatMessage()"
                        style="flex-shrink:0;padding:10px 16px;background:var(--neon-cyan);color:#000;border:none;border-radius:8px;font-family:'Share Tech Mono',monospace;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:.2s;"
                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                        <i data-lucide="send" style="width:14px;height:14px;"></i> SEND
                    </button>
                </form>
                <p style="margin:6px 0 0;font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">Enter to send · Shift+Enter for new line · Auto-refreshes every 4s</p>
            </div>
        </div>
    </div>
</div>

<!-- 4. Member Access Control (role-based tabbed panel) -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
<?php
    $macAccent = match($user_role) {
        'super_admin'    => ['color' => 'var(--neon-warning)', 'glow' => 'rgba(245,158,11,.4)', 'label' => 'SUPER ADMIN — FULL CLEARANCE'],
        'sub_admin'      => ['color' => 'var(--neon-purple)',  'glow' => 'rgba(168,85,247,.4)',  'label' => 'SUB ADMIN — MANAGEMENT CLEARANCE'],
        'team_moderator' => ['color' => 'var(--neon-cyan)',    'glow' => 'rgba(0,212,255,.4)',   'label' => 'TEAM MODERATOR — VIEW ACCESS'],
        default          => ['color' => 'var(--neon-cyan)',    'glow' => 'rgba(0,212,255,.4)',   'label' => ''],
    };
?>
<div id="modal-member-control" class="modal-overlay">
    <div class="modal-content" style="max-width:960px;">
        <div class="modal-header" style="border-bottom-color:<?= $macAccent['color'] ?>33;">
            <div>
                <h2 style="color:<?= $macAccent['color'] ?>;text-shadow:0 0 10px <?= $macAccent['glow'] ?>;">
                    <i data-lucide="users-round" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                    Member Access Control
                </h2>
                <p style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);letter-spacing:1.5px;margin-top:3px;"><?= $macAccent['label'] ?></p>
            </div>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">

        <?php if ($user_role === 'team_moderator'): ?>
            <!-- ── TEAM MODERATOR VIEW (read-only) ── -->
            <?php if ($modTeamInfo): ?>
            <div style="display:flex;gap:16px;align-items:center;margin-bottom:16px;padding:10px 14px;background:rgba(0,212,255,.04);border:1px solid rgba(0,212,255,.12);border-radius:8px;font-family:'Share Tech Mono',monospace;font-size:11px;">
                <span style="color:var(--text-muted);">TEAM:</span>
                <span style="color:var(--neon-cyan);font-weight:700;"><?= htmlspecialchars($modTeamInfo['team_name']) ?></span>
                <span style="color:var(--text-muted);">DEPT:</span>
                <span style="color:var(--neon-cyan);"><?= htmlspecialchars($modTeamInfo['department']) ?></span>
                <span style="margin-left:auto;color:var(--neon-success);font-weight:700;"><?= count($modTeamMembers) ?> MEMBER<?= count($modTeamMembers) !== 1 ? 'S' : '' ?></span>
            </div>
            <?php endif; ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($modTeamMembers)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:1px;">
                        <?= $modTeamInfo ? 'NO PERSONNEL REGISTERED IN YOUR TEAM YET.' : 'YOU ARE NOT ASSIGNED TO A TEAM.' ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($modTeamMembers as $i => $m):
                        $sc = match($m['status']) {
                            'active'    => 'var(--neon-success)',
                            'pending'   => 'var(--neon-warning)',
                            'suspended' => 'var(--neon-error)',
                            'banned'    => 'var(--neon-error)',
                            default     => 'var(--text-muted)',
                        };
                    ?>
                    <tr>
                        <td style="color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= $i+1 ?></td>
                        <td style="color:var(--text-main);"><?= htmlspecialchars($m['full_name'] ?: '—') ?></td>
                        <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($m['username']) ?></td>
                        <td style="color:var(--text-muted);font-size:11px;"><?= htmlspecialchars($m['email'] ?: '—') ?></td>
                        <td><span style="color:<?= $sc ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= strtoupper($m['status']) ?></span></td>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);"><?= substr($m['created_at'],0,10) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p style="margin-top:12px;font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:.5px;">
                ℹ As Team Moderator you have read-only visibility of your team roster. Contact a Sub Admin or Super Admin for member management.
            </p>

        <?php else: ?>
            <!-- ── SUPER ADMIN / SUB ADMIN TABBED VIEW ── -->
            <!-- Tab bar -->
            <div style="display:flex;gap:4px;margin-bottom:18px;border-bottom:1px solid rgba(255,255,255,.08);padding-bottom:0;">
                <button class="mac-tab active" data-tab="mac-pending"
                    style="padding:8px 18px;font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;background:none;border:none;border-bottom:2px solid var(--neon-warning);color:var(--neon-warning);cursor:pointer;margin-bottom:-1px;">
                    PENDING
                    <?php if (count($pendingMembers) > 0): ?>
                    <span style="background:var(--neon-warning);color:#000;border-radius:10px;padding:1px 6px;font-size:9px;margin-left:5px;font-weight:700;"><?= count($pendingMembers) ?></span>
                    <?php endif; ?>
                </button>
                <button class="mac-tab" data-tab="mac-active"
                    style="padding:8px 18px;font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);cursor:pointer;margin-bottom:-1px;">
                    ACTIVE
                    <span style="background:rgba(0,255,136,.15);color:var(--neon-success);border-radius:10px;padding:1px 6px;font-size:9px;margin-left:5px;"><?= count($activeStaff) ?></span>
                </button>
                <?php if ($user_role === 'super_admin'): ?>
                <button class="mac-tab" data-tab="mac-restricted"
                    style="padding:8px 18px;font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);cursor:pointer;margin-bottom:-1px;">
                    SUSPENDED / BANNED
                    <?php if (count($restrictedStaff) > 0): ?>
                    <span style="background:rgba(255,45,85,.15);color:var(--neon-error);border-radius:10px;padding:1px 6px;font-size:9px;margin-left:5px;"><?= count($restrictedStaff) ?></span>
                    <?php endif; ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- TAB: Pending -->
            <div id="mac-pending" class="mac-tab-panel">
                <?php if (empty($pendingMembers)): ?>
                    <div style="text-align:center;padding:40px;font-family:'Share Tech Mono',monospace;color:var(--neon-success);font-size:11px;letter-spacing:1px;">
                        <i data-lucide="shield-check" style="width:28px;height:28px;display:block;margin:0 auto 10px;"></i>
                        ALL CLEARANCE REQUESTS PROCESSED — QUEUE CLEAR
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Requested Role</th>
                            <th>Requested</th>
                            <th>Assign Team</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingMembers as $member): ?>
                    <tr id="member-row-<?= $member['id'] ?>">
                        <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($member['username']) ?></td>
                        <td style="color:var(--text-main);"><?= htmlspecialchars($member['full_name']) ?></td>
                        <td>
                            <?php
                                $roleC = match($member['role']) {
                                    'sub_admin' => 'var(--neon-purple)',
                                    'team_moderator' => 'var(--neon-cyan)',
                                    default => 'var(--neon-warning)',
                                };
                                $roleL = match($member['role']) {
                                    'sub_admin' => 'SUB ADMIN',
                                    'team_moderator' => 'MODERATOR',
                                    default => 'USER',
                                };
                            ?>
                            <span style="color:<?= $roleC ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= $roleL ?></span>
                        </td>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);"><?= substr($member['created_at'],0,10) ?></td>
                        <td>
                            <select class="team-assign-select" style="background:var(--surface-2,#1a1a2e);color:var(--text-main);border:1px solid var(--border-color,#333);border-radius:4px;padding:4px 6px;font-size:10px;font-family:'Share Tech Mono',monospace;width:100%;min-width:150px;">
                                <option value="">— No team yet —</option>
                                <?php
                                $prevDept = '';
                                foreach ($allTeams as $t):
                                    if ($t['department'] !== $prevDept):
                                        if ($prevDept !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($t['department']) . '">';
                                        $prevDept = $t['department'];
                                    endif;
                                ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['team_name']) ?></option>
                                <?php endforeach; if ($prevDept !== '') echo '</optgroup>'; ?>
                            </select>
                        </td>
                        <td style="white-space:nowrap;">
                            <button class="action-btn" style="padding:4px 9px;font-size:10px;" onclick="approveMember(<?= $member['id'] ?>)">
                                <i data-lucide="check" style="width:11px;height:11px;"></i> Approve
                            </button>
                            <button class="action-btn" style="padding:4px 9px;font-size:10px;border-color:var(--neon-error);color:var(--neon-error);margin-left:3px;" onclick="rejectMember(<?= $member['id'] ?>)">
                                <i data-lucide="x" style="width:11px;height:11px;"></i> Reject
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- TAB: Active -->
            <div id="mac-active" class="mac-tab-panel" style="display:none;">
                <?php if (empty($activeStaff)): ?>
                    <div style="text-align:center;padding:40px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:11px;letter-spacing:1px;">NO ACTIVE STAFF MEMBERS FOUND.</div>
                <?php else: ?>
                <!-- Role filter pills -->
                <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
                    <button class="mac-role-filter active" data-filter="all" style="padding:4px 12px;font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:20px;color:var(--text-main);cursor:pointer;">ALL</button>
                    <?php if ($user_role === 'super_admin'): ?>
                    <button class="mac-role-filter" data-filter="sub_admin" style="padding:4px 12px;font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1px;background:rgba(168,85,247,.06);border:1px solid rgba(168,85,247,.2);border-radius:20px;color:var(--neon-purple);cursor:pointer;">SUB ADMIN</button>
                    <?php endif; ?>
                    <button class="mac-role-filter" data-filter="team_moderator" style="padding:4px 12px;font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1px;background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.2);border-radius:20px;color:var(--neon-cyan);cursor:pointer;">MODERATOR</button>
                    <button class="mac-role-filter" data-filter="user" style="padding:4px 12px;font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:20px;color:var(--neon-warning);cursor:pointer;">USER</button>
                </div>
                <table id="mac-active-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Team</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activeStaff as $s):
                        $rc = match($s['role']) { 'sub_admin' => 'var(--neon-purple)', 'team_moderator' => 'var(--neon-cyan)', default => 'var(--neon-warning)' };
                        $rl = match($s['role']) { 'sub_admin' => 'SUB ADMIN', 'team_moderator' => 'MODERATOR', default => 'USER' };
                        $teamLabel = $s['team_name'] ? htmlspecialchars($s['department'].' › '.$s['team_name']) : '—';
                    ?>
                    <tr id="mac-active-row-<?= $s['id'] ?>" data-role="<?= htmlspecialchars($s['role']) ?>">
                        <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($s['username']) ?></td>
                        <td style="color:var(--text-main);"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><span style="color:<?= $rc ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= $rl ?></span></td>
                        <td style="font-size:11px;color:var(--text-muted);"><?= $teamLabel ?></td>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);"><?= substr($s['created_at'],0,10) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($user_role === 'super_admin'): ?>
                                <!-- Role change (super admin only) -->
                                <div style="display:flex;gap:3px;align-items:center;margin-bottom:4px;">
                                    <select id="mac-role-<?= $s['id'] ?>" style="flex:1;background:rgba(168,85,247,.08);color:var(--text-main);border:1px solid rgba(168,85,247,.25);border-radius:4px;padding:3px 5px;font-size:10px;font-family:'Share Tech Mono',monospace;min-width:100px;">
                                        <option value="sub_admin" <?= $s['role']==='sub_admin'?'selected':'' ?>>Sub Admin</option>
                                        <option value="team_moderator" <?= $s['role']==='team_moderator'?'selected':'' ?>>Moderator</option>
                                        <option value="user" <?= $s['role']==='user'?'selected':'' ?>>User</option>
                                    </select>
                                    <button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-purple);color:var(--neon-purple);"
                                        onclick="changeRole(<?= $s['id'] ?>, document.getElementById('mac-role-<?= $s['id'] ?>'))">↑↓</button>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex;gap:3px;flex-wrap:wrap;">
                                <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-warning);color:var(--neon-warning);"
                                    onclick="suspendMember(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="pause-circle" style="width:10px;height:10px;"></i> Suspend
                                </button>
                                <?php if ($user_role === 'super_admin'): ?>
                                <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);"
                                    onclick="banMember(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="ban" style="width:10px;height:10px;"></i> Ban
                                </button>
                                <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);"
                                    onclick="removeStaff(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="trash-2" style="width:10px;height:10px;"></i> Remove
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php if ($user_role === 'super_admin'): ?>
            <!-- TAB: Restricted (Suspended / Banned) — Super Admin only -->
            <div id="mac-restricted" class="mac-tab-panel" style="display:none;">
                <?php if (empty($restrictedStaff)): ?>
                    <div style="text-align:center;padding:40px;font-family:'Share Tech Mono',monospace;color:var(--neon-success);font-size:11px;letter-spacing:1px;">
                        <i data-lucide="shield-check" style="width:28px;height:28px;display:block;margin:0 auto 10px;"></i>
                        NO RESTRICTED MEMBERS — ALL CLEAR
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Team</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($restrictedStaff as $s):
                        $rc = match($s['role']) { 'sub_admin' => 'var(--neon-purple)', 'team_moderator' => 'var(--neon-cyan)', default => 'var(--neon-warning)' };
                        $rl = match($s['role']) { 'sub_admin' => 'SUB ADMIN', 'team_moderator' => 'MODERATOR', default => 'USER' };
                        $statusC = $s['status'] === 'banned' ? 'var(--neon-error)' : 'var(--neon-warning)';
                        $teamLabel = $s['team_name'] ? htmlspecialchars($s['department'].' › '.$s['team_name']) : '—';
                    ?>
                    <tr id="mac-restr-row-<?= $s['id'] ?>">
                        <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($s['username']) ?></td>
                        <td style="color:var(--text-main);"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><span style="color:<?= $rc ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= $rl ?></span></td>
                        <td><span style="color:<?= $statusC ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= strtoupper($s['status']) ?></span></td>
                        <td style="font-size:11px;color:var(--text-muted);"><?= $teamLabel ?></td>
                        <td style="white-space:nowrap;">
                            <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-success);color:var(--neon-success);"
                                onclick="restoreMember(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                <i data-lucide="refresh-cw" style="width:10px;height:10px;"></i> Restore
                            </button>
                            <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);margin-left:3px;"
                                onclick="removeStaff(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                <i data-lucide="trash-2" style="width:10px;height:10px;"></i> Remove
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <p style="margin-top:14px;font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:.5px;">
                <?php if ($user_role === 'super_admin'): ?>
                ⚠ Super Admin: Full access — approve, suspend, ban, change roles, and permanently remove members.
                <?php else: ?>
                ⚠ Sub Admin: Can approve/reject pending requests and suspend active users. Role changes and permanent removal require Super Admin clearance.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        </div>
    </div>
</div>
<?php endif; ?>

<!-- 4b. Staff Management -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
<div id="modal-staff-management" class="modal-overlay">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-error);text-shadow:0 0 10px rgba(255,45,85,.4);">
                <i data-lucide="shield-minus" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Staff Management
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <!-- Filter tabs -->
            <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
                <button class="staff-tab action-btn active" data-filter="all" style="padding:5px 14px;font-size:10px;">All Staff</button>
                <button class="staff-tab action-btn" data-filter="sub_admin" style="padding:5px 14px;font-size:10px;border-color:var(--neon-purple);color:var(--neon-purple);">Sub Admins</button>
                <button class="staff-tab action-btn" data-filter="team_moderator" style="padding:5px 14px;font-size:10px;border-color:var(--neon-cyan);color:var(--neon-cyan);">Team Moderators</button>
                <button class="staff-tab action-btn" data-filter="user" style="padding:5px 14px;font-size:10px;border-color:var(--neon-warning);color:var(--neon-warning);">Users</button>
            </div>
            <table id="staff-table">
                <thead>
                    <tr>
                        <th>Login ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Team Assignment</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($allStaff)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">No staff members found.</td></tr>
                <?php else: ?>
                    <?php foreach ($allStaff as $s): ?>
                    <?php
                        $roleColor = match($s['role']) {
                            'sub_admin'       => 'var(--neon-purple)',
                            'team_moderator'  => 'var(--neon-cyan)',
                            default           => 'var(--neon-warning)',
                        };
                        $roleLabel = match($s['role']) {
                            'sub_admin'       => 'SUB ADMIN',
                            'team_moderator'  => 'TEAM MOD',
                            default           => 'USER',
                        };
                        $statusColor = match($s['status']) {
                            'active'    => 'var(--neon-success)',
                            'pending'   => 'var(--neon-warning)',
                            'suspended' => 'var(--neon-error)',
                            'banned'    => 'var(--neon-error)',
                            default     => 'var(--text-muted)',
                        };
                        $currentTeamLabel = ($s['team_name']) ? htmlspecialchars($s['department'] . ' › ' . $s['team_name']) : '—';
                    ?>
                    <tr id="staff-row-<?= $s['id'] ?>" data-role="<?= htmlspecialchars($s['role']) ?>">
                        <td style="color:var(--text-main);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($s['username']) ?></td>
                        <td style="color:var(--text-main);"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td style="color:var(--text-muted);font-size:11px;"><?= htmlspecialchars($s['email']) ?></td>
                        <td><span style="color:<?= $roleColor ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= $roleLabel ?></span></td>
                        <td><span style="color:<?= $statusColor ?>;font-family:'Share Tech Mono',monospace;font-size:10px;"><?= strtoupper($s['status']) ?></span></td>
                        <td style="min-width:200px;">
                            <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                                <select id="team-sel-<?= $s['id'] ?>" style="background:var(--surface-2,#1a1a2e);color:var(--text-main);border:1px solid var(--border-color,#333);border-radius:4px;padding:3px 5px;font-size:10px;font-family:'Share Tech Mono',monospace;flex:1;min-width:130px;">
                                    <option value="">— No team —</option>
                                    <?php
                                    $prevDept2 = '';
                                    foreach ($allTeams as $t2):
                                        if ($t2['department'] !== $prevDept2):
                                            if ($prevDept2 !== '') echo '</optgroup>';
                                            echo '<optgroup label="' . htmlspecialchars($t2['department']) . '">';
                                            $prevDept2 = $t2['department'];
                                        endif;
                                    ?>
                                    <option value="<?= $t2['id'] ?>" <?= ($s['team_id'] == $t2['id']) ? 'selected' : '' ?>><?= htmlspecialchars($t2['team_name']) ?></option>
                                    <?php endforeach; if ($prevDept2 !== '') echo '</optgroup>'; ?>
                                </select>
                                <button class="action-btn" style="padding:3px 8px;font-size:10px;border-color:var(--neon-cyan);color:var(--neon-cyan);white-space:nowrap;"
                                    onclick="assignTeam(<?= $s['id'] ?>, document.getElementById('team-sel-<?= $s['id'] ?>'))">
                                    <i data-lucide="check" style="width:10px;height:10px;"></i> Save
                                </button>
                            </div>
                        </td>
                        <td>
                            <?php if ($user_role === 'super_admin'): ?>
                            <div style="display:flex;gap:3px;align-items:center;margin-bottom:4px;">
                                <select id="role-sel-<?= $s['id'] ?>" style="flex:1;background:rgba(168,85,247,.08);color:var(--text-main);border:1px solid rgba(168,85,247,.25);border-radius:4px;padding:3px 5px;font-size:10px;font-family:'Share Tech Mono',monospace;">
                                    <option value="sub_admin" <?= $s['role']==='sub_admin'?'selected':'' ?>>Sub Admin</option>
                                    <option value="team_moderator" <?= $s['role']==='team_moderator'?'selected':'' ?>>Team Mod</option>
                                    <option value="user" <?= $s['role']==='user'?'selected':'' ?>>User</option>
                                </select>
                                <button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-purple);color:var(--neon-purple);white-space:nowrap;"
                                    onclick="changeRole(<?= $s['id'] ?>, document.getElementById('role-sel-<?= $s['id'] ?>'))">
                                    ↑↓ Promote
                                </button>
                            </div>
                            <?php if (in_array($s['role'], ['sub_admin','team_moderator'])): ?>
                            <div style="margin-bottom:4px;">
                                <button class="action-btn" style="width:100%;padding:3px 7px;font-size:9px;border-color:var(--neon-cyan);color:var(--neon-cyan);"
                                    onclick="resendCredentials(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>', '<?= htmlspecialchars($s['email'],ENT_QUOTES) ?>')">
                                    <i data-lucide="send" style="width:9px;height:9px;"></i> Resend Credentials
                                </button>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="staff-action-btns" style="display:flex;gap:3px;flex-wrap:wrap;">
                                <?php if ($s['status'] === 'active'): ?>
                                <button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-warning);color:var(--neon-warning);"
                                    onclick="suspendMember(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="pause-circle" style="width:10px;height:10px;"></i> Suspend
                                </button>
                                <button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);"
                                    onclick="banMember(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="ban" style="width:10px;height:10px;"></i> Ban
                                </button>
                                <?php elseif (in_array($s['status'], ['suspended','banned'])): ?>
                                <button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-success);color:var(--neon-success);"
                                    onclick="restoreMember(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="refresh-cw" style="width:10px;height:10px;"></i> Restore
                                </button>
                                <?php endif; ?>
                                <button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);"
                                    onclick="removeStaff(<?= $s['id'] ?>, '<?= htmlspecialchars($s['username'],ENT_QUOTES) ?>')">
                                    <i data-lucide="trash-2" style="width:10px;height:10px;"></i> Remove
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p style="margin-top:14px;font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:.5px;">
                ⚠ Removing a staff member permanently deletes their account and all associated data.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 4c. Teams Directory -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
<div id="modal-teams-directory" class="modal-overlay">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-cyan);text-shadow:0 0 10px rgba(0,212,255,.4);">
                <i data-lucide="layout-list" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Teams Directory
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <?php
            $deptGroups = [];
            foreach ($teamsDirectory as $team) {
                $deptGroups[$team['department']][] = $team;
            }
            $deptColors = [
                'Pre-Production'  => 'var(--neon-purple)',
                '3D Production'   => 'var(--neon-cyan)',
                'Post-Production' => 'var(--neon-success)',
                'Live Acting'     => 'var(--neon-warning)',
                'Cybersecurity'   => 'var(--neon-error)',
            ];
            if (empty($deptGroups)):
            ?>
                <p style="text-align:center;color:var(--text-muted);font-family:'Share Tech Mono',monospace;padding:30px 0;">No teams found in the system.</p>
            <?php else: ?>
            <?php foreach ($deptGroups as $dept => $teams):
                $deptColor = $deptColors[$dept] ?? 'var(--neon-cyan)';
            ?>
            <div style="margin-bottom:22px;">
                <div style="color:<?= $deptColor ?>;font-family:'Share Tech Mono',monospace;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;padding:6px 0;border-bottom:1px solid <?= $deptColor ?>;margin-bottom:10px;opacity:.9;">
                    <i data-lucide="folder-open" style="width:12px;height:12px;vertical-align:middle;margin-right:6px;"></i>
                    <?= htmlspecialchars($dept) ?>
                    <span style="float:right;opacity:.6;font-size:10px;"><?= count($teams) ?> team<?= count($teams) !== 1 ? 's' : '' ?></span>
                </div>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">
                            <th style="text-align:left;padding:4px 8px;">Team Name</th>
                            <th style="text-align:center;padding:4px 8px;">Members</th>
                            <th style="text-align:left;padding:4px 8px;">Allowed Files</th>
                            <?php if ($user_role === 'super_admin'): ?><th style="text-align:center;padding:4px 8px;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teams as $team): ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,.04);">
                        <td style="padding:6px 8px;color:var(--text-main);font-size:12px;"><?= htmlspecialchars($team['team_name']) ?></td>
                        <td style="padding:6px 8px;text-align:center;">
                            <span style="color:<?= $team['member_count'] > 0 ? 'var(--neon-success)' : 'var(--text-muted)' ?>;font-family:'Share Tech Mono',monospace;font-size:11px;font-weight:700;">
                                <?= $team['member_count'] ?>
                            </span>
                        </td>
                        <td style="padding:6px 8px;color:var(--text-muted);font-size:10px;font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($team['allowed_extensions']) ?></td>
                        <?php if ($user_role === 'super_admin'): ?>
                        <td style="padding:6px 8px;text-align:center;white-space:nowrap;">
                            <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-cyan);color:var(--neon-cyan);"
                                onclick="openEditTeam(<?= $team['id'] ?>,'<?= htmlspecialchars($team['team_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($team['department'],ENT_QUOTES) ?>','<?= htmlspecialchars($team['allowed_extensions'],ENT_QUOTES) ?>')">
                                <i data-lucide="edit-2" style="width:9px;height:9px;"></i> Edit
                            </button>
                            <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);margin-left:3px;"
                                onclick="deleteTeam(<?= $team['id'] ?>,'<?= htmlspecialchars($team['team_name'],ENT_QUOTES) ?>')">
                                <i data-lucide="trash-2" style="width:9px;height:9px;"></i> Delete
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 5. Create Production -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
<div id="modal-create-production" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="clapperboard" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Initialize New Production</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="prod-alert" class="alert"></div>
            <form id="form-create-production">
                <div class="input-group">
                    <label>Production Codename</label>
                    <input type="text" name="name" required placeholder="e.g. PROJECT HORIZON">
                </div>
                <div class="input-group">
                    <label>Synopsis / Description</label>
                    <textarea name="description" rows="3" placeholder="Brief description of the production..."></textarea>
                </div>
                <button type="submit" class="action-btn">
                    <i data-lucide="rocket" style="width:14px;height:14px;"></i>
                    Initialize Production
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 6. Create Team -->
<?php if ($user_role === 'super_admin'): ?>
<div id="modal-create-team" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="color:var(--neon-warning);text-shadow:0 0 10px rgba(245,158,11,.4);">
                <i data-lucide="users" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Establish New Team
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="team-alert" class="alert"></div>
            <form id="form-create-team">
                <div class="input-group">
                    <label>Department</label>
                    <select name="department" required>
                        <option value="" disabled selected>Select department...</option>
                        <option value="Pre-Production">Pre-Production</option>
                        <option value="3D Production">3D Production</option>
                        <option value="Live Acting">Live Acting</option>
                        <option value="Post-Production">Post-Production</option>
                        <option value="Audio & Sound">Audio &amp; Sound</option>
                        <option value="Cybersecurity">Cybersecurity</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Team Name</label>
                    <input type="text" name="team_name" required placeholder="e.g. Advanced VFX Unit">
                </div>
                <div class="input-group">
                    <label>Allowed File Extensions (comma-separated)</label>
                    <input type="text" name="allowed_extensions" required placeholder="PNG,BLEND,MP4,FBX">
                </div>
                <button type="submit" class="action-btn" style="border-color:var(--neon-warning);color:var(--neon-warning);">
                    <i data-lucide="plus-circle" style="width:14px;height:14px;"></i>
                    Establish Team
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 7. Login/Logout Activity Modal -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
<div id="modal-activity-log" class="modal-overlay">
    <div class="modal-content" style="max-width:800px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-success);text-shadow:0 0 10px rgba(0,255,136,.4);">
                <i data-lucide="activity" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Login &amp; Logout Activity Log
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div style="display:flex;gap:16px;margin-bottom:16px;font-family:'Share Tech Mono',monospace;font-size:11px;">
                <span style="color:var(--neon-success);">▶ LOGIN</span>
                <span style="color:var(--neon-error);">◀ LOGOUT</span>
                <span style="color:var(--neon-warning);">[ADMIN]</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Username</th>
                        <th>Event</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($activityLogs)): ?>
                    <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">No login or logout events recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($activityLogs as $log):
                        $isLogin  = in_array($log['action_type'], ['user_login', 'admin_login']);
                        $isLogout = $log['action_type'] === 'user_logout';
                        $evtColor = $isLogin ? 'var(--neon-success)' : ($isLogout ? 'var(--neon-error)' : 'var(--neon-warning)');
                        $evtLabel = $isLogin ? '▶ LOGIN' : ($isLogout ? '◀ LOGOUT' : strtoupper($log['action_type']));
                        $isAdmin  = $log['action_type'] === 'admin_login';
                    ?>
                    <tr>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;">
                            <?= htmlspecialchars($log['username']) ?>
                            <?php if ($isAdmin): ?><span style="color:var(--neon-warning);font-size:9px;margin-left:4px;">[ADMIN]</span><?php endif; ?>
                        </td>
                        <td><span style="color:<?= $evtColor ?>;font-family:'Share Tech Mono',monospace;font-weight:bold;"><?= $evtLabel ?></span></td>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 8. Cybersecurity Logs -->
<?php if ($user_role === 'super_admin'): ?>
<div id="modal-cybersecurity" class="modal-overlay">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h2><i data-lucide="shield-alert" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Cybersecurity Event Logs</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Username</th>
                        <th>Action Type</th>
                        <th>IP Address</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($securityLogs)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">No security events recorded. System nominal.</td></tr>
                <?php else: ?>
                    <?php foreach ($securityLogs as $log):
                        $atype = $log['action_type'];
                        $acolor = match(true) {
                            in_array($atype, ['user_login','admin_login'])   => 'var(--neon-success)',
                            in_array($atype, ['user_logout'])                => 'var(--neon-error)',
                            in_array($atype, ['password_reset_request','password_reset_complete']) => 'var(--neon-purple)',
                            in_array($atype, ['admin_created','approve_member']) => 'var(--neon-cyan)',
                            default => 'var(--neon-warning)',
                        };
                    ?>
                    <tr>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($log['username']) ?></td>
                        <td style="color:<?= $acolor ?>;font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($atype) ?></td>
                        <td style="font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 8a. Department Members Panel (Super Admin, Sub Admin, Team Moderator) -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
<div id="modal-dept-members" class="modal-overlay">
    <div class="modal-content" style="max-width:820px;">
        <div class="modal-header" id="dept-modal-header">
            <h2 id="dept-modal-title" style="color:var(--neon-cyan);text-shadow:0 0 10px rgba(0,212,255,.4);">
                <i data-lucide="users" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Department Team
            </h2>
            <div style="display:flex;align-items:center;gap:8px;">
                <button id="dept-add-btn" title="Add member to team"
                    style="display:none;align-items:center;gap:5px;font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;background:none;border:1px solid rgba(0,240,255,.35);color:var(--neon-cyan);border-radius:5px;padding:5px 12px;cursor:pointer;text-transform:uppercase;">
                    <i data-lucide="user-plus" style="width:12px;height:12px;"></i> Add Member
                </button>
                <button class="close-modal"><i data-lucide="x"></i></button>
            </div>
        </div>

        <!-- Inline Add-Member Form (hidden until "Add Member" clicked) -->
        <div id="dept-add-form" style="display:none;padding:16px 20px;background:rgba(0,240,255,.04);border-bottom:1px solid rgba(0,240,255,.12);">
            <p style="font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:2px;color:var(--neon-cyan);margin:0 0 14px;text-transform:uppercase;">
                <i data-lucide="user-plus" style="width:11px;height:11px;vertical-align:middle;margin-right:4px;"></i>
                Register New Team Member
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end;">

                <!-- Avatar preview + upload -->
                <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
                    <div id="dept-avatar-preview" style="width:64px;height:64px;border-radius:50%;border:2px solid rgba(0,240,255,.3);background:rgba(0,240,255,.06);display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;" onclick="document.getElementById('dept-img-input').click()">
                        <i data-lucide="camera" style="width:20px;height:20px;color:var(--text-muted);"></i>
                    </div>
                    <input type="file" id="dept-img-input" accept="image/*" style="display:none;">
                    <label style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;cursor:pointer;" onclick="document.getElementById('dept-img-input').click()">Photo</label>
                </div>

                <!-- Full Name -->
                <div>
                    <label style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);display:block;margin-bottom:5px;text-transform:uppercase;">Full Name *</label>
                    <input type="text" id="dept-add-name" placeholder="e.g. Alex Mercer"
                        style="width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:8px 10px;color:var(--text-main);font-size:12px;font-family:'Share Tech Mono',monospace;outline:none;box-sizing:border-box;">
                </div>

                <!-- Email -->
                <div>
                    <label style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);display:block;margin-bottom:5px;text-transform:uppercase;">Email *</label>
                    <input type="email" id="dept-add-email" placeholder="member@studio.com"
                        style="width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:8px 10px;color:var(--text-main);font-size:12px;font-family:'Share Tech Mono',monospace;outline:none;box-sizing:border-box;">
                </div>

                <!-- Age -->
                <div>
                    <label style="font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--text-muted);display:block;margin-bottom:5px;text-transform:uppercase;">Age</label>
                    <input type="number" id="dept-add-age" placeholder="e.g. 24" min="10" max="100"
                        style="width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:8px 10px;color:var(--text-main);font-size:12px;font-family:'Share Tech Mono',monospace;outline:none;box-sizing:border-box;">
                </div>

                <!-- Actions -->
                <div style="display:flex;gap:8px;align-self:flex-end;">
                    <button id="dept-add-confirm" class="action-btn primary" style="padding:8px 18px;font-size:11px;white-space:nowrap;">
                        <i data-lucide="user-plus" style="width:11px;height:11px;"></i> Add
                    </button>
                    <button id="dept-add-cancel" class="action-btn" style="padding:8px 13px;font-size:11px;">
                        <i data-lucide="x" style="width:11px;height:11px;"></i>
                    </button>
                </div>

            </div>
            <div id="dept-add-msg" style="display:none;margin-top:12px;font-family:'Share Tech Mono',monospace;font-size:11px;padding:8px 12px;border-radius:5px;"></div>
            <!-- Credentials reveal after success -->
            <div id="dept-creds-box" style="display:none;margin-top:12px;background:rgba(0,255,136,.06);border:1px solid rgba(0,255,136,.2);border-radius:6px;padding:12px 14px;">
                <p style="margin:0 0 6px;font-family:'Share Tech Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--neon-success);text-transform:uppercase;">Member Created — Save These Credentials</p>
                <p style="margin:0;font-family:'Share Tech Mono',monospace;font-size:12px;color:var(--text-main);">Login ID: <strong id="dept-cred-user" style="color:var(--neon-cyan);"></strong> &nbsp;|&nbsp; Temp Password: <strong id="dept-cred-pass" style="color:var(--neon-warning);"></strong></p>
            </div>
        </div>

        <div class="modal-body">
            <!-- Team info strip -->
            <div id="dept-team-info" style="display:none;margin-bottom:18px;padding:12px 14px;background:rgba(0,240,255,.04);border:1px solid rgba(0,240,255,.12);border-radius:8px;">
                <div style="display:flex;gap:20px;flex-wrap:wrap;font-family:'Share Tech Mono',monospace;font-size:11px;">
                    <span>DEPARTMENT: <span id="dept-info-dept" style="color:var(--neon-cyan);font-weight:700;"></span></span>
                    <span>TEAM: <span id="dept-info-team" style="color:var(--neon-cyan);font-weight:700;"></span></span>
                    <span>FILES: <span id="dept-info-ext" style="color:var(--neon-warning);"></span></span>
                    <span style="margin-left:auto;">MEMBERS: <span id="dept-info-count" style="color:var(--neon-success);font-weight:700;"></span></span>
                </div>
            </div>

            <!-- Loading / Error states -->
            <div id="dept-loading" style="text-align:center;padding:40px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:12px;letter-spacing:1px;">
                <i data-lucide="loader" style="width:20px;height:20px;display:block;margin:0 auto 10px;animation:spin 1s linear infinite;color:var(--neon-cyan);"></i>
                QUERYING PERSONNEL DATABASE...
            </div>
            <div id="dept-error" style="display:none;text-align:center;padding:30px;color:var(--neon-error);font-family:'Share Tech Mono',monospace;font-size:12px;letter-spacing:1px;"></div>

            <!-- Members table -->
            <div id="dept-table-wrap" style="display:none;overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Photo</th>
                            <th>Full Name</th>
                            <th>Age</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="dept-members-tbody"></tbody>
                </table>
                <p id="dept-no-members" style="display:none;text-align:center;padding:30px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:11px;letter-spacing:1px;">
                    NO REGISTERED PERSONNEL FOUND FOR THIS TEAM.
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 8b. Full Activity Log (Super Admin only) -->
<?php if ($user_role === 'super_admin'): ?>
<div id="modal-full-activity-log" class="modal-overlay">
    <div class="modal-content" style="max-width:1000px;">
        <div class="modal-header" style="border-bottom-color:rgba(255,50,50,.25);">
            <h2 style="color:var(--neon-error);text-shadow:0 0 12px rgba(255,50,50,.4);">
                <i data-lucide="scroll-text" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Full Security Activity Log
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">

            <!-- Stats Bar -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
                <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px 14px;text-align:center;">
                    <div style="font-family:'Share Tech Mono',monospace;font-size:22px;font-weight:700;color:var(--text-main);"><?= number_format($activityStats['total']) ?></div>
                    <div style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);letter-spacing:1.5px;margin-top:3px;">TOTAL EVENTS</div>
                </div>
                <div style="background:rgba(0,255,136,.04);border:1px solid rgba(0,255,136,.15);border-radius:8px;padding:12px 14px;text-align:center;">
                    <div style="font-family:'Share Tech Mono',monospace;font-size:22px;font-weight:700;color:var(--neon-success);"><?= number_format($activityStats['logins']) ?></div>
                    <div style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);letter-spacing:1.5px;margin-top:3px;">LOGINS</div>
                </div>
                <div style="background:rgba(255,50,50,.04);border:1px solid rgba(255,50,50,.15);border-radius:8px;padding:12px 14px;text-align:center;">
                    <div style="font-family:'Share Tech Mono',monospace;font-size:22px;font-weight:700;color:var(--neon-error);"><?= number_format($activityStats['logouts']) ?></div>
                    <div style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);letter-spacing:1.5px;margin-top:3px;">LOGOUTS</div>
                </div>
                <div style="background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.15);border-radius:8px;padding:12px 14px;text-align:center;">
                    <div style="font-family:'Share Tech Mono',monospace;font-size:22px;font-weight:700;color:var(--neon-warning);"><?= number_format($activityStats['other']) ?></div>
                    <div style="font-family:'Share Tech Mono',monospace;font-size:9px;color:var(--text-muted);letter-spacing:1.5px;margin-top:3px;">OTHER ACTIONS</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="flex:1;min-width:160px;position:relative;">
                    <i data-lucide="search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;height:13px;color:var(--text-muted);pointer-events:none;"></i>
                    <input type="text" id="al-search-user" placeholder="Search by username…"
                        style="width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:8px 10px 8px 32px;color:var(--text-main);font-size:12px;font-family:'Share Tech Mono',monospace;outline:none;box-sizing:border-box;">
                </div>
                <div style="flex:1;min-width:160px;">
                    <select id="al-filter-action"
                        style="width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:8px 10px;color:var(--text-main);font-size:12px;font-family:'Share Tech Mono',monospace;outline:none;box-sizing:border-box;cursor:pointer;">
                        <option value="">All Action Types</option>
                        <?php foreach ($activityActionTypes as $atype): ?>
                        <option value="<?= htmlspecialchars($atype) ?>"><?= htmlspecialchars(strtoupper(str_replace('_',' ',$atype))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1;min-width:120px;">
                    <select id="al-filter-role"
                        style="width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:8px 10px;color:var(--text-main);font-size:12px;font-family:'Share Tech Mono',monospace;outline:none;box-sizing:border-box;cursor:pointer;">
                        <option value="">All Roles</option>
                        <option value="super_admin">Super Admin</option>
                        <option value="sub_admin">Sub Admin</option>
                        <option value="team_moderator">Moderator</option>
                        <option value="user">User</option>
                        <option value="">System</option>
                    </select>
                </div>
                <button id="al-reset-filters" class="action-btn" style="padding:8px 14px;font-size:11px;white-space:nowrap;">
                    <i data-lucide="rotate-ccw" style="width:12px;height:12px;"></i> Reset
                </button>
                <button id="al-export-csv" class="action-btn" style="padding:8px 14px;font-size:11px;white-space:nowrap;border-color:var(--neon-success);color:var(--neon-success);">
                    <i data-lucide="download" style="width:12px;height:12px;"></i> Export CSV
                </button>
            </div>

            <!-- Match Count -->
            <div style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);margin-bottom:8px;letter-spacing:.5px;">
                Showing <span id="al-match-count"><?= count($fullActivityLogs) ?></span> of <?= count($fullActivityLogs) ?> records (latest 500)
            </div>

            <!-- Table -->
            <div style="overflow-x:auto;max-height:420px;overflow-y:auto;">
                <table id="al-table" style="min-width:760px;">
                    <thead style="position:sticky;top:0;z-index:2;">
                        <tr>
                            <th>Timestamp</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="al-tbody">
                    <?php if (empty($fullActivityLogs)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">No security events recorded. System nominal.</td></tr>
                    <?php else: ?>
                        <?php foreach ($fullActivityLogs as $log):
                            $atype  = $log['action_type'];
                            $acolor = match(true) {
                                in_array($atype, ['user_login','admin_login'])                           => 'var(--neon-success)',
                                $atype === 'user_logout'                                                  => 'var(--neon-error)',
                                in_array($atype, ['password_reset_request','password_reset_complete'])    => 'var(--neon-purple)',
                                in_array($atype, ['admin_created','approve_member','member_approved'])    => 'var(--neon-cyan)',
                                in_array($atype, ['ban_member','suspend_member','delete_user'])           => 'var(--neon-error)',
                                default                                                                   => 'var(--neon-warning)',
                            };
                            $roleLabel = match($log['user_role']) {
                                'super_admin'    => 'SUPER ADMIN',
                                'sub_admin'      => 'SUB ADMIN',
                                'team_moderator' => 'MODERATOR',
                                'user'           => 'USER',
                                default          => $log['user_role'] ? strtoupper($log['user_role']) : 'SYSTEM',
                            };
                            $roleColor = match($log['user_role']) {
                                'super_admin'    => 'var(--neon-error)',
                                'sub_admin'      => 'var(--neon-purple)',
                                'team_moderator' => 'var(--neon-cyan)',
                                'user'           => 'var(--neon-warning)',
                                default          => 'var(--text-muted)',
                            };
                        ?>
                        <tr class="al-row"
                            data-username="<?= strtolower(htmlspecialchars($log['username'])) ?>"
                            data-action="<?= htmlspecialchars($atype) ?>"
                            data-role="<?= htmlspecialchars($log['user_role']) ?>">
                            <td style="font-family:'Share Tech Mono',monospace;font-size:10.5px;white-space:nowrap;"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;">
                                <?= htmlspecialchars($log['username']) ?>
                                <?php if (!empty($log['full_name'])): ?>
                                <div style="color:var(--text-muted);font-size:9px;margin-top:1px;"><?= htmlspecialchars($log['full_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color:<?= $roleColor ?>;font-family:'Share Tech Mono',monospace;font-size:9.5px;font-weight:700;letter-spacing:.5px;"><?= $roleLabel ?></span>
                            </td>
                            <td>
                                <span style="color:<?= $acolor ?>;font-family:'Share Tech Mono',monospace;font-size:10.5px;font-weight:600;"><?= htmlspecialchars(strtoupper(str_replace('_',' ',$atype))) ?></span>
                            </td>
                            <td style="font-family:'Share Tech Mono',monospace;font-size:10.5px;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                            <td style="font-size:11px;color:var(--text-muted);max-width:220px;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p style="margin-top:12px;font-size:9.5px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:.5px;">
                ⚡ Showing up to the most recent 500 events. All times are server-local. Filters apply client-side.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 8c. Create Admin Account -->
<?php if ($user_role === 'super_admin'): ?>
<div id="modal-create-admin" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="color:var(--neon-purple);text-shadow:0 0 10px rgba(168,85,247,.4);">
                <i data-lucide="user-cog" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Provision Management Account
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="admin-create-alert" class="alert"></div>
            <form id="form-create-admin">
                <input type="hidden" name="action" value="create_admin_account">
                <div class="input-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="Full legal name">
                </div>
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="admin@example.com">
                </div>
                <div class="input-group">
                    <label>Clearance Role</label>
                    <select name="new_role" required>
                        <option value="" disabled selected>Assign clearance...</option>
                        <option value="sub_admin">Sub Admin (Level 4)</option>
                        <option value="team_moderator">Team Moderator (Level 3)</option>
                    </select>
                </div>
                <button type="submit" class="action-btn" style="border-color:var(--neon-purple);color:var(--neon-purple);">
                    <i data-lucide="key" style="width:14px;height:14px;"></i>
                    Generate Credentials
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     SCRIPTS
══════════════════════════════════════════════════ -->
<script>
lucide.createIcons();

// ── Safe JSON parser (strips PHP warnings) ────────
function safeJSON(text) {
    const s = text.trim();
    const i = s.indexOf('{');
    if (i > 0) {
        try { return JSON.parse(s.slice(i)); } catch {}
    }
    try { return JSON.parse(s); } catch {}
    return { status: 'error', message: s || 'Unknown server error.' };
}

// ── Sidebar dropdown toggles ──────────────────────
document.querySelectorAll('.dropdown-toggle').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        btn.closest('.menu-dropdown').classList.toggle('active');
        lucide.createIcons();
    });
});

// ── Modal map ─────────────────────────────────────
const modalMap = {
    'btn-work-update':    'modal-work-update',
    'btn-work-update-2':  'modal-work-update',
    'btn-assigned-tasks':   'modal-assigned-tasks',
    'btn-my-submissions':   'modal-my-submissions',
    'btn-team-collab':    'modal-team-collab',
    'btn-member-control': 'modal-member-control',
    'btn-new-prod':       'modal-create-production',
    'btn-new-prod-2':     'modal-create-production',
    'btn-new-team':       'modal-create-team',
    'btn-full-activity-log': 'modal-full-activity-log',
    'btn-cybersecurity':  'modal-cybersecurity',
    'btn-create-admin':   'modal-create-admin',
    'btn-activity-log':     'modal-activity-log',
    'btn-staff-management':    'modal-staff-management',
    'btn-teams-directory':     'modal-teams-directory',
    'btn-contact-us':         'modal-contact-us',
    'btn-send-notification':  'modal-send-notification',
    'btn-contact-messages':   'modal-contact-messages',
    'btn-production-mgmt':    'modal-production-mgmt',
    'btn-work-review':        'modal-work-review',
};

Object.entries(modalMap).forEach(([btnId, modalId]) => {
    const btn = document.getElementById(btnId);
    if (btn) btn.addEventListener('click', e => {
        e.preventDefault();
        const modal = document.getElementById(modalId);
        if (modal) modal.classList.add('active');
    });
});

// Close buttons
document.querySelectorAll('.close-modal').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('.modal-overlay').classList.remove('active'));
});

// Click outside to close
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('active'); });
});

// ── Member Access Control tabs + role filter ──────
(function() {
    // Tab switching
    document.querySelectorAll('.mac-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            // Deactivate all tabs
            document.querySelectorAll('.mac-tab').forEach(t => {
                t.style.borderBottomColor = 'transparent';
                t.style.color = 'var(--text-muted)';
                t.classList.remove('active');
            });
            // Activate clicked tab
            tab.classList.add('active');
            tab.style.color = 'var(--neon-warning)';
            tab.style.borderBottomColor = 'var(--neon-warning)';
            // Show corresponding panel
            document.querySelectorAll('.mac-tab-panel').forEach(p => p.style.display = 'none');
            const target = document.getElementById(tab.dataset.tab);
            if (target) target.style.display = '';
            lucide.createIcons();
        });
    });

    // Role filter pills on Active tab
    document.querySelectorAll('.mac-role-filter').forEach(pill => {
        pill.addEventListener('click', () => {
            document.querySelectorAll('.mac-role-filter').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            const filter = pill.dataset.filter;
            document.querySelectorAll('#mac-active-table tbody tr').forEach(row => {
                row.style.display = (filter === 'all' || row.dataset.role === filter) ? '' : 'none';
            });
        });
    });
})();

// ── Live System Monitor widget ─────────────────────
<?php if ($user_role === 'super_admin'): ?>
(function () {
    const els = {
        online:  document.getElementById('lsw-online'),
        pending: document.getElementById('lsw-pending'),
        activity:document.getElementById('lsw-activity'),
        work:    document.getElementById('lsw-work'),
        members: document.getElementById('lsw-members'),
        newToday:document.getElementById('lsw-new'),
        feed:    document.getElementById('lsw-feed'),
        updated: document.getElementById('lsw-last-update'),
        countdown:document.getElementById('lsw-countdown'),
        pulse:   document.getElementById('lsw-pulse'),
        btn:     document.getElementById('lsw-refresh-btn'),
    };

    const INTERVAL = 30;
    let timer = INTERVAL;
    let cdInterval = null;
    let fetchInFlight = false;

    const feedColors = {
        login:          'var(--neon-success)',
        logout:         'var(--text-muted)',
        admin_created:  'var(--neon-purple)',
        role_changed:   'var(--neon-warning)',
        member_approved:'var(--neon-success)',
        member_banned:  'var(--neon-error)',
        member_suspended:'var(--neon-error)',
        member_restored:'var(--neon-success)',
        work_submitted: 'var(--neon-cyan)',
    };

    function fmt(type) {
        return type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function setPulse(ok) {
        if (!els.pulse) return;
        els.pulse.style.background = ok ? 'var(--neon-success)' : 'var(--neon-error)';
        els.pulse.style.boxShadow  = ok ? '0 0 6px var(--neon-success)' : '0 0 6px var(--neon-error)';
    }

    function numFlash(el, val) {
        if (!el) return;
        el.style.transition = 'opacity .15s';
        el.style.opacity = '0';
        setTimeout(() => {
            el.textContent = val;
            el.style.opacity = '1';
        }, 150);
    }

    window.lswFetch = function () {
        if (fetchInFlight) return;
        fetchInFlight = true;
        if (els.btn) els.btn.disabled = true;
        timer = INTERVAL;
        if (els.countdown) els.countdown.textContent = INTERVAL;

        fetch('api/admin/live_stats.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'ok') throw new Error(d.message || 'Error');

                numFlash(els.online,   d.online_now);
                numFlash(els.pending,  d.pending_approvals);
                numFlash(els.activity, d.activity_1h);
                numFlash(els.work,     d.pending_work);
                numFlash(els.members,  d.active_members);
                numFlash(els.newToday, d.new_today);

                if (els.updated) els.updated.textContent = d.last_updated;
                setPulse(true);

                // Render mini feed
                if (els.feed && d.recent_feed && d.recent_feed.length) {
                    els.feed.innerHTML = d.recent_feed.map(row => {
                        const color = feedColors[row.action_type] || 'var(--text-muted)';
                        const t = row.created_at ? row.created_at.split(' ')[1] : '';
                        return `<div style="display:flex;align-items:center;gap:8px;font-family:'Share Tech Mono',monospace;font-size:10px;">
                            <span style="color:var(--text-muted);min-width:58px;">${t}</span>
                            <span style="color:${color};min-width:140px;">${fmt(row.action_type)}</span>
                            <span style="color:var(--neon-cyan);">${row.username ? '@ ' + row.username : '—'}</span>
                        </div>`;
                    }).join('');
                }

                // Ping the pending approvals stat-card on the main dashboard too
                const mainPending = document.querySelector('.stat-card .value[style*="neon-warning"]');
                if (mainPending) mainPending.textContent = d.pending_approvals;
            })
            .catch(() => setPulse(false))
            .finally(() => {
                fetchInFlight = false;
                if (els.btn) els.btn.disabled = false;
                lucide.createIcons();
            });
    };

    // Countdown tick
    function startCountdown() {
        if (cdInterval) clearInterval(cdInterval);
        cdInterval = setInterval(() => {
            timer--;
            if (els.countdown) els.countdown.textContent = Math.max(0, timer);
            if (timer <= 0) {
                timer = INTERVAL;
                lswFetch();
            }
        }, 1000);
    }

    // Boot
    lswFetch();
    startCountdown();
})();
<?php endif; ?>

// ── Full Activity Log filters + CSV export ─────────
(function() {
    const searchInput  = document.getElementById('al-search-user');
    const actionFilter = document.getElementById('al-filter-action');
    const roleFilter   = document.getElementById('al-filter-role');
    const resetBtn     = document.getElementById('al-reset-filters');
    const exportBtn    = document.getElementById('al-export-csv');
    const matchCount   = document.getElementById('al-match-count');
    if (!searchInput) return;

    function applyFilters() {
        const q      = searchInput.value.toLowerCase().trim();
        const action = actionFilter.value;
        const role   = roleFilter.value;
        let visible  = 0;
        document.querySelectorAll('#al-tbody .al-row').forEach(row => {
            const username  = row.dataset.username || '';
            const rowAction = row.dataset.action   || '';
            const rowRole   = row.dataset.role     || '';
            const matchUser   = !q      || username.includes(q);
            const matchAction = !action || rowAction === action;
            const matchRole   = !role   || rowRole   === role;
            const show = matchUser && matchAction && matchRole;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (matchCount) matchCount.textContent = visible;
    }

    searchInput.addEventListener('input', applyFilters);
    actionFilter.addEventListener('change', applyFilters);
    roleFilter.addEventListener('change', applyFilters);
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            searchInput.value = '';
            actionFilter.value = '';
            roleFilter.value = '';
            applyFilters();
        });
    }

    // CSV Export
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const headers = ['Timestamp', 'Username', 'Full Name', 'Role', 'Action', 'IP Address', 'Details'];
            const rows = [headers];
            document.querySelectorAll('#al-tbody .al-row').forEach(row => {
                if (row.style.display === 'none') return;
                const cells = row.querySelectorAll('td');
                const ts       = cells[0]?.textContent?.trim() ?? '';
                const userCell = cells[1];
                const username = userCell?.childNodes[0]?.textContent?.trim() ?? userCell?.textContent?.trim() ?? '';
                const fullname = userCell?.querySelector('div')?.textContent?.trim() ?? '';
                const roleVal  = cells[2]?.textContent?.trim() ?? '';
                const action   = cells[3]?.textContent?.trim() ?? '';
                const ip       = cells[4]?.textContent?.trim() ?? '';
                const details  = cells[5]?.textContent?.trim() ?? '';
                rows.push([ts, username, fullname, roleVal, action, ip, details]);
            });
            const csv  = rows.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = 'nexus_activity_log_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }
})();

// ── Department Members Panel (all authenticated users) ────────────────
<?php if (true): // all logged-in roles get click handler ?>
(function() {
    const modal      = document.getElementById('modal-dept-members');
    if (!modal) return;

    const titleEl    = document.getElementById('dept-modal-title');
    const teamInfo   = document.getElementById('dept-team-info');
    const infoDept   = document.getElementById('dept-info-dept');
    const infoTeam   = document.getElementById('dept-info-team');
    const infoExt    = document.getElementById('dept-info-ext');
    const infoCount  = document.getElementById('dept-info-count');
    const loadingEl  = document.getElementById('dept-loading');
    const errorEl    = document.getElementById('dept-error');
    const tableWrap  = document.getElementById('dept-table-wrap');
    const tbody      = document.getElementById('dept-members-tbody');
    const noMembers  = document.getElementById('dept-no-members');

    // Add-member form elements
    const addBtn     = document.getElementById('dept-add-btn');
    const addForm    = document.getElementById('dept-add-form');
    const addConfirm = document.getElementById('dept-add-confirm');
    const addCancel  = document.getElementById('dept-add-cancel');
    const addMsg     = document.getElementById('dept-add-msg');
    const nameInput  = document.getElementById('dept-add-name');
    const emailInput = document.getElementById('dept-add-email');
    const ageInput   = document.getElementById('dept-add-age');
    const imgInput   = document.getElementById('dept-img-input');
    const avatarPrev = document.getElementById('dept-avatar-preview');
    const credsBox   = document.getElementById('dept-creds-box');
    const credUser   = document.getElementById('dept-cred-user');
    const credPass   = document.getElementById('dept-cred-pass');

    let currentTeamId   = null;
    let currentTeamName = null;

    const roleColors = {
        super_admin:    'var(--neon-error)',
        sub_admin:      'var(--neon-purple)',
        team_moderator: 'var(--neon-cyan)',
        user:           'var(--neon-warning)',
    };
    const roleLabels = {
        super_admin:    'SUPER ADMIN',
        sub_admin:      'SUB ADMIN',
        team_moderator: 'MODERATOR',
        user:           'USER',
    };
    const statusColors = {
        active:    'var(--neon-success)',
        pending:   'var(--neon-warning)',
        suspended: 'var(--neon-error)',
        banned:    'var(--neon-error)',
    };

    function escHtml(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showLoading() {
        loadingEl.style.display = '';
        errorEl.style.display   = 'none';
        tableWrap.style.display = 'none';
        teamInfo.style.display  = 'none';
        if (addBtn)  addBtn.style.display  = 'none';
        if (addForm) addForm.style.display = 'none';
        lucide.createIcons();
    }

    function showError(msg) {
        loadingEl.style.display = 'none';
        errorEl.style.display   = '';
        errorEl.textContent     = '⚠ ' + msg;
        tableWrap.style.display = 'none';
        teamInfo.style.display  = 'none';
    }

    function showTable(data) {
        loadingEl.style.display = 'none';
        errorEl.style.display   = 'none';
        tableWrap.style.display = '';
        tbody.innerHTML         = '';
        noMembers.style.display = 'none';

        const isAdmin = data.is_admin === true;

        if (data.team) {
            currentTeamId = data.team.id;
            teamInfo.style.display = '';
            infoDept.textContent   = data.team.department        || '—';
            infoTeam.textContent   = data.team.team_name         || '—';
            infoExt.textContent    = data.team.allowed_extensions || '—';
            infoCount.textContent  = data.members.length;
            if (addBtn) addBtn.style.display = isAdmin ? 'inline-flex' : 'none';
        } else {
            teamInfo.style.display = 'none';
        }

        // Show/hide email column header based on role
        const emailTh = document.querySelector('#dept-table-wrap thead th:nth-child(6)');
        const actionTh = document.querySelector('#dept-table-wrap thead th:last-child');
        if (emailTh)  emailTh.style.display  = isAdmin ? '' : 'none';
        if (actionTh) actionTh.style.display = isAdmin ? '' : 'none';

        if (!data.members || data.members.length === 0) {
            noMembers.style.display = '';
            return;
        }

        data.members.forEach((m, i) => {
            const rc     = roleColors[m.role]     || 'var(--text-muted)';
            const rl     = roleLabels[m.role]     || m.role.toUpperCase();
            const sc     = statusColors[m.status] || 'var(--text-muted)';
            const joined = m.created_at ? m.created_at.slice(0, 10) : '—';
            const age    = m.age ? m.age : '—';

            // Avatar cell
            const avatarHtml = m.profile_image
                ? `<img src="${escHtml(m.profile_image)}" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(0,240,255,.3);">`
                : `<div style="width:32px;height:32px;border-radius:50%;background:rgba(0,240,255,.08);border:1.5px solid rgba(0,240,255,.15);display:flex;align-items:center;justify-content:center;"><i data-lucide="user" style="width:14px;height:14px;color:var(--text-muted);"></i></div>`;

            // Remove button — only for admins, not for super_admin rows
            const removeBtn = (isAdmin && m.role !== 'super_admin')
                ? `<button class="dept-remove-btn action-btn" data-uid="${m.id}" data-name="${escHtml(m.full_name || m.username)}"
                        style="padding:4px 10px;font-size:9px;letter-spacing:1px;border-color:rgba(255,50,50,.35);color:var(--neon-error);white-space:nowrap;">
                        <i data-lucide="user-minus" style="width:10px;height:10px;"></i> Remove
                   </button>`
                : '<span style="font-size:10px;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;">—</span>';

            // Email cell — hidden from regular users
            const emailCell = isAdmin
                ? `<td style="color:var(--text-muted);font-size:11px;">${escHtml(m.email || '—')}</td>`
                : `<td style="display:none;"></td>`;

            // Action cell — hidden from regular users
            const actionCell = isAdmin
                ? `<td>${removeBtn}</td>`
                : `<td style="display:none;"></td>`;

            const tr = document.createElement('tr');
            tr.dataset.uid = m.id;
            tr.innerHTML = `
                <td style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--text-muted);">${i + 1}</td>
                <td style="text-align:center;">${avatarHtml}</td>
                <td style="color:var(--text-main);font-size:12px;">${escHtml(m.full_name || '—')}</td>
                <td style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--neon-warning);text-align:center;">${age}</td>
                <td style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;">${escHtml(m.username)}</td>
                ${emailCell}
                <td><span style="color:${rc};font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;">${rl}</span></td>
                <td><span style="color:${sc};font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;">${escHtml(m.status?.toUpperCase() || '—')}</span></td>
                <td style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--text-muted);">${joined}</td>
                ${actionCell}
            `;
            tbody.appendChild(tr);
        });

        // Remove button handlers
        tbody.querySelectorAll('.dept-remove-btn').forEach(btn => {
            btn.addEventListener('click', () => removeMember(btn));
        });

        lucide.createIcons();
    }

    // ── Remove member ────────────────────────────────
    async function removeMember(btn) {
        const uid  = btn.dataset.uid;
        const name = btn.dataset.name;
        if (!confirm(`Remove "${name}" from this team?`)) return;

        btn.disabled    = true;
        btn.textContent = '…';

        const fd = new FormData();
        fd.append('user_id', uid);
        fd.append('team_id', currentTeamId);

        try {
            const res  = await fetch('api/admin/remove_dept_member.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.status === 'success') {
                const row = btn.closest('tr');
                if (row) {
                    row.style.opacity = '0';
                    row.style.transition = 'opacity .35s';
                    setTimeout(() => { row.remove(); refreshRowNumbers(); }, 380);
                }
                infoCount.textContent = Math.max(0, parseInt(infoCount.textContent || '0') - 1);
            } else {
                alert('⚠ ' + (data.message || 'Remove failed.'));
                btn.disabled    = false;
                btn.innerHTML   = '<i data-lucide="user-minus" style="width:10px;height:10px;"></i> Remove';
                lucide.createIcons();
            }
        } catch(e) {
            alert('⚠ Connection error.');
            btn.disabled    = false;
            btn.innerHTML   = '<i data-lucide="user-minus" style="width:10px;height:10px;"></i> Remove';
            lucide.createIcons();
        }
    }

    function refreshRowNumbers() {
        tbody.querySelectorAll('tr').forEach((r, i) => {
            const first = r.querySelector('td:first-child');
            if (first) first.textContent = i + 1;
        });
    }

    // ── Avatar preview ───────────────────────────────
    if (imgInput && avatarPrev) {
        imgInput.addEventListener('change', () => {
            const file = imgInput.files[0];
            if (!file) return;
            const url = URL.createObjectURL(file);
            avatarPrev.innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:cover;">`;
        });
    }

    // ── Toggle add form ──────────────────────────────
    if (addBtn) {
        addBtn.addEventListener('click', () => {
            const isOpen = addForm.style.display !== 'none';
            if (isOpen) {
                addForm.style.display = 'none';
            } else {
                addForm.style.display = '';
                resetAddForm();
                lucide.createIcons();
                if (nameInput) nameInput.focus();
            }
        });
    }

    // Cancel button
    if (addCancel) {
        addCancel.addEventListener('click', () => {
            addForm.style.display = 'none';
            resetAddForm();
        });
    }

    function resetAddForm() {
        if (nameInput)  nameInput.value  = '';
        if (emailInput) emailInput.value = '';
        if (ageInput)   ageInput.value   = '';
        if (imgInput)   imgInput.value   = '';
        if (avatarPrev) avatarPrev.innerHTML = '<i data-lucide="camera" style="width:20px;height:20px;color:var(--text-muted);"></i>';
        if (addMsg)     addMsg.style.display = 'none';
        if (credsBox)   credsBox.style.display = 'none';
        lucide.createIcons();
    }

    // ── Add member (create + assign) ─────────────────
    if (addConfirm) {
        addConfirm.addEventListener('click', async () => {
            const name  = (nameInput?.value  || '').trim();
            const email = (emailInput?.value || '').trim();
            const age   = (ageInput?.value   || '').trim();

            if (!name)  { showAddMsg('Full name is required.', false); return; }
            if (!email) { showAddMsg('Email is required.', false); return; }
            if (!currentTeamId) { showAddMsg('No team loaded.', false); return; }

            addConfirm.disabled = true;
            addConfirm.textContent = 'Adding…';
            if (credsBox) credsBox.style.display = 'none';

            const fd = new FormData();
            fd.append('full_name', name);
            fd.append('email',     email);
            fd.append('age',       age);
            fd.append('team_id',   currentTeamId);
            if (imgInput?.files[0]) fd.append('profile_image', imgInput.files[0]);

            try {
                const res  = await fetch('api/admin/add_dept_member.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await res.json();
                if (data.status === 'success') {
                    showAddMsg('✓ ' + (data.message || 'Member added.'), true);
                    if (credsBox && credUser && credPass) {
                        credUser.textContent  = data.username      || '';
                        credPass.textContent  = data.temp_password || '';
                        credsBox.style.display = '';
                    }
                    resetAddFormFields();
                    setTimeout(() => openDeptPanel(currentTeamName), 1400);
                } else {
                    showAddMsg('⚠ ' + (data.message || 'Add failed.'), false);
                }
            } catch(e) {
                showAddMsg('⚠ Connection error.', false);
            } finally {
                addConfirm.disabled = false;
                addConfirm.innerHTML = '<i data-lucide="user-plus" style="width:11px;height:11px;"></i> Add';
                lucide.createIcons();
            }
        });
    }

    function resetAddFormFields() {
        if (nameInput)  nameInput.value  = '';
        if (emailInput) emailInput.value = '';
        if (ageInput)   ageInput.value   = '';
        if (imgInput)   imgInput.value   = '';
        if (avatarPrev) avatarPrev.innerHTML = '<i data-lucide="camera" style="width:20px;height:20px;color:var(--text-muted);"></i>';
        lucide.createIcons();
    }

    function showAddMsg(msg, ok) {
        if (!addMsg) return;
        addMsg.style.display    = '';
        addMsg.textContent      = msg;
        addMsg.style.color      = ok ? 'var(--neon-success)' : 'var(--neon-error)';
        addMsg.style.background = ok ? 'rgba(0,255,136,.08)' : 'rgba(255,50,50,.08)';
        addMsg.style.border     = '1px solid ' + (ok ? 'rgba(0,255,136,.2)' : 'rgba(255,50,50,.2)');
        if (ok) setTimeout(() => { if (addMsg) addMsg.style.display = 'none'; }, 6000);
    }

    // ── Open panel ───────────────────────────────────
    async function openDeptPanel(teamName) {
        currentTeamName = teamName;
        if (addForm) addForm.style.display = 'none';
        if (addMsg)  addMsg.style.display  = 'none';
        if (credsBox) credsBox.style.display = 'none';
        if (titleEl) titleEl.innerHTML = `<i data-lucide="users" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>${escHtml(teamName)} Team`;
        modal.classList.add('active');
        showLoading();
        lucide.createIcons();
        try {
            const res  = await fetch('api/admin/get_department_members.php?team_name=' + encodeURIComponent(teamName));
            const data = await res.json();
            if (data.status !== 'success') { showError(data.message || 'Failed to load data.'); return; }
            showTable(data);
        } catch(e) {
            showError('Connection error. Please try again.');
        }
    }

    // Wire dept sub-item clicks
    document.querySelectorAll('[data-dept-item]').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            openDeptPanel(link.dataset.deptItem);
        });
    });

    // Close handlers
    modal.querySelectorAll('.close-modal').forEach(btn => {
        btn.addEventListener('click', () => modal.classList.remove('active'));
    });
    modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('active'); });
})();
<?php endif; ?>

// ── Alert helper ──────────────────────────────────
function showModalAlert(id, msg, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className   = 'alert alert-' + type;
    el.style.display = 'block';
    if (type === 'success') setTimeout(() => { el.style.display = 'none'; }, 6000);
}

// ── Work Update submit ─────────────────────────────
const workForm = document.getElementById('form-work-update');
if (workForm) {
    workForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = workForm.querySelector('button[type="submit"]');
        const orig = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.textContent = '⟳ TRANSMITTING...'; }
        try {
            const res  = await fetch('api/system_core.php', { method: 'POST', body: new FormData(e.target) });
            const data = safeJSON(await res.text());
            showModalAlert('work-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') { workForm.reset(); }
        } catch { showModalAlert('work-alert', 'Connection error.', 'error'); }
        finally { if (btn) { btn.disabled = false; btn.innerHTML = orig; } }
    });
}

// ── Approve Member ────────────────────────────────
async function approveMember(id) {
    const btn = event.target.closest('button');
    if (btn) { btn.disabled = true; btn.textContent = '⟳'; }
    const row = document.getElementById('member-row-' + id);
    const teamSelect = row?.querySelector('.team-assign-select');
    const teamId = teamSelect ? teamSelect.value : '';
    const fd = new FormData();
    fd.append('action', 'approve_member');
    fd.append('target_user_id', id);
    if (teamId) fd.append('team_id', teamId);
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status === 'success') {
            if (row) { row.style.transition = 'opacity .4s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 400); }
        } else {
            alert('Error: ' + data.message);
            if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="check" style="width:12px;height:12px;"></i> Approve'; lucide.createIcons(); }
        }
    } catch {
        alert('Error connecting to Core API.');
        if (btn) { btn.disabled = false; btn.textContent = 'Approve'; }
    }
}

// ── Assign Team ────────────────────────────────────
async function assignTeam(userId, selectEl) {
    const teamId = selectEl ? selectEl.value : '';
    const fd = new FormData();
    fd.append('action', 'assign_team');
    fd.append('target_user_id', userId);
    fd.append('team_id', teamId);
    const origBorder = selectEl?.style.borderColor;
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status === 'success') {
            if (selectEl) { selectEl.style.borderColor = 'var(--neon-success)'; setTimeout(() => { selectEl.style.borderColor = origBorder; }, 1500); }
        } else {
            alert('Error: ' + data.message);
        }
    } catch { alert('Connection error.'); }
}

// ── Reject Member ─────────────────────────────────
async function rejectMember(id) {
    if (!confirm('Reject this member request?')) return;
    const fd = new FormData();
    fd.append('action', 'reject_member');
    fd.append('target_user_id', id);
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status === 'success') {
            const row = document.getElementById('member-row-' + id);
            if (row) { row.style.transition = 'opacity .4s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 400); }
        }
    } catch { alert('Error connecting to Core API.'); }
}

// ── Create Production ─────────────────────────────
const prodForm = document.getElementById('form-create-production');
if (prodForm) {
    prodForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = prodForm.querySelector('button[type="submit"]');
        const orig = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.textContent = '⟳ INITIALIZING...'; }
        try {
            const res  = await fetch('api/admin/create_production.php', { method: 'POST', body: new FormData(e.target) });
            const data = safeJSON(await res.text());
            showModalAlert('prod-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') { prodForm.reset(); setTimeout(() => location.reload(), 1600); }
        } catch { showModalAlert('prod-alert', 'Connection error.', 'error'); }
        finally { if (btn) { btn.disabled = false; btn.innerHTML = orig; } }
    });
}

// ── Create Team ───────────────────────────────────
const teamForm = document.getElementById('form-create-team');
if (teamForm) {
    teamForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = teamForm.querySelector('button[type="submit"]');
        const orig = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.textContent = '⟳ ESTABLISHING...'; }
        try {
            const res  = await fetch('api/admin/create_team.php', { method: 'POST', body: new FormData(e.target) });
            const data = safeJSON(await res.text());
            showModalAlert('team-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') { teamForm.reset(); }
        } catch { showModalAlert('team-alert', 'Connection error.', 'error'); }
        finally { if (btn) { btn.disabled = false; btn.innerHTML = orig; } }
    });
}

// ── Notification & Settings Panels ───────────────
(function() {
    const btnNotif    = document.getElementById('btn-notif');
    const panelNotif  = document.getElementById('panel-notif');
    const btnSettings = document.getElementById('btn-settings');
    const panelSettings = document.getElementById('panel-settings');

    function closeAll() {
        panelNotif?.classList.remove('hdr-open');
        panelSettings?.classList.remove('hdr-open');
    }

    btnNotif?.addEventListener('click', e => {
        e.stopPropagation();
        const isOpen = panelNotif.classList.contains('hdr-open');
        closeAll();
        if (!isOpen) panelNotif.classList.add('hdr-open');
    });

    btnSettings?.addEventListener('click', e => {
        e.stopPropagation();
        const isOpen = panelSettings.classList.contains('hdr-open');
        closeAll();
        if (!isOpen) panelSettings.classList.add('hdr-open');
    });

    // Close when clicking outside
    document.addEventListener('click', e => {
        if (!e.target.closest('.hdr-dropdown-wrap')) closeAll();
    });

    // Notification shortcut → open Member Control
    document.getElementById('notif-goto-members')?.addEventListener('click', e => {
        e.preventDefault();
        closeAll();
        document.getElementById('modal-member-control')?.classList.add('active');
    });

    // Change password form
    const pwForm = document.getElementById('form-change-password');
    const pwMsg  = document.getElementById('settings-msg');
    pwForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = pwForm.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = '⟳ UPDATING...';
        pwMsg.style.display = 'none';
        try {
            const res  = await fetch('api/auth/change_password.php', { method: 'POST', body: new FormData(pwForm) });
            const data = safeJSON(await res.text());
            pwMsg.style.display = 'block';
            pwMsg.className = 'alert ' + (data.status === 'success' ? 'alert-success' : 'alert-error');
            pwMsg.textContent = data.message;
            if (data.status === 'success') pwForm.reset();
        } catch { pwMsg.style.display='block'; pwMsg.className='alert alert-error'; pwMsg.textContent='Connection error.'; }
        finally { btn.disabled = false; btn.innerHTML = orig; lucide.createIcons(); }
    });

    // Compact sidebar toggle
    const compactToggle = document.getElementById('toggle-compact-sidebar');
    const compact = localStorage.getItem('nexus-compact-sidebar') === '1';
    if (compact && compactToggle) { compactToggle.checked = true; document.body.classList.add('compact-sidebar'); }
    compactToggle?.addEventListener('change', () => {
        document.body.classList.toggle('compact-sidebar', compactToggle.checked);
        localStorage.setItem('nexus-compact-sidebar', compactToggle.checked ? '1' : '0');
    });

    // N.A.V.I visibility toggle
    const naviToggle = document.getElementById('toggle-navi-visible');
    const naviTrigger = document.getElementById('navi-trigger');
    const naviHidden = localStorage.getItem('nexus-navi-hidden') === '1';
    if (naviHidden && naviToggle) { naviToggle.checked = false; if(naviTrigger) naviTrigger.style.display='none'; }
    naviToggle?.addEventListener('change', () => {
        const visible = naviToggle.checked;
        if (naviTrigger) naviTrigger.style.display = visible ? '' : 'none';
        localStorage.setItem('nexus-navi-hidden', visible ? '0' : '1');
        if (!visible) document.getElementById('navi-panel')?.classList.remove('navi-open');
    });
})();

// ── Contact Us form ───────────────────────────────
const contactForm = document.getElementById('form-contact-us');
if (contactForm) {
    contactForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = contactForm.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = '⟳ SENDING...';
        try {
            const res  = await fetch('api/contact.php', { method: 'POST', body: new FormData(contactForm) });
            const data = safeJSON(await res.text());
            showModalAlert('contact-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') contactForm.reset();
        } catch { showModalAlert('contact-alert', 'Connection error.', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = orig; lucide.createIcons(); }
    });
}

// ── Send Notification form ─────────────────────────
const notifSendForm = document.getElementById('form-send-notification');
if (notifSendForm) {
    notifSendForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = notifSendForm.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = '⟳ BROADCASTING...';
        try {
            const res  = await fetch('api/send_notification.php', { method: 'POST', body: new FormData(notifSendForm) });
            const data = safeJSON(await res.text());
            showModalAlert('notif-send-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') { notifSendForm.reset(); }
        } catch { showModalAlert('notif-send-alert', 'Connection error.', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = orig; lucide.createIcons(); }
    });
}

// ── Mark contact messages read when panel opens ────
document.getElementById('btn-contact-messages')?.addEventListener('click', () => {
    fetch('api/contact.php', { method: 'POST', body: (() => { const f = new FormData(); f.append('action','mark_read'); return f; })() });
});

// ── Remove Staff Member ───────────────────────────
async function removeStaff(id, username) {
    if (!confirm(`TERMINATE ACCESS\n\nPermanently remove "${username}" from NEXUS CORE?\n\nThis action cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete_user');
    fd.append('target_user_id', id);
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status === 'success') {
            const row = document.getElementById('staff-row-' + id);
            if (row) { row.style.transition = 'opacity .4s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 400); }
        } else {
            alert('Error: ' + data.message);
        }
    } catch { alert('Connection error.'); }
}

// ── Staff Filter Tabs ──────────────────────────────
document.querySelectorAll('.staff-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.staff-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        document.querySelectorAll('#staff-table tbody tr[data-role]').forEach(row => {
            row.style.display = (filter === 'all' || row.dataset.role === filter) ? '' : 'none';
        });
    });
});

// ── Create Admin Account ──────────────────────────
const adminCreateForm = document.getElementById('form-create-admin');
if (adminCreateForm) {
    adminCreateForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = adminCreateForm.querySelector('button[type="submit"]');
        const orig = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.textContent = '⟳ PROVISIONING...'; }
        try {
            const res  = await fetch('api/system_core.php', { method: 'POST', body: new FormData(e.target) });
            const data = safeJSON(await res.text());
            showModalAlert('admin-create-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') { adminCreateForm.reset(); }
        } catch { showModalAlert('admin-create-alert', 'Connection error.', 'error'); }
        finally { if (btn) { btn.disabled = false; btn.innerHTML = orig; } }
    });
}

// ── Ban / Suspend / Restore Members ───────────────
async function banMember(id, username) {
    if (!confirm(`BAN USER\n\nBan "${username}" permanently from NEXUS CORE?\n\nThey will lose all access immediately.`)) return;
    const fd = new FormData();
    fd.append('action', 'ban_member');
    fd.append('target_user_id', id);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const row = document.getElementById('staff-row-' + id);
        if (row) {
            const cells = row.querySelectorAll('td');
            if (cells[4]) cells[4].innerHTML = '<span style="color:var(--neon-error);font-family:\'Share Tech Mono\',monospace;font-size:10px;">BANNED</span>';
            updateStaffActionBtns(row, 'banned', id, username);
        }
    } else { alert('Error: ' + data.message); }
}

async function suspendMember(id, username) {
    if (!confirm(`SUSPEND USER\n\nSuspend "${username}" temporarily?\n\nThey lose access until restored.`)) return;
    const fd = new FormData();
    fd.append('action', 'suspend_member');
    fd.append('target_user_id', id);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const row = document.getElementById('staff-row-' + id);
        if (row) {
            const cells = row.querySelectorAll('td');
            if (cells[4]) cells[4].innerHTML = '<span style="color:var(--neon-warning);font-family:\'Share Tech Mono\',monospace;font-size:10px;">SUSPENDED</span>';
            updateStaffActionBtns(row, 'suspended', id, username);
        }
    } else { alert('Error: ' + data.message); }
}

async function restoreMember(id, username) {
    if (!confirm(`RESTORE ACCESS\n\nRestore "${username}" to active status?`)) return;
    const fd = new FormData();
    fd.append('action', 'restore_member');
    fd.append('target_user_id', id);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const row = document.getElementById('staff-row-' + id);
        if (row) {
            const cells = row.querySelectorAll('td');
            if (cells[4]) cells[4].innerHTML = '<span style="color:var(--neon-success);font-family:\'Share Tech Mono\',monospace;font-size:10px;">ACTIVE</span>';
            updateStaffActionBtns(row, 'active', id, username);
        }
    } else { alert('Error: ' + data.message); }
}

function updateStaffActionBtns(row, newStatus, id, username) {
    const div = row.querySelector('.staff-action-btns');
    if (!div) return;
    let html = '';
    if (newStatus === 'active') {
        html += `<button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-warning);color:var(--neon-warning);" onclick="suspendMember(${id},'${username}')"><i data-lucide="pause-circle" style="width:10px;height:10px;"></i> Suspend</button>`;
        html += `<button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);" onclick="banMember(${id},'${username}')"><i data-lucide="ban" style="width:10px;height:10px;"></i> Ban</button>`;
    } else {
        html += `<button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-success);color:var(--neon-success);" onclick="restoreMember(${id},'${username}')"><i data-lucide="refresh-cw" style="width:10px;height:10px;"></i> Restore</button>`;
    }
    html += `<button class="action-btn" style="padding:3px 7px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);" onclick="removeStaff(${id},'${username}')"><i data-lucide="trash-2" style="width:10px;height:10px;"></i> Remove</button>`;
    div.innerHTML = html;
    lucide.createIcons();
}

// ── Promote / Demote Role ─────────────────────────
async function changeRole(id, selectEl) {
    const newRole = selectEl.value;
    const label = { sub_admin: 'SUB ADMIN', team_moderator: 'TEAM MODERATOR', user: 'USER' }[newRole] || newRole.toUpperCase();
    if (!confirm(`ROLE CHANGE\n\nChange this user's clearance to "${label}"?\n\nThis takes effect immediately.`)) return;
    const fd = new FormData();
    fd.append('action', 'change_role');
    fd.append('target_user_id', id);
    fd.append('new_role', newRole);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const row = document.getElementById('staff-row-' + id);
        if (row) {
            const roleColors = { sub_admin: 'var(--neon-purple)', team_moderator: 'var(--neon-cyan)', user: 'var(--neon-warning)' };
            const roleLabels = { sub_admin: 'SUB ADMIN', team_moderator: 'TEAM MOD', user: 'USER' };
            const cells = row.querySelectorAll('td');
            if (cells[3]) cells[3].innerHTML = `<span style="color:${roleColors[newRole]||'var(--neon-warning)'};font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;">${roleLabels[newRole]||label}</span>`;
            row.dataset.role = newRole;
        }
    } else { alert('Error: ' + data.message); }
}

// ── Open Work Detail Modal ─────────────────────────
async function openWorkDetail(subId) {
    document.getElementById('modal-work-detail')?.classList.add('active');
    const body = document.getElementById('work-detail-body');
    body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);"><i data-lucide="loader" style="width:24px;height:24px;display:block;margin:0 auto 10px;opacity:.4;"></i>Loading...</div>';
    if (window.lucide) lucide.createIcons();

    const fd = new FormData();
    fd.append('action', 'get_work_detail');
    fd.append('submission_id', subId);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status !== 'success') { body.innerHTML = '<p style="color:var(--neon-error);padding:20px;">Error: ' + data.message + '</p>'; return; }

    const d = data.data;
    const statusColors = { approved:'var(--neon-success)', rejected:'var(--neon-error)', featured:'var(--neon-purple)', needs_fix:'var(--neon-warning)', pending_review:'var(--text-muted)' };
    const statusLabels = { approved:'Approved', rejected:'Rejected', featured:'Featured', needs_fix:'Needs Fix', pending_review:'Pending Review' };
    const sColor = statusColors[d.status] || 'var(--text-muted)';
    const sLabel = statusLabels[d.status] || d.status;

    const fileSection = d.file_path ? `
        <div style="margin-top:16px;">
            <p style="margin:0 0 8px;font-size:11px;letter-spacing:2px;color:var(--neon-success);text-transform:uppercase;">── Uploaded File</p>
            <div style="display:flex;align-items:center;gap:10px;background:rgba(0,255,136,0.05);border:1px solid rgba(0,255,136,0.2);border-radius:6px;padding:12px 16px;">
                <i data-lucide="file" style="width:20px;height:20px;color:var(--neon-success);flex-shrink:0;"></i>
                <span style="color:var(--text-main);font-family:'Share Tech Mono',monospace;font-size:12px;flex:1;word-break:break-all;">${d.file_path.split('/').pop()}</span>
                <a href="api/admin/download_work.php?id=${d.id}" download
                   style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:var(--neon-success);color:#000;border-radius:4px;text-decoration:none;font-size:11px;font-weight:700;font-family:'Share Tech Mono',monospace;white-space:nowrap;">
                   <i data-lucide="download" style="width:12px;height:12px;"></i> Download
                </a>
            </div>
        </div>` : '';

    const driveSection = d.drive_link ? `
        <div style="margin-top:16px;">
            <p style="margin:0 0 8px;font-size:11px;letter-spacing:2px;color:var(--neon-cyan);text-transform:uppercase;">── Google Drive</p>
            <div style="display:flex;align-items:center;gap:10px;background:rgba(0,240,255,0.05);border:1px solid rgba(0,240,255,0.2);border-radius:6px;padding:12px 16px;">
                <i data-lucide="hard-drive" style="width:20px;height:20px;color:var(--neon-cyan);flex-shrink:0;"></i>
                <span style="color:var(--text-main);font-family:'Share Tech Mono',monospace;font-size:12px;flex:1;word-break:break-all;">${d.drive_link.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>
                <a href="${d.drive_link}" target="_blank" rel="noopener"
                   style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:var(--neon-cyan);color:#000;border-radius:4px;text-decoration:none;font-size:11px;font-weight:700;font-family:'Share Tech Mono',monospace;white-space:nowrap;">
                   <i data-lucide="external-link" style="width:12px;height:12px;"></i> Open
                </a>
            </div>
        </div>` : '';

    const noAttachments = !d.file_path && !d.drive_link
        ? '<p style="color:var(--text-muted);font-size:12px;margin-top:12px;font-style:italic;">No file or Drive link submitted.</p>'
        : '';

    const prevFeedback = d.feedback ? `
        <div style="background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.2);border-radius:6px;padding:12px 16px;margin-bottom:12px;">
            <p style="margin:0 0 4px;font-size:10px;color:var(--neon-warning);letter-spacing:2px;text-transform:uppercase;">Previous Feedback</p>
            <p style="margin:0;font-size:13px;color:var(--text-main);line-height:1.6;">${d.feedback.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</p>
        </div>` : '';

    body.innerHTML = `
    <div style="padding:20px 24px;">
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
            <div style="flex:1;min-width:140px;background:rgba(0,240,255,0.05);border:1px solid rgba(0,240,255,0.15);border-radius:6px;padding:10px 14px;">
                <p style="margin:0 0 2px;font-size:10px;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;">Member</p>
                <p style="margin:0;font-size:13px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;">${d.username}</p>
                <p style="margin:2px 0 0;font-size:11px;color:var(--text-muted);">${d.full_name}</p>
            </div>
            <div style="flex:1;min-width:140px;background:rgba(0,240,255,0.05);border:1px solid rgba(0,240,255,0.15);border-radius:6px;padding:10px 14px;">
                <p style="margin:0 0 2px;font-size:10px;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;">Production</p>
                <p style="margin:0;font-size:13px;color:var(--text-main);">${d.prod_name}</p>
                <p style="margin:2px 0 0;font-size:11px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">${d.team_name}</p>
            </div>
            <div style="flex:1;min-width:120px;background:rgba(0,240,255,0.05);border:1px solid rgba(0,240,255,0.15);border-radius:6px;padding:10px 14px;">
                <p style="margin:0 0 4px;font-size:10px;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;">Progress</p>
                <p style="margin:0 0 6px;font-size:16px;font-weight:700;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;">${d.progress_percentage}%</p>
                <div style="background:rgba(255,255,255,0.06);border-radius:4px;height:5px;">
                    <div style="background:var(--neon-cyan);height:100%;width:${d.progress_percentage}%;border-radius:4px;"></div>
                </div>
            </div>
            <div style="flex:1;min-width:100px;background:rgba(0,240,255,0.05);border:1px solid rgba(0,240,255,0.15);border-radius:6px;padding:10px 14px;">
                <p style="margin:0 0 4px;font-size:10px;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;">Status</p>
                <p style="margin:0;font-size:13px;font-weight:700;color:${sColor};font-family:'Share Tech Mono',monospace;">${sLabel}</p>
                <p style="margin:4px 0 0;font-size:10px;color:var(--text-muted);">${new Date(d.submitted_at).toLocaleDateString()}</p>
            </div>
        </div>

        <p style="margin:0 0 8px;font-size:11px;letter-spacing:2px;color:var(--neon-cyan);text-transform:uppercase;">── Work Description</p>
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:14px 16px;margin-bottom:4px;">
            <p style="margin:0;font-size:13px;color:var(--text-main);line-height:1.8;white-space:pre-wrap;">${d.work_description.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</p>
        </div>

        ${fileSection}
        ${driveSection}
        ${noAttachments}

        <div style="margin-top:20px;border-top:1px solid rgba(255,255,255,0.06);padding-top:20px;">
            <p style="margin:0 0 8px;font-size:11px;letter-spacing:2px;color:var(--neon-warning);text-transform:uppercase;">── Review Decision</p>
            ${prevFeedback}
            <textarea id="wd-feedback" rows="3" placeholder="Leave feedback for the member (optional but recommended)..."
                style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--text-main);padding:10px 12px;font-size:13px;resize:vertical;box-sizing:border-box;margin-bottom:12px;">${d.feedback || ''}</textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="action-btn" style="flex:1;justify-content:center;border-color:var(--neon-success);color:var(--neon-success);padding:8px;" onclick="reviewWork(${d.id},'approved')">
                    <i data-lucide="check-circle" style="width:13px;height:13px;"></i> Approve
                </button>
                <button class="action-btn" style="flex:1;justify-content:center;border-color:var(--neon-warning);color:var(--neon-warning);padding:8px;" onclick="reviewWork(${d.id},'needs_fix')">
                    <i data-lucide="wrench" style="width:13px;height:13px;"></i> Needs Fix
                </button>
                <button class="action-btn" style="flex:1;justify-content:center;border-color:var(--neon-purple);color:var(--neon-purple);padding:8px;" onclick="reviewWork(${d.id},'featured')">
                    <i data-lucide="star" style="width:13px;height:13px;"></i> Feature
                </button>
                <button class="action-btn" style="flex:1;justify-content:center;border-color:var(--neon-error);color:var(--neon-error);padding:8px;" onclick="reviewWork(${d.id},'rejected')">
                    <i data-lucide="x-circle" style="width:13px;height:13px;"></i> Reject
                </button>
            </div>
        </div>
    </div>`;
    if (window.lucide) lucide.createIcons();
}

// ── Review Work Submission ─────────────────────────
async function reviewWork(subId, status) {
    const feedback = document.getElementById('wd-feedback')?.value?.trim() || '';
    const fd = new FormData();
    fd.append('action', 'review_work');
    fd.append('submission_id', subId);
    fd.append('status', status);
    fd.append('feedback', feedback);
    const row = document.getElementById('work-row-' + subId);
    const sc  = row?.querySelector('.work-status-cell');
    const orig = sc?.textContent;
    if (sc) sc.textContent = '⟳';
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const statusColors = { approved:'var(--neon-success)', rejected:'var(--neon-error)', featured:'var(--neon-purple)', needs_fix:'var(--neon-warning)', pending_review:'var(--text-muted)' };
        const statusLabels = { approved:'Approved', rejected:'Rejected', featured:'Featured', needs_fix:'Needs Fix', pending_review:'Pending Review' };
        if (sc) {
            sc.textContent = statusLabels[status] || status;
            sc.style.color = statusColors[status] || 'var(--text-muted)';
        }
        if (row) row.dataset.status = status;
        // Update status badge inside the detail modal
        const detailStatusEl = document.querySelector('#work-detail-body .wd-status-badge');
        if (detailStatusEl) {
            detailStatusEl.textContent = statusLabels[status] || status;
            detailStatusEl.style.color = statusColors[status] || 'var(--text-muted)';
        }
        document.getElementById('modal-work-detail')?.classList.remove('active');
    } else {
        if (sc) sc.textContent = orig;
        alert('Error: ' + data.message);
    }
}

// ── Delete Work Submission ─────────────────────────
async function deleteWork(subId) {
    if (!confirm('DELETE SUBMISSION\n\nPermanently delete this work submission?\n\nThis cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_work');
    fd.append('submission_id', subId);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const row = document.getElementById('work-row-' + subId);
        if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(() => row.remove(), 300); }
        document.getElementById('modal-work-detail')?.classList.remove('active');
    } else { alert('Error: ' + data.message); }
}

// ── Work Review Filter Tabs ────────────────────────
function filterWorkReview(status) {
    document.querySelectorAll('.wr-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.wr-tab[data-status="${status}"]`)?.classList.add('active');
    document.querySelectorAll('#work-review-tbody tr').forEach(row => {
        row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
    });
}

// ── My Submissions Filter Tabs ─────────────────────
function filterMySubmissions(status) {
    document.querySelectorAll('.ms-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.ms-tab[data-status="${status}"]`)?.classList.add('active');
    document.querySelectorAll('#my-submissions-list .ms-card').forEach(card => {
        card.style.display = (status === 'all' || card.dataset.status === status) ? '' : 'none';
    });
}

// ── My Tasks Filter Tabs ────────────────────────────
function filterTasks(status) {
    document.querySelectorAll('.task-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.task-tab[data-tstatus="${status}"]`)?.classList.add('active');
    document.querySelectorAll('#tasks-list .task-card').forEach(card => {
        card.style.display = (status === 'all' || card.dataset.tstatus === status) ? '' : 'none';
    });
}

// ── Update Task Status ──────────────────────────────
async function updateTaskStatus(taskId, newStatus, btn) {
    const card = btn.closest('.task-card');
    const allBtns = card ? card.querySelectorAll('button[onclick^="updateTaskStatus"]') : [];
    allBtns.forEach(b => b.disabled = true);
    const fd = new FormData();
    fd.append('action', 'update_task_status');
    fd.append('task_id', taskId);
    fd.append('task_status', newStatus);
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status === 'success') {
            // Update card visually without full reload
            const statusColors = { in_progress:'var(--neon-cyan)', pending:'var(--neon-warning)', on_hold:'var(--neon-purple)', completed:'var(--neon-success)' };
            const statusLabels = { in_progress:'In Progress', pending:'Pending', on_hold:'On Hold', completed:'Completed' };
            const statusBgs    = { in_progress:'rgba(0,240,255,0.03)', pending:'rgba(245,158,11,0.03)', on_hold:'rgba(168,85,247,0.03)', completed:'rgba(0,255,136,0.03)' };
            const statusBorders= { in_progress:'rgba(0,240,255,0.15)', pending:'rgba(245,158,11,0.15)', on_hold:'rgba(168,85,247,0.15)', completed:'rgba(0,255,136,0.15)' };
            if (card) {
                card.dataset.tstatus = newStatus;
                card.style.background = statusBgs[newStatus] || 'rgba(255,255,255,0.02)';
                card.style.borderColor = statusBorders[newStatus] || 'rgba(255,255,255,0.07)';
                const badge = card.querySelector('.task-status-badge');
                if (badge) { badge.textContent = statusLabels[newStatus]; badge.style.color = statusColors[newStatus]; badge.style.borderColor = statusColors[newStatus]; }
                // Highlight the active status button
                allBtns.forEach(b => {
                    const bStatus = b.getAttribute('onclick').match(/'([^']+)'\s*\)/)?.[1];
                    const isActive = bStatus === newStatus;
                    b.style.background = isActive ? (statusColors[bStatus] || 'transparent') : 'transparent';
                    b.style.color      = isActive ? '#000' : (statusColors[bStatus] || 'var(--text-muted)');
                });
            }
        } else { alert('Error: ' + data.message); }
    } catch { alert('Connection error. Please retry.'); }
    finally { allBtns.forEach(b => b.disabled = false); }
}

// ── Team Collaboration Chat ─────────────────────────
let chatLastId    = 0;
let chatPollTimer = null;

function chatBubble(msg, isMine) {
    const time = new Date(msg.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const escaped = msg.message.replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    if (isMine) {
        return `<div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;">
            <div style="max-width:75%;background:rgba(0,240,255,0.12);border:1px solid rgba(0,240,255,0.25);border-radius:12px 12px 2px 12px;padding:9px 14px;font-size:13px;color:var(--text-main);line-height:1.6;word-break:break-word;">${escaped}</div>
            <span style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">${time}</span>
        </div>`;
    } else {
        return `<div style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;">
            <span style="font-size:10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-weight:700;margin-left:2px;">${msg.username}</span>
            <div style="max-width:75%;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px 12px 12px 2px;padding:9px 14px;font-size:13px;color:var(--text-main);line-height:1.6;word-break:break-word;">${escaped}</div>
            <span style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">${time}</span>
        </div>`;
    }
}

async function loadChatMessages(initial = false) {
    const fd = new FormData();
    fd.append('action', 'get_messages');
    if (!initial && chatLastId > 0) fd.append('since', chatLastId);
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status !== 'success') return;

        const area    = document.getElementById('chat-messages');
        const loading = document.getElementById('chat-loading');
        if (loading) loading.remove();

        const myId    = data.current_user_id;
        const msgs    = data.messages || [];

        if (initial) {
            if (msgs.length === 0) {
                area.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:12px;opacity:.6;">No messages yet. Say hello to your team!</div>';
            } else {
                area.innerHTML = msgs.map(m => chatBubble(m, String(m.sender_id || '') === String(myId))).join('');
                if (msgs.length) chatLastId = Math.max(...msgs.map(m => m.id));
                area.scrollTop = area.scrollHeight;
            }
        } else if (msgs.length > 0) {
            const wasAtBottom = area.scrollTop + area.clientHeight >= area.scrollHeight - 40;
            msgs.forEach(m => {
                const el = document.createElement('div');
                el.innerHTML = chatBubble(m, String(m.sender_id || '') === String(myId));
                area.appendChild(el.firstChild);
                chatLastId = Math.max(chatLastId, m.id);
            });
            if (wasAtBottom) area.scrollTop = area.scrollHeight;
        }
    } catch {}
}

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const msg   = input?.value.trim();
    if (!msg) return;
    input.value = '';
    input.style.height = 'auto';
    const fd = new FormData();
    fd.append('action', 'send_message');
    fd.append('message', msg);
    try {
        await fetch('api/system_core.php', { method: 'POST', body: fd });
        await loadChatMessages(false); // immediately fetch the new message
    } catch {}
}

// Start/stop chat polling when modal opens/closes
document.getElementById('btn-team-collab')?.addEventListener('click', () => {
    chatLastId = 0;
    clearInterval(chatPollTimer);
    loadChatMessages(true);
    chatPollTimer = setInterval(() => loadChatMessages(false), 4000);
});
document.querySelector('#modal-team-collab .close-modal')?.addEventListener('click', () => {
    clearInterval(chatPollTimer);
});

// ── Delete Production ──────────────────────────────
async function deleteProd(id, name) {
    if (!confirm(`DELETE PRODUCTION\n\nPermanently delete "${name}"?\n\nThis cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete_production');
    fd.append('prod_id', id);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') {
        const row = document.getElementById('prod-row-' + id);
        if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(() => row.remove(), 300); }
    } else { alert('Error: ' + data.message); }
}

// ── Edit Production Modal ──────────────────────────
function openEditProd(id, name, description, status, progress) {
    document.getElementById('ep-id').value          = id;
    document.getElementById('ep-name').value        = name;
    document.getElementById('ep-description').value = description;
    document.getElementById('ep-status').value      = status;
    document.getElementById('ep-progress').value    = progress;
    document.getElementById('modal-edit-production')?.classList.add('active');
}

const editProdForm = document.getElementById('form-edit-production');
if (editProdForm) {
    editProdForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn  = editProdForm.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = '⟳ SAVING...';
        const fd = new FormData(editProdForm);
        fd.append('action', 'edit_production');
        try {
            const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
            const data = safeJSON(await res.text());
            showModalAlert('ep-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') setTimeout(() => location.reload(), 1200);
        } catch { showModalAlert('ep-alert', 'Connection error.', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = orig; }
    });
}

// ── Edit Team Modal ────────────────────────────────
function openEditTeam(id, name, dept, ext) {
    document.getElementById('et-id').value   = id;
    document.getElementById('et-name').value = name;
    document.getElementById('et-dept').value = dept;
    document.getElementById('et-ext').value  = ext;
    document.getElementById('modal-edit-team')?.classList.add('active');
}

const editTeamForm = document.getElementById('form-edit-team');
if (editTeamForm) {
    editTeamForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn  = editTeamForm.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = '⟳ SAVING...';
        const fd = new FormData(editTeamForm);
        fd.append('action', 'edit_team');
        try {
            const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
            const data = safeJSON(await res.text());
            showModalAlert('et-alert', data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') setTimeout(() => location.reload(), 1200);
        } catch { showModalAlert('et-alert', 'Connection error.', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = orig; }
    });
}

// ── Resend Credentials ─────────────────────────────
async function resendCredentials(id, username, email) {
    if (!confirm(`RESEND CREDENTIALS\n\nThis will:\n• Generate a NEW temporary password for "${username}"\n• Reset their current password\n• Send the new login details to: ${email}\n\nProceed?`)) return;
    const btn = event.currentTarget;
    const orig = btn.innerHTML;
    btn.disabled = true; btn.textContent = '⟳ Sending...';
    const fd = new FormData();
    fd.append('action', 'resend_credentials');
    fd.append('target_user_id', id);
    try {
        const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
        const data = safeJSON(await res.text());
        if (data.status === 'success') {
            btn.textContent = '✓ Sent!';
            btn.style.borderColor = 'var(--neon-success)';
            btn.style.color       = 'var(--neon-success)';
            alert(data.message);
            setTimeout(() => {
                btn.innerHTML = orig;
                btn.style.borderColor = '';
                btn.style.color       = '';
                btn.disabled = false;
                lucide.createIcons();
            }, 3000);
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false; btn.innerHTML = orig; lucide.createIcons();
        }
    } catch {
        alert('Connection error.');
        btn.disabled = false; btn.innerHTML = orig; lucide.createIcons();
    }
}

// ── Delete Team ────────────────────────────────────
async function deleteTeam(id, name) {
    if (!confirm(`DELETE TEAM\n\nDelete "${name}"?\n\nAll members will be unlinked from this team.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete_team');
    fd.append('team_id', id);
    const res  = await fetch('api/system_core.php', { method: 'POST', body: fd });
    const data = safeJSON(await res.text());
    if (data.status === 'success') { location.reload(); }
    else { alert('Error: ' + data.message); }
}
</script>

<!-- ══════════════════════════════════════════════════
     Contact Us Modal
══════════════════════════════════════════════════ -->
<div id="modal-contact-us" class="modal-overlay">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h2><i data-lucide="mail" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Contact Us / Get Help</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <p style="color:var(--text-muted);font-size:12px;margin-bottom:14px;font-family:'Share Tech Mono',monospace;border-left:2px solid var(--neon-cyan);padding-left:10px;">
                <?php if ($user_role === 'user'): ?>
                Your message will be sent directly to the Sub Admin and Super Admin teams.
                <?php elseif ($user_role === 'team_moderator'): ?>
                Your message will be sent directly to the Super Admin.
                <?php elseif ($user_role === 'sub_admin'): ?>
                Your message will be sent directly to the Super Admin.
                <?php endif; ?>
            </p>
            <div id="contact-alert" class="alert"></div>
            <form id="form-contact-us">
                <div class="input-group">
                    <label>Your Name</label>
                    <input type="text" value="<?= htmlspecialchars($userProfile['full_name'] ?? $username) ?>" readonly style="opacity:.6;cursor:not-allowed;">
                </div>
                <div class="input-group">
                    <label>Subject <span style="color:var(--neon-error);">*</span></label>
                    <input type="text" name="subject" required placeholder="Brief summary of your issue or query...">
                </div>
                <div class="input-group">
                    <label>Message <span style="color:var(--neon-error);">*</span></label>
                    <textarea name="message" rows="5" required placeholder="Describe your issue, question, or request in detail..."></textarea>
                </div>
                <button type="submit" class="action-btn" style="width:100%;justify-content:center;">
                    <i data-lucide="send" style="width:14px;height:14px;"></i>
                    Send Message
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     Send Notification Modal (Admins Only)
══════════════════════════════════════════════════ -->
<?php if (in_array($user_role, ['super_admin','sub_admin','team_moderator'])): ?>
<div id="modal-send-notification" class="modal-overlay">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-warning);"><i data-lucide="megaphone" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Broadcast Notification</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="notif-send-alert" class="alert"></div>
            <form id="form-send-notification">
                <div class="input-group">
                    <label>Notification Title <span style="color:var(--neon-error);">*</span></label>
                    <input type="text" name="title" required placeholder="Short alert title...">
                </div>
                <div class="input-group">
                    <label>Message <span style="color:var(--neon-error);">*</span></label>
                    <textarea name="message" rows="4" required placeholder="Full notification message..."></textarea>
                </div>
                <div class="input-group">
                    <label>Send To</label>
                    <select name="target_roles">
                        <option value="all">Everyone (All Users & Staff)</option>
                        <option value="user">Users Only</option>
                        <?php if ($user_role === 'super_admin'): ?>
                        <option value="staff">Staff Only (Sub Admins + Team Mods)</option>
                        <option value="sub_admin">Sub Admins Only</option>
                        <option value="team_moderator">Team Moderators Only</option>
                        <?php endif; ?>
                    </select>
                </div>
                <button type="submit" class="action-btn" style="width:100%;justify-content:center;border-color:var(--neon-warning);color:var(--neon-warning);">
                    <i data-lucide="megaphone" style="width:14px;height:14px;"></i>
                    Broadcast Now
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     Contact Messages Viewer (Admins Only)
══════════════════════════════════════════════════ -->
<?php if (in_array($user_role, ['super_admin','sub_admin'])): ?>
<div id="modal-contact-messages" class="modal-overlay">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-cyan);"><i data-lucide="inbox" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>Contact Messages
                <?php if ($unreadContactCount > 0): ?><span class="badge" style="position:static;margin-left:8px;font-size:10px;"><?= $unreadContactCount ?> unread</span><?php endif; ?>
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <?php if (empty($contactMessages)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">
                <i data-lucide="inbox" style="width:32px;height:32px;display:block;margin:0 auto 12px;opacity:.4;"></i>
                No contact messages yet.
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($contactMessages as $cm): ?>
                <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(0,240,255,<?= $cm['status']==='unread'?'0.15':'0.05' ?>);border-radius:8px;padding:14px 16px;<?= $cm['status']==='unread'?'border-left:3px solid var(--neon-cyan);':'' ?>">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:6px;">
                        <div>
                            <span style="font-size:13px;font-weight:600;color:var(--text-main);"><?= htmlspecialchars($cm['subject']) ?></span>
                            <?php if ($cm['status']==='unread'): ?><span style="font-size:9px;color:var(--neon-cyan);background:rgba(0,240,255,.1);border:1px solid rgba(0,240,255,.2);border-radius:3px;padding:1px 5px;margin-left:6px;font-family:'Share Tech Mono',monospace;">NEW</span><?php endif; ?>
                        </div>
                        <span style="font-size:10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;white-space:nowrap;"><?= date('M d, H:i', strtotime($cm['created_at'])) ?></span>
                    </div>
                    <div style="font-size:11px;color:var(--neon-warning);margin-bottom:6px;font-family:'Share Tech Mono',monospace;">
                        FROM: <?= htmlspecialchars($cm['sender_name']) ?> [<?= strtoupper(str_replace('_',' ',$cm['sender_role'])) ?>]
                    </div>
                    <div style="font-size:13px;color:var(--text-muted);line-height:1.6;"><?= nl2br(htmlspecialchars($cm['message'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     Work Review Panel
══════════════════════════════════════════════════ -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
<div id="modal-work-review" class="modal-overlay">
    <div class="modal-content" style="max-width:1000px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-success);text-shadow:0 0 10px rgba(0,255,136,.4);">
                <i data-lucide="clipboard-check" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Work Review Panel
                <?php if ($user_role === 'team_moderator' && $modTeamId): ?>
                <span style="font-size:11px;color:var(--neon-cyan);margin-left:10px;font-family:'Share Tech Mono',monospace;">[YOUR TEAM]</span>
                <?php endif; ?>
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <!-- Filter tabs -->
            <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
                <?php
                $wrStatuses = ['all'=>'All','pending_review'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','featured'=>'Featured','needs_fix'=>'Needs Fix'];
                $wrColors   = ['all'=>'var(--text-muted)','pending_review'=>'var(--neon-warning)','approved'=>'var(--neon-success)','rejected'=>'var(--neon-error)','featured'=>'var(--neon-purple)','needs_fix'=>'var(--neon-cyan)'];
                foreach ($wrStatuses as $k => $label): ?>
                <button class="wr-tab action-btn <?= $k==='all'?'active':'' ?>" data-status="<?= $k ?>"
                    style="padding:4px 12px;font-size:10px;<?= $k!=='all'?'border-color:'.$wrColors[$k].';color:'.$wrColors[$k].';':'' ?>"
                    onclick="filterWorkReview('<?= $k ?>')">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if (empty($reviewSubmissions)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">
                <i data-lucide="inbox" style="width:32px;height:32px;display:block;margin:0 auto 12px;opacity:.4;"></i>
                <?= $user_role === 'team_moderator' ? 'No submissions found for your team.' : 'No work submissions found.' ?>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.08);">
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">DATE</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">MEMBER</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">PRODUCTION</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">TEAM</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">DESCRIPTION</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">PROGRESS</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">FILE</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">STATUS</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody id="work-review-tbody">
                    <?php foreach ($reviewSubmissions as $ws):
                        $wsColors = ['approved'=>'var(--neon-success)','rejected'=>'var(--neon-error)','featured'=>'var(--neon-purple)','needs_fix'=>'var(--neon-warning)','pending_review'=>'var(--text-muted)'];
                        $wsColor  = $wsColors[$ws['status']] ?? 'var(--text-muted)';
                        $wsLabel  = ucwords(str_replace('_',' ',$ws['status']));
                        $hasFile  = !empty($ws['file_path']);
                        $fileExt  = $hasFile ? strtoupper(pathinfo($ws['file_path'], PATHINFO_EXTENSION)) : '';
                    ?>
                    <tr id="work-row-<?= $ws['id'] ?>" data-status="<?= htmlspecialchars($ws['status']) ?>" style="border-bottom:1px solid rgba(255,255,255,0.04);">
                        <td style="padding:8px 10px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:11px;white-space:nowrap;"><?= date('M d, H:i', strtotime($ws['submitted_at'])) ?></td>
                        <td style="padding:8px 10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:11px;"><?= htmlspecialchars($ws['username']) ?><br><span style="color:var(--text-muted);font-size:10px;"><?= htmlspecialchars($ws['full_name']) ?></span></td>
                        <td style="padding:8px 10px;color:var(--text-main);font-size:11px;"><?= htmlspecialchars($ws['prod_name']) ?></td>
                        <td style="padding:8px 10px;color:var(--text-muted);font-size:11px;font-family:'Share Tech Mono',monospace;"><?= htmlspecialchars($ws['team_name']) ?></td>
                        <td style="padding:8px 10px;color:var(--text-main);font-size:12px;max-width:180px;">
                            <?= htmlspecialchars(substr($ws['work_description'],0,55)) ?><?= strlen($ws['work_description'])>55?'…':'' ?>
                        </td>
                        <td style="padding:8px 10px;text-align:center;min-width:70px;">
                            <div style="font-size:10px;color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;margin-bottom:3px;"><?= $ws['progress_percentage'] ?>%</div>
                            <div style="background:rgba(255,255,255,0.06);border-radius:3px;height:4px;overflow:hidden;">
                                <div style="background:var(--neon-cyan);height:100%;width:<?= $ws['progress_percentage'] ?>%;"></div>
                            </div>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <?php if ($hasFile): ?>
                            <a href="api/admin/download_work.php?id=<?= $ws['id'] ?>" download
                               style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border:1px solid var(--neon-success);color:var(--neon-success);border-radius:4px;text-decoration:none;font-size:9px;font-family:'Share Tech Mono',monospace;">
                               <i data-lucide="download" style="width:9px;height:9px;"></i> <?= $fileExt ?>
                            </a>
                            <?php else: ?>
                            <span style="color:var(--text-muted);font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <span class="work-status-cell" style="color:<?= $wsColor ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= $wsLabel ?></span>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <div style="display:flex;gap:3px;justify-content:center;flex-wrap:wrap;">
                                <button class="action-btn" style="padding:2px 8px;font-size:9px;border-color:var(--neon-cyan);color:var(--neon-cyan);" onclick="openWorkDetail(<?= $ws['id'] ?>)"><i data-lucide="eye" style="width:9px;height:9px;"></i> View</button>
                                <?php if (in_array($user_role, ['super_admin','sub_admin'])): ?>
                                <button class="action-btn" style="padding:2px 6px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);" onclick="deleteWork(<?= $ws['id'] ?>)"><i data-lucide="trash-2" style="width:9px;height:9px;"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     Work Detail / Review Modal
══════════════════════════════════════════════════ -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin', 'team_moderator'])): ?>
<div id="modal-work-detail" class="modal-overlay">
    <div class="modal-content" style="max-width:680px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-cyan);text-shadow:0 0 10px rgba(0,240,255,.3);">
                <i data-lucide="file-search" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Work Submission Detail
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body" id="work-detail-body" style="padding:0;">
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <i data-lucide="loader" style="width:24px;height:24px;display:block;margin:0 auto 10px;opacity:.4;"></i>
                Loading...
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     Production Management Panel
══════════════════════════════════════════════════ -->
<?php if (in_array($user_role, ['super_admin', 'sub_admin'])): ?>
<div id="modal-production-mgmt" class="modal-overlay">
    <div class="modal-content" style="max-width:900px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-purple);text-shadow:0 0 10px rgba(168,85,247,.4);">
                <i data-lucide="clapperboard" style="width:16px;height:16px;vertical-align:middle;margin-right:8px;"></i>
                Production Management
            </h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <?php if (empty($allProductionsFull)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;">
                <i data-lucide="clapperboard" style="width:32px;height:32px;display:block;margin:0 auto 12px;opacity:.4;"></i>
                No productions found. Create your first production using the New Production button.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.08);">
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">#</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">NAME</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">STATUS</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">PROGRESS</th>
                            <th style="text-align:left;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">CREATED</th>
                            <th style="text-align:center;padding:8px 10px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:10px;letter-spacing:1px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allProductionsFull as $pf):
                        $pfColors = ['active'=>'var(--neon-success)','planning'=>'var(--neon-cyan)','on_hold'=>'var(--neon-warning)','completed'=>'var(--neon-purple)','archived'=>'var(--text-muted)'];
                        $pfColor  = $pfColors[$pf['status']] ?? 'var(--text-muted)';
                    ?>
                    <tr id="prod-row-<?= $pf['id'] ?>" style="border-bottom:1px solid rgba(255,255,255,0.04);">
                        <td style="padding:8px 10px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:11px;">#<?= $pf['id'] ?></td>
                        <td style="padding:8px 10px;color:var(--text-main);font-weight:600;"><?= htmlspecialchars($pf['name']) ?></td>
                        <td style="padding:8px 10px;text-align:center;">
                            <span style="color:<?= $pfColor ?>;font-family:'Share Tech Mono',monospace;font-size:10px;font-weight:700;"><?= strtoupper(str_replace('_',' ',$pf['status'])) ?></span>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <div style="display:flex;align-items:center;gap:6px;justify-content:center;">
                                <div style="width:70px;height:5px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden;">
                                    <div style="width:<?= $pf['progress'] ?>%;height:100%;background:var(--neon-cyan);"></div>
                                </div>
                                <span style="color:var(--neon-cyan);font-family:'Share Tech Mono',monospace;font-size:10px;"><?= $pf['progress'] ?>%</span>
                            </div>
                        </td>
                        <td style="padding:8px 10px;font-family:'Share Tech Mono',monospace;color:var(--text-muted);font-size:11px;"><?= date('M d, Y', strtotime($pf['created_at'])) ?></td>
                        <td style="padding:8px 10px;text-align:center;white-space:nowrap;">
                            <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-cyan);color:var(--neon-cyan);"
                                onclick="openEditProd(<?= $pf['id'] ?>,'<?= htmlspecialchars($pf['name'],ENT_QUOTES) ?>','<?= htmlspecialchars($pf['description']??'',ENT_QUOTES) ?>','<?= $pf['status'] ?>',<?= $pf['progress'] ?>)">
                                <i data-lucide="edit-2" style="width:9px;height:9px;"></i> Edit
                            </button>
                            <button class="action-btn" style="padding:3px 8px;font-size:9px;border-color:var(--neon-error);color:var(--neon-error);margin-left:3px;"
                                onclick="deleteProd(<?= $pf['id'] ?>,'<?= htmlspecialchars($pf['name'],ENT_QUOTES) ?>')">
                                <i data-lucide="trash-2" style="width:9px;height:9px;"></i> Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Production Modal -->
<div id="modal-edit-production" class="modal-overlay">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-cyan);"><i data-lucide="edit-2" style="width:14px;height:14px;vertical-align:middle;margin-right:8px;"></i>Edit Production</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="ep-alert" class="alert"></div>
            <form id="form-edit-production">
                <input type="hidden" id="ep-id" name="prod_id">
                <div class="input-group">
                    <label>Production Name</label>
                    <input type="text" id="ep-name" name="name" required placeholder="Production codename">
                </div>
                <div class="input-group">
                    <label>Description</label>
                    <textarea id="ep-description" name="description" rows="3" placeholder="Synopsis or description..."></textarea>
                </div>
                <div class="input-group">
                    <label>Status</label>
                    <select id="ep-status" name="prod_status" required>
                        <option value="planning">Planning</option>
                        <option value="active">Active</option>
                        <option value="on_hold">On Hold</option>
                        <option value="completed">Completed</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Progress (%)</label>
                    <input type="number" id="ep-progress" name="progress" min="0" max="100" required placeholder="0-100">
                </div>
                <button type="submit" class="action-btn" style="width:100%;justify-content:center;border-color:var(--neon-cyan);color:var(--neon-cyan);">
                    <i data-lucide="save" style="width:14px;height:14px;"></i> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Team Modal (Super Admin Only) -->
<?php if ($user_role === 'super_admin'): ?>
<div id="modal-edit-team" class="modal-overlay">
    <div class="modal-content" style="max-width:480px;">
        <div class="modal-header">
            <h2 style="color:var(--neon-warning);"><i data-lucide="edit-2" style="width:14px;height:14px;vertical-align:middle;margin-right:8px;"></i>Edit Team</h2>
            <button class="close-modal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div id="et-alert" class="alert"></div>
            <form id="form-edit-team">
                <input type="hidden" id="et-id" name="team_id">
                <div class="input-group">
                    <label>Department</label>
                    <select id="et-dept" name="department" required>
                        <option value="Pre-Production">Pre-Production</option>
                        <option value="3D Production">3D Production</option>
                        <option value="Live Acting">Live Acting</option>
                        <option value="Post-Production">Post-Production</option>
                        <option value="Audio &amp; Sound">Audio &amp; Sound</option>
                        <option value="Cybersecurity">Cybersecurity</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Team Name</label>
                    <input type="text" id="et-name" name="team_name" required placeholder="Team name">
                </div>
                <div class="input-group">
                    <label>Allowed File Extensions (comma-separated)</label>
                    <input type="text" id="et-ext" name="allowed_extensions" required placeholder="PNG,BLEND,MP4">
                </div>
                <button type="submit" class="action-btn" style="width:100%;justify-content:center;border-color:var(--neon-warning);color:var(--neon-warning);">
                    <i data-lucide="save" style="width:14px;height:14px;"></i> Save Team Changes
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     N.A.V.I — Personal AI Assistant Widget
══════════════════════════════════════════════════ -->

<!-- Floating trigger button -->
<button class="navi-trigger" id="navi-trigger" title="N.A.V.I — Your Personal AI">
    <span class="navi-dot"></span>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2a4 4 0 0 1 4 4v1h1a3 3 0 0 1 3 3v2a3 3 0 0 1-3 3h-1v1a4 4 0 0 1-8 0v-1H7a3 3 0 0 1-3-3v-2a3 3 0 0 1 3-3h1V6a4 4 0 0 1 4-4z"/>
        <circle cx="9" cy="10" r="1" fill="currentColor"/>
        <circle cx="15" cy="10" r="1" fill="currentColor"/>
        <path d="M9 14s1 1.5 3 1.5 3-1.5 3-1.5"/>
    </svg>
</button>

<!-- AI Chat Panel -->
<div class="navi-panel" id="navi-panel">
    <div class="navi-header">
        <div class="navi-avatar">🤖</div>
        <div class="navi-header-info">
            <h4>N.A.V.I</h4>
            <p>NEXUS ARTIFICIAL VIRTUAL INTELLIGENCE • ONLINE</p>
        </div>
        <button class="navi-close-btn" id="navi-close" title="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>

    <div class="navi-messages" id="navi-messages">
        <div class="navi-msg navi-msg-ai">
            <div class="navi-msg-icon">🤖</div>
            <div class="navi-msg-bubble">
                Greetings, <strong style="color:#00f0ff"><?= htmlspecialchars($username) ?></strong>. I am <strong>N.A.V.I</strong> — your personal AI assistant inside NEXUS CORE.<br><br>
                I can help with film production, creative brainstorming, task planning, writing, research, and anything else you need. How can I assist you today?
            </div>
        </div>
    </div>

    <div class="navi-quick-prompts" id="navi-quick-prompts">
        <button class="navi-quick-btn" data-prompt="Give me tips for managing a 3D animation production pipeline">🎬 Pipeline Tips</button>
        <button class="navi-quick-btn" data-prompt="How can I improve my team's workflow and productivity?">⚡ Workflow Help</button>
        <button class="navi-quick-btn" data-prompt="Write a creative story concept for a cinematic short film">✍️ Story Ideas</button>
        <button class="navi-quick-btn" data-prompt="What are best practices for VFX and compositing?">🎆 VFX Advice</button>
    </div>

    <div class="navi-input-area">
        <textarea class="navi-input" id="navi-input" placeholder="Ask N.A.V.I anything…" rows="1"></textarea>
        <button class="navi-send-btn" id="navi-send" title="Send">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
        </button>
    </div>
    <div style="padding:0 14px 10px;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:9.5px;color:var(--text-muted);font-family:'Share Tech Mono',monospace;letter-spacing:.5px;">POWERED BY NEXUS AI ENGINE</span>
        <button class="navi-clear-btn" id="navi-clear">Clear Chat</button>
    </div>
</div>

<script>
(function() {
    const trigger  = document.getElementById('navi-trigger');
    const panel    = document.getElementById('navi-panel');
    const closeBtn = document.getElementById('navi-close');
    const input    = document.getElementById('navi-input');
    const sendBtn  = document.getElementById('navi-send');
    const msgArea  = document.getElementById('navi-messages');
    const clearBtn = document.getElementById('navi-clear');
    const quickContainer = document.getElementById('navi-quick-prompts');

    let isOpen    = false;
    let isLoading = false;
    let history   = [];

    function togglePanel() {
        isOpen = !isOpen;
        panel.classList.toggle('navi-open', isOpen);
        if (isOpen) { input.focus(); scrollBottom(); }
    }

    trigger.addEventListener('click', togglePanel);
    closeBtn.addEventListener('click', () => { isOpen = false; panel.classList.remove('navi-open'); });

    // Quick prompts
    quickContainer.querySelectorAll('.navi-quick-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            input.value = btn.dataset.prompt;
            quickContainer.style.display = 'none';
            sendMessage();
        });
    });

    // Send on Enter (Shift+Enter for newline)
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    sendBtn.addEventListener('click', sendMessage);

    // Auto-resize textarea
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 90) + 'px';
    });

    // Clear chat
    clearBtn.addEventListener('click', () => {
        history = [];
        msgArea.innerHTML = '';
        appendMsg('ai', 'Chat cleared. NEXUS core memory wiped. How can I assist you next, <strong style="color:#00f0ff"><?= htmlspecialchars($username) ?></strong>?');
        quickContainer.style.display = 'flex';
    });

    function scrollBottom() {
        setTimeout(() => { msgArea.scrollTop = msgArea.scrollHeight; }, 50);
    }

    function appendMsg(type, html) {
        const wrap = document.createElement('div');
        wrap.className = `navi-msg navi-msg-${type === 'ai' ? 'ai' : 'user'}`;
        const icon = document.createElement('div');
        icon.className = 'navi-msg-icon';
        icon.textContent = type === 'ai' ? '🤖' : '👤';
        const bubble = document.createElement('div');
        bubble.className = 'navi-msg-bubble';
        bubble.innerHTML = html;
        wrap.appendChild(icon);
        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
        scrollBottom();
        return wrap;
    }

    function showTyping() {
        const wrap = document.createElement('div');
        wrap.className = 'navi-msg navi-msg-ai navi-typing';
        wrap.id = 'navi-typing';
        wrap.innerHTML = `
            <div class="navi-msg-icon">🤖</div>
            <div class="navi-msg-bubble">
                <div class="navi-typing-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>`;
        msgArea.appendChild(wrap);
        scrollBottom();
    }

    function removeTyping() {
        const t = document.getElementById('navi-typing');
        if (t) t.remove();
    }

    function escapeHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function formatResponse(text) {
        // Convert markdown-like formatting
        return text
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/`(.+?)`/g, '<code style="background:rgba(0,240,255,0.08);padding:1px 5px;border-radius:3px;font-family:monospace;font-size:11.5px;color:#00f0ff">$1</code>')
            .replace(/^• (.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0"><span style="color:#00f0ff;flex-shrink:0">•</span><span>$1</span></div>')
            .replace(/^- (.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0"><span style="color:#00f0ff;flex-shrink:0">•</span><span>$1</span></div>')
            .replace(/\n\n/g, '<br><br>')
            .replace(/\n/g, '<br>');
    }

    async function sendMessage() {
        const text = input.value.trim();
        if (!text || isLoading) return;

        // Add to history and UI
        history.push({ role: 'user', content: text });
        appendMsg('user', escapeHtml(text));
        input.value = '';
        input.style.height = 'auto';
        quickContainer.style.display = 'none';

        isLoading = true;
        sendBtn.disabled = true;
        showTyping();

        try {
            const res  = await fetch('api/ai_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messages: history })
            });
            const data = await res.json();
            removeTyping();

            if (data.status === 'success') {
                history.push({ role: 'assistant', content: data.message });
                appendMsg('ai', formatResponse(data.message));
            } else {
                appendMsg('ai', `<span style="color:var(--neon-error)">⚠ ${escapeHtml(data.message || 'N.A.V.I encountered an error.')}</span>`);
            }
        } catch (err) {
            removeTyping();
            appendMsg('ai', '<span style="color:var(--neon-error)">⚠ Connection to N.A.V.I lost. Please try again.</span>');
        } finally {
            isLoading = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }
})();
</script>
</body>
</html>
