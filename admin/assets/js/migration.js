( function() {
    function apiPost( path, body ) {
        body = body || {};
        return fetch( window.wpllmseo_admin.rest_url + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpllmseo_admin.nonce,
            },
            body: JSON.stringify( body ),
        } ).then( function( r ) { return r.json(); } );
    }

    function showOutput( msg ) {
        var out = document.getElementById( 'wpllmseo-migration-output' );
        out.textContent = JSON.stringify( msg, null, 2 );
    }

    document.addEventListener( 'DOMContentLoaded', function() {
        var dryrunBtn = document.getElementById( 'wpllmseo-cleanup-dryrun' );
        var runBtn = document.getElementById( 'wpllmseo-cleanup-run' );
        var cleanupStart = document.getElementById( 'wpllmseo-cleanup-start' );
        var cleanupStop = document.getElementById( 'wpllmseo-cleanup-stop' );
        var cleanupSpinner = document.getElementById( 'wpllmseo-cleanup-spinner' );

        var migrationDry = document.getElementById( 'wpllmseo-migration-dryrun' );
        var migrationRun = document.getElementById( 'wpllmseo-migration-run' );
        var migrationStart = document.getElementById( 'wpllmseo-migration-start' );
        var migrationStop = document.getElementById( 'wpllmseo-migration-stop' );
        var migrationSpinner = document.getElementById( 'wpllmseo-migration-spinner' );

        var autoRunning = false;

        function appendOutput( obj ) {
            var out = document.getElementById( 'wpllmseo-migration-output' );
            out.textContent = out.textContent + '\n' + JSON.stringify( obj );
        }

        function setRunningUI( running, spinnerEl, startBtn, stopBtn ) {
            if ( running ) {
                spinnerEl.style.display = '';
                startBtn.style.display = 'none';
                stopBtn.style.display = '';
            } else {
                spinnerEl.style.display = 'none';
                startBtn.style.display = '';
                stopBtn.style.display = 'none';
            }
        }

        if ( dryrunBtn ) {
            dryrunBtn.addEventListener( 'click', function() {
                var batch = parseInt( document.getElementById( 'wpllmseo-cleanup-batch' ).value, 10 ) || 50;
                var offset = parseInt( document.getElementById( 'wpllmseo-cleanup-offset' ).value, 10 ) || 0;
                showOutput( 'Running preview...' );
                apiPost( 'wp-llmseo/v1/cleanup/postmeta', { batch: batch, offset: offset, execute: false } ).then( showOutput ).catch( showOutput );
            } );
        }

        if ( runBtn ) {
            runBtn.addEventListener( 'click', function() {
                if ( ! confirm( 'Are you sure you want to execute cleanup and permanently delete legacy postmeta keys? This action cannot be undone.' ) ) {
                    return;
                }
                var batch = parseInt( document.getElementById( 'wpllmseo-cleanup-batch' ).value, 10 ) || 50;
                var offset = parseInt( document.getElementById( 'wpllmseo-cleanup-offset' ).value, 10 ) || 0;
                showOutput( 'Executing cleanup...' );
                apiPost( 'wp-llmseo/v1/cleanup/postmeta', { batch: batch, offset: offset, execute: true } ).then( showOutput ).catch( showOutput );
            } );
        }

        if ( cleanupStart ) {
            cleanupStart.addEventListener( 'click', function() {
                autoRunning = true;
                setRunningUI( true, cleanupSpinner, cleanupStart, cleanupStop );
                var batch = parseInt( document.getElementById( 'wpllmseo-cleanup-batch' ).value, 10 ) || 50;
                var offset = parseInt( document.getElementById( 'wpllmseo-cleanup-offset' ).value, 10 ) || 0;

                function runBatch() {
                    if ( ! autoRunning ) {
                        setRunningUI( false, cleanupSpinner, cleanupStart, cleanupStop );
                        return;
                    }
                    apiPost( 'wp-llmseo/v1/cleanup/postmeta', { batch: batch, offset: offset, execute: false } ).then( function( res ) {
                        appendOutput( res );
                        if ( res.found > 0 && autoRunning ) {
                            // advance offset and continue
                            offset += batch;
                            setTimeout( runBatch, 500 );
                        } else {
                            setRunningUI( false, cleanupSpinner, cleanupStart, cleanupStop );
                            autoRunning = false;
                        }
                    } ).catch( function( err ) { appendOutput( err ); setRunningUI( false, cleanupSpinner, cleanupStart, cleanupStop ); autoRunning = false; } );
                }

                runBatch();
            } );
        }

        if ( cleanupStop ) {
            cleanupStop.addEventListener( 'click', function() {
                autoRunning = false;
                setRunningUI( false, cleanupSpinner, cleanupStart, cleanupStop );
            } );
        }

        if ( migrationDry ) {
            migrationDry.addEventListener( 'click', function() {
                var batch = parseInt( document.getElementById( 'wpllmseo-migration-batch' ).value, 10 ) || 50;
                var offset = parseInt( document.getElementById( 'wpllmseo-migration-offset' ).value, 10 ) || 0;
                showOutput( 'Running migration dry-run...' );
                apiPost( 'wp-llmseo/v1/migrate/embeddings', { batch: batch, offset: offset } ).then( showOutput ).catch( showOutput );
            } );
        }

        if ( migrationRun ) {
            migrationRun.addEventListener( 'click', function() {
                if ( ! confirm( 'Run migration now? This will write into the chunks table.' ) ) {
                    return;
                }
                var batch = parseInt( document.getElementById( 'wpllmseo-migration-batch' ).value, 10 ) || 50;
                var offset = parseInt( document.getElementById( 'wpllmseo-migration-offset' ).value, 10 ) || 0;
                showOutput( 'Running migration...' );
                apiPost( 'wp-llmseo/v1/migrate/embeddings', { batch: batch, offset: offset } ).then( showOutput ).catch( showOutput );
            } );
        }

        if ( migrationStart ) {
            migrationStart.addEventListener( 'click', function() {
                autoRunning = true;
                setRunningUI( true, migrationSpinner, migrationStart, migrationStop );
                var batch = parseInt( document.getElementById( 'wpllmseo-migration-batch' ).value, 10 ) || 50;
                var offset = parseInt( document.getElementById( 'wpllmseo-migration-offset' ).value, 10 ) || 0;

                function runBatch() {
                    if ( ! autoRunning ) {
                        setRunningUI( false, migrationSpinner, migrationStart, migrationStop );
                        return;
                    }
                    apiPost( 'wp-llmseo/v1/migrate/embeddings', { batch: batch, offset: offset } ).then( function( res ) {
                        appendOutput( res );
                        if ( res.migrated > 0 && autoRunning ) {
                            // advance offset and continue
                            offset += batch;
                            setTimeout( runBatch, 500 );
                        } else {
                            setRunningUI( false, migrationSpinner, migrationStart, migrationStop );
                            autoRunning = false;
                        }
                    } ).catch( function( err ) { appendOutput( err ); setRunningUI( false, migrationSpinner, migrationStart, migrationStop ); autoRunning = false; } );
                }

                runBatch();
            } );
        }

        if ( migrationStop ) {
            migrationStop.addEventListener( 'click', function() {
                autoRunning = false;
                setRunningUI( false, migrationSpinner, migrationStart, migrationStop );
            } );
        }
    } );
} )();
