# Alpha Adventist Pre & Primary School — Official Website Platform

Production-ready official digital platform for **Alpha Adventist Pre & Primary School**,
a Seventh-day Adventist educational institution within the **Western Tanzania Conference – Kigoma**.

Identity line: **Education • Faith • Technology • Talent • Character • Future**

---

## 1. Run the platform

```bash
node server.js          # zero-dependency Node.js (v18+) server, port 3000
PORT=8080 node server.js
```

Open `http://localhost:3000`. No npm packages are required — the server, router,
CMS backend and renderer are written on the Node standard library only.

### CMS sign-in (first run)

| Field    | Value          |
|----------|----------------|
| URL      | `/admin`       |
| Username | `admin`        |
| Password | `Alpha@2026!`  |

> Created automatically on first start. **Change it immediately** via
> *Users & Roles* (create a new super-admin, then remove the default account).
> Sessions expire after 10 hours; login is rate-limited; every admin POST is CSRF-protected.

## 1.1 Deploy to Render

This repository includes a Render Blueprint in `render.yaml`. The application uses only Node.js built-ins, so no `npm install` is needed. The Blueprint attaches a persistent disk at `/var/data` for the CMS database. Render persistent disks require a paid web-service plan; do not remove the disk unless you have another persistent database/storage plan, or CMS edits can be lost on redeploy.

1. Push this project to a GitHub or GitLab repository.
2. In Render, choose **New → Blueprint**, connect the repository, and select its branch. Render reads `render.yaml` and creates the web service and persistent disk.
3. When prompted for `ADMIN_PASSWORD`, enter a unique, strong password and keep it private. On the disk's first initialization, the site copies the content database without its development admin account, then creates the `admin` user using this password.
4. After deployment, open the service URL and sign in at `/admin` with username `admin` and the password you supplied.
5. Set `BASE_URL` in the Render service environment to the deployed Render URL, or to your custom HTTPS domain after connecting it. Redeploy for canonical URLs and the sitemap to reflect the selected domain.
6. Add the custom domain in Render if needed and follow Render's DNS instructions. Render provides HTTPS for connected domains.

The service listens on Render's assigned `PORT`. The CMS database persists on the attached disk at `/var/data/db.json`; keep regular backups of that file. `ADMIN_PASSWORD` is only used to create the initial admin on a fresh disk. Changing the environment variable later does not reset an existing CMS password.

---

## 2. What was built

### Public website (server-side rendered, SEO-ready)
| Route | Purpose |
|---|---|
| `/` | Homepage — 20-section flow: contact bar, nav, 4-slide hero, trust cards, who we are, six pillars, academics, why Alpha, Head of School, computer learning, school life, faith, talent, admissions CTA, news, gallery, parent corner, kids zone, contact, footer |
| `/about` | Who we are, official Mission/Vision/Philosophy cards, full Head of School message, whole-learner development |
| `/academics` | KG I–II, Standard I–VII, day & boarding, teaching/assessment/monitoring features |
| `/admissions` | 5-step process, levels, boarding/day, visit booking form, application-request form, FAQs, call/WhatsApp CTAs |
| `/computer-learning` | Pupil ICT pathway (KG→Std VII), staff ICT development, **COMING SOON** community training centre + interest register |
| `/school-life` | Worship, choir, Pathfinder, ICT, academic exhibits, service; talent showcase |
| `/faith` | Spiritual life and Christian principles in practice |
| `/parents` | Parent Corner: calendar/exams/rules status, announcements, communication, future Parent Portal (no fake login) |
| `/students` | Alpha Kids Zone: working typing game, maths quiz, ICT revision, internet-safety corner |
| `/news`, `/news/:slug` | News system with categories, featured article, related articles, WhatsApp/Facebook/copy-link sharing |
| `/gallery` | Filterable masonry gallery with accessible lightbox (keyboard + captions) |
| `/contact` | Address, phones, WhatsApp, email, directions, enquiry form |
| `/privacy` | Website privacy & child-safeguarding notice |
| `/robots.txt`, `/sitemap.xml`, `/site.webmanifest` | SEO & PWA basics |

### CMS (`/admin`) — role-based
- **Roles:** `super` (all + users) · `admin` (all content/settings) · `editor` (news, announcements, gallery, courses, submissions) · `contributor` (news & announcements).
- **Editable:** mission/vision/philosophy, leadership (name, title, portrait path, messages), hero slides (text, photo, CTAs, enable), news, announcements, gallery (captions/categories/alt), computer courses (status current vs coming-soon), contact settings, WhatsApp line, email publication flag, form-submissions inbox, users.
- Storage: atomic JSON writes to `data/db.json` — trivially backed up and portable to a database in a later phase.

### Security & child privacy (implemented)
HTTPS-ready headers (HSTS via proxy, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy), secure HttpOnly SameSite cookies, scrypt password hashing, CSRF tokens on all admin POSTs, login + form rate limiting, honeypot spam protection, input sanitisation, output escaping everywhere, `noindex` on admin, and a published privacy/safeguarding notice. No pupil records, results, medical or fee data are ever exposed; gallery captions never identify children.

---

## 3. Source-of-truth rules honoured

- Official **Mission / Vision / Philosophy** wording is preserved verbatim and stored separately from explanatory copy (CMS-editable only by admin/super).
- Contact data used: **P.O. Box 891, Kigoma – Tanzania · +255 613 807 200 · +255 747 128 120 · alphaadventistschool@yahoo.com · Msimba area, Kigoma** (from the school's 2026 graduation programme and Head of School's office documents). WhatsApp line set to **+255 747 128 120** per school instruction; both numbers remain callable.
- Head of School: **Christopher Mashaka James** — displayed with a dignified crest placeholder until the school supplies an official portrait (set it in *CMS → Leadership*).
- Nothing invented: no fees, results, staff rosters, sports teams, social links or office hours are published. Missing items appear as honest "published by the school office / coming soon" states, all CMS-ready.
- Examination centre **PS0603108** is cited only as the publicly recorded 2026 Form One selection identifier.
- Only the photographs supplied by the school are used (optimised WebP/JPEG renditions in `public/img/`); the logo is unmodified.

## 4. Design system
Brand colours sampled from the official crest: navy `#0A1E59`, gold `#EAB239`, SDA green `#166428`; warm paper/sand neutrals. Type: **Source Serif 4** (display) + **Inter** (text), self-hosted as WOFF2 (`public/fonts/`). Mobile-first, sticky nav, bottom quick-action bar (WhatsApp / Call / Apply), reduced-motion support, visible focus states, semantic headings, skip link, alt text on every image.

## 5. Roadmap alignment
- **Phase 1 (delivered):** public website, academics, admissions info, school life, faith, computer learning, news, gallery, contact, CMS.
- **Phase 2 (ready to wire):** online admissions workflow, computer-course registration, events calendar, certificate management — submission endpoints and DB schema already accept them.
- **Phase 3 (designed, not faked):** secure Parent / Student / Teacher portals behind authentication; e-learning, payments, certificate verification, digital library.

## 6. Maintenance notes
- Backups: copy `data/db.json` (and `public/img/`) on a schedule.
- Add photographs: drop optimised `-480/-800/-1200/-1600` WebP+JPEG renditions into `public/img/`, add the key to `IMG_POOL` in `src/pages/admin.js`, then publish via *CMS → Gallery/Hero*.
- Change the public domain: edit `BASE_URL` env var (canonical URLs, sitemap, OG tags).
