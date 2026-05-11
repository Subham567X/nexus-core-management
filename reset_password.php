<?php
require_once 'api/config/session.php';
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'api/config/database.php';
use App\Config\Database;

$token = trim($_GET['token'] ?? '');
$tokenValid = false;

if ($token) {
    $db = (new Database())->getConnection();
    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $stmt = $db->prepare("SELECT prt.*, u.username FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE prt.token = ? AND prt.expires_at > datetime('now')");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    $tokenValid = (bool)$row;
    $tokenUsername = $row['username'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus Core | Password Reset</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;900&family=Inter:wght@400;500;600&family=Share+Tech+Mono&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;900&family=Inter:wght@400;500;600&family=Share+Tech+Mono&display=swap"></noscript>
    <link rel="stylesheet" href="css/style.css">
    <script src="js/vendor/lucide.min.js"></script>
    <style>
        .reset-container {
            position: relative;
            z-index: 1;
            width: min(480px, 96vw);
            padding: 40px 20px;
        }
        .reset-back {
            display: block;
            margin-top: 20px;
            text-align: center;
            font-size: 11px;
            color: var(--neon-cyan);
            cursor: pointer;
            letter-spacing: 1px;
            font-family: 'Share Tech Mono', monospace;
            text-decoration: none;
            opacity: .7;
            transition: all .2s;
        }
        .reset-back:hover { opacity: 1; text-shadow: 0 0 10px var(--neon-cyan); }
        .token-invalid {
            text-align: center;
            padding: 20px 0;
        }
        .token-invalid p {
            color: var(--neon-error);
            font-family: 'Share Tech Mono', monospace;
            font-size: 13px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="glow-overlay"></div>
    <div class="reset-container">
        <div class="zone-frame">
            <div class="corner-tl"></div>
            <?php if (!$token || !$tokenValid): ?>
            <div class="zone-header">
                <h2>Reset Failed</h2>
                <p>Authorization Link Error</p>
            </div>
            <div class="token-invalid">
                <p>&#9888; This reset link is invalid or has expired.</p>
                <p style="color:var(--text-muted);font-size:11px;">Reset links are valid for 1 hour only.</p>
            </div>
            <a href="index.php" class="reset-back">&#9664; Return to Login</a>
            <?php else: ?>
            <div class="zone-header">
                <h2>Set New Password</h2>
                <p>Credential Reset Protocol — <?= htmlspecialchars($tokenUsername) ?></p>
            </div>
            <div class="status-badge">
                <span class="status-dot"></span>
                SECURE RESET TERMINAL ACTIVE
            </div>
            <div class="alert" id="reset-alert"></div>
            <form id="form-reset-password">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="input-group">
                    <label>New Authorization Code</label>
                    <div class="password-wrap">
                        <input type="password" name="password" id="reset-pwd-1" required placeholder="••••••••" minlength="8">
                        <i data-lucide="eye" class="eye-toggle"></i>
                    </div>
                </div>
                <div class="input-group">
                    <label>Confirm New Code</label>
                    <div class="password-wrap">
                        <input type="password" name="password_confirm" id="reset-pwd-2" required placeholder="••••••••" minlength="8">
                        <i data-lucide="eye" class="eye-toggle"></i>
                    </div>
                </div>
                <button type="submit" class="action-btn">
                    <i data-lucide="shield-check" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>
                    Update Credentials
                </button>
            </form>
            <a href="index.php" class="reset-back">&#9664; Return to Login</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();

        document.querySelectorAll('.eye-toggle').forEach(eye => {
            eye.addEventListener('click', function () {
                const input = this.previousElementSibling;
                if (!input) return;
                if (input.type === 'password') {
                    input.type = 'text';
                    this.setAttribute('data-lucide', 'eye-off');
                } else {
                    input.type = 'password';
                    this.setAttribute('data-lucide', 'eye');
                }
                lucide.createIcons();
            });
        });

        const form = document.getElementById('form-reset-password');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertEl = document.getElementById('reset-alert');
            const pwd1 = document.getElementById('reset-pwd-1')?.value;
            const pwd2 = document.getElementById('reset-pwd-2')?.value;

            const showAlert = (msg, type) => {
                alertEl.textContent = msg;
                alertEl.className = 'alert alert-' + type;
                alertEl.style.display = 'block';
            };

            if (pwd1 !== pwd2) {
                showAlert('⚠ Passwords do not match.', 'error');
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            const origHTML = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '⟳ UPDATING...'; }

            try {
                const res  = await fetch('api/auth/reset_password.php', { method: 'POST', body: new FormData(form) });
                const text = await res.text();
                const i = text.indexOf('{');
                const data = JSON.parse(i !== -1 ? text.slice(i) : text);

                showAlert(data.status === 'success' ? '✓ ' + data.message : '⚠ ' + data.message,
                          data.status === 'success' ? 'success' : 'error');

                if (data.status === 'success') {
                    form.style.display = 'none';
                    setTimeout(() => { window.location.href = 'index.php'; }, 2000);
                } else {
                    if (btn) { btn.disabled = false; btn.innerHTML = origHTML; }
                }
            } catch {
                showAlert('⚠ Connection error. Please try again.', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = origHTML; }
            }
        });
    });
    </script>
</body>
</html>
