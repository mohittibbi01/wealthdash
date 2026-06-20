-- WealthDash — t451: Micro-animations Migration
-- No database changes required — pure frontend CSS + JS.
-- Files: public/css/micro_animations.css, public/js/micro_animations.js
-- Add to layout.php:
--   <link rel="stylesheet" href="<?= APP_URL ?>/public/css/micro_animations.css">
--   <script src="<?= wd_js_url('micro_animations.js') ?>"></script>
-- Respects prefers-reduced-motion automatically.
SELECT 1;
