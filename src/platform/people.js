'use strict';
const L = require('../lib');
const A = require('./access');
const { audit } = require('./audit');
const W = require('./workflows');
const V = require('./validation');
const { createUserRecord, safeUser } = require('./auth');
const { HttpError } = L;
const PENDING_LEAVE = ['SUBMITTED', 'UNDER_REVIEW'];
function businessDays(start, end, excluded) {
  const first = new Date(start + 'T00:00:00Z'), last = new Date(end + 'T00:00:00Z');
  let days = 0;
  for (let time = first.getTime(); time < last.getTime(); time += 86400000) if (!excluded.includes(new Date(time).getUTCDay())) days++;
  return days;
}
async function leaveFields(tx, user, body) {
  const profile = await tx.get('staff_profiles', user.id);
  if (!profile) throw new HttpError(409, 'HR must complete your staff profile before you apply for leave.');
  const type = await tx.get('leave_types', body.typeId);
  if (!type?.enabled) throw new HttpError(400, 'Select an enabled leave type.');
  const startDate = V.date(body.startDate), returnDate = V.date(body.returnDate);
  if (returnDate <= startDate || Date.parse(returnDate) - Date.parse(startDate) > 366 * 86400000 || startDate.slice(0, 4) !== new Date(Date.parse(returnDate) - 86400000).toISOString().slice(0, 4)) throw new HttpError(400, 'The return date must follow the start date within the same leave year. Split requests across years.');
  const days = businessDays(startDate, returnDate, type.nonWorkingDays);
  if (!days) throw new HttpError(400, 'The selected dates contain no working days.');
  return { ownerId: user.id, staffId: profile.staffId, departmentId: profile.departmentId, supervisorId: profile.supervisorId, typeId: type.id, startDate, returnDate, days, reason: L.text(body.reason, 2000, true), handover: L.text(body.handover, 120, true), emergencyContact: L.text(body.emergencyContact, 100, true) };
}
async function submitLeave(tx, actor, config, record) {
  if (!['DRAFT', 'RETURNED'].includes(record.status)) throw new HttpError(409, 'Only a draft or returned request can be submitted.');
  if (record.startDate < new Date().toISOString().slice(0, 10)) throw new HttpError(400, 'Leave must start today or later.');
  const type = await tx.get('leave_types', record.typeId);
  const profile = await tx.get('staff_profiles', actor.user.id);
  record.supervisorId = profile.supervisorId; record.departmentId = profile.departmentId;
  if ((await tx.list('leave_requests')).some(item => item.id !== record.id && item.ownerId === record.ownerId && [...PENDING_LEAVE, 'APPROVED'].includes(item.status) && record.startDate < item.returnDate && record.returnDate > item.startDate)) throw new HttpError(409, 'These dates overlap an existing leave request.');
  record.balanceId = null;
  if (type.deductsBalance) {
    const id = `${record.ownerId}:${record.startDate.slice(0, 4)}:${record.typeId}`;
    const balance = await tx.get('leave_balances', id);
    if (!balance || balance.entitledDays - balance.usedDays - balance.reservedDays < record.days) throw new HttpError(409, 'Insufficient available leave. Ask HR to confirm your entitlement. Pending requests reserve their days.');
    await tx.update('leave_balances', { ...balance, reservedDays: balance.reservedDays + record.days });
    record.balanceId = id;
  }
  const flow = await W.startWorkflow(tx, actor, config, type.workflowId, record);
  record.workflowIds = [...(record.workflowIds || []), flow.id];
  record.workflowId = flow.id; record.status = 'SUBMITTED'; record.submittedAt = new Date().toISOString();
  return tx.update('leave_requests', record);
}
module.exports = {
  async users(actor) {
    return this.run(actor, async (tx, actor) => {
      if (!A.has(actor.user, 'user.create') && !A.has(actor.user, 'role.grant') && !A.has(actor.user, 'staff.manage')) throw new HttpError(403, 'Account directory is restricted.');
      await audit(tx, this.config, actor, 'user.list', 'user', 'directory');
      return (await tx.list('users')).map(safeUser);
    });
  },
  async createUser(actor, body) {
    const user = await createUserRecord(L.text(body.username, 64, true).toLowerCase(), body.name, body.roles || ['supporting_staff'], body.password);
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'user.create');
      // Technical account provisioning is not a business-role grant.
      if (!A.has(actor.user, 'role.grant') && user.roles.some(role => role !== 'supporting_staff')) throw new HttpError(403, 'Management must grant business roles after account creation.');
      if (user.roles.some(role => ['system_admin', 'ict_officer'].includes(role))) throw new HttpError(403, 'Technical roles require the operator provisioning procedure.');
      if ((await tx.list('users')).some(item => item.username === user.username)) throw new HttpError(409, 'Username is already in use.');
      const saved = await tx.insert('users', user);
      await audit(tx, this.config, actor, 'user.create', 'user', saved.id, 'success', { roles: saved.roles });
      return safeUser(saved);
    });
  },
  async changeUser(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, body.roles ? 'role.grant' : 'user.manage');
      if (id === actor.user.id) throw new HttpError(403, 'You cannot change your own account authority.');
      const user = await tx.get('users', id);
      if (!user) throw new HttpError(404, 'Account not found.');
      V.revision(user, body);
      if (user.id === actor.user.id) throw new HttpError(403, 'You cannot change your own account authority.');
      if (body.roles) {
        A.requirePermission(actor.user, 'role.grant');
        if (user.roles.some(role => ['system_admin', 'ict_officer'].includes(role))) throw new HttpError(403, 'Business roles cannot be added to technical accounts.');
        const roles = A.validRoles(body.roles);
        if (roles.some(role => ['system_admin', 'ict_officer'].includes(role))) throw new HttpError(403, 'Technical roles require operator provisioning.');
        user.roles = roles;
        user.auditScopes = roles.includes('auditor') ? V.strings(body.auditScopes, 30) : [];
        const scopes = ['user', 'staff_profile', 'leave_request', 'leave_balance', 'contract', 'document', 'document_version', 'workflow_definition', 'workflow_instance', 'notice', 'cms_content', 'admissions', 'submissions', 'calendar_event', 'media', 'media_consent', 'privacy_request', 'incident', 'api', 'outbox', 'system', 'audit_log'];
        if (user.auditScopes.some(scope => !scopes.includes(scope))) throw new HttpError(400, 'Select recognised auditor resource scopes.');
      } else {
        A.requirePermission(actor.user, 'user.manage');
        if (typeof body.active !== 'boolean') throw new HttpError(400, 'Specify the account status.');
        user.active = body.active;
      }
      const others = (await tx.list('users')).filter(item => item.id !== user.id && item.active);
      for (const essential of ['head_teacher', 'system_admin']) {
        const old = await tx.get('users', id);
        if (old.roles.includes(essential) && (!user.active || !user.roles.includes(essential)) && !others.some(item => item.roles.includes(essential))) throw new HttpError(409, 'The last institutional or technical authority cannot be removed.');
      }
      user.authVersion++;
      const saved = await tx.update('users', user);
      for (const session of await tx.list('sessions')) if (session.userId === user.id) await tx.remove('sessions', session.id);
      await audit(tx, this.config, actor, body.roles ? 'user.role_assignment' : 'user.status_change', 'user', id, 'success', { roles: saved.roles, active: saved.active });
      return safeUser(saved);
    });
  },
  async resetUserMfa(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'user.manage');
      if (id === actor.user.id) throw new HttpError(403, 'Ask another authorised account manager to reset your authenticator.');
      const user = await tx.get('users', id);
      if (!user) throw new HttpError(404, 'Account not found.');
      V.revision(user, body);
      if (!user.mfaEnabled) throw new HttpError(409, 'This account has no enrolled authenticator to reset.');
      const updated = { ...user, mfaEnabled: false, mfaResetRequired: true, authVersion: user.authVersion + 1 };
      delete updated.mfaSecretEnc;
      delete updated.lastTotpStep;
      const saved = await tx.update('users', updated);
      for (const session of await tx.list('sessions')) if (session.userId === user.id) await tx.remove('sessions', session.id);
      await audit(tx, this.config, actor, 'auth.mfa_reset', 'user', id, 'success', { reenrollmentRequired: true });
      return safeUser(saved);
    });
  },
  async staff(actor, directory = false) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'staff.view_self');
      const profiles = await tx.list('staff_profiles');
      if (directory) return profiles.map(({ id, name, departmentId, position }) => ({ id, name, departmentId, position }));
      await audit(tx, this.config, actor, 'staff.read', 'staff_profile', 'authorised');
      return profiles.filter(profile => A.canReadStaff(actor.user, profile));
    });
  },
  async saveStaff(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'staff.manage');
      const user = await tx.get('users', id);
      if (!user || !A.isStaff(user)) throw new HttpError(400, 'Choose an active staff account, not a technical-only or family account.');
      if (!await tx.get('departments', body.departmentId)) throw new HttpError(400, 'Select a department.');
      const supervisorId = L.text(body.supervisorId, 100);
      if (supervisorId) {
        const supervisor = await tx.get('users', supervisorId);
        if (supervisorId === id || !supervisor?.active || !A.has(supervisor, 'leave.review')) throw new HttpError(400, 'Choose a different authorised supervisor.');
      }
      const previous = await tx.get('staff_profiles', id);
      if (previous) V.revision(previous, body);
      const staffId = L.text(body.staffId, 40, true);
      if ((await tx.list('staff_profiles')).some(profile => profile.id !== id && profile.staffId === staffId)) throw new HttpError(409, 'Staff ID is already in use.');
      const profile = { ...(previous || {}), id, staffId, name: user.name, position: L.text(body.position, 120, true), departmentId: body.departmentId, employmentType: V.choose(body.employmentType, ['Permanent', 'Contract', 'Temporary']), employmentDate: V.date(body.employmentDate), supervisorId, email: L.text(body.email, 160), phone: L.text(body.phone, 30), updatedAt: new Date().toISOString() };
      const result = previous ? await tx.update('staff_profiles', profile) : await tx.insert('staff_profiles', profile);
      await tx.update('users', { ...user, departmentId: profile.departmentId });
      await audit(tx, this.config, actor, 'staff.update', 'staff_profile', id);
      return result;
    });
  },
  async balances(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'leave.view_self');
      return (await tx.list('leave_balances')).filter(balance => balance.userId === actor.user.id || A.has(actor.user, 'staff.manage'));
    });
  },
  async setBalance(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'staff.manage');
      if (!await tx.get('staff_profiles', body.userId) || !await tx.get('leave_types', body.typeId)) throw new HttpError(400, 'Choose a staff member and leave type.');
      if (!Number.isInteger(body.year) || body.year < 2020 || body.year > 2100 || !Number.isFinite(body.entitledDays) || body.entitledDays < 0 || body.entitledDays > 366) throw new HttpError(400, 'Enter a valid year and entitlement.');
      const id = `${body.userId}:${body.year}:${body.typeId}`;
      const old = await tx.get('leave_balances', id);
      if (old) V.revision(old, body);
      if (old && body.entitledDays < old.usedDays + old.reservedDays) throw new HttpError(409, 'Entitlement cannot be less than used and reserved days.');
      const record = { ...(old || { usedDays: 0, reservedDays: 0 }), id, userId: body.userId, year: body.year, typeId: body.typeId, entitledDays: body.entitledDays };
      const saved = old ? await tx.update('leave_balances', record) : await tx.insert('leave_balances', record);
      await audit(tx, this.config, actor, 'leave.entitlement', 'leave_balance', id, 'success', { entitledDays: body.entitledDays });
      return saved;
    });
  },
  async configureLeaveType(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'workflow.configure');
      const old = await tx.get('leave_types', id);
      if (!old) throw new HttpError(404, 'Leave type not found.');
      V.revision(old, body);
      const definition = await tx.get('workflow_definitions', body.workflowId);
      if (definition?.kind !== 'LEAVE_REQUEST' || typeof body.deductsBalance !== 'boolean' || !Array.isArray(body.nonWorkingDays) || body.nonWorkingDays.length > 6 || body.nonWorkingDays.some(day => !Number.isInteger(day) || day < 0 || day > 6)) throw new HttpError(400, 'Choose a leave route, balance rule and nonworking weekdays (0–6).');
      const saved = await tx.update('leave_types', { ...old, workflowId: definition.id, deductsBalance: body.deductsBalance, nonWorkingDays: [...new Set(body.nonWorkingDays)], enabled: body.enabled !== false });
      await audit(tx, this.config, actor, 'leave.type_configure', 'leave_type', id);
      return saved;
    });
  },
  async leaves(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'leave.view_self');
      const records = (await tx.list('leave_requests')).filter(record => A.canReadLeave(actor.user, record)).map(V.safeRecord);
      await audit(tx, this.config, actor, 'leave.read', 'leave_request', 'authorised');
      return records;
    });
  },
  async createLeave(actor, body) {
    A.requirePermission(actor.user, 'leave.create');
    const file = body.file ? await this.files.prepare(body.file) : null;
    try {
      return await this.run(actor, async (tx, actor) => {
        A.requirePermission(actor.user, 'leave.create');
        const fields = await leaveFields(tx, actor.user, body);
        const id = L.rid('LEV-');
        let saved = await tx.insert('leave_requests', { id, reference: `LEV-${new Date().getFullYear()}-${id.slice(-8).toUpperCase()}`, ...fields, file, createdBy: actor.user.id, createdAt: new Date().toISOString(), status: 'DRAFT' });
        await audit(tx, this.config, actor, 'leave.create', 'leave_request', id);
        if (body.submit) saved = await submitLeave(tx, actor, this.config, saved);
        return V.safeRecord(saved);
      });
    } catch (error) { await this.files.discard(file); throw error; }
  },
  async editLeave(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'leave.create');
      const record = await tx.get('leave_requests', id);
      if (!record || record.ownerId !== actor.user.id) throw new HttpError(403, 'This leave request is not yours.');
      V.revision(record, body);
      if (!['DRAFT', 'RETURNED'].includes(record.status)) throw new HttpError(409, 'Only draft or returned leave can be edited.');
      const saved = await tx.update('leave_requests', { ...record, ...(await leaveFields(tx, actor.user, body)) });
      await audit(tx, this.config, actor, 'leave.edit', 'leave_request', id);
      return V.safeRecord(saved);
    });
  },
  async leaveAction(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'leave.create');
      const record = await tx.get('leave_requests', id);
      if (!record || record.ownerId !== actor.user.id) throw new HttpError(403, 'This leave request is not yours.');
      V.revision(record, body);
      if (body.action === 'SUBMIT') return V.safeRecord(await submitLeave(tx, actor, this.config, record));
      if (body.action !== 'CANCEL' || !['DRAFT', 'RETURNED', ...PENDING_LEAVE, 'APPROVED'].includes(record.status)) throw new HttpError(409, 'This request cannot be cancelled.');
      if (record.status === 'APPROVED' && record.startDate <= new Date().toISOString().slice(0, 10)) throw new HttpError(409, 'Ask HR about leave that has already started.');
      if (record.balanceId && [...PENDING_LEAVE, 'APPROVED'].includes(record.status)) {
        const balance = await tx.get('leave_balances', record.balanceId);
        if (record.status === 'APPROVED') balance.usedDays -= record.days; else balance.reservedDays -= record.days;
        await tx.update('leave_balances', balance);
      }
      if (record.workflowId) {
        const workflow = await tx.get('workflow_instances', record.workflowId);
        if (workflow.status === 'PENDING') await tx.update('workflow_instances', { ...workflow, status: 'CANCELLED' });
      }
      const saved = await tx.update('leave_requests', { ...record, status: 'CANCELLED' });
      await audit(tx, this.config, actor, 'leave.cancel', 'leave_request', id);
      return V.safeRecord(saved);
    });
  },
  async contracts(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'contract.view_self');
      const records = [];
      for (const record of await tx.list('contracts')) {
        const instance = record.workflowId && await tx.get('workflow_instances', record.workflowId);
        if (A.canReadContract(actor.user, record, instance && instance.status === 'PENDING' && W.eligible(actor.user, instance, record))) records.push(V.safeRecord(record));
      }
      await audit(tx, this.config, actor, 'contract.read', 'contract', 'authorised');
      return records;
    });
  },
  async createContract(actor, body) {
    A.requirePermission(actor.user, 'contract.create');
    const file = await this.files.prepare(body.file);
    try {
      return await this.run(actor, async (tx, actor) => {
        A.requirePermission(actor.user, 'contract.create');
        const employee = await tx.get('staff_profiles', body.ownerId);
        if (!employee) throw new HttpError(400, 'Choose a staff profile.');
        const startDate = V.date(body.startDate), endDate = V.date(body.endDate);
        if (endDate <= startDate) throw new HttpError(400, 'Contract expiry must follow its start date.');
        const previous = body.replacesId ? await tx.get('contracts', body.replacesId) : null;
        if (body.replacesId && (!previous || previous.ownerId !== employee.id || !['ACTIVE', 'EXPIRED'].includes(previous.status))) throw new HttpError(409, 'Only the same employee’s active or expired contract can be renewed.');
        const id = L.rid('CTR-');
        const saved = await tx.insert('contracts', { id, reference: `CTR-${new Date().getFullYear()}-${id.slice(-8).toUpperCase()}`, title: L.text(body.title, 160, true), ownerId: employee.id, departmentId: employee.departmentId, supervisorId: employee.supervisorId, startDate, endDate, file, financialReview: body.financialReview === true, status: 'DRAFT', createdBy: actor.user.id, createdAt: new Date().toISOString(), replacesId: previous?.id || '', version: (previous?.version || 0) + 1 });
        await audit(tx, this.config, actor, 'contract.create', 'contract', id);
        return V.safeRecord(saved);
      });
    } catch (error) { await this.files.discard(file); throw error; }
  },
  async reviseContract(actor, id, body) {
    A.requirePermission(actor.user, 'contract.create');
    const file = await this.files.prepare(body.file);
    try {
      return await this.run(actor, async (tx, actor) => {
        A.requirePermission(actor.user, 'contract.create');
        const record = await tx.get('contracts', id);
        if (!record || record.createdBy !== actor.user.id) throw new HttpError(403, 'Only the HR author can revise this contract.');
        V.revision(record, body);
        if (!['DRAFT', 'RETURNED'].includes(record.status)) throw new HttpError(409, 'Issued or pending contracts cannot be overwritten. Draft a renewal instead.');
        const startDate = V.date(body.startDate), endDate = V.date(body.endDate);
        if (endDate <= startDate || body.ownerId !== record.ownerId) throw new HttpError(400, 'Keep the same employee and valid contract dates.');
        const previousVersions = [...(record.previousVersions || []), { version: record.version, file: record.file, title: record.title, startDate: record.startDate, endDate: record.endDate, workflowId: record.workflowId || '', supersededAt: new Date().toISOString() }];
        const saved = await tx.update('contracts', { ...record, title: L.text(body.title, 160, true), startDate, endDate, financialReview: body.financialReview === true, file, previousVersions, version: record.version + 1, status: 'DRAFT', workflowId: '', approvedBy: '', approvedAt: '' });
        await audit(tx, this.config, actor, 'contract.revise', 'contract', id, 'success', { version: saved.version });
        return V.safeRecord(saved);
      });
    } catch (error) { await this.files.discard(file); throw error; }
  },
  async contractSubmit(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'contract.create');
      const record = await tx.get('contracts', id);
      if (!record || record.createdBy !== actor.user.id) throw new HttpError(403, 'Only the HR author can submit this draft.');
      V.revision(record, body);
      if (!['DRAFT', 'RETURNED'].includes(record.status)) throw new HttpError(409, 'This contract is already under review or issued.');
      const flow = await W.startWorkflow(tx, actor, this.config, 'contract-standard', record);
      return V.safeRecord(await tx.update('contracts', { ...record, status: 'UNDER_REVIEW', workflowId: flow.id, workflowIds: [...(record.workflowIds || []), flow.id] }));
    });
  }
};
