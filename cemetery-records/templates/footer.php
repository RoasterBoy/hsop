<?php
/**
 * The footer template for cemetery records
 */
?>
    </div><!-- #content -->

    <footer id="colophon" class="site-footer">
        <div class="site-info">
            <?php
            printf(
                esc_html__('Cemetery Records Manager', 'cemetery-records')
            );
            ?>
            <span class="sep"> | </span>
            <?php
            printf(
                esc_html__('Version %s', 'cemetery-records'),
                CEMETERY_RECORDS_VERSION
            );
            ?>
        </div>
    </footer>
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html> 