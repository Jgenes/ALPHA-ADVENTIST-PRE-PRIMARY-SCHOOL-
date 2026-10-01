'use strict';
const L = require('../lib');
function external(value, hosts) {
  const text = L.text(value, 500);
  if (!text) return '';
  let url;
  try { url = new URL(text); } catch { throw new L.HttpError(400, 'Enter a valid official HTTPS link.'); }
  if (url.protocol !== 'https:' || url.username || url.password || !hosts.some(host => url.hostname === host || url.hostname.endsWith('.' + host))) throw new L.HttpError(400, 'Use a verified official channel or Google Maps link.');
  return url.href;
}
function validateDetails(input) {
  let body = input;
  if (typeof input === 'string') { try { body = JSON.parse(input); } catch { throw new L.HttpError(400, 'School details must use the structured school-details form.'); } }
  if (!body || body.detailsVerified !== true) throw new L.HttpError(400, 'Confirm the school has verified these public contact details.');
  function phone(value) { const text = L.text(value, 24, true); if (!/^\+[1-9][0-9 ()-]{7,22}$/.test(text)) throw new L.HttpError(400, 'Use an international school telephone number.'); return text; }
  function optionalPhone(value) { const text = L.text(value, 24, true); return text ? phone(text) : ''; }
  const email = L.text(body.email, 160, true);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new L.HttpError(400, 'Use the verified school email address.');
  const centreCode = L.text(body.centreCode, 24, true);
  if (!/^[A-Z0-9-]+$/.test(centreCode)) throw new L.HttpError(400, 'Use the official examination centre code.');
  return { officePhone: phone(body.officePhone), headPhone: phone(body.headPhone), whatsappPhone: phone(body.whatsappPhone), safeguardingName: L.text(body.safeguardingName, 120, true), safeguardingPhone: optionalPhone(body.safeguardingPhone), email, box: L.text(body.box, 80, true), locationText: L.text(body.locationText, 180, true), officeHours: L.text(body.officeHours, 500, true), centreCode, mapUrl: external(body.mapUrl, ['google.com', 'maps.app.goo.gl']), facebookUrl: external(body.facebookUrl, ['facebook.com']), instagramUrl: external(body.instagramUrl, ['instagram.com']), youtubeUrl: external(body.youtubeUrl, ['youtube.com', 'youtu.be']), detailsVerified: true };
}
function applyDetails(settings, published) {
  const record = published.find(item => item.kind === 'settings' && item.slug === 'school-contact');
  if (!record) return settings;
  const details = validateDetails(record.body);
  return { ...settings, email: details.email, emailPublished: true, safeguardingName: details.safeguardingName, safeguardingPhone: details.safeguardingPhone, phones: [{ label: 'School Office', number: details.officePhone, href: details.officePhone.replace(/[^+0-9]/g, '') }, { label: "Head of School's Office", number: details.headPhone, href: details.headPhone.replace(/[^+0-9]/g, '') }], whatsapp: { number: details.whatsappPhone, href: details.whatsappPhone.replace(/[^0-9]/g, '') }, address: { ...settings.address, box: details.box }, locationText: details.locationText, officeHours: details.officeHours, centreCode: details.centreCode, mapUrl: details.mapUrl, mapNote: details.mapUrl ? 'School-verified campus map link.' : settings.mapNote, social: [['Facebook', details.facebookUrl], ['Instagram', details.instagramUrl], ['YouTube', details.youtubeUrl]].filter(([, url]) => url).map(([name, url]) => ({ name, url })) };
}
module.exports = { validateDetails, applyDetails };
