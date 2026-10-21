# Website templates and assets

The website is server-rendered HTML from PHP templates, not a frontend framework or generated application.

- Public page templates are in `src/php/pages_*.php`; shared headers/navigation/footer are in `src/php/layout.php`.
- CSS, fonts and photographs live in `public/css`, `public/fonts` and `public/img`.
- The browser scripts in `public/js` power menus, carousel controls, gallery filters/lightboxes, modal dialogs, forms, games and the staff/family portal. Edit them directly; no package manager or build step is needed.
- Homepage slide content is in `src/content/home-slides.json`; approved published banners can override it.
- Existing routes, template markup and interactions are retained. `test/run.php` compares public page body hashes with `test/render-hashes.json` to catch unintended markup changes.

Make visual edits directly in the PHP templates and CSS. Test the pages and authenticated portal manually at desktop/mobile sizes after changes, including keyboard navigation and form feedback.
