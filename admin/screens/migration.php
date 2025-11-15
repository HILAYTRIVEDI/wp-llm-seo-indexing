<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap">
    <h1><?php esc_html_e( 'Migrations & Cleanup', 'wpllmseo' ); ?></h1>
    <?php $last = get_option( 'wpllmseo_cleanup_last_result' ); ?>
    <?php if ( $last ) : ?>
        <div class="notice notice-success is-dismissible">
            <?php if ( isset( $last['reason'] ) && 'max_total_reached' === $last['reason'] ) : ?>
                <p><?php echo esc_html( sprintf( 'Cleanup stopped after reaching max_total: %d/%d processed at %s', $last['total_processed'], $last['max_total'], $last['time'] ) ); ?></p>
            <?php else : ?>
                <p><?php echo esc_html( sprintf( 'Cleanup completed: %d entries processed at %s', $last['total_processed'] ?? 0, $last['time'] ?? '' ) ); ?></p>
            <?php endif; ?>
        </div>
        <?php delete_option( 'wpllmseo_cleanup_last_result' ); ?>
    <?php endif; ?>

    <div id="wpllmseo-migration-app">
        <h2><?php esc_html_e( 'Embeddings Migration', 'wpllmseo' ); ?></h2>
        <p><?php esc_html_e( 'Migrate old per-chunk postmeta embeddings into the `wpllmseo_chunks` table.', 'wpllmseo' ); ?></p>

        <label><?php esc_html_e( 'Batch size', 'wpllmseo' ); ?>
            <input id="wpllmseo-migration-batch" type="number" value="50" min="1" />
        </label>
        <label><?php esc_html_e( 'Offset', 'wpllmseo' ); ?>
            <input id="wpllmseo-migration-offset" type="number" value="0" min="0" />
        </label>

        <p>
            <button id="wpllmseo-migration-dryrun" class="button button-secondary"><?php esc_html_e( 'Run Dry-Run', 'wpllmseo' ); ?></button>
            <button id="wpllmseo-migration-run" class="button button-primary"><?php esc_html_e( 'Run Migration', 'wpllmseo' ); ?></button>
            <button id="wpllmseo-migration-start" class="button"><?php esc_html_e( 'Start Auto-Batch', 'wpllmseo' ); ?></button>
            <button id="wpllmseo-migration-stop" class="button" style="display:none;"><?php esc_html_e( 'Stop', 'wpllmseo' ); ?></button>
            <span id="wpllmseo-migration-spinner" style="display:none; margin-left:10px;">⏳</span>
        </p>

        <h2><?php esc_html_e( 'Postmeta Cleanup', 'wpllmseo' ); ?></h2>
        <p><?php esc_html_e( 'Preview and optionally remove legacy per-chunk postmeta keys.', 'wpllmseo' ); ?></p>

        <label><?php esc_html_e( 'Batch size', 'wpllmseo' ); ?>
            <input id="wpllmseo-cleanup-batch" type="number" value="50" min="1" />
        </label>
        <label><?php esc_html_e( 'Offset', 'wpllmseo' ); ?>
            <input id="wpllmseo-cleanup-offset" type="number" value="0" min="0" />
        </label>

        <p>
            <button id="wpllmseo-cleanup-dryrun" class="button button-secondary"><?php esc_html_e( 'Preview Cleanup', 'wpllmseo' ); ?></button>
            <button id="wpllmseo-cleanup-run" class="button button-primary"><?php esc_html_e( 'Execute Cleanup', 'wpllmseo' ); ?></button>
            <?php $progress = get_option( 'wpllmseo_cleanup_progress', array() ); ?>
            <form method="post" id="wpllmseo-cleanup-start-form" style="display:inline; margin-left:8px;">
                <?php wp_nonce_field( 'wpllmseo_cleanup_control' ); ?>
                <input type="hidden" name="action" value="wpllmseo_cleanup_start" />
                <input type="hidden" name="batch" value="<?php echo esc_attr( $progress['batch'] ?? 50 ); ?>" />
                <label style="display:inline-block; margin-left:8px;"><?php esc_html_e( 'Max total processed (0 = unlimited):', 'wpllmseo' ); ?> <input id="wpllmseo-cleanup-max-total" type="number" name="max_total" value="<?php echo esc_attr( $progress['max_total'] ?? 0 ); ?>" min="0" style="width:120px;" /></label>
                <label style="display:inline-block; margin-left:8px; font-weight:normal;"><input id="wpllmseo-cleanup-force" type="checkbox" name="force_start" value="1" /> <?php esc_html_e( 'Force start without preview (admin override)', 'wpllmseo' ); ?></label>
                <input type="hidden" name="_wpnonce_force" value="<?php echo esc_attr( wp_create_nonce( 'wpllmseo_cleanup_force' ) ); ?>" />
                <button id="wpllmseo-cleanup-start" class="button"><?php esc_html_e( 'Start Auto-Batch', 'wpllmseo' ); ?></button>
            </form>
            <button id="wpllmseo-cleanup-confirm-start" class="button button-primary" style="display:none; margin-left:8px;"><?php esc_html_e( 'Confirm Start', 'wpllmseo' ); ?></button>
            <form method="post" style="display:inline; margin-left:8px;">
                <?php wp_nonce_field( 'wpllmseo_cleanup_control' ); ?>
                <input type="hidden" name="action" value="wpllmseo_cleanup_stop" />
                <button id="wpllmseo-cleanup-stop" class="button"><?php esc_html_e( 'Stop', 'wpllmseo' ); ?></button>
            </form>
            <form method="post" style="display:inline; margin-left:8px;">
                <?php wp_nonce_field( 'wpllmseo_cleanup_control' ); ?>
                <input type="hidden" name="action" value="wpllmseo_cleanup_resume" />
                <button id="wpllmseo-cleanup-resume" class="button"><?php esc_html_e( 'Resume', 'wpllmseo' ); ?></button>
            </form>
            <span id="wpllmseo-cleanup-spinner" style="display:none; margin-left:10px;">⏳</span>
            <div style="display:inline-block; margin-left:12px;">
                <?php if ( ! empty( $progress ) ) : ?>
                    <strong><?php esc_html_e( 'Cleanup Progress:', 'wpllmseo' ); ?></strong>
                    <span><?php echo esc_html( sprintf( 'offset=%d batch=%d running=%s total=%d max=%d last_run=%s', $progress['offset'] ?? 0, $progress['batch'] ?? 50, $progress['running'] ? 'yes' : 'no', $progress['total_processed'] ?? 0, $progress['max_total'] ?? 0, $progress['last_run'] ?? 'n/a' ) ); ?></span>
                <?php else : ?>
                    <em><?php esc_html_e( 'No cleanup in progress.', 'wpllmseo' ); ?></em>
                <?php endif; ?>
            </div>
        </p>

        <div id="wpllmseo-migration-output" style="margin-top:20px; white-space:pre-wrap; background:#fff; padding:12px; border:1px solid #e1e1e1;"></div>
    </div>
