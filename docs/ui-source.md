# Preserving the original UI in PHP

The conversion is based on the existing repository layout; it is not a visual redesign.

- All public stylesheets, portal stylesheets, fonts, photographs and browser scripts are unchanged.
- Server-rendered public pages and portal shells now live in `src/php/pages_*.php` and `src/php/layout.php`.
- Existing CSS classes, DOM structures, data attributes, URL paths and API response contracts are retained.
- The original homepage slides remain in `src/content/home-slides.json`; published CMS banners can still override them.
- The browser JavaScript continues to power carousel controls, filters/lightbox, modal dialogs, forms, Kids Zone games and the portal interface.

Before retiring the Node renderer, all 15 main public pages were compared using the same context: their complete HTML was byte-for-byte identical. Original body hashes are checked by `test/run.php` using `test/render-hashes.json`. Browser checks additionally cover bilingual pages, staff screens, mobile widths and HTTPS embedded authentication.

See [PHP migration](php-migration.md) for test details and deployment caveats. Node is now used only by optional browser-test scripts, not by the application.
