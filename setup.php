<?php
require_once 'api/config/session.php';
session_start();
require_once 'api/config/database.php';
use App\Config\Database;

$dbClass = new Database();
$db = $dbClass->getConnection();

// If super admin already exists, redirect to login
$existing = $db->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn();
if ($existing > 0) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEXUS CORE — Owner Initialization</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --cyan: #00f5ff;
            --cyan-dim: rgba(0,245,255,0.12);
            --red: #ff2244;
            --gold: #ffd700;
            --bg: #030810;
            --panel: #070f1a;
            --border: rgba(0,245,255,0.18);
            --text: #c8e6ff;
            --text-muted: #4a6a8a;
            --mono: 'Share Tech Mono', monospace;
            --head: 'Exo 2', sans-serif;
        }

        body {
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--head);
            overflow: hidden;
            position: relative;
        }

        /* animated grid */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(0,245,255,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,245,255,0.04) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: gridMove 20s linear infinite;
            pointer-events: none;
        }
        @keyframes gridMove { from { background-position: 0 0; } to { background-position: 50px 50px; } }

        /* corner glow blobs */
        body::after {
            content: '';
            position: fixed;
            top: -200px; left: -200px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(0,245,255,0.06) 0%, transparent 70%);
            pointer-events: none;
        }

        .setup-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 560px;
            padding: 20px;
        }

        .setup-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 40px 44px;
            position: relative;
            box-shadow: 0 0 60px rgba(0,245,255,0.06), 0 0 120px rgba(0,245,255,0.03);
        }

        /* corner accents */
        .setup-panel::before, .setup-panel::after {
            content: '';
            position: absolute;
            width: 24px; height: 24px;
            border-color: var(--cyan);
            border-style: solid;
        }
        .setup-panel::before { top: -1px; left: -1px; border-width: 2px 0 0 2px; }
        .setup-panel::after  { bottom: -1px; right: -1px; border-width: 0 2px 2px 0; }

        .badge {
            display: inline-block;
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 3px;
            color: var(--gold);
            border: 1px solid rgba(255,215,0,0.3);
            padding: 4px 10px;
            border-radius: 2px;
            margin-bottom: 18px;
            background: rgba(255,215,0,0.05);
        }

        h1 {
            font-family: var(--head);
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 3px;
            color: var(--cyan);
            text-transform: uppercase;
            margin-bottom: 4px;
            text-shadow: 0 0 20px rgba(0,245,255,0.5);
        }

        .sub {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 2px;
            margin-bottom: 28px;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--cyan), transparent);
            margin-bottom: 28px;
            opacity: 0.3;
        }

        .notice {
            background: rgba(255,215,0,0.06);
            border: 1px solid rgba(255,215,0,0.2);
            border-radius: 3px;
            padding: 12px 16px;
            margin-bottom: 28px;
            font-family: var(--mono);
            font-size: 11px;
            color: var(--gold);
            letter-spacing: 1px;
            line-height: 1.6;
        }

        .notice strong { display: block; margin-bottom: 4px; font-size: 12px; }

        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 2px;
            color: var(--text-muted);
            margin-bottom: 7px;
            text-transform: uppercase;
        }
        .field input {
            width: 100%;
            background: rgba(0,245,255,0.04);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 11px 14px;
            color: var(--text);
            font-family: var(--mono);
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .field input:focus {
            border-color: var(--cyan);
            box-shadow: 0 0 12px rgba(0,245,255,0.15);
        }
        .field input::placeholder { color: var(--text-muted); }

        .btn-setup {
            width: 100%;
            padding: 14px;
            background: transparent;
            border: 1px solid var(--cyan);
            color: var(--cyan);
            font-family: var(--mono);
            font-size: 13px;
            letter-spacing: 3px;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: 3px;
            transition: all 0.2s;
            margin-top: 6px;
            position: relative;
            overflow: hidden;
        }
        .btn-setup::before {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--cyan);
            transform: translateX(-100%);
            transition: transform 0.3s;
            z-index: 0;
        }
        .btn-setup:hover::before { transform: translateX(0); }
        .btn-setup:hover { color: var(--bg); }
        .btn-setup span { position: relative; z-index: 1; }

        .msg {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 3px;
            font-family: var(--mono);
            font-size: 12px;
            letter-spacing: 1px;
            display: none;
        }
        .msg.success { background: rgba(0,245,255,0.08); border: 1px solid rgba(0,245,255,0.3); color: var(--cyan); }
        .msg.error   { background: rgba(255,34,68,0.08);  border: 1px solid rgba(255,34,68,0.3);  color: var(--red); }
        .msg.show    { display: block; }

        .step-indicator {
            display: flex;
            gap: 6px;
            margin-bottom: 28px;
        }
        .step { height: 3px; flex: 1; background: var(--border); border-radius: 2px; }
        .step.active { background: var(--cyan); box-shadow: 0 0 8px rgba(0,245,255,0.6); }

        .scanning {
            display: inline-block;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--gold);
            box-shadow: 0 0 8px var(--gold);
            margin-right: 8px;
            animation: pulse 1s ease-in-out infinite;
        }
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:0.3;} }

        .footer-text {
            text-align: center;
            margin-top: 20px;
            font-family: var(--mono);
            font-size: 10px;
            color: var(--text-muted);
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
<div class="setup-wrap">
    <div class="setup-panel">
        <div class="badge">⬡ FIRST-TIME INITIALIZATION — OWNER SETUP</div>
        <h1>NEXUS CORE</h1>
        <div class="sub">ESTABLISH SYSTEM OWNERSHIP — LEVEL OMEGA CLEARANCE</div>

        <div class="step-indicator">
            <div class="step active"></div>
            <div class="step active"></div>
            <div class="step active"></div>
            <div class="step"></div>
        </div>

        <div class="divider"></div>

        <div class="notice">
            <strong>⬡ SYSTEM NOTICE — NEW INSTALLATION DETECTED</strong>
            No owner account exists. You are the first to access this system.
            Register your Super Admin credentials to claim ownership of NEXUS CORE.
        </div>

        <form id="setup-form">
            <div class="row">
                <div class="field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" placeholder="Your full name" required>
                </div>
                <div class="field">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="login handle" required>
                </div>
            </div>
            <div class="field">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="owner@nexuscore.sys" required>
            </div>
            <div class="row">
                <div class="field">
                    <label>Phone</label>
                    <input type="text" name="phone" placeholder="+00 000 000 0000">
                </div>
                <div class="field">
                    <label>Age</label>
                    <input type="number" name="age" placeholder="25" min="16" max="99">
                </div>
            </div>
            <div class="field">
                <label>Master Password (min 8 chars)</label>
                <input type="password" name="password" placeholder="••••••••••••" required minlength="8">
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <input type="password" name="password_confirm" placeholder="••••••••••••" required>
            </div>
            <div class="field">
                <label>Security Phrase (secret recovery phrase)</label>
                <input type="text" name="security_phrase" placeholder="e.g. Nexus Alpha Override Echo" required>
            </div>

            <button type="submit" class="btn-setup">
                <span id="btn-text"><span class="scanning"></span>INITIALIZE SYSTEM OWNERSHIP</span>
            </button>
        </form>

        <div id="msg" class="msg"></div>
    </div>
    <div class="footer-text">NEXUS CORE v2.0 · SECURE · CINEMATIC · INTELLIGENT</div>
</div>

<script>
document.getElementById('setup-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    const msg  = document.getElementById('msg');
    const btn  = document.getElementById('btn-text');

    if (data.get('password') !== data.get('password_confirm')) {
        msg.className = 'msg error show';
        msg.textContent = '⚠ Passwords do not match. Verify and retry.';
        return;
    }

    btn.innerHTML = '<span class="scanning"></span>INITIALIZING...';
    form.querySelectorAll('input,button').forEach(el => el.disabled = true);

    try {
        const res  = await fetch('/api/auth/setup.php', { method: 'POST', body: data });
        const text = await res.text();
        const json = JSON.parse(text.slice(text.indexOf('{')));

        if (json.status === 'success') {
            msg.className = 'msg success show';
            msg.textContent = '✓ ' + json.message;
            setTimeout(() => window.location.href = '/', 2200);
        } else {
            msg.className = 'msg error show';
            msg.textContent = '⚠ ' + json.message;
            form.querySelectorAll('input,button').forEach(el => el.disabled = false);
            btn.innerHTML = '<span class="scanning"></span>INITIALIZE SYSTEM OWNERSHIP';
        }
    } catch (err) {
        msg.className = 'msg error show';
        msg.textContent = '⚠ System error. Check connection.';
        form.querySelectorAll('input,button').forEach(el => el.disabled = false);
        btn.innerHTML = '<span class="scanning"></span>INITIALIZE SYSTEM OWNERSHIP';
    }
});
</script>
</body>
</html>