</div>

<script>
(function(){
    const progressEl = document.querySelector('#wpllmseo-migration-app');
    const spinner = document.getElementById('wpllmseo-cleanup-spinner');

    function fetchProgress(){
        fetch('<?php echo esc_url( rest_url( WPLLMSEO_REST_NAMESPACE . "/cleanup/progress" ) ); ?>', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
        })
        .then(r=>r.json())
        .then(data=>{
            if(!data) return;
            const prog = data;
            const info = progressEl.querySelector('div');
            if(info){
                info.textContent = `offset=${prog.offset||0} batch=${prog.batch||50} running=${prog.running? 'yes':'no'} last_run=${prog.last_run||'n/a'}`;
            }
        }).catch(()=>{});
    }

    // Poll every 10 seconds
    setInterval(fetchProgress, 10000);
    fetchProgress();

    // Confirm before starting
    const startForm = document.getElementById('wpllmseo-cleanup-start-form');
    const confirmBtn = document.getElementById('wpllmseo-cleanup-confirm-start');
    const previewBtn = document.getElementById('wpllmseo-cleanup-dryrun');
    const output = document.getElementById('wpllmseo-migration-output');

    // When clicking Preview Cleanup, call REST preview and show results
    previewBtn.addEventListener('click', function(e){
        e.preventDefault();
        const batch = document.getElementById('wpllmseo-cleanup-batch').value || 50;
        const offset = document.getElementById('wpllmseo-cleanup-offset').value || 0;
        output.textContent = 'Running preview...';
        fetch('<?php echo esc_url( rest_url( WPLLMSEO_REST_NAMESPACE . "/cleanup/postmeta" ) ); ?>?batch='+batch+'&offset='+offset+'&execute=false', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>', 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch: parseInt(batch), offset: parseInt(offset), execute: false })
        }).then(r=>r.json()).then(data=>{
            // Build friendly HTML preview
            let html = '<strong>Preview Results</strong><br/>';
            html += '<div style="margin-top:8px;">Processed: <strong>'+ (data.processed||0) +'</strong> — Found keys: <strong>'+(data.found||0)+'</strong> — Deleted(if execute): <strong>'+(data.deleted||0)+'</strong></div>';
            if ( data.samples && data.samples.length ) {
                html += '<table style="width:100%; border-collapse:collapse; margin-top:8px;">';
                html += '<thead><tr><th style="text-align:left; border-bottom:1px solid #ddd; padding:6px;">Post ID</th><th style="text-align:left; border-bottom:1px solid #ddd; padding:6px;">Meta Key</th></tr></thead>';
                html += '<tbody>';
                data.samples.forEach(s=>{
                    html += '<tr><td style="padding:6px; border-bottom:1px solid #f1f1f1;">'+(s.post_id||'')+'</td><td style="padding:6px; border-bottom:1px solid #f1f1f1;">'+(s.meta_key||'')+'</td></tr>';
                });
                html += '</tbody></table>';
            }
            output.innerHTML = html;
            // Show confirm button to allow starting
            confirmBtn.style.display = 'inline-block';
        }).catch(err=>{ output.textContent = 'Preview failed.'; });
    });

    // When clicking Confirm Start, submit the start form
    confirmBtn.addEventListener('click', function(e){
        e.preventDefault();
        if ( confirm('Are you absolutely sure you want to start automatic cleanup? This will perform deletes as scheduled.') ) {
            startForm.submit();
        }
    });
})();
</script>
