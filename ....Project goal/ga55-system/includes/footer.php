<?php
// ============================================================
// GA-55A SYSTEM — includes/footer.php
// Closing tags + main JS — har page ke end me include karo
// ============================================================
?>
</div><!-- .app-shell -->

<script src="<?= BASE_URL ?>/assets/js/01_main.js"></script>

<?php if (isset($extraJs)): ?>
    <?php foreach ($extraJs as $jsFile): ?>
        <script src="<?= BASE_URL ?>/assets/js/<?= $jsFile ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
