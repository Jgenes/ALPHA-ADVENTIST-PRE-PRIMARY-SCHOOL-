'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { Agent, fixture } = require('./helpers');
const { createUserRecord } = require('../src/platform/auth');

test('family and teaching roles get role-aware portal data', async () => {
  const fixtureData = await fixture();
  const { app, base, password, users, agents } = fixtureData;
  try {
    const parentRecord = await createUserRecord('portal.parent', 'Parent User', ['parent'], password);
    parentRecord.mustChangePassword = false;
    const studentRecord = await createUserRecord('portal.student', 'Student User', ['student'], password);
    studentRecord.mustChangePassword = false;
    await app.store.run(async tx => {
      await tx.insert('users', parentRecord);
      await tx.insert('users', studentRecord);
    });
    const classroom = await agents.head.ok('POST', '/api/classes', { id: 'kg-ii', name: 'KG II', yearLevel: 'KG II' });
    await agents.head.ok('POST', '/api/class-teachers', { classId: classroom.id, teacherUserId: users.teacher.id });
    const student = await agents.head.ok('POST', '/api/students', { userId: studentRecord.id, studentRef: 'STU-PORTAL-100', classId: classroom.id, yearLevel: 'KG II' });
    await agents.head.ok('POST', `/api/students/${student.id}/guardians`, { guardianUserId: parentRecord.id, relationship: 'Parent', verificationReference: 'SYNTHETIC-VERIFY-01', authorityVerified: true });
    await agents.teacher.ok('POST', '/api/attendance', { studentId: student.id, date: new Date().toISOString().slice(0, 10), status: 'PRESENT', note: 'Synthetic internal attendance note.' });
    const result = await agents.teacher.ok('POST', '/api/results', { studentId: student.id, subject: 'Mathematics', term: 'Term 1', score: 'A' });
    await agents.head.ok('POST', `/api/results/${result.id}/publish`, { revision: result.revision });
    const material = await agents.teacher.ok('POST', '/api/learning-materials', { title: 'Number practice', description: 'Synthetic lesson.', href: '/downloads/number-practice', classIds: [classroom.id] });
    await agents.head.ok('POST', `/api/learning-materials/${material.id}/publish`, { revision: material.revision });

    const parentAgent = new Agent(base);
    await parentAgent.login(parentRecord, password);
    const contacts = await parentAgent.ok('GET', '/api/family/contacts?studentId=' + student.id);
    assert.equal(contacts[0].contacts[0].id, users.teacher.id);
    await parentAgent.ok('POST', '/api/family/messages', { studentId: student.id, recipientId: users.teacher.id, message: 'Synthetic parent question.' });
    assert.equal((await agents.teacher.ok('GET', '/api/family/messages?studentId=' + student.id)).length, 1);
    const parentDashboard = await parentAgent.ok('GET', '/api/dashboard');
    assert.ok(parentDashboard.family, 'Parent dashboard should include family context');
    assert.equal(parentDashboard.family.children.length, 1);
    const familyData = await parentAgent.ok('GET', '/api/family');
    assert.equal(familyData.children.length, 1);
    assert.equal(familyData.children[0].attendance.present, 1);
    assert.equal(familyData.children[0].results[0].score, 'A');
    assert.equal(familyData.children[0].materials[0].title, 'Number practice');
    const parentAttendance = await parentAgent.ok('GET', '/api/attendance?studentId=' + student.id);
    assert.equal('note' in parentAttendance[0], false, 'Internal attendance notes must not be exposed to families');
    const parentResults = await parentAgent.ok('GET', '/api/results?studentId=' + student.id);
    assert.equal('recordedBy' in parentResults[0], false, 'Staff publication metadata must not be exposed to families');

    const studentAgent = new Agent(base);
    await studentAgent.login(studentRecord, password);
    const studentDashboard = await studentAgent.ok('GET', '/api/dashboard');
    assert.ok(studentDashboard.student, 'Student dashboard should include learner context');
    const studentData = await studentAgent.ok('GET', '/api/family');
    assert.equal(studentData.student.studentRef, 'STU-PORTAL-100');
    assert.equal(studentData.student.results[0].score, 'A');

    const teacherDashboard = await agents.teacher.ok('GET', '/api/dashboard');
    assert.ok(teacherDashboard.teacher, 'Teacher dashboard should include assigned classroom context');
    const teacherData = await agents.teacher.ok('GET', '/api/family');
    assert.equal(teacherData.classrooms[0].name, 'KG II');
    assert.equal(teacherData.classrooms[0].students[0].studentRef, 'STU-PORTAL-100');

    const unrelatedParent = await createUserRecord('portal.other-parent', 'Other Parent', ['parent'], password);
    unrelatedParent.mustChangePassword = false;
    await app.store.run(tx => tx.insert('users', unrelatedParent));
    const unrelatedAgent = new Agent(base);
    await unrelatedAgent.login(unrelatedParent, password);
    assert.equal((await unrelatedAgent.ok('GET', '/api/family')).children.length, 0);
    const deniedMessage = await unrelatedAgent.request('POST', '/api/family/messages', { studentId: student.id, recipientId: users.teacher.id, message: 'Must not reach the school.' });
    assert.equal(deniedMessage.status, 403);
  } finally {
    await fixtureData.close();
  }
});

