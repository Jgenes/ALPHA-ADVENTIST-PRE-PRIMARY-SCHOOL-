'use strict';
const L = require('../lib');
const A = require('./access');
const V = require('./validation');
const { audit } = require('./audit');
const { HttpError } = L;

async function assignedClassIds(tx, user) {
  return new Set((await tx.list('class_teachers')).filter(item => item.teacherUserId === user.id && item.active !== false).map(item => item.classId));
}
async function visibleStudents(tx, user) {
  const profiles = (await tx.list('student_profiles')).filter(item => item.active !== false);
  if (A.has(user, 'student.manage')) return profiles;
  if (user.roles.includes('student')) return profiles.filter(item => item.userId === user.id);
  if (user.roles.includes('parent')) {
    const links = await tx.list('student_guardians');
    const ids = new Set(links.filter(item => item.guardianUserId === user.id && item.status === 'VERIFIED' && item.verifiedAt).map(item => item.studentId));
    return profiles.filter(item => ids.has(item.id));
  }
  if (user.roles.includes('teacher')) {
    const classIds = await assignedClassIds(tx, user);
    return profiles.filter(item => classIds.has(item.classId));
  }
  return [];
}
async function requireStudentAccess(tx, user, studentId, action = 'student.read') {
  A.requirePermission(user, action);
  const student = await tx.get('student_profiles', studentId);
  if (!student || student.active === false) throw new HttpError(404, 'Learner record not found.');
  if (A.has(user, 'student.manage')) return student;
  const allowed = (await visibleStudents(tx, user)).some(item => item.id === student.id);
  if (!allowed) throw new HttpError(403, 'This learner record is outside your assigned scope.');
  return student;
}
async function requireTeacherAssignment(tx, user, classId) {
  if (A.has(user, 'student.manage')) return;
  if (!user.roles.includes('teacher') || !(await assignedClassIds(tx, user)).has(classId)) throw new HttpError(403, 'You are not assigned to this classroom.');
}
async function userClassIds(tx, user, students) {
  if (A.has(user, 'class.manage')) return new Set((await tx.list('classes')).map(item => item.id));
  if (user.roles.includes('teacher')) return assignedClassIds(tx, user);
  return new Set(students.map(item => item.classId));
}
function studentView(student, classRecord) {
  return { id: student.id, studentRef: student.studentRef, userId: student.userId, name: student.name, classId: student.classId, className: classRecord?.name || '', yearLevel: student.yearLevel || classRecord?.yearLevel || '', active: student.active !== false, revision: student.revision };
}

