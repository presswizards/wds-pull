<?php
/*
 * WDS Pull snippet — run once from ManageWP > Tools > Execute PHP, or drop
 * in mu-plugins for the migration window. Then it serves authenticated
 * requests from the target server. LOAD + RUN, nothing to configure.
 * DELETE the file (or run ?wds_action=cleanup) after cutover.
 *
 * Auth: token auto-derives as sha256(lowercase(hostname) . '|wdsnap')[0:40],
 * passed as ?token=. All actions except `ping` require it (hash_equals).
 *
 * Actions (GET):
 *   ping                    -> {"ok":true,"host":...} (no auth; liveness only)
 *   sizes&token=            -> JSON: wp-content subdir sizes, db size, php limits
 *   zip&token=&path=<rel>   -> exec-zip one path relative to wp-content
 *                              (e.g. path=plugins, path=uploads/2025) into
 *                              wdsnap/, returns {"file","bytes"}. Uses `zip`
 *                              binary (fast, streaming); no ZipArchive loads.
 *   db&token=               -> mysqldump into wdsnap/, returns {"file","bytes"}.
 *                              404 + JSON error when no mysqldump binary.
 *   cleanup&token=          -> deletes the whole wdsnap dir. Run post-cutover.
 *
 * Files are served by the web server itself (static URLs under
 * wp-content/wdsnap/), so the target downloads with curl --retry + resume
 * (curl -C -). Nothing is streamed through PHP.
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // direct web hit to this file: refuse.
}

define( 'WDS_PULL_DEST', WP_CONTENT_DIR . '/mu-plugins/wds-pull.php' );
define( 'WDS_PULL_MARKER', 'WDS-PULL-v1' ); // must appear in installed copy
define( 'WDS_PULL_VERSION', '1.1' );
// WDS-PULL-v1
$WDS_HOST  = strtolower( trim( (string) parse_url( home_url(), PHP_URL_HOST ) ) );
$WDS_TOKEN = substr( hash( 'sha256', $WDS_HOST . '|wdsnap' ), 0, 40 );

$snap_dir = WP_CONTENT_DIR . '/wdsnap';
if ( ! is_dir( $snap_dir ) ) {
	wp_mkdir_p( $snap_dir );
}
if ( ! file_exists( $snap_dir . '/index.php' ) ) {
	file_put_contents( $snap_dir . '/index.php', "<?php // silence\n" );
}

function wds_pull_json( $data, $code = 200 ) {
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		// no-op: headers already sent in most admin contexts; body is JSON.
	}
	header( 'Content-Type: application/json' );
	status_header( $code );
	echo json_encode( $data );
	exit;
}

function wds_pull_respond( $data, $code = 200 ) {
	// Echo now and keep running (the request returns while the build
	// continues detached). wds_pull_json() exits; this does not.
	header( 'Content-Type: application/json' );
	status_header( $code );
	echo json_encode( $data );
	if ( function_exists( 'flush' ) ) {
		flush();
	}
}

$action = isset( $_GET['wds_action'] ) ? sanitize_key( $_GET['wds_action'] ) : '';
if ( $action === '' ) {
	return; // installed copy: inert on normal loads. (First install is done
	        // by the loader snippet, which reports the token + actions.)
}
if ( $action === 'ping' ) {
	wds_pull_json( array( 'ok' => true, 'host' => $WDS_HOST, 'php' => PHP_VERSION ) );
}
$token = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';
if ( strlen( $token ) !== 40 || ! hash_equals( $WDS_TOKEN, $token ) ) {
	wds_pull_json( array( 'error' => 'bad token' ), 403 );
}

// ---- async job queue: every request answers fast; builds run detached ----
function wds_job_file( $job ) {
	global $WDS_TOKEN;
	$job = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $job ) );
	if ( $job === '' ) {
		return null;
	}
	return WP_CONTENT_DIR . '/wdsnap/' . $GLOBALS['WDS_TOKEN'] . '-job-' . $job . '.json';
}
function wds_job_read( $job ) {
	$f = wds_job_file( $job );
	if ( ! $f || ! file_exists( $f ) ) {
		return null;
	}
	$d = json_decode( file_get_contents( $f ), true );
	return is_array( $d ) ? $d : null;
}
function wds_job_write( $job, $data ) {
	$f = wds_job_file( $job );
	if ( ! $f ) {
		return false;
	}
	$data['updated'] = time();
	return file_put_contents( $f, json_encode( $data ) ) !== false;
}
function wds_job_id( $kind, $rel ) {
	global $WDS_TOKEN;
	return substr( sha1( $WDS_TOKEN . '|' . $kind . '|' . $rel ), 0, 12 );
}
function wds_job_stale( $d ) {
	// running untouched 30+ min = dead worker; requeueable.
	return ( $d['state'] ?? '' ) === 'running' && ( time() - (int) ( $d['updated'] ?? 0 ) ) > 1800;
}
function wds_detach_and_build( $job, $build_fn ) {
	// Answer NOW, build after the HTTP connection closes.
	@set_time_limit( 0 );
	@ignore_user_abort( true );
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	// Re-check state post-detach: another worker may have finished it.
	$d = wds_job_read( $job );
	if ( ! $d || ( $d['state'] ?? '' ) !== 'queued' ) {
		return;
	}
	$d['state'] = 'running';
	wds_job_write( $job, $d );
	$build_fn( $d );
}

switch ( $action ) {
	case 'sizes':
		$dirs  = array();
		$total = 0;
		foreach ( glob( WP_CONTENT_DIR . '/*', GLOB_ONLYDIR ) as $d ) {
			$rel = basename( $d );
			if ( $rel === 'wdsnap' ) {
				continue;
			}
			$out = array();
			@exec( 'du -sb ' . escapeshellarg( $d ) . ' 2>/dev/null', $out );
			$bytes = (int) ( isset( $out[0] ) ? explode( "\t", $out[0] )[0] : 0 );
			// uploads drill-down: per-year sizes so the target can split pulls.
			$subs = null;
			if ( $rel === 'uploads' ) {
				$subs = array();
				foreach ( glob( $d . '/*', GLOB_ONLYDIR ) as $sd ) {
					$o2 = array();
					@exec( 'du -sb ' . escapeshellarg( $sd ) . ' 2>/dev/null', $o2 );
					$subs[ basename( $sd ) ] = (int) ( isset( $o2[0] ) ? explode( "\t", $o2[0] )[0] : 0 );
				}
			}
			$dirs[ $rel ] = $subs === null ? $bytes : array( 'total' => $bytes, 'subdirs' => $subs );
			$total += $bytes;
		}
		global $wpdb;
		$db_bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);
		$zips = array();
		foreach ( glob( $snap_dir . '/*.zip' ) as $z ) {
			$zips[ basename( $z ) ] = filesize( $z );
		}
		wds_pull_json(
			array(
				'host'        => $WDS_HOST,
				'wp_content'  => $total,
				'dirs'        => $dirs,
				'db_bytes'    => $db_bytes,
				'php_version' => PHP_VERSION,
				'has_zip_bin' => trim( (string) @shell_exec( 'command -v zip 2>/dev/null' ) ) !== '',
				'has_dump'    => trim( (string) @shell_exec( 'command -v mysqldump 2>/dev/null' ) ) !== '',
				'exec_ok'     => function_exists( 'exec' ),
				'ready_zips'  => $zips,
			)
		);
		break;

	case 'zip':
		// Queue a folder build; answers immediately. Poll status, then download.
		if ( ! function_exists( 'exec' ) ) {
			wds_pull_json( array( 'error' => 'exec disabled on this host' ), 500 );
		}
		$rel = isset( $_GET['path'] ) ? trim( (string) $_GET['path'], '/' ) : '';
		if ( $rel === '' || strpos( $rel, '..' ) !== false || strpos( $rel, './' ) !== false ) {
			wds_pull_json( array( 'error' => 'bad path' ), 400 );
		}
		if ( ! file_exists( WP_CONTENT_DIR . '/' . $rel ) ) {
			wds_pull_json( array( 'error' => 'path not found' ), 404 );
		}
		$job = wds_job_id( 'zip', $rel );
		$d   = wds_job_read( $job );
		if ( $d && ( $d['state'] ?? '' ) === 'done' && ! empty( $d['file'] ) ) {
			wds_pull_json( array( 'job' => $job, 'state' => 'done', 'file' => $d['file'], 'bytes' => $d['bytes'] ) );
		}
		if ( ! $d || wds_job_stale( $d ) ) {
			wds_job_write( $job, array( 'state' => 'queued', 'kind' => 'zip', 'path' => $rel ) );
			$d = wds_job_read( $job );
		}
		if ( ( $d['state'] ?? '' ) === 'queued' ) {
			wds_pull_respond( array( 'job' => $job, 'state' => 'queued' ) );
			wds_detach_and_build(
				$job,
				function ( $dd ) {
					$job = wds_job_id( 'zip', $dd['path'] );
					$snap_dir = WP_CONTENT_DIR . '/wdsnap';
					$safe = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $dd['path'] );
					$dest = $snap_dir . '/' . $GLOBALS['WDS_TOKEN'] . '-' . $safe . '.zip';
					@unlink( $dest );
					$cmd = sprintf(
						'cd %s && zip -rq -9 %s %s > /dev/null 2>&1',
						escapeshellarg( WP_CONTENT_DIR ),
						escapeshellarg( $dest ),
						escapeshellarg( $dd['path'] )
					);
					@exec( $cmd, $out, $rc );
					if ( $rc === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) {
						wds_job_write( $job, array( 'state' => 'done', 'kind' => 'zip', 'path' => $dd['path'], 'file' => content_url( 'wdsnap/' . basename( $dest ) ), 'bytes' => filesize( $dest ) ) );
					} else {
						@unlink( $dest );
						wds_job_write( $job, array( 'state' => 'error', 'kind' => 'zip', 'path' => $dd['path'], 'message' => 'zip failed', 'rc' => (int) $rc ) );
					}
				}
			);
		}
		break;

	case 'status':
		// Poll a job. No building here — answers in ms.
		$job = isset( $_GET['job'] ) ? (string) $_GET['job'] : '';
		$d   = wds_job_read( $job );
		if ( ! $d ) {
			wds_pull_json( array( 'error' => 'unknown job' ), 404 );
		}
		$out = array( 'job' => $job, 'state' => $d['state'] ?? 'queued' );
		foreach ( array( 'file', 'bytes', 'message', 'rc' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$out[ $k ] = $d[ $k ];
			}
		}
		wds_pull_json( $out );
		break;

	case 'db':
		// Queue a DB dump; answers immediately. Poll status, then download.
		$mysqldump = trim( (string) @shell_exec( 'command -v mysqldump 2>/dev/null || which mysqldump 2>/dev/null' ) );
		if ( $mysqldump === '' || ! function_exists( 'exec' ) ) {
			wds_pull_json( array( 'error' => 'no mysqldump binary: dump the DB another way' ), 500 );
		}
		$job = wds_job_id( 'db', DB_NAME );
		$d   = wds_job_read( $job );
		if ( $d && ( $d['state'] ?? '' ) === 'done' && ! empty( $d['file'] ) ) {
			wds_pull_json( array( 'job' => $job, 'state' => 'done', 'file' => $d['file'], 'bytes' => $d['bytes'] ) );
		}
		if ( ! $d || wds_job_stale( $d ) ) {
			wds_job_write( $job, array( 'state' => 'queued', 'kind' => 'db', 'path' => DB_NAME ) );
			$d = wds_job_read( $job );
		}
		if ( ( $d['state'] ?? '' ) === 'queued' ) {
			wds_pull_respond( array( 'job' => $job, 'state' => 'queued' ) );
			wds_detach_and_build(
				$job,
				function ( $dd ) {
					$job = wds_job_id( 'db', $dd['path'] );
					$snap_dir = WP_CONTENT_DIR . '/wdsnap';
					$dest = $snap_dir . '/' . $GLOBALS['WDS_TOKEN'] . '-db.sql';
					$mhost = DB_HOST;
					$msock = '';
					$mport = '';
					if ( preg_match( '/^(.*?):(\/.*)$/', DB_HOST, $mm ) ) {
						$mhost = $mm[1];
						$msock = $mm[2];
					} elseif ( preg_match( '/^(.*?):(\d+)$/', DB_HOST, $mm ) ) {
						$mhost = $mm[1];
						$mport = $mm[2];
					}
					$mysqldump = trim( (string) @shell_exec( 'command -v mysqldump 2>/dev/null || which mysqldump 2>/dev/null' ) );
					$base = sprintf(
						'%s --single-transaction --quick -h %s%s%s -u %s %s %s',
						escapeshellarg( $mysqldump ),
						escapeshellarg( $mhost ),
						$msock !== '' ? ' --socket=' . escapeshellarg( $msock ) : '',
						$mport !== '' ? ' -P' . (int) $mport : '',
						escapeshellarg( DB_USER ),
						DB_PASSWORD !== '' ? '-p' . escapeshellarg( DB_PASSWORD ) : '',
						escapeshellarg( DB_NAME )
					);
					$errf = $dest . '.err';
					@exec( $base . ' --routines --events > ' . escapeshellarg( $dest ) . ' 2> ' . escapeshellarg( $errf ), $out, $rc );
					if ( $rc !== 0 ) {
						@exec( $base . ' > ' . escapeshellarg( $dest ) . ' 2> ' . escapeshellarg( $errf ), $out, $rc );
					}
					@unlink( $errf );
					$head = file_exists( $dest ) ? file_get_contents( $dest, false, null, 0, 2000 ) : '';
					if ( $rc === 0 && strpos( $head, 'CREATE TABLE' ) !== false ) {
						wds_job_write( $job, array( 'state' => 'done', 'kind' => 'db', 'path' => $dd['path'], 'file' => content_url( 'wdsnap/' . basename( $dest ) ), 'bytes' => filesize( $dest ) ) );
					} else {
						@unlink( $dest );
						wds_job_write( $job, array( 'state' => 'error', 'kind' => 'db', 'path' => $dd['path'], 'message' => 'mysqldump failed', 'rc' => (int) $rc ) );
					}
				}
			);
		}
		break;

	case 'cleanup':
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$fs = new WP_Filesystem_Direct( null );
		$fs->rmdir( $snap_dir, true );
		wds_pull_json( array( 'cleaned' => true ) );
		break;

	default:
		wds_pull_json( array( 'error' => 'unknown action' ), 400 );
}
