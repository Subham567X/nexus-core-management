<?php
require_once 'api/config/session.php';
session_start();
// If already logged in, go straight to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'api/config/database.php';
use App\Config\Database;

$dbClass = new Database();
$db      = $dbClass->getConnection();

// If no super admin exists yet, send to owner setup
$hasOwner = $db->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active'")->fetchColumn();
if (!$hasOwner) {
    header('Location: setup.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus Core | Systems Protocol</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;900&family=Inter:wght@400;500;600&family=Share+Tech+Mono&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;900&family=Inter:wght@400;500;600&family=Share+Tech+Mono&display=swap"></noscript>
    <link rel="stylesheet" href="css/style.css">
    <script src="js/vendor/lucide.min.js"></script>
</head>
<body>

    <div class="glow-overlay"></div>

    <div class="system-container">

        <!-- LEFT ZONE: Personnel Access -->
        <div class="zone" id="user-zone">
            <div class="zone-frame">
                <div class="corner-tl"></div>

                <!-- User Login -->
                <div id="view-user-login" class="form-view active">
                    <div class="zone-header">
                        <h2>Personnel Access</h2>
                        <p>Standard User Identification Protocol</p>
                    </div>
                    <div class="status-badge">
                        <span class="status-dot"></span>
                        SYSTEM ONLINE — AWAITING AUTHENTICATION
                    </div>
                    <div class="alert" id="user-login-alert"></div>
                    <form id="form-user-login">
                        <div class="input-group">
                            <label>Identity (Username or Email)</label>
                            <input type="text" name="identifier" required autocomplete="off" spellcheck="false" placeholder="Enter username or email">
                        </div>
                        <div class="input-group">
                            <label>Authorization Code</label>
                            <div class="password-wrap">
                                <input type="password" name="password" required placeholder="••••••••">
                                <i data-lucide="eye" class="eye-toggle"></i>
                            </div>
                        </div>
                        <button type="submit" class="action-btn">
                            <i data-lucide="log-in" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>
                            Initialize Connection
                        </button>
                        <a class="switch-link" data-target="view-user-forgot">
                            &#9654; Forgot Password / Username?
                        </a>
                        <a class="switch-link" data-target="view-user-register">
                            &#9654; No account? Request Clearance
                        </a>
                    </form>
                </div>

                <!-- Forgot Password -->
                <div id="view-user-forgot" class="form-view">
                    <div class="zone-header">
                        <h2>Recovery Protocol</h2>
                        <p>Password &amp; Username Recovery</p>
                    </div>
                    <div class="status-badge">
                        <span class="status-dot"></span>
                        CREDENTIAL RECOVERY TERMINAL
                    </div>
                    <div class="alert" id="user-forgot-alert"></div>
                    <form id="form-user-forgot">
                        <div class="input-group">
                            <label>Username or Email Address</label>
                            <input type="text" name="identifier" required autocomplete="off" spellcheck="false" placeholder="Enter username or email">
                        </div>
                        <button type="submit" class="action-btn">
                            <i data-lucide="key-round" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>
                            Generate Reset Link
                        </button>
                        <a class="switch-link" data-target="view-user-login">&#9664; Return to Personnel Access</a>
                    </form>
                </div>

                <!-- User Registration -->
                <div id="view-user-register" class="form-view">
                    <div class="zone-header">
                        <h2>New Personnel</h2>
                        <p>Registration &amp; Clearance Request</p>
                    </div>
                    <div class="status-badge">
                        <span class="status-dot"></span>
                        REGISTRATION TERMINAL ACTIVE
                    </div>
                    <div class="alert" id="user-register-alert"></div>
                    <form id="form-user-register">
                        <div class="input-group row">
                            <div>
                                <label>Username</label>
                                <input type="text" name="username" required autocomplete="off" placeholder="callsign">
                            </div>
                            <div>
                                <label>Full Name</label>
                                <input type="text" name="full_name" required autocomplete="off" placeholder="Full name">
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Gmail ID</label>
                            <input type="email" name="email" required placeholder="user@gmail.com">
                        </div>
                        <div class="input-group row">
                            <div>
                                <label>Phone</label>
                                <input type="text" name="phone" required placeholder="+00 000 000">
                            </div>
                            <div>
                                <label>Age</label>
                                <input type="number" name="age" required min="18" placeholder="18+">
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Password</label>
                            <div class="password-wrap">
                                <input type="password" name="password" id="reg-pwd-1" required placeholder="••••••••">
                                <i data-lucide="eye" class="eye-toggle"></i>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Confirm Password</label>
                            <div class="password-wrap">
                                <input type="password" name="password_confirm" id="reg-pwd-2" required placeholder="••••••••">
                                <i data-lucide="eye" class="eye-toggle"></i>
                            </div>
                        </div>
                        <button type="submit" class="action-btn">
                            <i data-lucide="user-plus" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>
                            Transmit Registration
                        </button>
                        <a class="switch-link" data-target="view-user-login">&#9664; Return to Personnel Access</a>
                    </form>
                </div>

            </div>
        </div>

        <!-- Divider -->
        <div class="zone-divider"></div>

        <!-- RIGHT ZONE: System Override / Admin -->
        <div class="zone" id="admin-zone">
            <div class="zone-frame">
                <div class="corner-tl"></div>

                <div class="alert" id="admin-alert" style="margin-bottom:16px;"></div>

                <div class="form-view active">
                    <div class="zone-header">
                        <h2 style="color:var(--neon-error);text-shadow:0 0 20px rgba(255,45,85,.6);">System Override</h2>
                        <p style="color:var(--neon-error);opacity:.7;">Administrator Access Only — Level 3+</p>
                    </div>
                    <div class="status-badge" style="color:var(--neon-error);">
                        <span class="status-dot" style="background:var(--neon-error);box-shadow:0 0 8px var(--neon-error);"></span>
                        RESTRICTED TERMINAL — CLEARANCE REQUIRED
                    </div>

                    <form id="form-admin-login">
                        <div class="input-group">
                            <label>Security Clearance Level</label>
                            <select name="role" required>
                                <option value="" disabled selected>Select clearance...</option>
                                <option value="super_admin">&#9632; Super Admin — Level 5</option>
                                <option value="sub_admin">&#9632; Sub Admin — Level 4</option>
                                <option value="team_moderator">&#9632; Team Moderator — Level 3</option>
                            </select>
                        </div>

                        <div class="input-group">
                            <label>Admin ID (Email or Username)</label>
                            <input type="text" name="identifier" id="admin-id" required autocomplete="off" spellcheck="false" placeholder="admin@nexuscore.sys">
                        </div>

                        <div class="input-group" id="admin-password-group">
                            <label>Master Override Code</label>
                            <div class="password-wrap">
                                <input type="password" name="password" id="admin-pwd" placeholder="••••••••">
                                <i data-lucide="eye" class="eye-toggle"></i>
                            </div>
                        </div>

                        <!-- Biometric scanner (hidden by default) -->
                        <div id="face-scanner-group" style="display:none;">
                            <div class="bio-scanner-wrap">
                                <label id="scanner-status">Biometric Scanner Inactive</label>
                                <div class="bio-ring">
                                    <i data-lucide="camera-off" id="camera-icon" style="color:var(--neon-cyan);width:36px;height:36px;position:relative;z-index:2;"></i>
                                    <video id="webcam-feed" autoplay playsinline></video>
                                    <div id="scan-laser"></div>
                                </div>
                                <span class="bio-fail-btn" id="face-scan-fail-btn">Scanner Failed? Use Security Phrase.</span>
                            </div>
                        </div>

                        <!-- Security phrase (hidden by default) -->
                        <div class="input-group" id="security-phrase-group" style="display:none;">
                            <label style="color:var(--neon-error);">Security Phrase Backup</label>
                            <div class="password-wrap">
                                <input type="password" name="security_phrase" id="admin-phrase" placeholder="Enter backup security phrase">
                                <i data-lucide="eye" class="eye-toggle"></i>
                            </div>
                        </div>

                        <div class="dual-btns">
                            <button type="button" id="toggle-face-scan" class="action-btn scan-btn">
                                <i data-lucide="scan-face" style="width:14px;height:14px;"></i> Face Scan
                            </button>
                            <button type="button" id="toggle-password-mode" class="action-btn" style="display:none;border-color:var(--text-muted);color:var(--text-muted);">
                                <i data-lucide="key" style="width:14px;height:14px;"></i> Password
                            </button>
                        </div>

                        <button type="submit" class="action-btn admin-btn">
                            <i data-lucide="shield" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>
                            Engage Override
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
</body>
</html>
