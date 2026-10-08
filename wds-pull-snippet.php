<?php
/*
 * WDS Pull LOADER — paste whole file into ManageWP > Tools > Execute PHP.
 * LOAD + RUN, nothing to configure. Self-installs, no manual steps.
 *
 * First run: downloads the endpoint from the public repo into
 * wp-content/mu-plugins/wds-pull.php. Later ?wds_action= requests load it
 * per-request. Re-runs just report status. Frontend loads stay inert
 * (installer refuses outside an actionable context, silently).
 * DELETE wp-content/mu-plugins/wds-pull.php + ?wds_action=cleanup after cutover.
 *
 * Source: https://github.com/presswizards/wds-pull
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "WDS loader starting...\n";
if ( function_exists( 'flush' ) ) { flush(); }

if ( ! function_exists( 'wds_pull_loader_install' ) ) {
	define( 'WDS_PULL_SOURCE', 'https://raw.githubusercontent.com/presswizards/wds-pull/main/wds-pull.php' );
	define( 'WDS_PULL_DEST', WP_CONTENT_DIR . '/mu-plugins/wds-pull.php' );
	define( 'WDS_PULL_MARKER', 'WDS-PULL-v1' );
	function wds_pull_loader_install() {
		$dest = WDS_PULL_DEST;
		$have = file_exists( $dest ) ? file_get_contents( $dest ) : false;
		if ( $have !== false && strpos( $have, WDS_PULL_MARKER ) !== false ) {
			return 'installed';
		}
		// ManageWP Execute PHP is not admin context; run anywhere interactive.
		$code = wp_remote_retrieve_body( wp_remote_get( WDS_PULL_SOURCE, array( 'timeout' => 30 ) ) );
		if ( ! is_string( $code ) || $code === '' || strpos( $code, WDS_PULL_MARKER ) === false ) {
			return 'fetch-failed';
		}
		if ( ! is_dir( dirname( $dest ) ) ) {
			wp_mkdir_p( dirname( $dest ) );
		}
		if ( file_put_contents( $dest, $code ) === false ) {
			return 'write-failed';
		}
		return 'just-installed';
	}
}

$WDS_HOST  = strtolower( trim( (string) parse_url( home_url(), PHP_URL_HOST ) ) );
$WDS_TOKEN = substr( hash( 'sha256', $WDS_HOST . '|wdsnap' ), 0, 40 );

$wds_st = wds_pull_loader_install();
if ( $wds_st === 'installed' || $wds_st === 'just-installed' ) {
	echo ( $wds_st === 'just-installed' ? "INSTALLED to mu-plugins.\n" : "Already installed.\n" );
	echo "Endpoint ready. Token: $WDS_TOKEN\n";
	echo "Actions: ping | sizes | zip | db | cleanup (all but ping need ?token=).\n";
} else {
	echo "INSTALL FAILED ($wds_st).\n";
}
return;
