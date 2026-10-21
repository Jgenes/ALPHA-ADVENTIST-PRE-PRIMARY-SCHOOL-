# Website templates and assets

The website is server-rendered HTML from PHP templates, not a frontend framework or generated application.

- Public page templates are in `alpha-school/src/php/pages_*.php`; shared headers/navigation/footer are in `alpha-school/src/php/layout.php`.
- CSS, fonts and photographs live in `public_html/css`, `public_html/fonts` and `public_html/img`.
- The browser scripts in `public_html/js` power menus, carousel controls, gallery filters/lightboxes, modal dialogs, forms, games and the staff/family portal. Edit them directly; no package manager or build step is needed.
- Homepage slide content is in `alpha-school/src/content/home-slides.json`; approved published banners can override it.
- Existing routes, template markup and interactions are retained. `alpha-school/test/run.php` compares public page body hashes with `alpha-school/test/render-hashes.json` to catch unintended markup changes.

Make visual edits directly in the PHP templates and CSS. Test the pages and authenticated portal manually at desktop/mobile sizes after changes, including keyboard navigation and form feedback.