test('family API excludes unverified links, fabricated records, and off-audience notices', async () => {
  const fixtureData = await fixture();
  const { app, base, password } = fixtureData;
  try {
    const parent = await createUserRecord('audit.parent', 'Synthetic Parent', ['parent'], password);
    parent.mustChangePassword = false;
    const student = await createUserRecord('audit.student', 'Synthetic Student', ['student'], password);
    student.mustChangePassword = false;
    student.parentIds = [parent.id];
    await app.store.run(async tx => {
      await tx.insert('users', parent);
      await tx.insert('users', student);
    });
    const restricted = await fixtureData.agents.hr.ok('POST', '/api/notices', {
      title: 'Synthetic staff-only notice',
      message: 'Must not be visible to a family account.',
      category: 'HR',
      priority: 'Information',
      audience: { type: 'SELECTED', roles: ['system_admin'] }
    });
    await fixtureData.agents.head.ok('POST', `/api/notices/${restricted.id}/publish`, { revision: restricted.revision });

    const parentAgent = new Agent(base);
    await parentAgent.login(parent, password);
    const data = await parentAgent.ok('GET', '/api/family');
    assert.equal(data.children.length, 0, 'User-supplied parentIds must not establish guardian authority');
    assert.equal(data.notices.some(item => item.id === restricted.id), false, 'Role-targeted staff notices must stay private');
  } finally {
    await fixtureData.close();
  }
});

test('parent can submit and track only their own authenticated admission applications', async () => {
  const fixtureData = await fixture();
  const { app, base, password, agents } = fixtureData;
  try {
    const parent = await createUserRecord('admission.parent', 'Synthetic Applicant', ['parent'], password);
    parent.mustChangePassword = false;
    const otherParent = await createUserRecord('admission.other', 'Other Applicant', ['parent'], password);
    otherParent.mustChangePassword = false;
    await app.store.run(async tx => { await tx.insert('users', parent); await tx.insert('users', otherParent); });
    const applicant = new Agent(base);
    const unrelated = new Agent(base);
    await applicant.login(parent, password);
    await unrelated.login(otherParent, password);

    const application = await applicant.ok('POST', '/api/my/admissions', { childName: 'Synthetic Child', level: 'KG I', arrangement: 'Day', guardianPhone: '+255700000000', guardianEmail: 'family@example.test', message: 'Synthetic application.', privacyConsent: true });
    assert.equal(application.status, 'SUBMITTED');
    assert.ok(application.reference);
    assert.equal((await applicant.ok('GET', '/api/my/admissions')).length, 1);
    assert.equal((await unrelated.ok('GET', '/api/my/admissions')).length, 0);

    const adminQueue = await agents.office.ok('GET', '/api/admissions');
    assert.ok(adminQueue.some(item => item.id === application.id));
    await agents.office.ok('PATCH', `/api/admissions/${application.id}`, { revision: application.revision, status: 'UNDER_REVIEW', comment: 'Synthetic review.' });
    const updated = await applicant.ok('GET', '/api/my/admissions');
    assert.equal(updated[0].status, 'UNDER_REVIEW');
    assert.equal((await unrelated.ok('GET', '/api/my/admissions')).length, 0);
  } finally {
    await fixtureData.close();
  }
});
