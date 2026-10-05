'use strict';
const L = require('../lib');
const A = require('./access');
const { requireReady, requireCsrf, limit } = require('./auth');
const { FORM_RULES } = require('./communications');
const { HttpError } = L;
function sameOrigin(req) {
  if (req.headers['sec-fetch-site'] === 'cross-site') throw new HttpError(403, 'Cross-site requests are not accepted.', 'ORIGIN_FAILED');
  if (req.headers.origin) {
    let origin;
    try { origin = new URL(req.headers.origin); } catch { throw new HttpError(403, 'Invalid request origin.'); }
    if (!['https:', 'http:'].includes(origin.protocol) || origin.host !== req.headers.host) throw new HttpError(403, 'Cross-site requests are not accepted.', 'ORIGIN_FAILED');
  }
}
function json(res, status, data) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Robots-Tag': 'noindex, nofollow' });
  res.end(JSON.stringify(data));
}
function sendFile(res, result, inline = false) {
  const name = result.file.name.replace(/["\r\n]/g, '_');
  res.writeHead(200, { 'Content-Type': result.file.mime, 'Content-Length': result.buffer.length, 'Content-Disposition': `${inline ? 'inline' : 'attachment'}; filename="${name}"`, 'Cache-Control': 'private, no-store, max-age=0', 'X-Content-Type-Options': 'nosniff', 'X-Robots-Tag': 'noindex, nofollow' });
  res.end(result.buffer);
}
async function handleApi(req, res, url, platform, auth) {
  const route = url.pathname;
  const verb = req.method;
  const mutating = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(verb);
  if (!['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(verb)) throw new HttpError(405, 'Method not allowed.');
  if (mutating) sameOrigin(req);
  const publicKind = route.slice(5);
  if (Object.hasOwn(FORM_RULES, publicKind) && verb === 'POST') {
    const body = await L.parseForm(req);
    const { requestIp } = require('./auth');
    const result = await platform.submitPublic({ ip: requestIp(req, platform.config), userAgent: req.headers['user-agent'] || '' }, publicKind, body);
    return json(res, 200, result);
  }
  const actor = await auth.actor(req, res, route === '/api/auth/session' && verb === 'GET');
  req.actor = actor;
  if (route === '/api/auth/session' && verb === 'GET') return json(res, 200, auth.info(actor));
  if (route.startsWith('/api/auth/')) {
    if (verb !== 'POST') throw new HttpError(405, 'Use POST for this authentication action.');
    const body = await L.parseForm(req);
    requireCsrf(actor, req, body);
    let result;
    if (route === '/api/auth/login') result = await auth.login(actor, body, req, res);
    else if (route === '/api/auth/logout') result = await auth.logout(actor, req, res);
    else if (route === '/api/auth/password') result = await auth.password(actor, body, req, res);
    else throw new HttpError(404, 'Authentication action not found.');
    return json(res, 200, { ok: true, ...result });
  }
  requireReady(actor);
  let body = {};
  if (mutating) {
    // File bodies are explicitly bounded; anonymous clients never reach here.
    const large = verb === 'POST' && (/^\/api\/(documents|contracts|media|leave)$/.test(route) || /^\/api\/documents\/[^/]+\/versions$/.test(route) || /^\/api\/my\/admissions\/[^/]+\/documents$/.test(route)) || verb === 'PUT' && /^\/api\/(contracts|leave)\/[^/]+$/.test(route);
    if (large && !await platform.store.run(tx => limit(tx, 'upload:' + actor.user.id, 30, 10 * 60 * 1000))) throw new HttpError(429, 'Too many upload attempts. Please wait before retrying.', 'RATE_LIMIT');
    body = await L.parseForm(req, large ? 7 * 1024 * 1024 : 100000);
    requireCsrf(actor, req, body);
  }
  const parts = route.split('/').filter(Boolean);
  const id = parts[2] || '';
  const action = parts[3] || '';
  let result;
  if (verb === 'GET' && /^\/api\/downloads\/[a-f0-9]{64}$/.test(route)) return sendFile(res, await platform.privateDownload(actor, id));
  if (route === '/api/my/admissions' && verb === 'GET') result = await platform.myAdmissions(actor);
  else if (route === '/api/my/admissions' && verb === 'POST') result = await platform.createMyAdmission(actor, body);
  else if (/^\/api\/my\/admissions\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.updateMyAdmission(actor, id, body);
  else if (/^\/api\/my\/admissions\/[^/]+\/submit$/.test(route) && verb === 'POST') result = await platform.submitMyAdmission(actor, id, body);
  else if (/^\/api\/my\/admissions\/[^/]+\/documents$/.test(route) && verb === 'POST') result = await platform.uploadAdmissionDocument(actor, id, body);
  else if (/^\/api\/admission-documents\/[^/]+\/download-link$/.test(route) && verb === 'POST') result = await platform.admissionDocumentGrant(actor, id);
  else if (route === '/api/dashboard' && verb === 'GET') result = await platform.dashboard(actor);
  else if (route === '/api/family' && verb === 'GET') result = await platform.family(actor);
  else if (route === '/api/students' && verb === 'GET') result = await platform.students(actor);
  else if (route === '/api/students' && verb === 'POST') result = await platform.saveStudent(actor, body);
  else if (/^\/api\/students\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.saveStudent(actor, body, id);
  else if (/^\/api\/students\/[^/]+\/guardians$/.test(route) && verb === 'POST') result = await platform.verifyGuardian(actor, id, body);
  else if (route === '/api/classes' && verb === 'GET') result = await platform.classes(actor);
  else if (route === '/api/classes' && verb === 'POST') result = await platform.saveClass(actor, body);
  else if (/^\/api\/classes\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.saveClass(actor, body, id);
  else if (route === '/api/class-teachers' && verb === 'POST') result = await platform.assignTeacher(actor, body);
  else if (route === '/api/attendance' && verb === 'GET') result = await platform.attendance(actor, url.searchParams.get('studentId') || '');
  else if (route === '/api/attendance' && verb === 'POST') result = await platform.recordAttendance(actor, body);
  else if (route === '/api/results' && verb === 'GET') result = await platform.results(actor, url.searchParams.get('studentId') || '');
  else if (route === '/api/results' && verb === 'POST') result = await platform.saveResult(actor, body);
  else if (/^\/api\/results\/[^/]+\/publish$/.test(route) && verb === 'POST') result = await platform.publishResult(actor, id, body);
  else if (route === '/api/learning-materials' && verb === 'GET') result = await platform.materials(actor);
  else if (route === '/api/learning-materials' && verb === 'POST') result = await platform.saveMaterial(actor, body);
  else if (/^\/api\/learning-materials\/[^/]+\/publish$/.test(route) && verb === 'POST') result = await platform.publishMaterial(actor, id, body);
  else if (route === '/api/family/messages' && verb === 'GET') result = await platform.familyMessages(actor, url.searchParams.get('studentId') || '');
  else if (route === '/api/family/messages' && verb === 'POST') result = await platform.sendFamilyMessage(actor, body);
  else if (route === '/api/family/contacts' && verb === 'GET') result = await platform.familyContacts(actor, url.searchParams.get('studentId') || '');
  else if (route === '/api/meta' && verb === 'GET') result = await platform.metadata(actor);
  else if (route === '/api/permissions' && verb === 'GET') result = A.permissions(actor.user);
  else if (route === '/api/roles' && verb === 'GET') { A.requirePermission(actor.user, 'role.grant'); result = A.ROLE_PERMISSIONS; }
  else if (route === '/api/users' && verb === 'GET') result = await platform.users(actor);
  else if (route === '/api/users' && verb === 'POST') result = await platform.createUser(actor, body);
  else if (/^\/api\/users\/[^/]+$/.test(route) && verb === 'PATCH') result = await platform.changeUser(actor, id, body);
  else if (route === '/api/staff' && verb === 'GET') result = await platform.staff(actor);
  else if (route === '/api/staff/directory' && verb === 'GET') result = await platform.staff(actor, true);
  else if (/^\/api\/staff\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.saveStaff(actor, id, body);
  else if (route === '/api/departments' && verb === 'GET') result = (await platform.metadata(actor)).departments;
  else if (route === '/api/leave/balances' && verb === 'GET') result = await platform.balances(actor);
  else if (route === '/api/leave/balances' && verb === 'POST') result = await platform.setBalance(actor, body);
  else if (/^\/api\/leave\/types\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.configureLeaveType(actor, action, body);
  else if (route === '/api/leave' && verb === 'GET') result = await platform.leaves(actor);
  else if (route === '/api/leave' && verb === 'POST') result = await platform.createLeave(actor, body);
  else if (/^\/api\/leave\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.editLeave(actor, id, body);
  else if (/^\/api\/leave\/[^/]+\/action$/.test(route) && verb === 'POST') result = await platform.leaveAction(actor, id, body);
  else if (route === '/api/contracts' && verb === 'GET') result = await platform.contracts(actor);
  else if (route === '/api/contracts' && verb === 'POST') result = await platform.createContract(actor, body);
  else if (/^\/api\/contracts\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.reviseContract(actor, id, body);
  else if (/^\/api\/contracts\/[^/]+\/submit$/.test(route) && verb === 'POST') result = await platform.contractSubmit(actor, id, body);
  else if (route === '/api/documents' && verb === 'GET') result = await platform.documents(actor);
  else if (route === '/api/documents' && verb === 'POST') result = await platform.createDocument(actor, body);
  else if (/^\/api\/documents\/[^/]+\/versions$/.test(route) && verb === 'POST') result = await platform.createDocument(actor, body, id);
  else if (/^\/api\/documents\/[^/]+\/action$/.test(route) && verb === 'POST') result = await platform.documentAction(actor, id, body);
  else if (/^\/api\/(documents|contracts|leave|media)\/[^/]+\/download-link$/.test(route) && verb === 'POST') result = await platform.downloadGrant(actor, { documents: 'document', contracts: 'contract', leave: 'leave', media: 'media' }[parts[1]], id);
  else if (route === '/api/approvals' && verb === 'GET') result = await platform.approvalQueue(actor);
  else if (/^\/api\/approvals\/[^/]+\/decision$/.test(route) && verb === 'POST') result = await platform.decide(actor, id, body);
  else if (route === '/api/requests' && verb === 'GET') result = await platform.requests(actor);
  else if (route === '/api/workflows' && verb === 'GET') result = await platform.definitions(actor);
  else if (route === '/api/workflows' && verb === 'POST') result = await platform.createWorkflow(actor, body);
  else if (/^\/api\/workflows\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.configureWorkflow(actor, id, body);
  else if (route === '/api/notices' && verb === 'GET') result = await platform.notices(actor);
  else if (route === '/api/notices' && verb === 'POST') result = await platform.saveNotice(actor, body);
  else if (/^\/api\/notices\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.saveNotice(actor, body, id);
  else if (/^\/api\/notices\/[^/]+\/publish$/.test(route) && verb === 'POST') result = await platform.publishNotice(actor, id, body);
  else if (/^\/api\/notices\/[^/]+\/acknowledge$/.test(route) && verb === 'POST') result = await platform.acknowledgeNotice(actor, id, body);
  else if (/^\/api\/notices\/[^/]+\/report$/.test(route) && verb === 'GET') result = await platform.noticeReport(actor, id);
  else if (route === '/api/calendar' && verb === 'GET') result = await platform.calendar(actor);
  else if (route === '/api/calendar' && verb === 'POST') result = await platform.createEvent(actor, body);
  else if (route === '/api/cms' && verb === 'GET') result = await platform.cms(actor);
  else if (route === '/api/cms' && verb === 'POST') result = await platform.saveContent(actor, body);
  else if (/^\/api\/cms\/[^/]+$/.test(route) && verb === 'PUT') result = await platform.saveContent(actor, body, id);
  else if (/^\/api\/cms\/[^/]+\/action$/.test(route) && verb === 'POST') result = await platform.contentAction(actor, id, body);
  else if (route === '/api/admissions' && verb === 'GET') result = await platform.enquiries(actor, true);
  else if (route === '/api/submissions' && verb === 'GET') result = await platform.enquiries(actor, false);
  else if (/^\/api\/(admissions|submissions)\/[^/]+$/.test(route) && verb === 'PATCH') result = await platform.changeEnquiry(actor, id, body, parts[1] === 'admissions');
  else if (route === '/api/notifications' && verb === 'GET') result = await platform.notifications(actor);
  else if (/^\/api\/notifications\/[^/]+\/read$/.test(route) && verb === 'POST') result = await platform.readNotification(actor, id);
  else if (route === '/api/privacy' && verb === 'GET') result = await platform.privacyRecords(actor);
  else if (route === '/api/privacy/consents' && verb === 'POST') result = await platform.saveConsent(actor, body);
  else if (/^\/api\/privacy\/consents\/[^/]+\/withdraw$/.test(route) && verb === 'POST') result = await platform.withdrawConsent(actor, parts[3], body);
  else if (/^\/api\/privacy\/consents\/[^/]+\/removal$/.test(route) && verb === 'POST') result = await platform.confirmConsentRemoval(actor, parts[3], body);
  else if (/^\/api\/privacy\/requests\/[^/]+$/.test(route) && verb === 'PATCH') result = await platform.resolvePrivacyRequest(actor, parts[3], body);
  else if (route === '/api/privacy/incidents' && verb === 'POST') result = await platform.createIncident(actor, body);
  else if (/^\/api\/privacy\/incidents\/[^/]+$/.test(route) && verb === 'PATCH') result = await platform.updateIncident(actor, parts[3], body);
  else if (route === '/api/media' && verb === 'GET') result = await platform.media(actor);
  else if (route === '/api/media' && verb === 'POST') result = await platform.createMedia(actor, body);
  else if (/^\/api\/media\/[^/]+\/action$/.test(route) && verb === 'POST') result = await platform.mediaAction(actor, id, body);
  else if (route === '/api/audit' && verb === 'GET') result = await platform.auditLog(actor, Object.fromEntries(url.searchParams));
  else if (route === '/api/reports' && verb === 'GET') result = await platform.reports(actor);
  else if (route === '/api/search' && verb === 'GET') result = await platform.search(actor, Object.fromEntries(url.searchParams));
  else if (route === '/api/system' && verb === 'GET') result = await platform.systemStatus(actor);
  else if (/^\/api\/system\/alerts\/[^/]+\/retry$/.test(route) && verb === 'POST') result = await platform.retryAlert(actor, parts[3]);
  else throw new HttpError(404, 'API route not found.');
  return json(res, 200, { ok: true, data: result });
}
module.exports = { handleApi, json, sendFile, sameOrigin };