module.exports = {
  async students(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.read');
      const [profiles, classes] = await Promise.all([visibleStudents(tx, actor.user), tx.list('classes')]);
      const byId = new Map(classes.map(item => [item.id, item]));
      return profiles.map(item => studentView(item, byId.get(item.classId)));
    });
  },
  async classes(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.read');
      const classes = await tx.list('classes');
      if (A.has(actor.user, 'student.manage')) return classes;
      if (actor.user.roles.includes('teacher')) {
        const ids = await assignedClassIds(tx, actor.user);
        return classes.filter(item => ids.has(item.id));
      }
      const students = await visibleStudents(tx, actor.user);
      const ids = new Set(students.map(item => item.classId));
      return classes.filter(item => ids.has(item.id));
    });
  },
  async saveClass(actor, body, id = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'class.manage');
      const old = id && await tx.get('classes', id);
      if (id && !old) throw new HttpError(404, 'Class not found.');
      if (old) V.revision(old, body);
      const classId = id || L.text(body.id, 40, true);
      if (!/^[a-z0-9][a-z0-9._-]{1,39}$/.test(classId)) throw new HttpError(400, 'Use a 2–40 character lowercase class ID.');
      const record = { ...(old || {}), id: classId, name: L.text(body.name, 100, true), yearLevel: L.text(body.yearLevel, 80, true), active: body.active !== false, updatedAt: new Date().toISOString() };
      const saved = old ? await tx.update('classes', record) : await tx.insert('classes', record);
      await audit(tx, this.config, actor, old ? 'class.update' : 'class.create', 'class', classId);
      return saved;
    });
  },
  async assignTeacher(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'class.manage');
      const classRecord = await tx.get('classes', body.classId);
      const teacher = await tx.get('users', body.teacherUserId);
      if (!classRecord || !teacher?.active || !teacher.roles.includes('teacher')) throw new HttpError(400, 'Choose an existing class and active teacher account.');
      const old = (await tx.list('class_teachers')).find(item => item.classId === classRecord.id && item.teacherUserId === teacher.id);
      if (old) {
        V.revision(old, body);
        const saved = await tx.update('class_teachers', { ...old, active: body.active !== false, updatedAt: new Date().toISOString() });
        await audit(tx, this.config, actor, 'class.teacher_assign', 'class', classRecord.id);
        return saved;
      }
      const saved = await tx.insert('class_teachers', { id: L.rid('CTA-'), classId: classRecord.id, teacherUserId: teacher.id, active: true, assignedBy: actor.user.id, assignedAt: new Date().toISOString() });
      await audit(tx, this.config, actor, 'class.teacher_assign', 'class', classRecord.id);
      return saved;
    });
  },
  async saveStudent(actor, body, id = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.manage');
      const old = id && await tx.get('student_profiles', id);
      if (id && !old) throw new HttpError(404, 'Learner record not found.');
      if (old) V.revision(old, body);
      const user = await tx.get('users', body.userId);
      const classRecord = await tx.get('classes', body.classId);
      const studentRef = L.text(body.studentRef, 80, true).toUpperCase();
      if (!user?.active || !user.roles.includes('student') || !classRecord?.active || !/^[A-Z0-9-]{3,80}$/.test(studentRef)) throw new HttpError(400, 'Choose an active student account, an active class, and a valid private learner reference.');
      if ((await tx.list('student_profiles')).some(item => item.id !== id && (item.userId === user.id || item.studentRef === studentRef))) throw new HttpError(409, 'This student account or private reference is already linked.');
      const profile = { ...(old || {}), id: id || L.rid('STU-'), studentRef, userId: user.id, name: user.name, classId: classRecord.id, yearLevel: L.text(body.yearLevel, 80), active: body.active !== false, updatedAt: new Date().toISOString() };
      const saved = old ? await tx.update('student_profiles', profile) : await tx.insert('student_profiles', profile);
      await audit(tx, this.config, actor, old ? 'student.update' : 'student.create', 'student_profile', saved.id);
      return studentView(saved, classRecord);
    });
  },
  async verifyGuardian(actor, studentId, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.guardian.verify');
      const student = await tx.get('student_profiles', studentId);
      const guardian = await tx.get('users', body.guardianUserId);
      const relationship = V.choose(body.relationship, ['Parent', 'Legal guardian']);
      const verificationReference = L.text(body.verificationReference, 120, true);
      if (!student?.active || !guardian?.active || !guardian.roles.includes('parent') || body.authorityVerified !== true || verificationReference.length < 4) throw new HttpError(400, 'Verify the guardian’s identity and authority before linking the account.');
      const links = await tx.list('student_guardians');
      const old = links.find(item => item.studentId === student.id && item.guardianUserId === guardian.id);
      if (old?.status === 'VERIFIED') throw new HttpError(409, 'This guardian is already linked to the learner.');
      const link = { ...(old || {}), id: old?.id || L.rid('GDN-'), studentId: student.id, guardianUserId: guardian.id, guardianName: guardian.name, relationship, status: 'VERIFIED', verificationReference, verifiedBy: actor.user.id, verifiedAt: new Date().toISOString() };
      const saved = old ? await tx.update('student_guardians', link) : await tx.insert('student_guardians', link);
      await audit(tx, this.config, actor, 'student.guardian_verify', 'student_guardian', saved.id);
      return { id: saved.id, studentId: saved.studentId, guardianUserId: saved.guardianUserId, relationship: saved.relationship, status: saved.status, verifiedAt: saved.verifiedAt };
    });
  },
  async attendance(actor, studentId = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.attendance.read');
      const students = studentId ? [await requireStudentAccess(tx, actor.user, studentId, 'student.attendance.read')] : await visibleStudents(tx, actor.user);
      const ids = new Set(students.map(item => item.id));
      const records = (await tx.list('attendance_records')).filter(item => ids.has(item.studentId)).sort((a, b) => b.date.localeCompare(a.date));
      if (actor.user.roles.some(role => ['parent', 'student'].includes(role))) return records.map(({ id, studentId, date, status }) => ({ id, studentId, date, status }));
      return records;
    });
  },
  async recordAttendance(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.attendance.manage');
      const student = await tx.get('student_profiles', body.studentId);
      if (!student?.active) throw new HttpError(404, 'Learner record not found.');
      await requireTeacherAssignment(tx, actor.user, student.classId);
      const date = V.date(body.date);
      if (date > new Date().toISOString().slice(0, 10)) throw new HttpError(400, 'Attendance cannot be recorded for a future date.');
      const status = V.choose(body.status, ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']);
      const id = `${student.id}:${date}`;
      const old = await tx.get('attendance_records', id);
      if (old) V.revision(old, body);
      const record = { ...(old || {}), id, studentId: student.id, classId: student.classId, date, status, note: L.text(body.note, 500), recordedBy: actor.user.id, updatedAt: new Date().toISOString() };
      const saved = old ? await tx.update('attendance_records', record) : await tx.insert('attendance_records', record);
      await audit(tx, this.config, actor, old ? 'attendance.update' : 'attendance.record', 'attendance_record', id);
      return saved;
    });
  },
  async results(actor, studentId = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.results.read');
      const students = studentId ? [await requireStudentAccess(tx, actor.user, studentId, 'student.results.read')] : await visibleStudents(tx, actor.user);
      const ids = new Set(students.map(item => item.id));
      const records = (await tx.list('student_results')).filter(item => ids.has(item.studentId) && (A.has(actor.user, 'student.results.manage') || item.status === 'PUBLISHED')).sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
      if (actor.user.roles.some(role => ['parent', 'student'].includes(role))) return records.map(({ id, studentId, subject, term, score, publishedAt }) => ({ id, studentId, subject, term, score, publishedAt }));
      return records;
    });
  },
  async saveResult(actor, body, id = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.results.manage');
      const student = await tx.get('student_profiles', body.studentId);
      if (!student?.active) throw new HttpError(404, 'Learner record not found.');
      await requireTeacherAssignment(tx, actor.user, student.classId);
      const old = id && await tx.get('student_results', id);
      if (id && !old) throw new HttpError(404, 'Result record not found.');
      if (old) V.revision(old, body);
      if (old && old.status === 'PUBLISHED') throw new HttpError(409, 'Published results cannot be edited; create a corrected version for review.');
      const record = { ...(old || {}), id: id || L.rid('RES-'), studentId: student.id, classId: student.classId, subject: L.text(body.subject, 100, true), term: L.text(body.term, 80, true), score: L.text(body.score, 40, true), status: 'DRAFT', recordedBy: actor.user.id, updatedAt: new Date().toISOString() };
      const saved = old ? await tx.update('student_results', record) : await tx.insert('student_results', record);
      await audit(tx, this.config, actor, old ? 'student.result_update' : 'student.result_create', 'student_result', saved.id);
      return saved;
    });
  },
  async publishResult(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.results.publish');
      const record = await tx.get('student_results', id);
      if (!record || record.status !== 'DRAFT' || record.recordedBy === actor.user.id) throw new HttpError(409, 'Only another staff member’s draft result can be published.');
      V.revision(record, body);
      const saved = await tx.update('student_results', { ...record, status: 'PUBLISHED', publishedBy: actor.user.id, publishedAt: new Date().toISOString() });
      await audit(tx, this.config, actor, 'student.result_publish', 'student_result', saved.id);
      return saved;
    });
  },
  async materials(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.read');
      const students = await visibleStudents(tx, actor.user);
      const studentIds = new Set(students.map(item => item.id));
      const classIds = await userClassIds(tx, actor.user, students);
      return (await tx.list('learning_materials')).filter(item => {
        const owned = item.createdBy === actor.user.id && A.has(actor.user, 'student.material.manage');
        const assignedDraft = item.status === 'DRAFT' && A.has(actor.user, 'student.material.publish') && ((item.studentIds || []).some(id => studentIds.has(id)) || (item.classIds || []).some(id => classIds.has(id)));
        return owned || assignedDraft || (item.status === 'PUBLISHED' && ((item.studentIds || []).some(id => studentIds.has(id)) || (item.classIds || []).some(id => classIds.has(id))));
      });
    });
  },
  async familyMessages(actor, studentId = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.communication.read');
      const students = studentId ? [await requireStudentAccess(tx, actor.user, studentId, 'student.communication.read')] : await visibleStudents(tx, actor.user);
      const ids = new Set(students.map(item => item.id));
      return (await tx.list('family_messages')).filter(item => ids.has(item.studentId) && (item.senderId === actor.user.id || item.recipientId === actor.user.id)).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
    });
  },
  async familyContacts(actor, studentId = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.communication.read');
      const students = studentId ? [await requireStudentAccess(tx, actor.user, studentId, 'student.communication.read')] : await visibleStudents(tx, actor.user);
      const output = [];
      for (const student of students) {
        const [guardians, assignments] = await Promise.all([tx.list('student_guardians'), tx.list('class_teachers')]);
        const ids = actor.user.roles.includes('parent')
          ? assignments.filter(item => item.classId === student.classId && item.active !== false).map(item => item.teacherUserId)
          : actor.user.roles.includes('teacher')
            ? guardians.filter(item => item.studentId === student.id && item.status === 'VERIFIED' && item.verifiedAt).map(item => item.guardianUserId)
            : [];
        const contacts = [];
        for (const id of new Set(ids)) {
          const user = await tx.get('users', id);
          if (user?.active) contacts.push({ id: user.id, name: user.name, role: actor.user.roles.includes('parent') ? 'Teacher' : 'Parent / guardian' });
        }
        output.push({ studentId: student.id, studentName: student.name, contacts });
      }
      return output;
    });
  },
  async sendFamilyMessage(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.communication.send');
      const student = await requireStudentAccess(tx, actor.user, body.studentId, 'student.communication.read');
      if (!actor.user.roles.some(role => ['parent', 'teacher'].includes(role))) throw new HttpError(403, 'Student messaging is not enabled.');
      const recipient = await tx.get('users', body.recipientId);
      if (!recipient?.active || recipient.id === actor.user.id) throw new HttpError(400, 'Choose an active member of this learner’s school team.');
      const [guardians, assignments] = await Promise.all([tx.list('student_guardians'), tx.list('class_teachers')]);
      const guardianIds = guardians.filter(item => item.studentId === student.id && item.status === 'VERIFIED' && item.verifiedAt).map(item => item.guardianUserId);
      const teacherIds = assignments.filter(item => item.classId === student.classId && item.active !== false).map(item => item.teacherUserId);
      const participants = new Set([...guardianIds, ...teacherIds, student.userId]);
      if (!participants.has(actor.user.id) || !participants.has(recipient.id)) throw new HttpError(403, 'Messages are limited to verified guardians and the learner’s assigned classroom team.');
      const content = L.text(body.message, 2000, true);
      const record = await tx.insert('family_messages', { id: L.rid('MSG-'), studentId: student.id, senderId: actor.user.id, recipientId: recipient.id, message: content, createdAt: new Date().toISOString() });
      await tx.insert('notifications', { id: L.rid('NTF-'), userId: recipient.id, title: 'New family message', href: '/portal/family', createdAt: record.createdAt, readAt: '' });
      await audit(tx, this.config, actor, 'family.message_send', 'family_message', record.id);
      return record;
    });
  },
  async saveMaterial(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.material.manage');
      const classIds = V.strings(body.classIds, 50);
      if (!classIds.length) throw new HttpError(400, 'Choose one or more classrooms.');
      for (const classId of classIds) {
        if (!await tx.get('classes', classId)) throw new HttpError(400, 'Choose existing classrooms.');
        await requireTeacherAssignment(tx, actor.user, classId);
      }
      const href = L.text(body.href, 500, true);
      if (!href.startsWith('/') || href.startsWith('//') || href.includes('\\') || /[\u0000-\u001f]/.test(href)) throw new HttpError(400, 'Use an internal approved school resource path.');
      const record = await tx.insert('learning_materials', { id: L.rid('MAT-'), title: L.text(body.title, 160, true), description: L.text(body.description, 1000), href, classIds, studentIds: [], status: 'DRAFT', createdBy: actor.user.id, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString() });
      await audit(tx, this.config, actor, 'student.material_create', 'learning_material', record.id);
      return record;
    });
  },
  async publishMaterial(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'student.material.publish');
      const record = await tx.get('learning_materials', id);
      if (!record || record.status !== 'DRAFT' || record.createdBy === actor.user.id) throw new HttpError(409, 'Only another staff member’s draft material can be published.');
      V.revision(record, body);
      const saved = await tx.update('learning_materials', { ...record, status: 'PUBLISHED', publishedBy: actor.user.id, publishedAt: new Date().toISOString() });
      await audit(tx, this.config, actor, 'student.material_publish', 'learning_material', saved.id);
      return saved;
    });
  }
};