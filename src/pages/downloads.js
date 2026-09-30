'use strict';
const X = require('../layout');
const { esc } = require('../lib');
const { phero } = require('./about');
const { icon, sectionHead } = X;
const size = bytes => bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
module.exports = {
  render(ctx) {
    const documents = ctx.downloads || [];
    const categories = [...new Set(documents.map(item => item.category))];
    const selected = ctx.query?.get('category') || '';
    const visible = selected ? documents.filter(item => item.category === selected) : documents;
    return { body: `${phero(ctx, 'Downloads', 'School information, at your fingertips.', 'Approved forms, calendars, family guidance and public policies — with a clear version and publication date.', 'campus')}<section class="sec"><div class="container">${sectionHead('PUBLIC DOWNLOAD CENTRE', 'The current, approved version.')}${categories.length ? `<form class="adsp-download-filter" method="get" action="/downloads"><label for="download-category">Category</label><select id="download-category" name="category"><option value="">All categories</option>${categories.map(category => `<option ${selected === category ? 'selected' : ''}>${esc(category)}</option>`).join('')}</select><button class="btn btn--ghost" type="submit">Filter</button></form>` : ''}${visible.length ? `<div class="adsp-download-grid">${visible.map(item => `<article class="card"><div class="adsp-download-icon">${icon('download')}</div><span class="news-cat">${esc(item.category)}</span><h3>${esc(item.title)}</h3><p>${esc(item.description)}</p><dl class="adsp-download-meta"><div><dt>Document</dt><dd>${esc(item.documentNumber)}</dd></div><div><dt>Version</dt><dd>${item.version}.0</dd></div><div><dt>Published</dt><dd>${esc(new Date(item.publishedAt).toLocaleDateString('en-GB'))}</dd></div><div><dt>Size</dt><dd>${size(item.size)}</dd></div></dl><a class="btn btn--primary" href="/downloads/${esc(item.id)}">${icon('download')} Download ${esc(item.name.split('.').at(-1).toUpperCase())}</a></article>`).join('')}</div>` : `<div class="adsp-empty"><span>${icon('book')}</span><h3>${selected ? 'No published documents in this category.' : 'Need a school document?'}</h3><p>Only approved, current documents appear here. Contact the school office for the current admissions form, fee schedule, uniform guidance or term dates.</p><a class="btn btn--primary" href="/contact">Contact the office ${icon('arrow')}</a></div>`}<p class="note-strip" style="margin-top:28px">${icon('lock')}<span>Staff policies, employment contracts and family records are not public downloads. Authorised employees access private documents through the <a href="/portal/documents">staff portal</a>.</span></p></div></section>`, meta: { title: 'Downloads — Approved School Documents | Alpha Adventist', desc: 'Download approved admission forms, calendars, family guidance and public policies from Alpha Adventist Pre & Primary School, Kigoma.' } };
  }
};
