'use strict';
const { esc } = require('../lib');
const { icon } = require('../layout');
const { safeUser, needsMfa } = require('../platform/auth');
const { has, ROLE_LABELS } = require('../platform/access');
const NAV = [
  ['dashboard', 'grid', 'Overview', null, 'WORKSPACE'],
  ['profile', 'users', 'My profile', 'staff.view_self'],
  ['leave', 'calendar', 'Leave', 'leave.create'],
  ['contracts', 'clipboard', 'My contracts', 'contract.view_self'],
  ['documents', 'book', 'Document library', 'document.read'],
  ['notices', 'mega', 'Notice board', 'notice.read'],
  ['calendar', 'calendar', 'Calendar & training', 'calendar.read'],
  ['requests', 'clock', 'My requests', 'request.read'],
  ['approvals', 'check', 'Approval inbox', ['leave.review', 'leave.approve', 'contract.review', 'contract.view_self', 'document.review', 'cms.review', 'cms.approve', 'media.review']],
  ['staff', 'users', 'People & HR', 'staff.manage', 'MANAGEMENT'],
  ['cms', 'pencil', 'Website content', ['cms.create', 'cms.review', 'cms.approve', 'cms.publish']],
  ['media', 'camera', 'Media library', ['media.create', 'media.review', 'media.publish']],
  ['admissions', 'badge', 'Admissions', 'admission.read'],
  ['enquiries', 'mail', 'Enquiry desk', 'submission.read'],
  ['privacy', 'shield', 'Privacy & consent', 'privacy.manage'],
  ['reports', 'growth', 'Reports', ['report.read', 'report.hr', 'system.read', 'cms.publish']],
  ['workflows', 'link', 'Approval routes', 'workflow.read', 'GOVERNANCE'],
  ['users', 'lock', 'Accounts & roles', ['user.create', 'role.grant']],
  ['audit', 'eye', 'Audit trail', 'audit.read'],
  ['system', 'chip', 'System health', 'system.read'],
  ['help', 'heart', 'Help & security', null]
];
function navigation(user, section) {
  return NAV.filter(([, , , permission]) => !permission || (Array.isArray(permission) ? permission.some(p => has(user, p)) : has(user, permission))).map(([key, ic, label, permission, group]) => `${group ? `<span class="p-nav-group">${group}</span>` : ''}<a href="/portal/${key === 'dashboard' ? '' : key}" ${section === key ? 'aria-current="page"' : ''}>${icon(ic)}<span>${label}</span>${key === 'approvals' ? '<span class="p-nav-count" data-approval-count hidden></span>' : ''}</a>`).join('');
}
function portal(ctx, actor, config) {
  const user = actor.user;
  const mode = !user ? 'login' : user.mustChangePassword ? 'password' : needsMfa(user) ? 'mfa' : 'app';
  const section = ctx.section || 'dashboard';
  const title = NAV.find(item => item[0] === section)?.[2] || (section === 'notifications' ? 'Notifications' : section === 'search' ? 'Search the platform' : 'Workspace');
  const context = { user: safeUser(user), csrf: actor.session?.csrf || '', mode, section, scannerConfigured: !!config.scanCommand, environment: config.environment };
  const encoded = JSON.stringify(context).replace(/</g, '\\u003c');
  const fields = mode === 'login' ? `
    <div data-login-credentials><div class="p-field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required autofocus placeholder="Your school username"></div>
    <div class="p-field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128" placeholder="Enter your password"></div>
    </div><div data-login-verification hidden></div>` : mode === 'password' ? `
    <div class="p-field"><label for="current-password">Current password</label><input id="current-password" type="password" name="currentPassword" autocomplete="current-password" required></div>
    <div class="p-field"><label for="new-password">New password</label><input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" required></div>
    <div class="p-field"><label for="confirm-password">Confirm new password</label><input id="confirm-password" type="password" name="confirmPassword" autocomplete="new-password" minlength="12" maxlength="128" required></div>` : `
    <ol class="p-small"><li>Open Google Authenticator, Microsoft Authenticator, or another TOTP authenticator app on your phone.</li><li>Choose <strong>Set up authenticator</strong> below. In your app, add an account using the setup key shown (time-based).</li><li>Enter the six-digit number displayed by the app. It changes every 30 seconds; it is not sent by SMS or email.</li></ol>
    <button class="p-btn p-btn-secondary" type="button" data-setup-mfa>${icon('lock')} Set up authenticator</button>
    <div id="mfa-secret" class="p-secret" hidden><span>Manual setup key · TOTP / SHA-1 / 30 seconds</span><code></code><p>Store this key securely. Do not share it or include it in support messages.</p></div>
    <div class="p-field"><label for="confirm-code">Current 6-digit code</label><input id="confirm-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></div>`;
  // Reuse the original repository's admin-login/admin-wrap/admin-side shell.
  // Authentication, MFA and API authorisation remain the governed implementation.
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#0A1E59"><title>${esc(mode === 'app' ? title : 'Staff sign in')} — Alpha School Portal</title><link rel="icon" href="/img/favicon-32.png"><link rel="manifest" href="/site.webmanifest"><link rel="stylesheet" href="/fonts/fonts.css"><link rel="stylesheet" href="/css/main.css?v=5"><link rel="stylesheet" href="/css/portal.css?v=3"></head>
<body class="${mode === 'app' ? 'p-app' : 'sec--sand p-auth'}">
<a class="skip-link" href="#workspace">Skip to workspace</a>
${mode !== 'app' ? `
<main class="container" id="workspace">
  <div class="card form admin-login">
    <div class="portal-login-brand"><img src="/img/logo-160.png" alt="Alpha Adventist School crest" width="64" height="64"><h1>${mode === 'login' ? 'Alpha School Portal' : mode === 'password' ? 'Make this account yours.' : 'Secure your account'}</h1><p class="muted">${mode === 'login' ? 'Authorised school staff and management only' : mode === 'password' ? 'Use a unique password of 12–128 characters, with uppercase, lowercase, a number and a symbol.' : 'Add your account to an authenticator app to protect school records.'}</p></div>
    <form id="auth-form" data-auth="${mode}">${fields}<p class="p-form-status" role="alert" hidden></p><button class="p-btn p-btn-primary p-full" type="submit">${mode === 'login' ? 'Sign in to workspace' : mode === 'password' ? 'Save new password' : 'Verify & secure account'} ${icon('arrow')}</button></form>
    <p class="p-small" data-framed-login hidden>If sign-in is blocked inside this preview, <a href="/portal" target="_blank" rel="noopener noreferrer">open the portal in a new tab</a>.</p>
    <p class="p-small text-center" style="margin-top:18px">Sessions expire after 30 minutes idle or 8 hours maximum. Login attempts are rate-limited.</p>
    <p class="p-small text-center"><a href="/">Back to school website</a> · <a href="/privacy">Privacy & safeguarding</a></p>
    ${mode !== 'login' ? '<button class="p-text-btn" type="button" data-logout>Sign out instead</button>' : ''}
  </div>
</main>` : `
<header class="site-head portal-header"><div class="container site-head__in"><a class="brand" href="/portal"><img class="brand__logo" src="/img/logo-96.png" alt="School crest" width="52" height="51"><span class="brand__text"><strong>Alpha School Portal</strong><small>${esc(user.roles.map(role => ROLE_LABELS[role] || role).join(' · '))}</small></span></a><div class="site-head__actions"><a class="btn btn--ghost btn--sm" href="/">View website</a><a class="p-icon-btn" href="/portal/notifications" aria-label="Notifications">${icon('mega')}<i data-notification-dot hidden></i></a><button class="btn btn--primary btn--sm" type="button" data-logout>Sign out</button></div></div></header>
<div class="admin-wrap">
  <nav class="admin-side p-sidebar" id="portal-navigation" aria-label="Workspace navigation">${navigation(user, section)}<a href="/portal/notifications">${icon('mega')} Notifications</a>${has(user, 'search') ? `<a href="/portal/search">${icon('eye')} Search the platform</a>` : ''}</nav>
  <main class="admin-main p-workspace" id="workspace" tabindex="-1"><div class="p-page-heading"><div><h1>${esc(title)}</h1><p class="p-small">${esc(user.name)} · ${esc(user.roles.map(role => ROLE_LABELS[role] || role).join(' · '))}</p></div><span class="p-date">${icon('calendar')} ${esc(new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Dar_es_Salaam' }))}</span></div><div id="page-content" aria-live="polite"><div class="p-loading">${icon('clock')} Loading your authorised workspace…</div></div></main>
</div>
<dialog class="p-dialog" id="record-dialog"><div class="p-dialog-head"><h2 id="dialog-title"></h2><button type="button" class="p-icon-btn" data-close-dialog aria-label="Close">${icon('close')}</button></div><form id="record-form"><div id="dialog-fields"></div><p class="p-form-status" role="alert" hidden></p><div class="p-dialog-actions"><button type="button" class="p-btn p-btn-secondary" data-close-dialog>Cancel</button><button type="submit" class="p-btn p-btn-primary">Save & continue ${icon('arrow')}</button></div></form></dialog>
<div class="p-toast" id="toast" role="status" hidden></div>`}
<noscript><p class="p-noscript">The secure workspace needs JavaScript. The <a href="/">public school website</a> remains available without it.</p></noscript>
<script id="portal-context" type="application/json" nonce="${esc(ctx.nonce)}">${encoded}</script><script src="/js/portal.js?v=3" defer></script>
</body></html>`;

}
module.exports = { portal };
