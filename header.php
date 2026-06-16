<?php require_login(); $u = current_user(); $flash = get_flash(); ?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digifyce HRMS</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%231e3a5f'/><text x='50%25' y='54%25' dominant-baseline='middle' text-anchor='middle' font-family='Plus Jakarta Sans,sans-serif' font-weight='800' font-size='13' fill='%233b82f6'>HR</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── HRMS Design System: Navy + Electric Blue ─────────────────── */
        :root {
            --sidebar-w: 250px;
            --sidebar-bg:    #0f1729;
            --sidebar-bdr:   rgba(255,255,255,.07);
            --sidebar-text:  #94a3b8;
            --sidebar-hover: rgba(59,130,246,.12);
            --sidebar-active:#1e3a5f;
            --sidebar-act-c: #60a5fa;
            --topbar-bg:     #ffffff;
            --topbar-bdr:    #e2e8f0;
            --body-bg:       #f0f4f8;
            --card-bg:       #ffffff;
            --card-bdr:      #e2e8f0;
            --text-primary:  #0f172a;
            --text-secondary:#475569;
            --text-muted:    #94a3b8;
            --primary:       #3b82f6;
            --primary-d:     #2563eb;
            --primary-bg:    #eff6ff;
            --primary-bdr:   #bfdbfe;
            --font: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            --r: 10px; --r2: 7px;
        }
        [data-theme="dark"] {
            --topbar-bg:     #111827;
            --topbar-bdr:    #1e2a3a;
            --body-bg:       #0a0f1e;
            --card-bg:       #111827;
            --card-bdr:      #1e2a3a;
            --text-primary:  #f1f5f9;
            --text-secondary:#94a3b8;
            --text-muted:    #475569;
            --primary-bg:    rgba(59,130,246,.1);
            --primary-bdr:   rgba(59,130,246,.25);
        }
        * { box-sizing: border-box; }
        body {
            background: var(--body-bg);
            color: var(--text-primary);
            font-family: var(--font);
            font-size: 14px;
            transition: background .2s, color .2s;
        }
        /* ── Sidebar ─────────────────────────────────────────────────── */
        #sidebar {
            width: var(--sidebar-w); height: 100vh;
            background: var(--sidebar-bg);
            position: fixed; top: 0; left: 0; z-index: 1000;
            display: flex; flex-direction: column;
            border-right: 1px solid var(--sidebar-bdr);
            box-shadow: 4px 0 24px rgba(0,0,0,.18);
            transition: transform 0.3s, width 0.3s;
        }
        #sidebar .brand {
            padding: 18px 18px 15px;
            border-bottom: 1px solid var(--sidebar-bdr);
            flex-shrink: 0;
        }
        #sidebar .brand-name { color: #fff; margin: 0; font-weight: 800; font-size: 1rem; letter-spacing: -.3px; }
        #sidebar .brand-name em { color: #3b82f6; font-style: normal; }
        #sidebar .brand-user { color: #64748b; font-size: 0.72rem; margin-top: 2px; }
        #sidebar-nav { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 10px 0; }
        #sidebar-nav::-webkit-scrollbar { width: 3px; }
        #sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 3px; }
        #sidebar .nav-section {
            padding: 12px 18px 4px;
            color: #334155; font-size: 0.62rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
        }
        #sidebar .nav-link {
            color: var(--sidebar-text);
            border-radius: var(--r2); padding: 9px 14px; margin: 1px 8px;
            font-size: 0.84rem; font-family: var(--font); font-weight: 500;
            display: flex; align-items: center; gap: 10px; transition: all 0.15s;
            text-decoration: none;
        }
        #sidebar .nav-link i { font-size: 0.95rem; width: 16px; text-align: center; flex-shrink: 0; opacity: .8; }
        #sidebar .nav-link:hover {
            background: var(--sidebar-hover);
            color: #e2e8f0;
        }
        #sidebar .nav-link:hover i { opacity: 1; }
        #sidebar .nav-link.active {
            background: var(--sidebar-active);
            color: var(--sidebar-act-c);
            font-weight: 600;
        }
        #sidebar .nav-link.active i { opacity: 1; color: var(--sidebar-act-c); }
        #sidebar .sidebar-footer {
            flex-shrink: 0; padding: 12px;
            border-top: 1px solid var(--sidebar-bdr);
            display: flex; align-items: center; gap: 6px;
        }
        #sidebar .sidebar-footer .nav-link {
            flex: 1; margin: 0;
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.04);
        }
        #sidebar .sidebar-footer .nav-link:hover {
            background: rgba(239,68,68,.15);
            color: #fca5a5; border-color: rgba(239,68,68,.3);
        }
        #btn-hrms-theme {
            width: 34px; height: 34px; border-radius: var(--r2);
            border: 1px solid rgba(255,255,255,.1);
            background: rgba(255,255,255,.05); color: #94a3b8;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background .12s, color .12s; flex-shrink: 0;
        }
        #btn-hrms-theme:hover { background: rgba(59,130,246,.2); color: #60a5fa; }
        /* ── Topbar ──────────────────────────────────────────────────── */
        #topbar {
            margin-left: var(--sidebar-w);
            background: var(--topbar-bg);
            padding: 12px 24px;
            border-bottom: 1px solid var(--topbar-bdr);
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 999;
            transition: margin-left 0.3s, background .2s;
        }
        #topbar .page-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
        /* ── Main ────────────────────────────────────────────────────── */
        #main {
            margin-left: var(--sidebar-w); padding: 24px;
            transition: margin-left 0.3s;
        }
        /* Override Bootstrap cards to use design tokens */
        .card {
            background: var(--card-bg) !important;
            border-color: var(--card-bdr) !important;
            color: var(--text-primary) !important;
            border-radius: var(--r) !important;
        }
        .card-body { color: var(--text-primary) !important; }
        .text-muted { color: var(--text-muted) !important; }
        .fw-semibold, .fw-bold { color: var(--text-primary); }
        /* Table dark mode */
        [data-theme="dark"] .table { color: var(--text-primary); }
        [data-theme="dark"] .table > :not(caption) > * > * {
            background-color: var(--card-bg);
            border-bottom-color: var(--card-bdr);
            color: var(--text-primary);
        }
        [data-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: rgba(255,255,255,.03);
        }
        /* Inputs dark mode */
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            background-color: var(--card-bg);
            border-color: var(--card-bdr);
            color: var(--text-primary);
        }
        [data-theme="dark"] .form-control::placeholder { color: var(--text-muted); }
        /* ── HRMS Stat Cards ─────────────────────────────────────────── */
        .hrms-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .hrms-stat-grid-6 { grid-template-columns: repeat(6, 1fr); }
        .hrms-stat-grid-4 { grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 768px) {
            .hrms-stat-grid-6 { grid-template-columns: repeat(3, 1fr); }
            .hrms-stat-grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .hrms-stat-grid-6 { grid-template-columns: repeat(2, 1fr); }
            .hrms-stat-grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        .hrms-stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-bdr);
            border-radius: var(--r);
            padding: 16px 18px;
            position: relative;
            overflow: hidden;
            transition: transform .15s, box-shadow .15s;
        }
        .hrms-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,.10);
        }
        .hrms-stat-card::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 3px;
            border-radius: var(--r) var(--r) 0 0;
        }
        .hrms-stat-card.c-blue::before   { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .hrms-stat-card.c-amber::before  { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .hrms-stat-card.c-teal::before   { background: linear-gradient(90deg, #0d9488, #2dd4bf); }
        .hrms-stat-card.c-green::before  { background: linear-gradient(90deg, #16a34a, #4ade80); }
        .hrms-stat-card.c-red::before    { background: linear-gradient(90deg, #dc2626, #f87171); }
        .hrms-stat-card.c-purple::before { background: linear-gradient(90deg, #7c3aed, #a78bfa); }
        .hrms-stat-icon {
            position: absolute; top: 14px; right: 14px;
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
        }
        .hrms-stat-card.c-blue   .hrms-stat-icon { background: #dbeafe; color: #2563eb; }
        .hrms-stat-card.c-amber  .hrms-stat-icon { background: #fef3c7; color: #d97706; }
        .hrms-stat-card.c-teal   .hrms-stat-icon { background: #ccfbf1; color: #0d9488; }
        .hrms-stat-card.c-green  .hrms-stat-icon { background: #dcfce7; color: #16a34a; }
        .hrms-stat-card.c-red    .hrms-stat-icon { background: #fee2e2; color: #dc2626; }
        .hrms-stat-card.c-purple .hrms-stat-icon { background: #ede9fe; color: #7c3aed; }
        [data-theme="dark"] .hrms-stat-card.c-blue   .hrms-stat-icon { background: rgba(59,130,246,.15); color: #60a5fa; }
        [data-theme="dark"] .hrms-stat-card.c-amber  .hrms-stat-icon { background: rgba(245,158,11,.15); color: #fbbf24; }
        [data-theme="dark"] .hrms-stat-card.c-teal   .hrms-stat-icon { background: rgba(13,148,136,.15); color: #2dd4bf; }
        [data-theme="dark"] .hrms-stat-card.c-green  .hrms-stat-icon { background: rgba(22,163,74,.15);  color: #4ade80; }
        [data-theme="dark"] .hrms-stat-card.c-red    .hrms-stat-icon { background: rgba(220,38,38,.15);  color: #f87171; }
        [data-theme="dark"] .hrms-stat-card.c-purple .hrms-stat-icon { background: rgba(124,58,237,.15); color: #a78bfa; }
        .hrms-stat-label {
            font-size: 10px; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .3px; margin-bottom: 6px;
            line-height: 1.3; padding-right: 28px;
        }
        .hrms-stat-value {
            font-size: 28px; font-weight: 800; letter-spacing: -1px;
            line-height: 1; color: var(--text-primary);
        }
        .hrms-stat-card.c-blue   .hrms-stat-value { color: #2563eb; }
        .hrms-stat-card.c-amber  .hrms-stat-value { color: #d97706; }
        .hrms-stat-card.c-teal   .hrms-stat-value { color: #0d9488; }
        .hrms-stat-card.c-green  .hrms-stat-value { color: #16a34a; }
        .hrms-stat-card.c-red    .hrms-stat-value { color: #dc2626; }
        .hrms-stat-card.c-purple .hrms-stat-value { color: #7c3aed; }
        [data-theme="dark"] .hrms-stat-card.c-blue   .hrms-stat-value { color: #60a5fa; }
        [data-theme="dark"] .hrms-stat-card.c-amber  .hrms-stat-value { color: #fbbf24; }
        [data-theme="dark"] .hrms-stat-card.c-teal   .hrms-stat-value { color: #2dd4bf; }
        [data-theme="dark"] .hrms-stat-card.c-green  .hrms-stat-value { color: #4ade80; }
        [data-theme="dark"] .hrms-stat-card.c-red    .hrms-stat-value { color: #f87171; }
        [data-theme="dark"] .hrms-stat-card.c-purple .hrms-stat-value { color: #a78bfa; }
        /* ── HRMS Section headings upgrade ──────────────────────────── */
        .hrms-section-head {
            font-size: 13px; font-weight: 700; color: var(--text-primary);
            letter-spacing: -.2px;
            display: flex; align-items: center; gap: 8px; margin-bottom: 14px;
        }
        .hrms-section-head i { color: var(--primary); font-size: 1rem; }
        /* ── Greeting block ──────────────────────────────────────────── */
        .hrms-greeting {
            margin-bottom: 20px;
        }
        .hrms-greeting-title {
            font-size: 20px; font-weight: 800; letter-spacing: -.5px; color: var(--text-primary);
        }
        .hrms-greeting-sub {
            font-size: 12px; color: var(--text-muted); margin-top: 2px;
        }
        /* ── Task item cards ─────────────────────────────────────────── */
        .hrms-task-item {
            background: var(--body-bg);
            border: 1px solid var(--card-bdr);
            border-radius: 8px; padding: 10px 14px;
            transition: border-color .15s, box-shadow .15s;
        }
        .hrms-task-item:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(59,130,246,.10);
        }
        [data-theme="dark"] .hrms-task-item { background: rgba(255,255,255,.03); }
        /* ── Nav section labels upgrade ─────────────────────────────── */
        #sidebar .nav-section {
            padding: 14px 18px 5px;
            color: #475569;
            font-size: 0.62rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.4px;
            position: relative;
        }
        #sidebar .nav-section::before {
            content: '';
            display: inline-block;
            width: 14px; height: 2px;
            background: rgba(59,130,246,.5);
            border-radius: 2px;
            margin-right: 6px;
            vertical-align: middle;
        }
        /* Badge override */
        .badge-role { font-size: 0.65rem; padding: 3px 8px; border-radius: 20px; }

        /* ── Inline edit ─────────────────────────────────────────────── */
        td[data-editable]:not([data-readonly="1"]) {
            cursor: pointer;
            transition: background .1s;
        }
        td[data-editable]:not([data-readonly="1"]):hover {
            background: var(--primary-bg) !important;
        }
        td[data-editable]:not([data-readonly="1"]):hover::after {
            content: ' ✎';
            font-size: .7rem;
            color: var(--primary);
            opacity: .6;
        }
        td.ie-editing { background: #eff6ff !important; padding: 2px 4px !important; }
        [data-theme="dark"] td.ie-editing { background: rgba(59,130,246,.12) !important; }
        .ie-input {
            width: 100%; min-width: 80px;
            border: 1.5px solid var(--primary);
            border-radius: 5px;
            padding: 4px 8px;
            font-size: 13px;
            font-family: var(--font);
            background: #fff;
            color: #0f172a;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }
        [data-theme="dark"] .ie-input { background: #1e2a3a; color: #f1f5f9; border-color: #3b82f6; }
        .ie-input:focus { box-shadow: 0 0 0 3px rgba(59,130,246,.25); }
        select.ie-input { cursor: pointer; }

        /* Save state flashes */
        @keyframes ie-save-flash { 0%,100%{background:transparent} 30%{background:#d1fae5} }
        @keyframes ie-err-flash  { 0%,100%{background:transparent} 30%{background:#fee2e2} }
        td.ie-saved  { animation: ie-save-flash 1.4s ease; }
        td.ie-error  { animation: ie-err-flash  2s   ease; }
        td.ie-saving { opacity: .6; pointer-events: none; }
        td.ie-saving::before { content:''; display:inline-block; width:10px; height:10px; border:2px solid #3b82f6; border-top-color:transparent; border-radius:50%; animation:spin .6s linear infinite; margin-right:4px; vertical-align:middle; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* ── Table scroll with visual hint ──────────────────────────── */
        .ie-scroll-wrap {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y;
            position: relative;
            border-radius: var(--r);
            will-change: scroll-position;
        }
        /* Fade-right gradient when more content is scrollable */
        .ie-scroll-wrap.ie-has-more::after {
            content: '';
            position: sticky;
            right: 0;
            top: 0;
            display: block;
            width: 40px;
            height: 100%;
            pointer-events: none;
            background: linear-gradient(to right, transparent, rgba(255,255,255,.85));
            float: right;
            margin-top: -100%;
        }
        [data-theme="dark"] .ie-scroll-wrap.ie-has-more::after {
            background: linear-gradient(to right, transparent, rgba(17,24,39,.85));
        }
        /* Scroll hint label */
        .ie-scroll-hint {
            display: none;
            text-align: right;
            font-size: .68rem;
            color: var(--text-muted);
            padding: 3px 8px 0;
        }
        .ie-has-more + .ie-scroll-hint,
        .ie-scroll-wrap.ie-has-more ~ .ie-scroll-hint { display: block; }
        /* Alert */
        [data-theme="dark"] .alert { border-color: var(--card-bdr) !important; }
        body.sidebar-closed #sidebar { width: 0; overflow: hidden; }
        body.sidebar-closed #topbar, body.sidebar-closed #main { margin-left: 0; }

        /* ── Mobile overlay ──────────────────────────────────────────── */
        #sidebar-overlay {
            display: none; position: fixed; inset: 0; z-index: 999;
            background: rgba(0,0,0,.5); backdrop-filter: blur(2px);
        }
        #sidebar-overlay.open { display: block; }

        @media (max-width: 768px) {
            /* Sidebar — off screen by default */
            #sidebar {
                width: 260px !important;
                transform: translateX(-100%);
                transition: transform .25s cubic-bezier(.4,0,.2,1);
                z-index: 1000;
            }
            #sidebar.open { transform: translateX(0); }

            /* Content — no left margin */
            #topbar, #main { margin-left: 0 !important; }

            /* Topbar — better mobile layout */
            #topbar {
                padding: 10px 14px;
                position: sticky; top: 0; z-index: 998;
            }
            #topbar .page-title { font-size: 14px; }

            /* Hamburger — always show on mobile */
            #topbar .d-md-none { display: flex !important; }

            /* Main content — comfortable mobile padding, no clipping */
            #main {
                padding: 14px 12px 40px;
                overflow-x: hidden; /* clip at page level, not at table level */
            }
            /* Cards holding tables must not clip horizontal scroll */
            .card { overflow: visible !important; }
            .card-body { overflow: visible !important; }

            /* Notification dropdown — full width */
            .notif-dropdown {
                position: fixed !important;
                left: 8px !important; right: 8px !important;
                top: 58px !important;
                width: auto !important;
            }

            /* Cards — tighter on mobile */
            .card { margin-bottom: 12px !important; }

            /* Tables — horizontal scroll with full touch support */
            .table-responsive {
                overflow-x: auto !important;
                overflow-y: visible !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-x pan-y !important;
                will-change: scroll-position;
                border-radius: var(--r);
                /* Prevent parent clipping */
                max-width: 100%;
                display: block;
            }
            table.table {
                font-size: 12px;
                min-width: 600px;
                width: 100%;
            }
            table.table th, table.table td { padding: 8px 10px !important; white-space: nowrap; }

            /* Bootstrap grid on mobile */
            .col-md-3, .col-md-4, .col-md-6, .col-md-8, .col-md-9 { width: 100% !important; }

            /* Stat grid — 2 columns */
            .hrms-stat-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .hrms-stat-value { font-size: 22px; }

            /* Buttons — minimum 44px tap target on mobile */
            .btn, button, [type="button"], [type="submit"] {
                min-height: 44px;
            }
            /* Keep small utility buttons (icon-only) from inflating */
            .btn-sm { min-height: 36px; }
            .btn-xs, .notif-bell-wrap button, #btn-hrms-theme { min-height: unset; }

            /* Full width on mobile forms */
            .btn-mobile-full { width: 100% !important; }

            /* Forms — prevent iOS zoom */
            input, select, textarea {
                font-size: 16px !important;
            }

            /* Task detail — full width action buttons */
            .task-action-row { flex-direction: column; gap: 8px; }
            .task-action-row .btn { width: 100%; }

            /* Hide name text in topbar, keep avatar */
            #topbar .d-none.d-md-inline { display: none !important; }

            /* Role badge — smaller */
            .badge-role { font-size: .6rem !important; padding: 2px 6px !important; }
        }

        @media (max-width: 380px) {
            #main { padding: 10px 10px 32px; }
            .hrms-stat-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .hrms-stat-value { font-size: 18px; }
            .hrms-stat-card { padding: 12px 10px; }
        }
    </style>
    <script>
        // Apply theme before render to avoid flash
        (function(){
            var t = localStorage.getItem('hrms-theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <script src="assets/hconfirm.js?v=1"></script>
    <!-- Firebase push notifications -->
    <script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js"></script>
    <script>
    firebase.initializeApp({
      apiKey: "AIzaSyBZvX1GGbvo0Ofyaxk32U2f1mc0SPlwUmY",
      authDomain: "digifyce-internal.firebaseapp.com",
      projectId: "digifyce-internal",
      storageBucket: "digifyce-internal.firebasestorage.app",
      messagingSenderId: "802656832683",
      appId: "1:802656832683:web:2b31977bdc5e184723fbee"
    });
    (async function initHrmsPush() {
      if (!('serviceWorker' in navigator) || !('Notification' in window)) return;
      try {
        const isLocal = location.hostname === 'localhost';
        const swPath  = isLocal ? '/hrms/php_implementation/firebase-messaging-sw.js' : '/firebase-messaging-sw.js';
        const swScope = isLocal ? '/hrms/php_implementation/' : '/';
        const reg  = await navigator.serviceWorker.register(swPath, { scope: swScope });
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') return;
        const messaging = firebase.messaging();
        const token = await messaging.getToken({
          vapidKey: 'BHlEkWxJtWAjYPPxknVthzKmDtZTaqeMnVK4IqGhlTbkUfS7n541-N1xwyTBcTjjW7HktEURToUz2t8Sn3TN6Xs',
          serviceWorkerRegistration: reg,
        });
        if (!token) return;
        fetch('push_register.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, action: 'register' }),
        });
        // Foreground messages → show a toast/alert
        messaging.onMessage(payload => {
          const n = payload.notification || {};
          const title = n.title || 'HRMS';
          const body  = n.body  || '';
          // Use HRMS flash-style notification if available, otherwise plain
          if (typeof set_flash_js === 'function') set_flash_js(title, body);
          else console.info('[HRMS Push]', title, body);
        });
      } catch(e) { console.debug('HRMS Push init:', e.message); }
    })();
    </script>
</head>
<body>

<!-- Mobile sidebar overlay -->
<div id="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<div id="sidebar">
    <div class="brand">
        <div class="d-flex align-items-center gap-2">
            <div style="width:34px;height:34px;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <img src="assets/images/logo.PNG" alt="Digifyce" style="width:34px;height:34px;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <span style="display:none;width:34px;height:34px;background:linear-gradient(135deg,#1e3a5f,#3b82f6);border-radius:8px;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;letter-spacing:-.5px;font-family:'Plus Jakarta Sans',sans-serif;">HR</span>
            </div>
            <div>
                <div class="brand-name">Digi<em>HRMS</em></div>
                <div class="brand-user"><?= sanitize($u['name']) ?></div>
            </div>
        </div>
    </div>

    <div id="sidebar-nav">

    <!-- ── Main ──────────────────────────────────────── -->
    <div class="nav-section">Main</div>
    <a href="index.php" class="nav-link <?= ($page ?? '') === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-grid-1x2-fill"></i> Dashboard
    </a>

    <!-- ── Work (all logged-in users) ────────────────── -->
    <div class="nav-section">Work</div>
    <?php
    $my_emp = $conn->prepare("SELECT id FROM employees WHERE email = ? LIMIT 1");
    $my_emp->execute([$u['email']]);
    $my_emp_row = $my_emp->fetch();
    $my_emp_id  = $my_emp_row ? $my_emp_row['id'] : null;
    ?>
    <?php if ($my_emp_id): ?>
    <a href="employee_profile.php?id=<?= $my_emp_id ?>" class="nav-link <?= ($page ?? '') === 'my_profile' ? 'active' : '' ?>">
        <i class="bi bi-person-circle"></i> My Profile
    </a>
    <?php endif; ?>
    <a href="tasks.php" class="nav-link <?= ($page ?? '') === 'tasks' ? 'active' : '' ?>">
        <i class="bi bi-list-task"></i> Tasks
    </a>
    <?php
    // Show approval badge count in sidebar
    $appr_count = 0;
    if (!empty($u) && !in_array($u['role'] ?? '', ['HR_ADMIN'])) {
        try {
            $is_tl_nav = in_array($u['role'] ?? '', ['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']);
            if ($is_tl_nav) {
                $ac = $conn->prepare("SELECT COUNT(*) FROM task_approvals ta JOIN tasks t ON t.id=ta.task_id WHERE ta.status='pending' AND t.deleted_at IS NULL");
                if ($u['role'] !== 'SUPER_ADMIN') {
                    $tm_ids = $conn->prepare("SELECT user_id FROM team_hierarchy WHERE tl_id=?");
                    $tm_ids->execute([$u['id']]);
                    $tids = array_column($tm_ids->fetchAll(), 'user_id');
                    $tids[] = $u['id'];
                    $in_str = implode(',', array_map('intval', $tids));
                    $ac = $conn->query("SELECT COUNT(*) FROM task_approvals ta JOIN tasks t ON t.id=ta.task_id WHERE ta.status='pending' AND t.deleted_at IS NULL AND (t.assigned_to IN ($in_str) OR t.assigned_by={$u['id']})");
                } else {
                    $ac = $conn->query("SELECT COUNT(*) FROM task_approvals ta JOIN tasks t ON t.id=ta.task_id WHERE ta.status='pending' AND t.deleted_at IS NULL");
                }
                $appr_count = (int)$ac->fetchColumn();
            } else {
                $ac2 = $conn->prepare("SELECT COUNT(*) FROM task_approvals WHERE submitted_by=? AND status IN ('pending','rework')");
                $ac2->execute([$u['id']]);
                $appr_count = (int)$ac2->fetchColumn();
            }
        } catch (Exception $e) { $appr_count = 0; }
    }
    ?>
    <a href="tasks.php?tab=approvals" class="nav-link <?= ($page ?? '') === 'tasks' && ($_GET['tab'] ?? '') === 'approvals' ? 'active' : '' ?>" style="<?= $appr_count ? 'color:#a78bfa;' : '' ?>">
        <i class="bi bi-patch-check<?= $appr_count ? '-fill' : '' ?>"></i> Approvals
        <?php if ($appr_count): ?>
        <span style="margin-left:auto;background:#7c3aed;color:#fff;border-radius:10px;padding:1px 8px;font-size:.65rem;font-weight:700;"><?= $appr_count ?></span>
        <?php endif; ?>
    </a>
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER','TEAM_LEAD')): ?>
    <a href="my_team.php" class="nav-link <?= ($page ?? '') === 'my_team' ? 'active' : '' ?>">
        <i class="bi bi-people-fill"></i> My Team
    </a>
    <a href="projects.php" class="nav-link <?= ($page ?? '') === 'projects' ? 'active' : '' ?>">
        <i class="bi bi-folder2-open"></i> Projects
    </a>
    <?php endif; ?>
    <a href="workflows.php" class="nav-link <?= ($page ?? '') === 'workflows' ? 'active' : '' ?>">
        <i class="bi bi-diagram-3"></i> Workflows
    </a>
    <a href="triggers.php" class="nav-link <?= ($page ?? '') === 'triggers' ? 'active' : '' ?>">
        <i class="bi bi-lightning-charge"></i> Triggers
    </a>
    <a href="learning.php" class="nav-link <?= ($page ?? '') === 'learning' ? 'active' : '' ?>">
        <i class="bi bi-mortarboard-fill"></i> Learning
    </a>
    <a href="leaves.php" class="nav-link <?= ($page ?? '') === 'leaves' ? 'active' : '' ?>">
        <i class="bi bi-calendar-check"></i> Leave &amp; Permissions
    </a>
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
    <a href="teamlogger.php" class="nav-link <?= ($page ?? '') === 'teamlogger' ? 'active' : '' ?>">
        <i class="bi bi-activity"></i> TeamLogger
    </a>
    <?php endif; ?>

    <!-- ── People (HR/Admin/Manager only) ────────────── -->
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER')): ?>
    <div class="nav-section">People</div>
    <a href="employees.php" class="nav-link <?= ($page ?? '') === 'employees' ? 'active' : '' ?>">
        <i class="bi bi-person-badge"></i> Employees
    </a>
    <a href="departments.php" class="nav-link <?= ($page ?? '') === 'departments' ? 'active' : '' ?>">
        <i class="bi bi-building"></i> Departments
    </a>
    <a href="roles.php" class="nav-link <?= ($page ?? '') === 'roles' ? 'active' : '' ?>">
        <i class="bi bi-tags"></i> Job Roles
    </a>
    <a href="points.php" class="nav-link <?= ($page ?? '') === 'points' ? 'active' : '' ?>">
        <i class="bi bi-star-fill"></i> Team Points
    </a>
    <?php endif; ?>
    <?php if (has_role('TEAM_LEAD')): ?>
    <div class="nav-section">My Team</div>
    <a href="my_team.php" class="nav-link <?= ($page ?? '') === 'my_team' ? 'active' : '' ?>">
        <i class="bi bi-people-fill"></i> My Team
    </a>
    <a href="projects.php" class="nav-link <?= ($page ?? '') === 'projects' ? 'active' : '' ?>">
        <i class="bi bi-folder2-open"></i> Projects
    </a>
    <a href="points.php" class="nav-link <?= ($page ?? '') === 'points' ? 'active' : '' ?>">
        <i class="bi bi-star-fill"></i> Team Points
    </a>
    <?php endif; ?>

    <!-- ── Recruitment (HR/Admin/Manager only) ────────── -->
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER')): ?>
    <div class="nav-section">Recruitment</div>
    <a href="jobs.php" class="nav-link <?= ($page ?? '') === 'jobs' ? 'active' : '' ?>">
        <i class="bi bi-briefcase"></i> Job Postings
    </a>
    <a href="applications.php" class="nav-link <?= ($page ?? '') === 'applications' ? 'active' : '' ?>">
        <i class="bi bi-inbox"></i> Applications
    </a>
    <a href="requests.php" class="nav-link <?= ($page ?? '') === 'requests' ? 'active' : '' ?>">
        <i class="bi bi-file-earmark-text"></i> Manpower Requests
    </a>
    <a href="candidates.php" class="nav-link <?= ($page ?? '') === 'candidates' ? 'active' : '' ?>">
        <i class="bi bi-person-lines-fill"></i> Candidates
    </a>
    <a href="interviews.php" class="nav-link <?= ($page ?? '') === 'interviews' ? 'active' : '' ?>">
        <i class="bi bi-camera-video"></i> Interviews
    </a>
    <?php endif; ?>
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
    <a href="portals.php" class="nav-link <?= ($page ?? '') === 'portals' ? 'active' : '' ?>">
        <i class="bi bi-plug"></i> Portal Connections
    </a>
    <a href="import_portals.php" class="nav-link <?= ($page ?? '') === 'import_portals' ? 'active' : '' ?>">
        <i class="bi bi-cloud-download"></i> Import from Portals
    </a>
    <?php endif; ?>

    <!-- ── Reports (HR/Admin/Manager only) ───────────── -->
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER')): ?>
    <div class="nav-section">Reports</div>
    <a href="reports.php" class="nav-link <?= ($page ?? '') === 'reports' ? 'active' : '' ?>">
        <i class="bi bi-bar-chart-line"></i> Reports
    </a>
    <?php endif; ?>

    <!-- ── Admin (SUPER_ADMIN only) ──────────────────── -->
    <?php if (has_role('SUPER_ADMIN')): ?>
    <div class="nav-section">Admin</div>
    <a href="users.php" class="nav-link <?= ($page ?? '') === 'users' ? 'active' : '' ?>">
        <i class="bi bi-shield-lock"></i> User Management
    </a>
    <?php endif; ?>
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
    <a href="hr_settings.php" class="nav-link <?= ($page ?? '') === 'hr_settings' ? 'active' : '' ?>">
        <i class="bi bi-gear-wide-connected"></i> HR Settings
    </a>
    <a href="api_keys.php" class="nav-link <?= ($page ?? '') === 'api_keys' ? 'active' : '' ?>">
        <i class="bi bi-key"></i> API Keys
    </a>
    <a href="api_docs.php" target="_blank" class="nav-link">
        <i class="bi bi-braces"></i> API Docs
    </a>
    <?php endif; ?>

    </div><!-- #sidebar-nav -->

    <div class="sidebar-footer">
        <button id="btn-hrms-theme" onclick="toggleHrmsTheme()" title="Toggle dark mode">
            <i class="bi bi-moon" id="hrms-theme-icon"></i>
        </button>
        <a href="logout.php" class="nav-link" style="margin:0;">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</div>

<script>
function toggleHrmsTheme() {
    var current = document.documentElement.getAttribute('data-theme') || 'light';
    var next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('hrms-theme', next);
    var icon = document.getElementById('hrms-theme-icon');
    if (icon) {
        icon.className = next === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
    }
}
// Set correct icon on load
(function(){
    var t = localStorage.getItem('hrms-theme') || 'light';
    var icon = document.getElementById('hrms-theme-icon');
    if (icon) icon.className = t === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
})();
</script>

<?php
// Fetch unread notifications for current user
$notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 20");
$notif_stmt->execute([$u['id']]);
$notifs = $notif_stmt->fetchAll();
$notif_count = count($notifs);

// Mark-read via AJAX endpoint (inline)
if (isset($_GET['mark_notifs_read'])) {
    $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$u['id']]);
    echo json_encode(['ok'=>true]); exit;
}
?>
<style>
.notif-bell-wrap { position:relative; }
.notif-badge { position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;border-radius:50%;width:17px;height:17px;font-size:.62rem;font-weight:700;display:flex;align-items:center;justify-content:center;line-height:1; }
.notif-dropdown { position:absolute;right:0;top:calc(100% + 8px);width:340px;border-radius:12px;z-index:2000;overflow:hidden;max-height:420px;overflow-y:auto; }
.notif-dropdown .notif-hdr { padding:11px 16px;display:flex;justify-content:space-between;align-items:center; }
.notif-dropdown .notif-hdr span { font-weight:700;font-size:.85rem; }
.notif-dropdown .notif-hdr a { font-size:.74rem;text-decoration:none; }
.notif-item { display:flex;gap:10px;padding:10px 16px;transition:background .1s;text-decoration:none;color:inherit; }
.notif-item:hover { background:var(--primary-bg) !important; }
.notif-item.unread { background:rgba(245,158,11,.05); }
.notif-icon { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem; }
.notif-icon.block_request { background:#fee2e2;color:#b91c1c; }
.notif-icon.block_resolved { background:#dcfce7;color:#166534; }
.notif-icon.unblocked { background:#dcfce7;color:#166534; }
.notif-icon.default { background:var(--primary-bg);color:var(--primary); }
.notif-body-text { font-size:.76rem;margin-top:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.notif-time { font-size:.67rem;color:var(--text-muted);margin-top:2px;white-space:nowrap; }
.notif-empty { padding:24px 16px;text-align:center;font-size:.82rem; }
</style>

<!-- Topbar -->
<div id="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-sm d-md-none" onclick="toggleSidebar()"
                style="border:1px solid var(--card-bdr);background:var(--card-bg);color:var(--text-primary);border-radius:7px;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-list" style="font-size:1.1rem;"></i>
        </button>
        <h6 class="mb-0 page-title"><?= $pageTitle ?? 'Dashboard' ?></h6>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge badge-role"
              style="background:var(--primary-bg);color:var(--primary);border:1px solid var(--primary-bdr);font-weight:600;">
            <?= sanitize($u['role']) ?>
        </span>

        <!-- Notification Bell -->
        <div class="notif-bell-wrap" id="notifWrap">
            <button id="notifBtn" onclick="toggleNotifs(event)"
                    style="width:34px;height:34px;border-radius:7px;border:1px solid var(--card-bdr);background:var(--card-bg);color:var(--text-secondary);position:relative;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .12s;">
                <i class="bi bi-bell<?= $notif_count ? '-fill' : '' ?>" style="font-size:.95rem;<?= $notif_count ? 'color:#f59e0b;' : '' ?>"></i>
                <?php if ($notif_count): ?>
                <span class="notif-badge"><?= $notif_count > 9 ? '9+' : $notif_count ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown" style="display:none;background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,.15);">
                <div class="notif-hdr" style="border-bottom:1px solid var(--card-bdr);">
                    <span style="color:var(--text-primary);">Notifications <?php if($notif_count):?><span style="color:#ef4444;font-size:.75rem;">(<?= $notif_count ?> new)</span><?php endif;?></span>
                    <?php if ($notif_count): ?>
                    <a href="#" onclick="markAllRead(event)" style="color:var(--primary);">Mark all read</a>
                    <?php endif; ?>
                </div>
                <?php if (!$notifs): ?>
                <div class="notif-empty" style="color:var(--text-muted);"><i class="bi bi-bell-slash d-block mb-2" style="font-size:1.5rem;"></i>No new notifications</div>
                <?php else: ?>
                <?php foreach ($notifs as $n):
                    $icon_class = in_array($n['type'], ['block_request','block_resolved','unblocked']) ? $n['type'] : 'default';
                    $icon_map = ['block_request'=>'slash-circle-fill','block_resolved'=>'check-circle-fill','unblocked'=>'shield-check-fill','default'=>'bell-fill'];
                    $icon = $icon_map[$n['type']] ?? 'bell-fill';
                ?>
                <a href="<?= sanitize($n['link'] ?? '#') ?>" class="notif-item unread" onclick="markAllRead(event, this.href)"
                   style="border-bottom:1px solid var(--card-bdr);">
                    <div class="notif-icon <?= $icon_class ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.8rem;font-weight:600;color:var(--text-primary);"><?= sanitize($n['title']) ?></div>
                        <div class="notif-body-text" style="color:var(--text-secondary);"><?= sanitize($n['body'] ?? '') ?></div>
                        <div class="notif-time"><?= date('d M, h:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- User avatar + account dropdown -->
        <div class="position-relative" id="userMenuWrap">
            <button onclick="toggleUserMenu(event)"
                    style="background:none;border:none;padding:0;cursor:pointer;display:flex;align-items:center;gap:8px;">
                <div style="width:32px;height:32px;background:linear-gradient(135deg,#1e3a5f,#3b82f6);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.8rem;flex-shrink:0;">
                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                </div>
                <span class="small fw-semibold d-none d-md-inline" style="color:var(--text-primary);"><?= sanitize($u['name']) ?></span>
                <i class="bi bi-chevron-down d-none d-md-inline" style="font-size:.65rem;color:var(--text-muted);"></i>
            </button>
            <div id="userMenuDropdown" style="display:none;position:absolute;right:0;top:calc(100% + 8px);min-width:200px;background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.15);z-index:2001;overflow:hidden;">
                <div style="padding:10px 14px;border-bottom:1px solid var(--card-bdr);">
                    <div class="small fw-semibold" style="color:var(--text-primary);"><?= sanitize($u['name']) ?></div>
                    <div style="font-size:.72rem;color:var(--text-muted);"><?= sanitize($u['email']) ?></div>
                </div>
                <a href="change_password.php" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text-secondary);text-decoration:none;font-size:.84rem;transition:background .12s;" onmouseover="this.style.background='var(--primary-bg)'" onmouseout="this.style.background=''">
                    <i class="bi bi-shield-lock" style="color:var(--primary);"></i> Change Password
                </a>
                <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:#dc2626;text-decoration:none;font-size:.84rem;border-top:1px solid var(--card-bdr);transition:background .12s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background=''">
                    <i class="bi bi-box-arrow-left"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebar-overlay');
    var open = s.classList.toggle('open');
    o.classList.toggle('open', open);
    document.body.style.overflow = open ? 'hidden' : '';
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('open');
    document.body.style.overflow = '';
}
function toggleUserMenu(e) {
    e.stopPropagation();
    var d = document.getElementById('userMenuDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function() {
    var d = document.getElementById('userMenuDropdown');
    if (d) d.style.display = 'none';
});

// Persist sidebar scroll position across page navigations
document.addEventListener('DOMContentLoaded', function() {
    var nav = document.getElementById('sidebar-nav');

    // Restore scroll position
    var saved = sessionStorage.getItem('hrms_sidebar_scroll');
    if (saved && nav) nav.scrollTop = parseInt(saved, 10);

    // Save scroll position before any nav link click
    document.querySelectorAll('#sidebar-nav .nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (nav) sessionStorage.setItem('hrms_sidebar_scroll', nav.scrollTop);
        });
    });

    // Also scroll the active link into view if it's off-screen
    var active = document.querySelector('#sidebar-nav .nav-link.active');
    if (active && nav && !saved) {
        var linkTop    = active.offsetTop;
        var navHeight  = nav.clientHeight;
        var linkHeight = active.clientHeight;
        if (linkTop + linkHeight > navHeight) {
            nav.scrollTop = linkTop - navHeight / 2 + linkHeight / 2;
        }
    }
});
function toggleNotifs(e) {
    e.stopPropagation();
    const d = document.getElementById('notifDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
}
function markAllRead(e, href) {
    e && e.preventDefault();
    fetch('?mark_notifs_read=1').then(() => {
        document.querySelectorAll('.notif-item').forEach(el => el.classList.remove('unread'));
        const badge = document.querySelector('.notif-badge');
        if (badge) badge.remove();
        const bell = document.querySelector('#notifBtn i');
        if (bell) { bell.className = 'bi bi-bell'; bell.style.color = ''; }
        const hdr = document.querySelector('.notif-hdr a');
        if (hdr) hdr.remove();
        if (href) window.location.href = href;
        else document.getElementById('notifDropdown').style.display = 'none';
    });
}
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('notifDropdown').style.display = 'none';
    }
});
</script>

<!-- Main Content -->
<div id="main">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
