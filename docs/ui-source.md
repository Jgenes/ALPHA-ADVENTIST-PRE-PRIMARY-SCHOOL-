# UI source: preserve the school's GitHub design

The user asked to use the UI supplied in this repository, not the alternate design introduced during feature implementation. The source reference is the checked-out GitHub commit **82757d3932e01e3ea53fdcc34e628b078fb16fe2**.

## Reused from the original code

- `public/css/main.css` is restored byte-for-byte: the original navy `#0A1E59`, gold `#EAB239`, SDA green, Inter/Source Serif typography, buttons, cards, spacing and responsive rules.
- `src/pages/home.js` reuses the original slideshow and homepage markup: quick features, introduction, pillars, academics, leadership, digital learning, school life, faith, talents, admissions, news, families, Kids Zone and contact.
- The header again uses the original navigation labels/links rather than the redesigned Explore menu. Downloads, Portal Login and EN/SW extend the existing navigation. The original Apply CTA, footer and mobile WhatsApp/Call/Apply bar remain.
- Parent Corner again uses the original clickable cards and native dialogs, connected to published records rather than sample calendars/fees or proposed programmes.
- `src/pages/portal.js` reuses the original CMS's centred crest/login card and `admin-wrap`, `admin-side`, `admin-main`, `admin-card`, `admin-table` shell. It does not reinstate the old CMS authentication or write routes.
- The Kiswahili homepage uses the same original hero, pillars, levels, cards and CTA components. Existing bilingual Admissions/Contact remain available.

## Additions, not another theme

`platform-public.css` contains only styles for new features, navigation sizing, accessibility and safe media fallbacks. `portal.css` extends the original admin components for API-driven HR/workflow features. New components must use the original brand variables instead of introducing a separate green/sage template, alternate marketing layout or fictional campus artwork.

The carousel retains its original controls and now has an explicit pause button and inert inactive slides. Reduced-motion preferences remain respected. Browser regressions exercise the slideshow, parent dialogs, responsive navigation, original-style portal and secure sign-in.

## Safety and functionality retained

- All server-side permissions, private-file controls, audit, consent, CSRF, MFA and embedded-preview login fixes remain in force.
- The login still has only username/password initially; restoring the original visual layout does not restore its old always-visible authenticator field.
- School contacts, public news/events, reviewed hero banners and approved downloads still come from the governed platform.
- Empty/unpublished news/gallery areas, unlaunched course promotions and sample parent information are not republished merely to imitate the old screen.
- Old credentials, runtime data, private artifacts and unverified pupil photos were **not** restored. Unapproved photo slots use the existing school crest. Photos may appear only through consent-checked `/media/` routes after independent approval. The invented campus SVG is removed.

This restores the provided visual design while retaining the added functionality. It is not a deployment to the live Render site or a claim that historical photographs have verified consent.
