<?php
/**
 * Repository-contained PHP unit tests.
 *
 * These tests exercise pure policy rules and the account-status workflow using
 * lightweight WordPress function stubs. They do not require a WordPress
 * installation and do not touch any external site or database.
 *
 * Run with: php tests/run-tests.php
 *
 * @package Penalis_Platform
 */

require_once __DIR__ . '/wp-stubs.php';

// The real bootstrap defines these; the unit tests include class files directly.
if ( ! defined( 'PENALIS_PLATFORM_VERSION' ) ) {
	define( 'PENALIS_PLATFORM_VERSION', '1.6.0' );
}
if ( ! defined( 'PENALIS_PLATFORM_DIR' ) ) {
	define( 'PENALIS_PLATFORM_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'PENALIS_PLATFORM_URL' ) ) {
	define( 'PENALIS_PLATFORM_URL', 'https://example.com/wp-content/plugins/penalis-platform/' );
}

require_once __DIR__ . '/../includes/class-policy.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-datetime.php';
require_once __DIR__ . '/../includes/class-editorial-policy.php';
require_once __DIR__ . '/../includes/class-roles.php';
require_once __DIR__ . '/../includes/class-account-status.php';
require_once __DIR__ . '/../includes/class-editorial-workflow.php';
require_once __DIR__ . '/../includes/class-editorial-notifications.php';
require_once __DIR__ . '/../includes/class-article-takedown.php';
require_once __DIR__ . '/../includes/class-admin.php';
require_once __DIR__ . '/../includes/class-editorial-admin.php';
require_once __DIR__ . '/../includes/class-access-control.php';
require_once __DIR__ . '/../includes/class-rest-api.php';
require_once __DIR__ . '/../includes/class-avatar.php';
require_once __DIR__ . '/../includes/class-default-thumbnail.php';
require_once __DIR__ . '/../includes/class-media-usage.php';
require_once __DIR__ . '/../includes/class-dashboard.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-analytics.php';
require_once __DIR__ . '/../includes/class-activator.php';
require_once __DIR__ . '/../includes/class-email-template.php';
require_once __DIR__ . '/../includes/class-email-settings.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-settings-panels.php';
require_once __DIR__ . '/../includes/class-emails.php';
require_once __DIR__ . '/../includes/class-email-log.php';
require_once __DIR__ . '/../includes/class-email-log-admin.php';
require_once __DIR__ . '/../includes/class-author-history.php';
require_once __DIR__ . '/../includes/class-username-change.php';
require_once __DIR__ . '/../includes/class-password-reset.php';
require_once __DIR__ . '/../includes/class-registration.php';
require_once __DIR__ . '/../includes/class-legacy-activation.php';
require_once __DIR__ . '/../includes/class-otp.php';
require_once __DIR__ . '/../includes/class-email-change.php';
require_once __DIR__ . '/../includes/class-turnstile.php';
require_once __DIR__ . '/../includes/class-revisions.php';
require_once __DIR__ . '/../includes/class-authors.php';
require_once __DIR__ . '/../includes/class-daily-quotes.php';
require_once __DIR__ . '/../includes/class-daily-quotes-admin.php';
require_once __DIR__ . '/../includes/class-reactions.php';
require_once __DIR__ . '/../includes/class-views.php';
require_once __DIR__ . '/../includes/class-toc.php';
require_once __DIR__ . '/../includes/class-article-share.php';
require_once __DIR__ . '/../includes/class-author-archive.php';

$GLOBALS['penalis_failures'] = 0;
$GLOBALS['penalis_assertions'] = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Message.
 * @return void
 */
function penalis_assert( $condition, $message ) {
	$GLOBALS['penalis_assertions']++;

	if ( $condition ) {
		return;
	}

	$GLOBALS['penalis_failures']++;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

/**
 * Assert two values are identical.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $message  Message.
 * @return void
 */
function penalis_same( $expected, $actual, $message ) {
	penalis_assert(
		$expected === $actual,
		$message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
	);
}

/**
 * Extract the six-digit code from the most recently captured email.
 *
 * The code is never stored, so tests read it from the delivered message the
 * same way a writer would. The email also contains six-digit CSS colours, so
 * the code is matched by its position after the "Kode verifikasi kamu:" line.
 *
 * @return string
 */
function penalis_last_code() {
	$mail = $GLOBALS['penalis_test_mail'];
	$last = end( $mail );

	if ( ! is_array( $last ) || ! isset( $last[2] ) ) {
		return '';
	}

	if ( 1 !== preg_match( '/Kode verifikasi kamu:<\/p><p[^>]*>([0-9]{6})<\/p>/', (string) $last[2], $matches ) ) {
		return '';
	}

	return $matches[1];
}

/**
 * Reset test state.
 *
 * @return void
 */
function penalis_reset() {
	$GLOBALS['penalis_test_users']        = array();
	$GLOBALS['penalis_test_role_changes'] = array();
	$GLOBALS['penalis_test_options']      = array();
	$GLOBALS['penalis_test_transients']   = array();
	$GLOBALS['penalis_test_mail']         = array();
	$GLOBALS['penalis_test_cache_deletes'] = array();
	$GLOBALS['penalis_test_cleaned_users'] = array();
	$GLOBALS['penalis_test_reset_key_users'] = array();
	$GLOBALS['penalis_test_password_resets'] = array();
	$GLOBALS['penalis_test_posts']           = array();
	$GLOBALS['penalis_test_post_terms']      = array();
	$GLOBALS['penalis_test_post_views']      = array();
	$GLOBALS['penalis_test_post_updates']    = array();
	$GLOBALS['penalis_test_password_notifications'] = array();
	$GLOBALS['penalis_test_filters']         = array();
	$GLOBALS['penalis_test_actions']         = array(
		'after_password_reset' => array( 10 => array( 'wp_password_change_notification' ) ),
	);
	unset(
		$GLOBALS['penalis_test_sessions_destroyed'],
		$GLOBALS['penalis_test_auth_cookie_cleared'],
		$GLOBALS['penalis_test_duplicate_login'],
		$GLOBALS['penalis_test_lock_available'],
		$GLOBALS['penalis_test_update_fails'],
		$GLOBALS['penalis_test_verified_user'],
		$GLOBALS['penalis_test_updated_login'],
		$GLOBALS['penalis_test_post_update_fails'],
		$GLOBALS['penalis_test_current_user'],
		$GLOBALS['penalis_test_mail_fails'],
		$GLOBALS['penalis_test_db_events'],
		$GLOBALS['penalis_test_lock_queries'],
		$GLOBALS['penalis_test_on_lock']
	);

	// Mirror the production bootstrap: editorial notifications are driven by
	// the hook, not by a second direct call from the workflow service.
	Penalis_Editorial_Notifications::init();
}

/* -------------------------------------------------------------------------
 * Policy: statuses and transitions
 * ---------------------------------------------------------------------- */

$statuses = Penalis_Policy::statuses();
penalis_same( 3, count( $statuses ), 'There are three account statuses.' );
penalis_assert( Penalis_Policy::is_valid_status( 'active' ), 'active is valid.' );
penalis_assert( Penalis_Policy::is_valid_status( 'pending_verification' ), 'pending_verification is valid.' );
penalis_assert( Penalis_Policy::is_valid_status( 'suspended' ), 'suspended is valid.' );
penalis_assert( ! Penalis_Policy::is_valid_status( 'pending_approval' ), 'pending_approval is not an account status.' );
penalis_assert( ! Penalis_Policy::is_valid_status( 'rejected' ), 'rejected is not an account status.' );
penalis_assert( ! Penalis_Policy::is_valid_status( 'administrator' ), 'administrator is not an account status.' );
penalis_assert( ! Penalis_Policy::is_valid_status( null ), 'null is not an account status.' );

penalis_assert(
	Penalis_Policy::can_transition( 'pending_verification', 'active' ),
	'Verification advances directly to active.'
);
penalis_assert(
	Penalis_Policy::can_transition( 'pending_verification', 'suspended' ),
	'Unverified spam accounts can be suspended.'
);
penalis_assert(
	Penalis_Policy::can_transition( 'active', 'suspended' ),
	'Active accounts can be suspended.'
);
penalis_assert(
	Penalis_Policy::can_transition( 'suspended', 'active' ),
	'Suspended accounts can be reactivated.'
);
penalis_assert(
	! Penalis_Policy::can_transition( 'active', 'pending_verification' ),
	'Active accounts cannot revert to pending verification.'
);
penalis_assert(
	! Penalis_Policy::can_transition( 'suspended', 'pending_verification' ),
	'Suspended accounts cannot revert to pending verification.'
);
penalis_assert(
	! Penalis_Policy::can_transition( 'active', 'active' ),
	'Same-state transitions are rejected.'
);

/* -------------------------------------------------------------------------
 * Policy: authorization decisions
 * ---------------------------------------------------------------------- */

penalis_assert( Penalis_Policy::can_login( 'pending_verification', false ), 'Unverified accounts may sign in to view status.' );
penalis_assert( Penalis_Policy::can_login( 'active', true ), 'Active accounts with access may sign in.' );
penalis_assert( ! Penalis_Policy::can_login( 'active', false ), 'Active accounts without access may not sign in.' );
penalis_assert( ! Penalis_Policy::can_login( 'suspended', true ), 'Suspended accounts may never sign in.' );
penalis_assert( Penalis_Policy::can_login( '', true ), 'Legacy account with explicit access may sign in.' );
penalis_assert( ! Penalis_Policy::can_login( '', false ), 'Legacy account without access may not sign in.' );

penalis_assert( Penalis_Policy::can_write( 'active', true ), 'Active writers may write.' );
penalis_assert( ! Penalis_Policy::can_write( 'pending_verification', true ), 'Unverified writers may not write.' );
penalis_assert( ! Penalis_Policy::can_write( 'suspended', true ), 'Suspended writers may not write.' );
penalis_assert( ! Penalis_Policy::can_write( 'active', false ), 'Writers without access may not write.' );

penalis_assert( Penalis_Policy::can_reset_password( 'pending_verification', false ), 'Unverified accounts may reset their password.' );
penalis_assert( Penalis_Policy::can_reset_password( 'active', true ), 'Active accounts with access may reset their password.' );
penalis_assert( ! Penalis_Policy::can_reset_password( 'active', false ), 'Active accounts without access may not reset their password.' );
penalis_assert( ! Penalis_Policy::can_reset_password( 'suspended', true ), 'Suspended accounts may not reset their password.' );
penalis_assert( Penalis_Policy::can_reset_password( '', true ), 'Legacy account with explicit access may reset its password.' );
penalis_assert( ! Penalis_Policy::can_reset_password( '', false ), 'Legacy account without access may not reset its password.' );

penalis_assert(
	Penalis_Policy::can_edit_article( 7, 7, 'active', true ),
	'Owners may edit their own article.'
);
penalis_assert(
	! Penalis_Policy::can_edit_article( 7, 8, 'active', true ),
	'Writers may not edit another author\'s article.'
);
penalis_assert(
	! Penalis_Policy::can_edit_article( 7, 7, 'suspended', true ),
	'Suspended writers may not edit their own article.'
);

$author = Penalis_Policy::resolve_author( null, 12 );
penalis_same( 12, $author['author'], 'Missing author resolves to the current user.' );

$author = Penalis_Policy::resolve_author( '12', 12 );
penalis_same( 12, $author['author'], 'Matching author is accepted.' );

$author = Penalis_Policy::resolve_author( '13', 12 );
penalis_assert( isset( $author['error'] ), 'Assigning another author is rejected.' );

$author = Penalis_Policy::resolve_author( 'abc', 12 );
penalis_assert( isset( $author['error'] ), 'Non-numeric author is rejected.' );

$author = Penalis_Policy::resolve_author( null, 0 );
penalis_assert( isset( $author['error'] ), 'Anonymous author assignment is rejected.' );

/* -------------------------------------------------------------------------
 * Policy: validation
 * ---------------------------------------------------------------------- */

$valid_registration = array(
	'display_name'    => 'Ada Lovelace',
	'user_login'      => 'ada_lovelace',
	'user_email'      => 'ada@example.com',
	'password'        => 'correct horse battery',
	'penalis_consent' => '1',
);

penalis_same( array(), Penalis_Policy::validate_registration( $valid_registration ), 'Valid registration passes.' );

$errors = Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'password' => 'short' ) ) );
penalis_assert( isset( $errors['password'] ), 'Short password is rejected.' );

$errors = Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'user_email' => 'nope' ) ) );
penalis_assert( isset( $errors['user_email'] ), 'Invalid email is rejected.' );

$errors = Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'user_login' => 'bad name' ) ) );
penalis_assert( isset( $errors['user_login'] ), 'Invalid username is rejected.' );

$errors = Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'penalis_consent' => '' ) ) );
penalis_assert( isset( $errors['penalis_consent'] ), 'Consent is required.' );

$errors = Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'penalis_website' => 'bot' ) ) );
penalis_assert( isset( $errors['_honeypot'] ), 'Filled honeypot is rejected.' );

penalis_same( '', Penalis_Policy::validate_password( 'twelve chars' ), 'Twelve-character password passes.' );
penalis_assert( '' !== Penalis_Policy::validate_password( 'short' ), 'Short password fails.' );
penalis_assert( '' !== Penalis_Policy::validate_password( str_repeat( 'a', 201 ) ), 'Overlong password fails.' );

penalis_same( array(), Penalis_Policy::validate_profile( array( 'display_name' => 'Ada', 'description' => 'Writer.' ) ), 'Valid profile passes.' );
penalis_same( array(), Penalis_Policy::validate_profile( array( 'description' => str_repeat( 'a', Penalis_Policy::MAX_BIO ) ) ), 'Bio at the 250-character maximum passes.' );
$errors = Penalis_Policy::validate_profile( array( 'description' => str_repeat( 'a', Penalis_Policy::MAX_BIO + 1 ) ) );
penalis_assert( isset( $errors['description'] ), 'Overlong bio (> 250 chars) is rejected.' );
penalis_same( "Baris 1\nBaris 2", Penalis_Policy::normalize_bio( "  Baris 1  \r\n\r\n \n\nBaris 2\n\n  " ), 'Bio normalization collapses blank lines to a single newline.' );
penalis_same( 250, Penalis_Policy::bio_length( "  " . str_repeat( 'a', 240 ) . "\n\n\n" . str_repeat( 'b', 9 ) . "  " ), 'Bio length counts normalized characters and single newlines.' );

penalis_same( 'penalis_id', Penalis_Policy::normalize_instagram( '  @penalis_id  ' ), 'Instagram normalization strips leading @ and trims whitespace.' );
penalis_same( 'penalis', Penalis_Policy::normalize_instagram( 'https://www.instagram.com/penalis/' ), 'Instagram profile URL normalizes to the bare handle.' );
penalis_same( '', Penalis_Policy::normalize_instagram( '   ' ), 'Blank Instagram normalizes to empty string.' );
penalis_same( '', Penalis_Policy::validate_instagram( '' ), 'Empty Instagram is valid because the field is optional.' );
penalis_same( '', Penalis_Policy::validate_instagram( '@penalis.seni_01' ), 'Instagram with letters, digits, dots, and underscores is valid.' );
penalis_same( '', Penalis_Policy::validate_instagram( 'https://instagram.com/penalis' ), 'Instagram profile URL with a valid handle passes validation.' );
penalis_assert( '' !== Penalis_Policy::validate_instagram( str_repeat( 'a', 31 ) ), 'Instagram longer than 30 characters is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_instagram( 'https://example.com/penalis' ), 'Non-Instagram URL is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_instagram( 'penalis-seni' ), 'Instagram with hyphens is rejected.' );

penalis_same(
	array(),
	Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'instagram' => '@penalis.id' ) ) ),
	'Registration with valid optional Instagram passes.'
);
$errors = Penalis_Policy::validate_registration( array_merge( $valid_registration, array( 'instagram' => 'bad handle!' ) ) );
penalis_assert( isset( $errors['instagram'] ), 'Registration with invalid Instagram is rejected.' );
$errors = Penalis_Policy::validate_profile( array( 'instagram' => 'bad handle!' ) );
penalis_assert( isset( $errors['instagram'] ), 'Profile with invalid Instagram is rejected.' );

$tags = Penalis_Policy::validate_tag_names( array( 'Poetry', 'poetry', '  Essays  ' ) );
penalis_same( array( 'Poetry', 'Essays' ), $tags['names'], 'Tags are trimmed and deduplicated.' );

$too_many = array();
for ( $i = 0; $i < 11; $i++ ) {
	$too_many[] = 'tag-' . $i;
}
penalis_assert( isset( Penalis_Policy::validate_tag_names( $too_many )['error'] ), 'More than ten tags is rejected.' );

penalis_assert( isset( Penalis_Policy::validate_tag_names( array( str_repeat( 'a', 51 ) ) )['error'] ), 'Overlong tag is rejected.' );

penalis_assert( Penalis_Policy::is_allowed_post_status( 'draft' ), 'Draft is allowed.' );
penalis_assert( Penalis_Policy::is_allowed_post_status( 'publish' ), 'Publish is allowed.' );
penalis_assert( ! Penalis_Policy::is_allowed_post_status( 'pending' ), 'Pending review is not offered by the dashboard.' );
penalis_assert( ! Penalis_Policy::is_allowed_post_status( 'private' ), 'Private is not allowed for writers.' );
penalis_assert( ! Penalis_Policy::is_allowed_post_status( 'future' ), 'Future is not allowed for writers.' );

/* -------------------------------------------------------------------------
 * Policy: tokens
 * ---------------------------------------------------------------------- */

$token = Penalis_Policy::generate_token();
penalis_assert( Penalis_Policy::is_token_format_valid( $token ), 'Generated challenge ID format is valid.' );
penalis_assert( strlen( $token ) >= 40 && strlen( $token ) <= 64, 'Generated challenge ID length is in range.' );
penalis_assert( $token !== Penalis_Policy::generate_token(), 'Challenge IDs are unique.' );
penalis_assert( ! Penalis_Policy::is_token_format_valid( 'short' ), 'Short challenge ID is rejected.' );
penalis_assert( ! Penalis_Policy::is_token_format_valid( str_repeat( '!', 50 ) ), 'Invalid characters are rejected.' );
penalis_assert( ! Penalis_Policy::is_token_format_valid( '123456' ), 'A six-digit code is never a valid challenge ID.' );

penalis_same( 600, Penalis_Policy::OTP_TTL, 'Verification codes expire after ten minutes.' );
penalis_same( 5, Penalis_Policy::OTP_MAX_ATTEMPTS, 'A challenge allows five attempts.' );
penalis_same( 6, Penalis_Policy::OTP_CODE_LENGTH, 'Verification codes are six digits.' );

/* -------------------------------------------------------------------------
 * Roles
 * ---------------------------------------------------------------------- */

$caps = Penalis_Roles::capabilities();
penalis_assert( ! empty( $caps['edit_posts'] ), 'Writer role can edit posts.' );
penalis_assert( ! empty( $caps['publish_posts'] ), 'Writer role can publish posts.' );
penalis_assert( empty( $caps['edit_others_posts'] ), 'Writer role cannot edit others\' posts.' );
penalis_assert( empty( $caps['delete_others_posts'] ), 'Writer role cannot delete others\' posts.' );
penalis_assert( empty( $caps['manage_options'] ), 'Writer role cannot manage options.' );
penalis_assert( empty( $caps['list_users'] ), 'Writer role cannot list users.' );
penalis_assert( empty( $caps['promote_users'] ), 'Writer role cannot promote users.' );
penalis_assert( empty( $caps['install_plugins'] ), 'Writer role cannot install plugins.' );
penalis_assert( empty( $caps['switch_themes'] ), 'Writer role cannot switch themes.' );
penalis_assert( empty( $caps['unfiltered_html'] ), 'Writer role cannot post unfiltered HTML.' );

/* -------------------------------------------------------------------------
 * Account status workflow
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][1] = new WP_User( 1, array( 'subscriber' ) );

penalis_assert( Penalis_Account_Status::initialize_pending( 1 ), 'Pending account initializes.' );
penalis_same( 'pending_verification', Penalis_Account_Status::get_status( 1 ), 'New account starts unverified.' );
penalis_assert( ! Penalis_Account_Status::is_access_enabled( 1 ), 'New account has no access.' );
penalis_assert( ! Penalis_Account_Status::can_write( 1 ), 'New account cannot write.' );
penalis_assert( Penalis_Account_Status::is_managed_account( 1 ), 'New account is plugin-managed.' );
penalis_assert( Penalis_Account_Status::can_login( 1 ), 'Unverified account may sign in to view status.' );

// Verification now uses a six-digit code bound to the account, not a bearer
// token. The code itself is never stored; only an HMAC digest is.
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$challenge       = Penalis_Otp::issue( 'registration', 1, 'user1@example.com' );
penalis_assert( ! is_wp_error( $challenge ), 'A registration challenge is issued.' );
penalis_assert( Penalis_Policy::is_token_format_valid( $challenge['challenge_id'] ), 'The challenge ID has the opaque token shape.' );
penalis_same( Penalis_Policy::OTP_RESEND_WAIT, $challenge['resend_after'], 'The response advertises the resend cooldown.' );
penalis_same( 1, count( $GLOBALS['penalis_test_mail'] ), 'Issuing a challenge sends exactly one email.' );
penalis_assert( 1 === preg_match( '/Kode verifikasi kamu:<\/p><p[^>]*>[0-9]{6}<\/p>/', $GLOBALS['penalis_test_mail'][0][2] ), 'The email contains a six-digit code.' );

$stored = get_user_meta( 1, '_penalis_otp_registration', true );
penalis_assert( is_array( $stored ), 'The challenge state is stored in user meta.' );
penalis_assert( ! isset( $stored['code'] ), 'The raw code is never stored.' );
penalis_same( 64, strlen( (string) $stored['hash'] ), 'Only a SHA-256 HMAC digest is stored.' );
penalis_same( 0, (int) $stored['attempts'], 'A new challenge starts with zero attempts.' );

// A wrong code is rejected and consumes an attempt.
$wrong = Penalis_Otp::consume( 'registration', $challenge['challenge_id'], '000000', static function () {
	return 'applied';
} );
penalis_assert( is_wp_error( $wrong ), 'A wrong code is rejected.' );
$stored = get_user_meta( 1, '_penalis_otp_registration', true );
penalis_same( 1, (int) $stored['attempts'], 'A failed attempt is counted.' );

// A code issued for another purpose cannot be consumed here.
$cross = Penalis_Otp::consume( 'email_change', $challenge['challenge_id'], '000000', static function () {
	return 'applied';
} );
penalis_assert( is_wp_error( $cross ), 'A challenge cannot be consumed under another purpose.' );

$result = Penalis_Account_Status::transition( 1, 'active', 'email_verified', 0 );
penalis_assert( true === $result, 'Verification transitions directly to active.' );
penalis_same( 'active', Penalis_Account_Status::get_status( 1 ), 'Status is active after email verification.' );
penalis_same( '', (string) get_user_meta( 1, '_penalis_otp_registration', true ), 'The challenge is cleared after the status change.' );
penalis_assert( Penalis_Account_Status::is_access_enabled( 1 ), 'Verified account has access.' );
penalis_assert( in_array( 'penalis_writer', $GLOBALS['penalis_test_users'][1]->roles, true ), 'Verified account receives the writer role.' );
penalis_same( 0, (int) get_user_meta( 1, Penalis_Account_Status::META_APPROVED_BY, true ), 'Self-service verification records actor 0.' );
$result = Penalis_Account_Status::transition( 1, 'suspended', 'admin_suspended', 2 );
penalis_assert( true === $result, 'Active account can be suspended.' );
penalis_assert( ! Penalis_Account_Status::is_access_enabled( 1 ), 'Suspended account loses access.' );
penalis_assert( ! Penalis_Account_Status::can_write( 1 ), 'Suspended account cannot write.' );
penalis_assert( ! Penalis_Account_Status::can_login( 1 ), 'Suspended account cannot sign in.' );
penalis_assert( in_array( 'subscriber', $GLOBALS['penalis_test_users'][1]->roles, true ), 'Suspended account loses the writer role.' );

$result = Penalis_Account_Status::transition( 1, 'active', 'admin_reactivated', 2 );
penalis_assert( true === $result, 'Suspended account can be reactivated.' );
penalis_assert( Penalis_Account_Status::can_write( 1 ), 'Reactivated account can write.' );
penalis_same( 2, (int) get_user_meta( 1, Penalis_Account_Status::META_APPROVED_BY, true ), 'Reactivation records administrator actor.' );

$result = Penalis_Account_Status::transition( 1, 'pending_verification', 'invalid', 2 );
penalis_assert( is_wp_error( $result ), 'Active account cannot return to unverified.' );

$log = Penalis_Account_Status::get_log( 1 );
penalis_assert( count( $log ) > 0, 'Status changes are audited.' );
penalis_assert( count( $log ) <= Penalis_Account_Status::LOG_LIMIT, 'Audit log is bounded.' );

// Instagram storage on account status.
Penalis_Account_Status::set_instagram( 1, '  @penalis.seni  ' );
penalis_same( 'penalis.seni', Penalis_Account_Status::get_instagram( 1 ), 'Instagram handle is normalized and stored.' );
Penalis_Account_Status::set_instagram( 1, '' );
penalis_same( '', Penalis_Account_Status::get_instagram( 1 ), 'Clearing Instagram removes the stored handle.' );

// Legacy account: no metadata, contributor role.
penalis_reset();
$GLOBALS['penalis_test_users'][5] = new WP_User( 5, array( 'contributor' ) );
penalis_same( '', Penalis_Account_Status::get_status( 5 ), 'Legacy account has no status.' );
penalis_assert( ! Penalis_Account_Status::is_access_enabled( 5 ), 'Legacy account is not enabled.' );
penalis_assert( ! Penalis_Account_Status::can_login( 5 ), 'Legacy account cannot sign in by default.' );
penalis_assert( ! Penalis_Account_Status::can_write( 5 ), 'Legacy account cannot write through the dashboard.' );
penalis_assert( ! Penalis_Account_Status::is_managed_account( 5 ), 'Legacy account is not plugin-managed.' );
penalis_assert( Penalis_Account_Status::is_legacy_account( 5 ), 'Contributor without metadata is a legacy account.' );

// Legacy conversion rules.
penalis_assert( Penalis_Policy::can_convert_legacy( 'pending_verification' ), 'Legacy accounts may start at email verification.' );
penalis_assert( Penalis_Policy::can_convert_legacy( 'active' ), 'Legacy accounts may convert directly to active.' );
penalis_assert( ! Penalis_Policy::can_convert_legacy( 'suspended' ), 'Legacy accounts cannot be converted to suspended.' );

// Role promotion rules.
penalis_assert( Penalis_Policy::can_promote_roles( array( 'subscriber' ) ), 'Subscribers may be promoted.' );
penalis_assert( Penalis_Policy::can_promote_roles( array( 'contributor' ) ), 'Legacy contributors may be promoted.' );
penalis_assert( Penalis_Policy::can_promote_roles( array( 'penalis_writer' ) ), 'Existing writers may be re-approved.' );
penalis_assert( Penalis_Policy::can_promote_roles( array() ), 'Accounts without a role may be promoted.' );
penalis_assert( ! Penalis_Policy::can_promote_roles( array( 'editor' ) ), 'Editors are never promoted by the workflow.' );
penalis_assert( ! Penalis_Policy::can_promote_roles( array( 'administrator' ) ), 'Administrators are never promoted by the workflow.' );
penalis_assert( ! Penalis_Policy::can_promote_roles( array( 'author' ) ), 'Authors are never promoted by the workflow.' );

// Conversion keeps the user ID and never touches posts.
$result = Penalis_Account_Status::convert_legacy( 5, 'active', 'admin_vouched', 2, true );
penalis_assert( true === $result, 'A legacy account can be converted directly to active.' );
penalis_same( 'active', Penalis_Account_Status::get_status( 5 ), 'Converted account is active.' );
penalis_assert( Penalis_Account_Status::is_access_enabled( 5 ), 'Converted account has dashboard access.' );
penalis_assert( Penalis_Account_Status::can_write( 5 ), 'Converted account can write immediately.' );
penalis_assert( Penalis_Account_Status::is_managed_account( 5 ), 'Converted account is now plugin-managed.' );
penalis_assert( ! Penalis_Account_Status::is_legacy_account( 5 ), 'Converted account is no longer legacy.' );
penalis_same( '1', (string) get_user_meta( 5, Penalis_Account_Status::META_VERIFIED, true ), 'Vouched conversion marks the email verified.' );
penalis_assert( Penalis_Account_Status::needs_password( 5 ), 'A vouched legacy account still needs a password.' );
penalis_assert( in_array( 'penalis_writer', $GLOBALS['penalis_test_users'][5]->roles, true ), 'Converted legacy account receives the writer role.' );

Penalis_Account_Status::clear_needs_password( 5 );
penalis_assert( ! Penalis_Account_Status::needs_password( 5 ), 'Setting a password clears the flag.' );

$result = Penalis_Account_Status::convert_legacy( 5, 'active', 'again', 2 );
penalis_assert( is_wp_error( $result ), 'A converted account cannot be converted twice.' );

// Regression: self-service activation sets a password before converting, so
// the account must not be flagged as still needing one.
penalis_reset();
$GLOBALS['penalis_test_users'][10] = new WP_User( 10, array( 'contributor' ) );
$result = Penalis_Account_Status::convert_legacy( 10, 'active', 'legacy_self_activated', 0, false );
penalis_assert( true === $result, 'Self-service activation converts directly to active.' );
penalis_assert( ! Penalis_Account_Status::needs_password( 10 ), 'Self-service activation does not flag the account as needing a password.' );
penalis_same( '1', (string) get_user_meta( 10, Penalis_Account_Status::META_VERIFIED, true ), 'Self-service activation marks the email verified.' );
penalis_assert( Penalis_Account_Status::can_write( 10 ), 'Self-service activated legacy account can write immediately.' );
penalis_assert( in_array( 'penalis_writer', $GLOBALS['penalis_test_users'][10]->roles, true ), 'Self-service activated legacy account receives the writer role.' );

// A stale flag from an earlier state is cleared by a self-service conversion.
penalis_reset();
$GLOBALS['penalis_test_users'][11] = new WP_User( 11, array( 'contributor' ) );
update_user_meta( 11, Penalis_Account_Status::META_NEEDS_PASSWORD, '1' );
$result = Penalis_Account_Status::convert_legacy( 11, 'active', 'legacy_self_activated', 0, false );
penalis_assert( true === $result, 'Conversion succeeds with a stale flag present.' );
penalis_assert( ! Penalis_Account_Status::needs_password( 11 ), 'Self-service conversion clears a stale needs-password flag.' );

// A legacy account converted through email verification starts unverified.
penalis_reset();
$GLOBALS['penalis_test_users'][7] = new WP_User( 7, array( 'contributor' ) );
$result = Penalis_Account_Status::convert_legacy( 7, 'pending_verification', 'legacy_self_activated', 0 );
penalis_assert( true === $result, 'A legacy account can start at email verification.' );
penalis_same( '0', (string) get_user_meta( 7, Penalis_Account_Status::META_VERIFIED, true ), 'Email-verification conversion leaves the email unverified.' );
penalis_assert( ! Penalis_Account_Status::can_write( 7 ), 'Unverified converted account cannot write.' );
penalis_assert( ! Penalis_Account_Status::needs_password( 7 ), 'Email-verification conversion does not flag a missing password.' );

// Non-legacy accounts cannot be converted.
penalis_reset();
$GLOBALS['penalis_test_users'][8] = new WP_User( 8, array( 'subscriber' ) );
penalis_assert( ! Penalis_Account_Status::is_legacy_account( 8 ), 'A subscriber is not a legacy account.' );
$result = Penalis_Account_Status::convert_legacy( 8, 'active', 'nope', 2 );
penalis_assert( is_wp_error( $result ), 'A non-legacy account cannot be converted.' );

// Editors and administrators are never treated as legacy accounts.
penalis_reset();
$GLOBALS['penalis_test_users'][9] = new WP_User( 9, array( 'editor' ) );
penalis_assert( ! Penalis_Account_Status::is_legacy_account( 9 ), 'An editor is not a legacy account.' );

// Unverified spam accounts can be suspended directly by an administrator, which
// also invalidates any pending verification code.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][6] = new WP_User( 6, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 6 );
$spam_challenge = Penalis_Otp::issue( 'registration', 6, 'user6@example.com' );
penalis_assert( ! is_wp_error( $spam_challenge ), 'Unverified account receives a verification challenge.' );
penalis_assert( true === Penalis_Account_Status::transition( 6, 'suspended', 'admin_suspended', 2 ), 'Unverified account can be suspended.' );
penalis_assert( ! Penalis_Account_Status::can_login( 6 ), 'Suspended unverified account cannot sign in.' );
penalis_assert( ! Penalis_Account_Status::can_write( 6 ), 'Suspended unverified account cannot write.' );
penalis_same( '', (string) get_user_meta( 6, '_penalis_otp_registration', true ), 'Suspending an unverified account invalidates its verification code.' );
penalis_assert( true === Penalis_Account_Status::transition( 6, 'active', 'admin_reactivated', 2 ), 'Suspended account can be activated by an administrator.' );
penalis_assert( Penalis_Account_Status::can_write( 6 ), 'Activated account can write.' );

/* -------------------------------------------------------------------------
 * Public display name
 * ---------------------------------------------------------------------- */

penalis_same(
	'Siti Sukentari',
	Penalis_Policy::resolve_display_name( 'auto', 'Siti', 'Sukentari', 'sukentari' ),
	'Automatic mode uses first and last name.'
);
penalis_same(
	'Siti',
	Penalis_Policy::resolve_display_name( 'auto', 'Siti', '', 'sukentari' ),
	'Automatic mode falls back to the first name.'
);
penalis_same(
	'Sukentari',
	Penalis_Policy::resolve_display_name( 'auto', '', 'Sukentari', 'sukentari' ),
	'Automatic mode falls back to the last name.'
);
penalis_same(
	'sukentari',
	Penalis_Policy::resolve_display_name( 'auto', '', '', 'sukentari' ),
	'Automatic mode falls back to the username.'
);
penalis_same(
	'Siti',
	Penalis_Policy::resolve_display_name( 'first', 'Siti', 'Sukentari', 'sukentari' ),
	'First-name mode ignores the last name.'
);
penalis_same(
	'Sukentari',
	Penalis_Policy::resolve_display_name( 'last', 'Siti', 'Sukentari', 'sukentari' ),
	'Last-name mode ignores the first name.'
);
penalis_same(
	'sukentari',
	Penalis_Policy::resolve_display_name( 'username', 'Siti', 'Sukentari', 'sukentari' ),
	'Username mode uses the login.'
);
penalis_same(
	'Sang Pujangga',
	Penalis_Policy::resolve_display_name( 'custom', 'Siti', 'Sukentari', 'sukentari', '  Sang   Pujangga ' ),
	'Custom names are normalised.'
);
penalis_same(
	'Siti Sukentari',
	Penalis_Policy::normalize_person_name( "  Siti \t Sukentari  " ),
	'Person names are trimmed and collapsed.'
);

$errors = Penalis_Policy::validate_profile(
	array(
		'display_name_mode' => 'first',
		'first_name'        => '',
		'last_name'         => 'Sukentari',
	)
);
penalis_assert( isset( $errors['first_name'] ), 'First-name mode requires a first name.' );

$errors = Penalis_Policy::validate_profile(
	array(
		'display_name_mode' => 'last',
		'first_name'        => 'Siti',
		'last_name'         => '',
	)
);
penalis_assert( isset( $errors['last_name'] ), 'Last-name mode requires a last name.' );

$errors = Penalis_Policy::validate_profile( array( 'display_name_mode' => 'nickname' ) );
penalis_assert( isset( $errors['display_name_mode'] ), 'Unknown modes are rejected.' );

$errors = Penalis_Policy::validate_profile(
	array(
		'display_name_mode' => 'custom',
		'display_name'      => 'A',
	)
);
penalis_assert( isset( $errors['display_name'] ), 'Short custom display names are rejected.' );

$errors = Penalis_Policy::validate_profile(
	array(
		'display_name_mode' => 'auto',
		'first_name'        => str_repeat( 'a', 45 ),
		'last_name'         => str_repeat( 'b', 45 ),
	)
);
penalis_assert( isset( $errors['display_name_mode'] ), 'Derived names longer than 80 characters are rejected.' );

$errors = Penalis_Policy::validate_profile( array( 'first_name' => str_repeat( 'a', 51 ) ) );
penalis_assert( isset( $errors['first_name'] ), 'Overlong first names are rejected.' );

penalis_assert(
	empty(
		Penalis_Policy::validate_profile(
			array(
				'display_name_mode' => 'auto',
				'first_name'        => 'Siti',
				'last_name'         => 'Sukentari',
			)
		)
	),
	'Valid name fields pass validation.'
);

penalis_assert(
	empty( Penalis_Policy::validate_profile( array( 'first_name' => '', 'last_name' => '' ) ) ),
	'Empty optional name fields pass validation.'
);

penalis_reset();
$GLOBALS['penalis_test_users'][7] = new WP_User( 7, array( 'subscriber' ) );
penalis_same( 'custom', Penalis_Account_Status::get_display_name_mode( 7 ), 'Missing preference defaults to a custom name.' );
update_user_meta( 7, Penalis_Account_Status::META_DISPLAY_NAME_MODE, 'auto' );
penalis_same( 'auto', Penalis_Account_Status::get_display_name_mode( 7 ), 'Stored preference is returned.' );
update_user_meta( 7, Penalis_Account_Status::META_DISPLAY_NAME_MODE, 'bogus' );
penalis_same( 'custom', Penalis_Account_Status::get_display_name_mode( 7 ), 'Unknown stored preferences fall back to custom.' );

/* -------------------------------------------------------------------------
 * Policy: email change authorization and validation
 * ---------------------------------------------------------------------- */

penalis_assert(
	Penalis_Policy::can_change_email( 'active', true ),
	'Active accounts with access may change their email address.'
);
penalis_assert(
	! Penalis_Policy::can_change_email( 'active', false ),
	'Active accounts without access may not change their email address.'
);
penalis_assert(
	! Penalis_Policy::can_change_email( 'pending_verification', true ),
	'Unverified accounts may not change their email address.'
);
penalis_assert(
	! Penalis_Policy::can_change_email( 'suspended', true ),
	'Suspended accounts may not change their email address.'
);
penalis_assert(
	Penalis_Policy::can_change_email( '', true ),
	'Legacy accounts with explicit access may change their email address.'
);

penalis_same( '', Penalis_Policy::validate_email_change( 'baru@example.com', 'lama@example.com' ), 'A new valid address passes.' );
penalis_same( '', Penalis_Policy::validate_email_change( 'baru@example.com' ), 'A valid address passes without a current address.' );
penalis_assert( '' !== Penalis_Policy::validate_email_change( '', 'lama@example.com' ), 'An empty address is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_email_change( 'bukan-email', 'lama@example.com' ), 'A malformed address is rejected.' );
penalis_assert(
	'' !== Penalis_Policy::validate_email_change( 'Lama@Example.com', 'lama@example.com' ),
	'Reusing the current address is rejected regardless of case.'
);
penalis_assert(
	'' !== Penalis_Policy::validate_email_change( str_repeat( 'a', 250 ) . '@example.com', 'lama@example.com' ),
	'Overlong addresses are rejected.'
);

/* -------------------------------------------------------------------------
 * Verification code hardening
 * ---------------------------------------------------------------------- */

// Five wrong attempts exhaust the challenge, even if the real code follows.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][30] = new WP_User( 30, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 30 );
$exhaust = Penalis_Otp::issue( 'registration', 30, 'user30@example.com' );
$real    = penalis_last_code();

for ( $i = 0; $i < Penalis_Policy::OTP_MAX_ATTEMPTS; $i++ ) {
	$attempt = Penalis_Otp::consume( 'registration', $exhaust['challenge_id'], '000000', static function () {
		return 'applied';
	} );
	penalis_assert( is_wp_error( $attempt ), 'A wrong code is rejected on attempt ' . ( $i + 1 ) . '.' );
}

$after_exhaust = Penalis_Otp::consume( 'registration', $exhaust['challenge_id'], $real, static function () {
	return 'applied';
} );
penalis_assert( is_wp_error( $after_exhaust ), 'The real code is refused after the attempt budget is spent.' );

// An expired challenge is refused even with the correct code.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][31] = new WP_User( 31, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 31 );
$expiring = Penalis_Otp::issue( 'registration', 31, 'user31@example.com' );
$expired  = get_user_meta( 31, '_penalis_otp_registration', true );
$expired['expires'] = time() - 1;
update_user_meta( 31, '_penalis_otp_registration', $expired );

$expired_result = Penalis_Otp::consume( 'registration', $expiring['challenge_id'], penalis_last_code(), static function () {
	return 'applied';
} );
penalis_assert( is_wp_error( $expired_result ), 'An expired challenge is refused.' );

// A second issue inside the cooldown is rate limited.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][32] = new WP_User( 32, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 32 );
$first_issue  = Penalis_Otp::issue( 'registration', 32, 'user32@example.com' );
$second_issue = Penalis_Otp::issue( 'registration', 32, 'user32@example.com' );
penalis_assert( ! is_wp_error( $first_issue ), 'The first issue succeeds.' );
penalis_assert( is_wp_error( $second_issue ), 'A resend inside the cooldown is rate limited.' );
penalis_same( 'penalis_rate_limited', $second_issue->get_error_code(), 'The cooldown reports a rate limit.' );

// A throttled resend keeps the code the writer already received usable.
$throttled_resend = Penalis_Registration::resend_verification( 'user32@example.com' );
penalis_same( $first_issue['challenge_id'], $throttled_resend['challenge_id'], 'A throttled resend returns the existing challenge ID.' );
penalis_same( 1, count( $GLOBALS['penalis_test_mail'] ), 'A throttled resend sends no additional email.' );

// A replacement challenge invalidates the previous code.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][33] = new WP_User( 33, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 33 );
$old_challenge = Penalis_Otp::issue( 'registration', 33, 'user33@example.com' );
$old_code      = penalis_last_code();

// Clear the cooldown budget so a replacement can be issued immediately.
$GLOBALS['penalis_test_transients'] = array();
$new_challenge = Penalis_Otp::issue( 'registration', 33, 'user33@example.com' );
penalis_assert( ! is_wp_error( $new_challenge ), 'A replacement challenge is issued after the cooldown.' );

$old_result = Penalis_Otp::consume( 'registration', $old_challenge['challenge_id'], $old_code, static function () {
	return 'applied';
} );
penalis_assert( is_wp_error( $old_result ), 'The previous code stops working after a replacement is issued.' );

// A failed delivery discards the challenge instead of leaving a usable code.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][34] = new WP_User( 34, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 34 );
$GLOBALS['penalis_test_mail_fails'] = true;
$failed_issue = Penalis_Otp::issue( 'registration', 34, 'user34@example.com' );
unset( $GLOBALS['penalis_test_mail_fails'] );
penalis_assert( is_wp_error( $failed_issue ), 'A failed delivery reports an error.' );
penalis_same( 'penalis_email_failed', $failed_issue->get_error_code(), 'The failure is reported as a mail failure.' );
penalis_same( '', (string) get_user_meta( 34, '_penalis_otp_registration', true ), 'A failed delivery leaves no usable challenge.' );

// A failed replacement restores the challenge from the earlier email.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][35] = new WP_User( 35, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 35 );
$delivered = Penalis_Otp::issue( 'registration', 35, 'user35@example.com' );
$delivered_code = penalis_last_code();

// Clear only the issuance budgets so a replacement can be attempted at once;
// the locator for the delivered challenge must survive.
foreach ( array_keys( $GLOBALS['penalis_test_transients'] ) as $transient_key ) {
	if ( 0 === strpos( (string) $transient_key, 'penalis_otp_budget_' ) ) {
		unset( $GLOBALS['penalis_test_transients'][ $transient_key ] );
	}
}

$GLOBALS['penalis_test_mail_fails'] = true;
$failed_replacement = Penalis_Otp::issue( 'registration', 35, 'user35@example.com' );
unset( $GLOBALS['penalis_test_mail_fails'] );
penalis_assert( is_wp_error( $failed_replacement ), 'A failed replacement reports an error.' );
penalis_same( $delivered['challenge_id'], (string) get_user_meta( 35, '_penalis_otp_registration', true )['id'], 'The earlier challenge is restored.' );

$restored = Penalis_Otp::consume( 'registration', $delivered['challenge_id'], $delivered_code, static function () {
	return 'applied';
} );
penalis_same( 'applied', $restored, 'The code from the earlier email still works after a failed replacement.' );

// The cumulative failure counter locks the account for every purpose.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][36] = new WP_User( 36, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 36 );
$locked_challenge = Penalis_Otp::issue( 'registration', 36, 'user36@example.com' );
$locked_code      = penalis_last_code();
update_user_meta( 36, '_penalis_otp_failures', Penalis_Policy::OTP_MAX_FAILURES );

$locked_consume = Penalis_Otp::consume( 'registration', $locked_challenge['challenge_id'], $locked_code, static function () {
	return 'applied';
} );
penalis_assert( is_wp_error( $locked_consume ), 'A locked account cannot confirm even the correct code.' );

$GLOBALS['penalis_test_transients'] = array();
$locked_issue = Penalis_Otp::issue( 'registration', 36, 'user36@example.com' );
penalis_assert( is_wp_error( $locked_issue ), 'A locked account cannot request a new code.' );

Penalis_Otp::unlock( 36 );
penalis_same( '', (string) get_user_meta( 36, '_penalis_otp_failures', true ), 'An administrator unlock clears the counter.' );
$unlocked_issue = Penalis_Otp::issue( 'registration', 36, 'user36@example.com' );
penalis_assert( ! is_wp_error( $unlocked_issue ), 'An unlocked account can request a code again.' );

// The wrong owner is rejected before any attempt is counted.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][37] = new WP_User( 37, array( 'penalis_writer' ) );
update_user_meta( 37, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 37, Penalis_Account_Status::META_ACCESS, '1' );
Penalis_Account_Status::set_pending_email( 37, 'baru37@example.com' );
$owner_challenge = Penalis_Otp::issue( 'email_change', 37, 'baru37@example.com' );
$owner_state     = get_user_meta( 37, '_penalis_otp_email_change', true );

$other_owner = Penalis_Otp::consume( 'email_change', $owner_challenge['challenge_id'], '000000', static function () {
	return 'applied';
}, 999 );
penalis_assert( is_wp_error( $other_owner ), 'A challenge cannot be consumed by another account.' );
penalis_same( (int) $owner_state['attempts'], (int) get_user_meta( 37, '_penalis_otp_email_change', true )['attempts'], 'A foreign owner consumes no attempt.' );
penalis_assert( is_array( get_user_meta( 37, '_penalis_otp_email_change', true ) ), 'A foreign owner leaves the challenge in place.' );

// A lock that cannot be acquired reports a busy server, not a wrong code.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][38] = new WP_User( 38, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 38 );
$GLOBALS['penalis_test_lock_available'] = false;
$busy_issue = Penalis_Otp::issue( 'registration', 38, 'user38@example.com' );
unset( $GLOBALS['penalis_test_lock_available'] );
penalis_assert( is_wp_error( $busy_issue ), 'A failed lock reports an error.' );
penalis_same( 'penalis_busy', $busy_issue->get_error_code(), 'A failed lock reports the busy code.' );
penalis_same( 503, $busy_issue->get_error_data()['status'], 'A failed lock reports HTTP 503.' );

// A daily issuance budget caps cumulative guessing per account.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][39] = new WP_User( 39, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 39 );

$day_key = 'penalis_otp_budget_' . hash_hmac( 'sha256', 'day_user:39', wp_salt( 'auth' ) );
set_transient(
	$day_key,
	array(
		'count' => Penalis_Policy::OTP_DAILY_ISSUES,
		'last'  => 0,
		'until' => time() + DAY_IN_SECONDS,
	),
	DAY_IN_SECONDS
);

$daily_capped = Penalis_Otp::issue( 'registration', 39, 'user39@example.com' );
penalis_assert( is_wp_error( $daily_capped ), 'The daily issue budget stops further codes.' );
penalis_same( 'penalis_rate_limited', $daily_capped->get_error_code(), 'The daily cap reports a rate limit.' );

// The daily budget is separate from the hourly one: a fresh hourly window does
// not reset it.
$GLOBALS['penalis_test_transients'] = array( $day_key => get_transient( $day_key ) );
$still_capped = Penalis_Otp::issue( 'registration', 39, 'user39@example.com' );
penalis_assert( is_wp_error( $still_capped ), 'Clearing hourly budgets does not reset the daily cap.' );

/* -------------------------------------------------------------------------
 * Email change challenge storage
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'penalis_writer' ) );
update_user_meta( 21, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 21, Penalis_Account_Status::META_ACCESS, '1' );

Penalis_Account_Status::set_pending_email( 21, 'baru@example.com' );
penalis_same( 'baru@example.com', Penalis_Account_Status::get_pending_email( 21 ), 'The pending address is stored.' );

$penalis_email_challenge = Penalis_Otp::issue( 'email_change', 21, 'baru@example.com' );
penalis_assert( ! is_wp_error( $penalis_email_challenge ), 'An email change challenge is issued.' );
penalis_assert( Penalis_Policy::is_token_format_valid( $penalis_email_challenge['challenge_id'] ), 'The challenge ID has the opaque token shape.' );
penalis_same( 'baru@example.com', $GLOBALS['penalis_test_mail'][0][0], 'The code is sent to the candidate address, not the current one.' );

$penalis_email_state = get_user_meta( 21, '_penalis_otp_email_change', true );
penalis_assert( is_array( $penalis_email_state ), 'The email change challenge is stored.' );
penalis_same( 'baru@example.com', $penalis_email_state['email'], 'The challenge stores the candidate address.' );
penalis_assert( ! isset( $penalis_email_state['code'] ), 'The raw email change code is never stored.' );

// A registration challenge cannot confirm an email change.
$penalis_registration_challenge = Penalis_Otp::issue( 'registration', 21, 'user21@example.com' );
penalis_assert( is_wp_error( $penalis_registration_challenge ), 'A registration challenge is refused for an active account.' );

// Confirming the emailed code applies the new address.
$penalis_change_result = Penalis_Email_Change::confirm(
	21,
	$penalis_email_challenge['challenge_id'],
	penalis_last_code()
);
penalis_assert( ! is_wp_error( $penalis_change_result ), 'A correct email change code is accepted.' );
penalis_same( 'baru@example.com', $GLOBALS['penalis_test_users'][21]->user_email, 'The account address is updated only after confirmation.' );
penalis_same( '', Penalis_Account_Status::get_pending_email( 21 ), 'The pending address is cleared after confirmation.' );
penalis_same( 'user21@example.com', $GLOBALS['penalis_test_mail'][1][0], 'The previous address receives the security notice.' );

// The consumed code cannot be replayed.
$penalis_replay = Penalis_Email_Change::confirm(
	21,
	$penalis_email_challenge['challenge_id'],
	penalis_last_code()
);
penalis_assert( is_wp_error( $penalis_replay ), 'A consumed email change code cannot be reused.' );

// A resend reissues for the stored candidate, not a browser-supplied address.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][22] = new WP_User( 22, array( 'penalis_writer' ) );
update_user_meta( 22, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 22, Penalis_Account_Status::META_ACCESS, '1' );

$penalis_missing_resend = Penalis_Email_Change::resend( 22 );
penalis_assert( is_wp_error( $penalis_missing_resend ), 'A resend without a pending change is refused.' );

Penalis_Account_Status::set_pending_email( 22, 'calon@example.com' );
$penalis_resend_result = Penalis_Email_Change::resend( 22 );
penalis_assert( ! is_wp_error( $penalis_resend_result ), 'A resend reissues the challenge.' );
penalis_same( 'calon@example.com', $penalis_resend_result['pendingEmail'], 'The resend uses the stored candidate address.' );
penalis_same( 'calon@example.com', $GLOBALS['penalis_test_mail'][0][0], 'The resent code goes to the stored candidate address.' );

Penalis_Account_Status::clear_email_change( 21 );
penalis_same( '', Penalis_Account_Status::get_pending_email( 21 ), 'Clearing removes the pending address.' );

/* -------------------------------------------------------------------------
 * Avatar validation
 * ---------------------------------------------------------------------- */

penalis_same( '', Penalis_Policy::validate_avatar( 'image/jpeg', 400, 400, true ), 'A JPEG avatar is accepted.' );
penalis_same( '', Penalis_Policy::validate_avatar( 'image/webp', 100, 100, true ), 'The minimum square size is accepted.' );
penalis_same( '', Penalis_Policy::validate_avatar( 'image/png', 0, 0, true ), 'Unknown dimensions do not block an avatar.' );
penalis_assert( '' !== Penalis_Policy::validate_avatar( 'image/svg+xml', 400, 400, true ), 'SVG avatars are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_avatar( 'application/pdf', 400, 400, true ), 'Non-image files are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_avatar( 'image/png', 80, 400, true ), 'Images below the minimum edge are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_avatar( 'image/png', 400, 400, false ), 'Images owned by another user are rejected.' );
penalis_same( array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), Penalis_Policy::avatar_mimes(), 'Avatar mime list matches the upload policy.' );

/* -------------------------------------------------------------------------
 * Avatar identifier resolution & standalone storage
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][12] = new WP_User( 12, array( 'author' ) );

penalis_same( 12, Penalis_Avatar::resolve_user_id( 12 ), 'Numeric identifiers resolve.' );
penalis_same( 12, Penalis_Avatar::resolve_user_id( '12' ), 'Numeric strings resolve.' );
penalis_same( 12, Penalis_Avatar::resolve_user_id( new WP_User( 12 ) ), 'WP_User objects resolve.' );
penalis_same( 12, Penalis_Avatar::resolve_user_id( 'user12@example.com' ), 'Email addresses resolve.' );
penalis_same( 0, Penalis_Avatar::resolve_user_id( 'not-an-email' ), 'Unknown strings resolve to zero.' );
penalis_same( 0, Penalis_Avatar::resolve_user_id( 'nobody@example.com' ), 'Unknown emails resolve to zero.' );

$penalis_post              = new WP_Post();
$penalis_post->post_author = 12;
penalis_same( 12, Penalis_Avatar::resolve_user_id( $penalis_post ), 'Post authors resolve.' );

$penalis_comment          = new WP_Comment();
$penalis_comment->user_id = 12;
penalis_same( 12, Penalis_Avatar::resolve_user_id( $penalis_comment ), 'Comment authors resolve.' );

// Strict filename validation prevents traversal and arbitrary paths.
penalis_same( true, Penalis_Avatar::is_valid_filename( 'u42-0123456789ab.jpg' ), 'Server-generated avatar filename is valid.' );
penalis_same( false, Penalis_Avatar::is_valid_filename( '../u42-0123456789ab.jpg' ), 'Traversal filename is rejected.' );
penalis_same( false, Penalis_Avatar::is_valid_filename( 'avatar-profile.jpg' ), 'Legacy static filename is rejected.' );
penalis_same( false, Penalis_Avatar::is_valid_filename( 'u42-0123456789ab.php' ), 'Non-JPEG extension is rejected.' );
penalis_same( false, Penalis_Avatar::is_valid_filename( 'u42-0123456789AB.jpg' ), 'Uppercase hex token is rejected.' );

// Build a minimal valid GIF89a with configurable width/height (pure PHP, no GD required).
$penalis_make_gif = static function ( $width, $height ) {
	$path = tempnam( sys_get_temp_dir(), 'penalis-gif-' );
	$gif  = 'GIF89a'
		. pack( 'vv', (int) $width, (int) $height )
		. "\x80\x00\x00\x00\x00\x00\xff\xff\xff\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";
	file_put_contents( $path, $gif );
	return $path;
};

$penalis_upload_root                = sys_get_temp_dir() . '/penalis-avatar-test-' . uniqid( '', true );
$GLOBALS['penalis_test_upload_dir'] = array(
	'basedir' => $penalis_upload_root,
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);
$GLOBALS['penalis_test_image_editor_error']     = false;
$GLOBALS['penalis_test_image_editor_fail_save'] = false;

// Legacy attachment fallback works without migration.
$penalis_legacy_attachment                 = new WP_Post();
$penalis_legacy_attachment->ID             = 501;
$penalis_legacy_attachment->post_type      = 'attachment';
$penalis_legacy_attachment->post_mime_type = 'image/jpeg';
$penalis_legacy_attachment->post_author    = 12;
$GLOBALS['penalis_test_posts'][501]        = $penalis_legacy_attachment;
update_user_meta( 12, Penalis_Avatar::meta_key(), 501 );

penalis_same( true, Penalis_Avatar::has_avatar( 12 ), 'Legacy attachment avatar reports has_avatar=true.' );
$penalis_legacy_avatar = Penalis_Avatar::filter_avatar_data( array( 'size' => 96, 'alt' => '' ), 12 );
penalis_same(
	'https://example.com/wp-content/uploads/attachment-501.jpg',
	$penalis_legacy_avatar['url'],
	'Legacy attachment avatar resolves via filter_avatar_data().'
);
penalis_same( true, $penalis_legacy_avatar['found_avatar'], 'Legacy attachment avatar marks found_avatar.' );

// Reject small images (< 100x100) and non-images.
$penalis_small_gif = $penalis_make_gif( 80, 80 );
$penalis_small_res = Penalis_Avatar::upload(
	12,
	array(
		'tmp_name' => $penalis_small_gif,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $penalis_small_gif ),
	)
);
penalis_assert( is_wp_error( $penalis_small_res ), 'Images smaller than 100x100 are rejected.' );
unlink( $penalis_small_gif );

$penalis_not_image = tempnam( sys_get_temp_dir(), 'penalis-txt-' );
file_put_contents( $penalis_not_image, 'not an image at all' );
$penalis_txt_res = Penalis_Avatar::upload(
	12,
	array(
		'tmp_name' => $penalis_not_image,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $penalis_not_image ),
	)
);
penalis_assert( is_wp_error( $penalis_txt_res ), 'Non-image uploads are rejected.' );
unlink( $penalis_not_image );

// Upload a valid 600x400 image: stored in penalis-avatars/, resized square, legacy meta detached (attachment untouched).
$penalis_gif_1  = $penalis_make_gif( 600, 400 );
$penalis_file_1 = Penalis_Avatar::upload(
	12,
	array(
		'tmp_name' => $penalis_gif_1,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $penalis_gif_1 ),
	)
);
unlink( $penalis_gif_1 );

penalis_assert( is_string( $penalis_file_1 ) && Penalis_Avatar::is_valid_filename( $penalis_file_1 ), 'Valid upload stores a u{id}-{token}.jpg filename.' );
penalis_assert( 0 === strpos( (string) $penalis_file_1, 'u12-' ), 'Stored filename is prefixed with user ID.' );
penalis_assert( is_file( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_1 ), 'Standalone avatar file exists on disk.' );
penalis_assert( is_file( $penalis_upload_root . '/penalis-avatars/index.php' ), 'Avatar subfolder has index.php guard.' );
penalis_assert( is_file( $penalis_upload_root . '/penalis-avatars/.htaccess' ), 'Avatar subfolder has .htaccess guard.' );
penalis_same( 1, count( $GLOBALS['penalis_test_last_editor']->resizes ), 'Non-square/oversized upload is resized square.' );
penalis_same( 256, $GLOBALS['penalis_test_last_editor']->resizes[0]['width'], 'Resize caps to min(OUTPUT_SIZE, width, height).' );
penalis_same( 85, $GLOBALS['penalis_test_last_editor']->quality, 'Avatar JPEG quality is 85.' );
penalis_same( 0, Penalis_Avatar::get_id( 12 ), 'Legacy attachment meta is detached after standalone upload.' );
penalis_assert( isset( $GLOBALS['penalis_test_posts'][501] ), 'Legacy Media Library attachment is never deleted.' );

$penalis_standalone_avatar = Penalis_Avatar::filter_avatar_data( array( 'size' => 96, 'alt' => '' ), 12 );
penalis_same(
	'https://example.com/wp-content/uploads/penalis-avatars/' . $penalis_file_1,
	$penalis_standalone_avatar['url'],
	'Standalone avatar URL resolves from penalis-avatars/.'
);
penalis_same( 'User 12', $penalis_standalone_avatar['alt'], 'Avatar alt falls back to display_name.' );

// Uploading a replacement immediately deletes the previous standalone file.
$penalis_gif_2  = $penalis_make_gif( 256, 256 );
$penalis_file_2 = Penalis_Avatar::upload(
	12,
	array(
		'tmp_name' => $penalis_gif_2,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $penalis_gif_2 ),
	)
);
unlink( $penalis_gif_2 );

penalis_assert( is_string( $penalis_file_2 ) && $penalis_file_2 !== $penalis_file_1, 'Replacement upload gets a fresh unique token.' );
penalis_assert( is_file( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_2 ), 'New standalone avatar file exists on disk.' );
penalis_assert( ! file_exists( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_1 ), 'Superseded standalone avatar file is immediately deleted.' );
penalis_same( 0, count( $GLOBALS['penalis_test_last_editor']->resizes ), 'Exact 256x256 square upload skips unnecessary resize.' );

// A failed save keeps the existing standalone avatar intact.
$GLOBALS['penalis_test_image_editor_fail_save'] = true;
$penalis_gif_3                                  = $penalis_make_gif( 256, 256 );
$penalis_fail_res                               = Penalis_Avatar::upload(
	12,
	array(
		'tmp_name' => $penalis_gif_3,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $penalis_gif_3 ),
	)
);
unlink( $penalis_gif_3 );
$GLOBALS['penalis_test_image_editor_fail_save'] = false;

penalis_assert( is_wp_error( $penalis_fail_res ), 'Failed image editor save returns WP_Error.' );
penalis_same( $penalis_file_2, Penalis_Avatar::get_file( 12 ), 'Failed replacement preserves existing avatar meta.' );
penalis_assert( is_file( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_2 ), 'Failed replacement preserves existing avatar file on disk.' );

// A failed update_user_meta() keeps the existing avatar intact and cleans up the new orphan file.
$GLOBALS['penalis_test_fail_user_meta_keys'] = array( Penalis_Avatar::META_FILE );
$penalis_gif_4                               = $penalis_make_gif( 256, 256 );
$penalis_meta_fail_res                       = Penalis_Avatar::upload(
	12,
	array(
		'tmp_name' => $penalis_gif_4,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $penalis_gif_4 ),
	)
);
unlink( $penalis_gif_4 );
unset( $GLOBALS['penalis_test_fail_user_meta_keys'] );

penalis_assert( is_wp_error( $penalis_meta_fail_res ), 'Failed update_user_meta() returns WP_Error.' );
penalis_same( 'penalis_avatar_save_failed', $penalis_meta_fail_res->get_error_code(), 'Failed update_user_meta() returns penalis_avatar_save_failed.' );
penalis_same( $penalis_file_2, Penalis_Avatar::get_file( 12 ), 'Failed meta write preserves existing avatar meta.' );
penalis_assert( is_file( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_2 ), 'Failed meta write preserves existing avatar file on disk.' );
$penalis_remaining_jpgs = glob( $penalis_upload_root . '/penalis-avatars/u12-*.jpg' );
penalis_same( array( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_2 ), $penalis_remaining_jpgs, 'Failed meta write cleans up the newly written orphan file.' );

// Transparent PNG uploaded directly is flattened onto white instead of black (when GD is available).
if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' ) && function_exists( 'imagecreatefrompng' ) ) {
	$penalis_png_path = tempnam( sys_get_temp_dir(), 'penalis-alpha-' );
	$penalis_png_img  = imagecreatetruecolor( 120, 120 );
	imagesavealpha( $penalis_png_img, true );
	imagealphablending( $penalis_png_img, false );
	$penalis_transparent = imagecolorallocatealpha( $penalis_png_img, 0, 0, 0, 127 );
	imagefill( $penalis_png_img, 0, 0, $penalis_transparent );
	imagepng( $penalis_png_img, $penalis_png_path );
	imagedestroy( $penalis_png_img );

	$penalis_file_3 = Penalis_Avatar::upload(
		12,
		array(
			'tmp_name' => $penalis_png_path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $penalis_png_path ),
		)
	);
	unlink( $penalis_png_path );

	penalis_assert( is_string( $penalis_file_3 ) && Penalis_Avatar::is_valid_filename( $penalis_file_3 ), 'Transparent PNG upload succeeds.' );
	$penalis_saved_img = imagecreatefrompng( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_3 );
	$penalis_rgb       = imagecolorat( $penalis_saved_img, 0, 0 );
	$penalis_r         = ( $penalis_rgb >> 16 ) & 0xFF;
	$penalis_g         = ( $penalis_rgb >> 8 ) & 0xFF;
	$penalis_b         = $penalis_rgb & 0xFF;
	imagedestroy( $penalis_saved_img );
	penalis_same( array( 255, 255, 255 ), array( $penalis_r, $penalis_g, $penalis_b ), 'Transparent PNG corner pixel is flattened to white.' );
	$penalis_file_2 = $penalis_file_3;
}

// Clearing removes the standalone file and meta.
Penalis_Avatar::clear( 12 );
penalis_same( '', Penalis_Avatar::get_file( 12 ), 'Clearing removes _penalis_avatar_file meta.' );
penalis_same( false, Penalis_Avatar::has_avatar( 12 ), 'Clearing sets has_avatar to false.' );
penalis_assert( ! file_exists( $penalis_upload_root . '/penalis-avatars/' . $penalis_file_2 ), 'Clearing immediately deletes standalone avatar file.' );

// Cleanup temporary test directory.
@unlink( $penalis_upload_root . '/penalis-avatars/index.php' );
@unlink( $penalis_upload_root . '/penalis-avatars/.htaccess' );
@rmdir( $penalis_upload_root . '/penalis-avatars' );
@rmdir( $penalis_upload_root );
unset( $GLOBALS['penalis_test_upload_dir'] );

/* -------------------------------------------------------------------------
 * Strict unused-only media deletion & usage protection
 * ---------------------------------------------------------------------- */

// Pure content matching rules (handles original, -scaled, intermediate sizes, wp-image-{id}, and avoids prefix collisions).
penalis_same(
	'2026/11/foto',
	Penalis_Media_Usage::content_search_needle( 701, '2026/11/foto-scaled.jpg' ),
	'Search needle strips extension and -scaled suffix.'
);
penalis_same(
	true,
	Penalis_Media_Usage::content_references_attachment(
		'<p><img src="https://penalis.com/wp-content/uploads/2026/11/foto.jpg" alt=""></p>',
		701,
		'2026/11/foto.jpg'
	),
	'Exact full URL reference matches.'
);
penalis_same(
	true,
	Penalis_Media_Usage::content_references_attachment(
		'<p><img src="https://penalis.com/wp-content/uploads/2026/11/foto-1024x683.jpg" alt=""></p>',
		701,
		'2026/11/foto-scaled.jpg'
	),
	'Intermediate size URL reference matches -scaled attachment.'
);
penalis_same(
	true,
	Penalis_Media_Usage::content_references_attachment(
		'<img class="alignnone wp-image-701" src="https://cdn.example.com/other.jpg">',
		701,
		'2026/11/foto.jpg'
	),
	'wp-image-{id} class reference matches.'
);
penalis_same(
	false,
	Penalis_Media_Usage::content_references_attachment(
		'<p><img src="https://penalis.com/wp-content/uploads/2026/11/foto-2.jpg"><img src="https://penalis.com/wp-content/uploads/2026/11/foto-liburan.jpg"></p>',
		701,
		'2026/11/foto.jpg'
	),
	'Similar filename prefixes (foto-2.jpg, foto-liburan.jpg) do not falsely match foto.jpg.'
);

penalis_reset();
$GLOBALS['penalis_test_deleted_attachments'] = array();

$penalis_writer_a = new WP_User( 41, array( Penalis_Roles::ROLE ) );
$penalis_writer_b = new WP_User( 42, array( Penalis_Roles::ROLE ) );
$GLOBALS['penalis_test_users'][41] = $penalis_writer_a;
$GLOBALS['penalis_test_users'][42] = $penalis_writer_b;
update_user_meta( 41, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 41, Penalis_Account_Status::META_ACCESS, '1' );
update_user_meta( 42, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 42, Penalis_Account_Status::META_ACCESS, '1' );

$penalis_make_att = static function ( $id, $author_id, $file ) {
	$att                 = new WP_Post();
	$att->ID             = $id;
	$att->post_type      = 'attachment';
	$att->post_status    = 'inherit';
	$att->post_mime_type = 'image/jpeg';
	$att->post_author    = $author_id;
	$att->post_title     = basename( $file );
	$GLOBALS['penalis_test_posts'][ $id ] = $att;
	update_post_meta( $id, '_wp_attached_file', $file );
	return $att;
};

$penalis_make_att( 801, 41, '2026/11/unused.jpg' );
$penalis_make_att( 802, 41, '2026/11/published-cover.jpg' );
$penalis_make_att( 803, 41, '2026/11/draft-inline.jpg' );
$penalis_make_att( 804, 41, '2026/11/pending-cover.jpg' );
$penalis_make_att( 805, 41, '2026/11/trashed-only.jpg' );
$penalis_make_att( 806, 41, '2026/11/legacy-avatar.jpg' );
$penalis_make_att( 807, 41, '2026/11/other-author-ref.jpg' );
$penalis_make_att( 808, 42, '2026/11/writer-b-file.jpg' );

// Published article using 802 as featured image.
$penalis_pub              = new WP_Post();
$penalis_pub->ID          = 901;
$penalis_pub->post_type   = 'post';
$penalis_pub->post_status = 'publish';
$penalis_pub->post_author = 41;
$penalis_pub->post_title  = 'Esai Senja';
$GLOBALS['penalis_test_posts'][901] = $penalis_pub;
update_post_meta( 901, '_thumbnail_id', 802 );

// Draft article embedding 803 inline in post_content.
$penalis_draft               = new WP_Post();
$penalis_draft->ID           = 902;
$penalis_draft->post_type    = 'post';
$penalis_draft->post_status  = 'draft';
$penalis_draft->post_author  = 41;
$penalis_draft->post_title   = 'Draft Puisi';
$penalis_draft->post_content = '<p>Teks</p><img src="https://example.com/wp-content/uploads/2026/11/draft-inline-1024x683.jpg" alt="" />';
$GLOBALS['penalis_test_posts'][902] = $penalis_draft;

// Pending review article using 804 as featured image.
$penalis_pending              = new WP_Post();
$penalis_pending->ID          = 903;
$penalis_pending->post_type   = 'post';
$penalis_pending->post_status = 'pending';
$penalis_pending->post_author = 41;
$penalis_pending->post_title  = 'Menunggu Kurasi';
$GLOBALS['penalis_test_posts'][903] = $penalis_pending;
update_post_meta( 903, '_thumbnail_id', 804 );

// Trashed article referencing 805 (must NOT block deletion).
$penalis_trashed              = new WP_Post();
$penalis_trashed->ID          = 904;
$penalis_trashed->post_type   = 'post';
$penalis_trashed->post_status = 'trash';
$penalis_trashed->post_author = 41;
$penalis_trashed->post_title  = 'Dibuang';
$GLOBALS['penalis_test_posts'][904] = $penalis_trashed;
update_post_meta( 904, '_thumbnail_id', 805 );

// Legacy avatar referencing 806.
update_user_meta( 42, Penalis_Avatar::meta_key(), 806 );

// Another author's draft referencing 807 (must protect without leaking title to user 41).
$penalis_other_post               = new WP_Post();
$penalis_other_post->ID           = 905;
$penalis_other_post->post_type    = 'post';
$penalis_other_post->post_status  = 'draft';
$penalis_other_post->post_author  = 42;
$penalis_other_post->post_title   = 'Rahasia Penulis Lain';
$penalis_other_post->post_content = '<img src="https://example.com/wp-content/uploads/2026/11/other-author-ref.jpg">';
$GLOBALS['penalis_test_posts'][905] = $penalis_other_post;

penalis_same( false, Penalis_Media_Usage::inspect( 801, 41 )['inUse'], 'Unused attachment is marked deletable.' );
penalis_same( true, Penalis_Media_Usage::inspect( 802, 41 )['inUse'], 'Published featured image is protected.' );
penalis_same( true, Penalis_Media_Usage::inspect( 803, 41 )['inUse'], 'Draft inline image is strictly protected.' );
penalis_same( true, Penalis_Media_Usage::inspect( 804, 41 )['inUse'], 'Pending-review featured image is protected.' );
penalis_same( false, Penalis_Media_Usage::inspect( 805, 41 )['inUse'], 'Attachment referenced only by trashed post is deletable.' );
penalis_same( true, Penalis_Media_Usage::inspect( 806, 41 )['inUse'], 'Attachment used as legacy avatar is protected.' );

$penalis_cross_usage = Penalis_Media_Usage::inspect( 807, 41 );
penalis_same( true, $penalis_cross_usage['inUse'], 'Attachment referenced by another author post is protected.' );
penalis_same( array(), $penalis_cross_usage['usedIn'], 'Other author post title is never leaked in usedIn.' );
penalis_assert( false === strpos( $penalis_cross_usage['reason'], 'Rahasia Penulis Lain' ), 'Other author post title is never leaked in error reason.' );

// Capability layer (map_meta_cap) mirrors strict protection on attachments.
$penalis_caps_used = Penalis_Access_Control::map_meta_cap( array( 'delete_posts' ), 'delete_post', 41, array( 802 ) );
penalis_assert( in_array( 'do_not_allow', $penalis_caps_used, true ), 'map_meta_cap blocks delete_post on referenced attachment.' );
$penalis_caps_unused = Penalis_Access_Control::map_meta_cap( array( 'delete_posts' ), 'delete_post', 41, array( 801 ) );
penalis_assert( ! in_array( 'do_not_allow', $penalis_caps_unused, true ), 'map_meta_cap allows delete_post on unused own attachment.' );

// REST handler: Penalis_Rest_Api::delete_media().
$GLOBALS['penalis_test_current_user'] = 41;

$penalis_del_foreign = Penalis_Rest_Api::delete_media( new WP_REST_Request( 'DELETE', '/penalis/v1/media/808', array( 'id' => 808 ) ) );
penalis_assert( is_wp_error( $penalis_del_foreign ) && 'penalis_not_found' === $penalis_del_foreign->get_error_code(), 'Cannot delete another writer attachment (404).' );

$penalis_del_draft_used = Penalis_Rest_Api::delete_media( new WP_REST_Request( 'DELETE', '/penalis/v1/media/803', array( 'id' => 803 ) ) );
penalis_assert( is_wp_error( $penalis_del_draft_used ) && 'penalis_media_in_use' === $penalis_del_draft_used->get_error_code(), 'Cannot delete attachment referenced in a draft (409).' );
penalis_same( 409, $penalis_del_draft_used->get_error_data()['status'], 'In-use media deletion returns HTTP 409.' );

$penalis_del_ok = Penalis_Rest_Api::delete_media( new WP_REST_Request( 'DELETE', '/penalis/v1/media/801', array( 'id' => 801 ) ) );
penalis_assert( $penalis_del_ok instanceof WP_REST_Response && 200 === $penalis_del_ok->get_status(), 'Deleting unused own attachment returns HTTP 200.' );
penalis_assert( ! isset( $GLOBALS['penalis_test_posts'][801] ), 'Unused attachment is removed by delete_media().' );

// Batch summary used by GET /penalis/v1/media.
$penalis_batch = Penalis_Media_Usage::summarize_for_user( array( 802, 803, 805 ), 41 );
penalis_same( true, $penalis_batch[802]['inUse'], 'Batch summary flags published featured image.' );
penalis_same( true, $penalis_batch[803]['inUse'], 'Batch summary flags draft inline image.' );
penalis_same( false, $penalis_batch[805]['inUse'], 'Batch summary allows trashed-only attachment.' );

/* -------------------------------------------------------------------------
 * Dashboard script loading
 * ---------------------------------------------------------------------- */

/*
 * The dashboard bundle is an ES module. WordPress prints a classic <script>
 * tag, which cannot parse `import.meta`, so the dashboard renders an empty
 * page. These assertions guard the filter that adds `type="module"`.
 */

$penalis_other_tag = '<script src="https://example.com/theme.js"></script>';
penalis_same(
	$penalis_other_tag,
	Penalis_Dashboard::module_script_tag( $penalis_other_tag, 'theme-script', 'https://example.com/theme.js' ),
	'Scripts with another handle are returned untouched.'
);

$penalis_module_tag = Penalis_Dashboard::module_script_tag(
	'<script src="https://example.com/dashboard.js"></script>',
	'penalis-dashboard',
	'https://example.com/dashboard.js'
);

penalis_assert(
	false !== strpos( $penalis_module_tag, 'type="module"' ),
	'The dashboard bundle is loaded as an ES module.'
);
penalis_assert(
	false !== strpos( $penalis_module_tag, 'crossorigin="anonymous"' ),
	'The module tag includes crossorigin="anonymous" for CORS compatibility.'
);
penalis_assert(
	false !== strpos( $penalis_module_tag, 'src="https://example.com/dashboard.js"' ),
	'The module tag keeps the enqueued source when same origin.'
);
penalis_assert(
	false !== strpos( $penalis_module_tag, 'id="penalis-dashboard-js"' ),
	'The module tag keeps the handle id so dependent localisations still resolve.'
);

$penalis_cdn_module_tag = Penalis_Dashboard::module_script_tag(
	'<script src="https://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0"></script>',
	'penalis-dashboard',
	'https://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0'
);
penalis_assert(
	false !== strpos( $penalis_cdn_module_tag, 'src="https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0"' ),
	'The module tag normalizes cross-origin CDN host to site origin to prevent CORS blocks.'
);

/*
 * Browsers compare origins (`scheme://host[:port]`), not hosts. An off-site CDN
 * URL on a non-standard port or with an http/https mismatch is a different
 * origin even when the host matches, and a cross-origin ES module without CORS
 * headers leaves the dashboard and authors directory empty. These assertions
 * guard the origin comparison and the rebuilt origin.
 */

$penalis_default_home = isset( $GLOBALS['penalis_test_home_url'] ) ? $GLOBALS['penalis_test_home_url'] : null;
$penalis_default_ssl  = isset( $GLOBALS['penalis_test_is_ssl'] ) ? $GLOBALS['penalis_test_is_ssl'] : null;

$GLOBALS['penalis_test_is_ssl'] = true;

$GLOBALS['penalis_test_home_url'] = 'https://example.com';
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0',
	Penalis_Dashboard::ensure_same_origin_url( 'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0' ),
	'A same-origin asset URL is returned untouched.'
);
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'https://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A cross-origin CDN URL is rebuilt on the site origin.'
);
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js#top',
	Penalis_Dashboard::ensure_same_origin_url( 'https://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js#top' ),
	'Rebuilding a cross-origin URL preserves its fragment.'
);
penalis_same(
	'/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( '/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A relative asset URL already resolves against the site origin and is untouched.'
);

// The production incident: off-site CDN URL while the site is on a non-default port.
$GLOBALS['penalis_test_home_url'] = 'https://penalis.com:8443';
penalis_same(
	'https://penalis.com:8443/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0',
	Penalis_Dashboard::ensure_same_origin_url( 'https://cdn.penalis.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0' ),
	'A rebuilt URL keeps the site port so non-standard-port installs still load their bundle.'
);
penalis_same(
	'https://penalis.com:8443/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'https://penalis.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A URL on the default port is a different origin than the site port and is rebuilt with it.'
);
penalis_same(
	'https://penalis.com:8443/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'https://penalis.com:8443/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A URL on the same host and port is returned untouched.'
);

$GLOBALS['penalis_test_home_url'] = 'https://example.com';
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'https://example.com:8443/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A non-default port on the same host is a different origin and is dropped.'
);
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'http://example.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'An http asset on an https site is rebuilt over https to avoid mixed content.'
);
penalis_same(
	'https://EXAMPLE.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'https://EXAMPLE.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'Host comparison is case-insensitive and the URL is kept untouched.'
);

$GLOBALS['penalis_test_home_url'] = 'https://example.com:443';
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'An explicit default https port compares equal to an omitted one.'
);

// A reverse proxy may terminate TLS while `home_url()` still reports http.
$GLOBALS['penalis_test_home_url'] = 'http://example.com';
penalis_same(
	'https://example.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'http://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A rebuilt URL is upgraded to https when the request is served over TLS.'
);

$GLOBALS['penalis_test_is_ssl'] = false;
$GLOBALS['penalis_test_home_url'] = 'http://example.com';
penalis_same(
	'http://example.com/wp-content/plugins/penalis-platform/build/assets/index.js',
	Penalis_Dashboard::ensure_same_origin_url( 'http://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js' ),
	'A plain http site keeps rebuilding over http.'
);

$GLOBALS['penalis_test_is_ssl'] = true;
$GLOBALS['penalis_test_home_url'] = 'https://example.com:8443';
$penalis_port_module_tag = Penalis_Dashboard::module_script_tag(
	'<script src="https://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0"></script>',
	'penalis-dashboard',
	'https://cdn.example.com/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0'
);
penalis_assert(
	false !== strpos( $penalis_port_module_tag, 'src="https://example.com:8443/wp-content/plugins/penalis-platform/build/assets/index.js?ver=1.0.0"' ),
	'The module tag keeps the site port when normalizing a CDN source.'
);

if ( null === $penalis_default_home ) {
	unset( $GLOBALS['penalis_test_home_url'] );
} else {
	$GLOBALS['penalis_test_home_url'] = $penalis_default_home;
}

if ( null === $penalis_default_ssl ) {
	unset( $GLOBALS['penalis_test_is_ssl'] );
} else {
	$GLOBALS['penalis_test_is_ssl'] = $penalis_default_ssl;
}

/* -------------------------------------------------------------------------
 * Username changes
 * ---------------------------------------------------------------------- */

// Validation is pure and must reject rather than rewrite.
penalis_same( '', Penalis_Policy::validate_username_change( 'penulis_baru-01' ), 'A plain login is valid.' );
penalis_same( '', Penalis_Policy::validate_username_change( 'a_b-c1' ), 'Underscores and hyphens are allowed.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'a.b_c-1' ), 'Dots are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'NamaBaru' ), 'Uppercase letters are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( '_nama' ), 'A leading underscore is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'nama-' ), 'A trailing hyphen is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( '' ), 'An empty login is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'bad name' ), 'Whitespace is rejected rather than stripped.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( ' nama' ), 'Leading whitespace is not trimmed away.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'nama@penalis' ), 'Characters removed by sanitization are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'nama!' ), 'Punctuation outside the allowed set is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'ab' ), 'Logins shorter than three characters are rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( str_repeat( 'a', 51 ) ), 'Logins longer than 50 characters are rejected.' );
penalis_same( '', Penalis_Policy::validate_username_change( str_repeat( 'a', 50 ) ), 'A 50-character login is accepted.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( '...' ), 'A login with no letter or digit is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'user30', 'user30' ), 'The current login is rejected.' );
penalis_assert( '' !== Penalis_Policy::validate_username_change( 'USER30', 'user30' ), 'A case-only change is rejected.' );
penalis_same( '', Penalis_Policy::validate_username_change( 'user31', 'user30' ), 'A different login is accepted.' );

// Eligibility mirrors email changes.
penalis_assert( Penalis_Policy::can_change_username( Penalis_Policy::STATUS_ACTIVE, true ), 'Active accounts with access may rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_ACTIVE, false ), 'Active accounts without access may not rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_PENDING_VERIFICATION, true ), 'Unverified accounts may not rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_SUSPENDED, true ), 'Suspended accounts may not rename.' );
penalis_assert( Penalis_Policy::can_change_username( '', true ), 'Legacy accounts with access may rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( '', false ), 'Legacy accounts without access may not rename.' );

/**
 * Build an active writer for the username-change workflow tests.
 *
 * @param int    $user_id User ID.
 * @param string $mode    Display-name mode.
 * @return void
 */
function penalis_make_writer( $user_id, $mode = 'username' ) {
	$GLOBALS['penalis_test_users'][ $user_id ]              = new WP_User( $user_id );
	$GLOBALS['penalis_test_users'][ $user_id ]->user_pass   = 'hash:correct';
	update_user_meta( $user_id, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
	update_user_meta( $user_id, Penalis_Account_Status::META_ACCESS, '1' );
	update_user_meta( $user_id, Penalis_Account_Status::META_DISPLAY_NAME_MODE, $mode );
}

penalis_reset();
penalis_make_writer( 30 );
$GLOBALS['penalis_test_current_user'] = 30;
$GLOBALS['wpdb']                      = new Penalis_Test_WPDB();

$penalis_wrong_password = Penalis_Username_Change::change( 30, 'penulis_baru', 'wrong' );
penalis_same( 'penalis_password_invalid', $penalis_wrong_password->get_error_code(), 'A wrong current password is rejected.' );
penalis_same( 'user30', $GLOBALS['penalis_test_users'][30]->user_login, 'A rejected password leaves the login untouched.' );
$GLOBALS['penalis_test_transients'] = array();

$penalis_invalid = Penalis_Username_Change::change( 30, 'bad name', 'correct' );
penalis_same( 'penalis_username_invalid', $penalis_invalid->get_error_code(), 'An invalid login is rejected with a field error.' );
penalis_same( 'new_username', array_key_first( $penalis_invalid->get_error_data()['fields'] ), 'The validation error is attached to the field.' );
$GLOBALS['penalis_test_transients'] = array();

$penalis_same_login = Penalis_Username_Change::change( 30, 'user30', 'correct' );
penalis_same( 'penalis_username_invalid', $penalis_same_login->get_error_code(), 'Renaming to the current login is rejected.' );
$GLOBALS['penalis_test_transients'] = array();

$GLOBALS['penalis_test_duplicate_login'] = 99;
$penalis_duplicate                       = Penalis_Username_Change::change( 30, 'penulis_baru', 'correct' );
penalis_same( 'penalis_username_unavailable', $penalis_duplicate->get_error_code(), 'A duplicate login gets the generic unavailable error.' );
penalis_same( 'user30', $GLOBALS['penalis_test_users'][30]->user_login, 'A duplicate leaves the login untouched.' );
$GLOBALS['penalis_test_duplicate_login'] = null;
$GLOBALS['penalis_test_transients']   = array();

$GLOBALS['penalis_test_lock_available'] = false;
$penalis_no_lock                        = Penalis_Username_Change::change( 30, 'penulis_baru', 'correct' );
penalis_same( 'penalis_username_unavailable', $penalis_no_lock->get_error_code(), 'A missing advisory lock fails closed.' );
$GLOBALS['penalis_test_lock_available'] = true;
$GLOBALS['penalis_test_transients']   = array();

$GLOBALS['penalis_test_update_fails'] = true;
$penalis_update_failed                = Penalis_Username_Change::change( 30, 'penulis_baru', 'correct' );
penalis_same( 'penalis_username_unavailable', $penalis_update_failed->get_error_code(), 'A failed write reports the generic error.' );
$GLOBALS['penalis_test_update_fails'] = false;
$GLOBALS['penalis_test_transients']   = array();

$penalis_changed = Penalis_Username_Change::change( 30, 'penulis_baru', 'correct' );
penalis_assert( ! is_wp_error( $penalis_changed ), 'A valid change succeeds.' );
penalis_same( 'penulis_baru', $GLOBALS['penalis_test_users'][30]->user_login, 'The login is updated.' );
penalis_same( 'penulis_baru', $GLOBALS['penalis_test_users'][30]->user_nicename, 'The author nicename follows the new login.' );
penalis_same( 30, Penalis_Author_History::redirect_owner( 'user30' ), 'The old author slug still resolves to its owner.' );
penalis_same( 0, Penalis_Author_History::redirect_owner( 'penulis_baru' ), 'The current slug is not treated as historical.' );
penalis_same( 'penulis_baru', $GLOBALS['penalis_test_users'][30]->display_name, 'Username display-name mode follows the new login.' );
penalis_assert( ! empty( $GLOBALS['penalis_test_sessions_destroyed'] ), 'All sessions are destroyed.' );
penalis_assert( ! empty( $GLOBALS['penalis_test_auth_cookie_cleared'] ), 'The current auth cookie is cleared.' );
penalis_assert( in_array( 'userlogins:user30', $GLOBALS['penalis_test_cache_deletes'], true ), 'The old login cache is invalidated.' );
penalis_assert( in_array( 'userlogins:penulis_baru', $GLOBALS['penalis_test_cache_deletes'], true ), 'The new login cache is invalidated.' );
penalis_assert( in_array( 30, $GLOBALS['penalis_test_cleaned_users'], true ), 'The user cache is cleaned.' );
penalis_same( 'user30@example.com', $GLOBALS['penalis_test_mail'][0][0], 'The security notice goes to the current email.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'penulis_baru' ), 'The notice names the new login.' );

// A custom pen name must survive a rename.
penalis_reset();
penalis_make_writer( 32, 'custom' );
$GLOBALS['penalis_test_users'][32]->display_name = 'Nama Pena';
$GLOBALS['penalis_test_current_user']            = 32;
$GLOBALS['wpdb']                                 = new Penalis_Test_WPDB();
$penalis_custom                                  = Penalis_Username_Change::change( 32, 'penulis_pena', 'correct' );
penalis_assert( ! is_wp_error( $penalis_custom ), 'A custom-name account can rename.' );
penalis_same( 'Nama Pena', $GLOBALS['penalis_test_users'][32]->display_name, 'A custom pen name is not overwritten.' );

// A first/last-name composition must survive a rename too.
penalis_reset();
penalis_make_writer( 33, 'auto' );
$GLOBALS['penalis_test_users'][33]->display_name = 'Depan Belakang';
$GLOBALS['penalis_test_current_user']            = 33;
$GLOBALS['wpdb']                                 = new Penalis_Test_WPDB();
Penalis_Username_Change::change( 33, 'penulis_auto', 'correct' );
penalis_same( 'Depan Belakang', $GLOBALS['penalis_test_users'][33]->display_name, 'A composed name is not overwritten.' );

// A rename from another browser must not clear this browser's cookie.
penalis_reset();
penalis_make_writer( 34 );
$GLOBALS['penalis_test_current_user'] = 0;
$GLOBALS['wpdb']                      = new Penalis_Test_WPDB();
$penalis_other_browser                = Penalis_Username_Change::change( 34, 'penulis_lain', 'correct' );
penalis_assert( ! is_wp_error( $penalis_other_browser ), 'A rename outside the current session succeeds.' );
penalis_assert( empty( $GLOBALS['penalis_test_auth_cookie_cleared'] ), 'Another browser does not clear this session cookie.' );
penalis_assert( ! empty( $GLOBALS['penalis_test_sessions_destroyed'] ), 'Sessions are still destroyed for the renamed account.' );

// Status gate.
penalis_reset();
$GLOBALS['penalis_test_users'][31]              = new WP_User( 31 );
$GLOBALS['penalis_test_users'][31]->user_pass   = 'hash:correct';
update_user_meta( 31, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_SUSPENDED );
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$penalis_forbidden = Penalis_Username_Change::change( 31, 'penulis_lain', 'correct' );
penalis_same( 'penalis_username_forbidden', $penalis_forbidden->get_error_code(), 'Suspended accounts cannot change their login.' );

penalis_reset();
$GLOBALS['penalis_test_users'][35]            = new WP_User( 35 );
$GLOBALS['penalis_test_users'][35]->user_pass = 'hash:correct';
update_user_meta( 35, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_PENDING_VERIFICATION );
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$penalis_pending = Penalis_Username_Change::change( 35, 'penulis_pending', 'correct' );
penalis_same( 'penalis_username_forbidden', $penalis_pending->get_error_code(), 'Unverified accounts cannot change their login.' );

// Throttling: the third attempt in the window is still allowed, the fourth is not.
penalis_reset();
penalis_make_writer( 36 );
$GLOBALS['penalis_test_current_user'] = 36;
$GLOBALS['wpdb']                      = new Penalis_Test_WPDB();
Penalis_Username_Change::change( 36, 'penulis_satu', 'correct' );
Penalis_Username_Change::change( 36, 'penulis_dua', 'correct' );
Penalis_Username_Change::change( 36, 'penulis_tiga', 'correct' );
$penalis_throttled = Penalis_Username_Change::change( 36, 'penulis_empat', 'correct' );
penalis_same( 'penalis_rate_limited', $penalis_throttled->get_error_code(), 'The per-user limit stops further attempts.' );

// An unknown account is not authenticated.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$penalis_missing = Penalis_Username_Change::change( 999, 'penulis_baru', 'correct' );
penalis_same( 'penalis_not_authenticated', $penalis_missing->get_error_code(), 'An unknown account is rejected.' );

/* -------------------------------------------------------------------------
 * Author history
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][40] = new WP_User( 40 );
$GLOBALS['penalis_test_users'][41] = new WP_User( 41 );
$GLOBALS['wpdb']                   = new Penalis_Test_WPDB();

penalis_assert( ! Penalis_Author_History::unavailable( 'user40', 40 ), 'An owner may reuse its own identifier.' );
penalis_assert( Penalis_Author_History::unavailable( 'user40' ), 'A current login is unavailable to others.' );
penalis_assert( Penalis_Author_History::unavailable( 'user40', 41 ), 'A current login is unavailable to another owner.' );

penalis_assert( Penalis_Author_History::remember( 40, 'penulis_lama', true ), 'A historical slug is remembered.' );
penalis_assert( Penalis_Author_History::remember( 40, 'penulis_lama', true ), 'Remembering the same slug is idempotent.' );
penalis_same( 1, count( get_user_meta( 40, Penalis_Author_History::META_KEY, false ) ), 'Only one history row is stored.' );
penalis_same( 1, count( get_user_meta( 40, Penalis_Author_History::META_KEY . '_reserved', false ) ), 'Only one reservation row is stored.' );
penalis_assert( Penalis_Author_History::unavailable( 'penulis_lama', 41 ), 'A reserved slug is unavailable to another owner.' );
penalis_assert( ! Penalis_Author_History::unavailable( 'penulis_lama', 40 ), 'A reserved slug stays available to its owner.' );

penalis_assert( Penalis_Author_History::remember( 40, 'penulis_login', false ), 'A login-only reservation is remembered.' );
penalis_same( 0, Penalis_Author_History::redirect_owner( 'penulis_login' ), 'A login-only reservation does not redirect.' );
penalis_assert( Penalis_Author_History::remember( 40, 'penulis_login', true ), 'A login-only reservation can be upgraded.' );
penalis_same( 40, Penalis_Author_History::redirect_owner( 'penulis_login' ), 'An upgraded reservation redirects.' );
penalis_same( 2, count( get_user_meta( 40, Penalis_Author_History::META_KEY, false ) ), 'Upgrading does not duplicate the history row.' );
penalis_same( 40, Penalis_Author_History::redirect_owner( 'penulis_lama' ), 'A historical author slug resolves to its owner.' );
penalis_same( 0, Penalis_Author_History::redirect_owner( 'user41' ), 'A current slug is not historical.' );

penalis_assert( Penalis_Author_History::release( 40, 'penulis_lama' ), 'A reservation can be released.' );
penalis_same( 0, Penalis_Author_History::redirect_owner( 'penulis_lama' ), 'A released slug no longer redirects.' );
penalis_assert( ! Penalis_Author_History::unavailable( 'penulis_lama', 41 ), 'A released slug is available again.' );
penalis_same( 2, count( get_user_meta( 40, Penalis_Author_History::META_KEY, false ) ), 'Released history rows are kept as audit data.' );

// Native registration and profile validation use supported hooks.
$registration_errors = new WP_Error();
Penalis_Author_History::validate_registration( $registration_errors, 'user40' );
penalis_same( 'penalis_username_unavailable', $registration_errors->get_error_code(), 'Registration rejects a reserved login.' );

$registration_errors = new WP_Error();
Penalis_Author_History::validate_registration( $registration_errors, 'penulis_bebas' );
penalis_same( '', $registration_errors->get_error_code(), 'Registration accepts a free login.' );

$profile_errors = new WP_Error();
$profile_user   = clone $GLOBALS['penalis_test_users'][41];
$profile_user->user_login = 'penulis_login';
Penalis_Author_History::validate_profile( $profile_errors, true, $profile_user );
penalis_same( 'penalis_username_unavailable', $profile_errors->get_error_code(), 'A profile update rejects a reserved login.' );

$profile_errors = new WP_Error();
$profile_user   = clone $GLOBALS['penalis_test_users'][40];
Penalis_Author_History::validate_profile( $profile_errors, true, $profile_user );
penalis_same( '', $profile_errors->get_error_code(), 'A profile update may keep its own login.' );

$profile_errors = new WP_Error();
$profile_user   = clone $GLOBALS['penalis_test_users'][41];
$profile_user->user_email = 'baru@example.com';
Penalis_Author_History::validate_profile( $profile_errors, true, $profile_user );
penalis_same( '', $profile_errors->get_error_code(), 'An unrelated profile edit is not blocked.' );

/* -------------------------------------------------------------------------
 * Password reset request: email only
 * ---------------------------------------------------------------------- */

// Usernames are public on author archives, so the reset request must not
// resolve one. These tests pin that rule: a username is answered generically
// and never issues a key or sends mail.
penalis_reset();
$GLOBALS['penalis_test_users'][50] = new WP_User( 50, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][50]->user_email = 'penulis@example.com';
$GLOBALS['penalis_test_users'][50]->user_login = 'penulis_publik';

$result = Penalis_Password_Reset::request( array( 'user_login' => 'penulis_publik' ) );
penalis_same( Penalis_Password_Reset::GENERIC_MESSAGE, $result['message'], 'A username-only request answers generically.' );
penalis_same( array(), $GLOBALS['penalis_test_reset_key_users'], 'A username-only request issues no reset key.' );
penalis_same( array(), $GLOBALS['penalis_test_mail'], 'A username-only request sends no email.' );

penalis_reset();
$GLOBALS['penalis_test_users'][50] = new WP_User( 50, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][50]->user_email = 'penulis@example.com';
$GLOBALS['penalis_test_users'][50]->user_login = 'penulis_publik';

$result = Penalis_Password_Reset::request( array( 'user_email' => 'penulis_publik' ) );
penalis_same( Penalis_Password_Reset::GENERIC_MESSAGE, $result['message'], 'A username in the email field answers generically.' );
penalis_same( array(), $GLOBALS['penalis_test_reset_key_users'], 'A username in the email field issues no reset key.' );
penalis_same( array(), $GLOBALS['penalis_test_mail'], 'A username in the email field sends no email.' );

penalis_reset();
$GLOBALS['penalis_test_users'][50] = new WP_User( 50, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][50]->user_email = 'penulis@example.com';
$GLOBALS['penalis_test_users'][50]->user_login = 'penulis_publik';

$result = Penalis_Password_Reset::request( array( 'user_email' => 'penulis@example.com' ) );
penalis_same( Penalis_Password_Reset::GENERIC_MESSAGE, $result['message'], 'A registered email answers generically.' );
penalis_same( array( 50 ), $GLOBALS['penalis_test_reset_key_users'], 'A registered email issues a reset key for that account.' );
penalis_same( 1, count( $GLOBALS['penalis_test_mail'] ), 'A registered email sends one reset email.' );
penalis_same( 'penulis@example.com', $GLOBALS['penalis_test_mail'][0][0], 'The reset email goes to the address on file.' );

penalis_reset();
$GLOBALS['penalis_test_users'][50] = new WP_User( 50, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][50]->user_email = 'penulis@example.com';

$result = Penalis_Password_Reset::request( array( 'user_email' => 'tidak-terdaftar@example.com' ) );
penalis_same( Penalis_Password_Reset::GENERIC_MESSAGE, $result['message'], 'An unknown email answers generically.' );
penalis_same( array(), $GLOBALS['penalis_test_reset_key_users'], 'An unknown email issues no reset key.' );
penalis_same( array(), $GLOBALS['penalis_test_mail'], 'An unknown email sends no email.' );

/* -------------------------------------------------------------------------
 * Branded transactional emails
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][90] = new WP_User( 90 );
$GLOBALS['penalis_test_users'][90]->user_email = 'writer@example.com';
$GLOBALS['penalis_test_users'][90]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_otp( 90, 'writer@example.com', '123456', 'registration' ), 'Verification code email is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], '<table' ), 'Transactional email uses the branded HTML layout.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'logo-penalis.png' ), 'Transactional email includes the Penalis logo.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], '123456' ), 'The verification code is present in the rendered email.' );
penalis_assert( false === strpos( $GLOBALS['penalis_test_mail'][0][2], 'penalis_verify=' ), 'The verification email contains no bearer link.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Berlaku 10 menit' ), 'The email states the code lifetime.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Jangan bagikan kode ini kepada siapa pun' ), 'The email warns against sharing the code.' );
penalis_assert( false === Penalis_Emails::send_otp( 90, 'writer@example.com', '123456', 'unknown' ), 'An unknown purpose is refused.' );
penalis_assert( false === strpos( Penalis_Email_Template::render( '<script>alert(1)</script>' ), '<script>' ), 'Message content is escaped before HTML output.' );
penalis_assert( false !== strpos( Penalis_Email_Template::render( 'A & B' ), 'A &amp; B' ), 'HTML special characters are escaped.' );
penalis_assert( false !== strpos( Penalis_Email_Template::render( 'Visit https://example.com/?a=1&b=2' ), 'href="https://example.com/?a=1&b=2"' ), 'Inline links retain their URL query string.' );
penalis_assert( in_array( 'Content-Type: text/html; charset=UTF-8', $GLOBALS['penalis_test_mail'][0][3] ?? array(), true ), 'HTML content type is passed per message.' );

penalis_reset();
$GLOBALS['penalis_test_users'][91] = new WP_User( 91 );
$GLOBALS['penalis_test_users'][91]->user_email = 'writer@example.com';
$GLOBALS['penalis_test_users'][91]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_password_reset( 91, 'reset-key' ), 'Password reset email is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'penalis_user=' ), 'Password reset link uses penalis_user parameter.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'permintaan reset password' ), 'Password reset email has reset-specific footer copy.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Jika kamu tidak meminta reset, abaikan email ini. Jangan bagikan isi email ini kepada siapapun.' ), 'Password reset footer uses the approved reset-denial and confidentiality copy.' );

penalis_reset();
$GLOBALS['penalis_test_users'][92] = new WP_User( 92 );
$GLOBALS['penalis_test_users'][92]->user_email = 'writer@example.com';
$GLOBALS['penalis_test_users'][92]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_otp( 92, 'new@example.com', '654321', 'email_change' ), 'Email change code email is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], '654321' ), 'The email change code is present in the rendered email.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Konfirmasi email baru' ), 'Email change code email uses the purpose-specific subject.' );

penalis_reset();
$GLOBALS['penalis_test_users'][93] = new WP_User( 93 );
$GLOBALS['penalis_test_users'][93]->user_email = 'writer@example.com';
$GLOBALS['penalis_test_users'][93]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_username_change_notice( 93, 'writer@example.com', 'oldname', 'newname' ), 'Username change notice is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Username akun Penalis kamu baru saja diubah' ), 'Username notification has security-specific footer copy.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'segera reset password akun Penalis kamu.' ), 'Username notification uses the approved security response copy.' );

penalis_reset();
$GLOBALS['penalis_test_users'][94] = new WP_User( 94 );
$GLOBALS['penalis_test_users'][94]->user_email = 'writer@example.com';
$GLOBALS['penalis_test_users'][94]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_otp( 94, 'writer@example.com', '111222', 'legacy' ), 'Legacy activation code email is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], '111222' ), 'The legacy activation code is present in the rendered email.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Aktivasi akun lama' ), 'Legacy activation email uses the purpose-specific subject.' );

penalis_reset();
$GLOBALS['penalis_test_users'][95] = new WP_User( 95 );
$GLOBALS['penalis_test_users'][95]->user_email = 'old@example.com';
$GLOBALS['penalis_test_users'][95]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_email_change_notice( 95, 'old@example.com', 'new@example.com' ), 'Email change security notice is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Alamat email akun Penalis kamu baru saja diubah' ), 'Email change notice has security-specific footer copy.' );

penalis_reset();
$GLOBALS['penalis_test_users'][96] = new WP_User( 96 );
$GLOBALS['penalis_test_users'][96]->user_email = 'writer@example.com';
$GLOBALS['penalis_test_users'][96]->display_name = 'Penulis Uji';
penalis_assert( Penalis_Emails::send_status_notification( 96, Penalis_Policy::STATUS_ACTIVE ), 'Account status notification is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'perlu mengirim pemberitahuan tentang status akun Penalis kamu yang terhubung ke email ini.' ), 'Status notification uses the approved status explanation.' );
penalis_assert( false !== strpos( Penalis_Email_Template::render( 'Body', 'Subject', 'Footer kontekstual.' ), 'Footer kontekstual.' ), 'Email layout renders the caller-provided contextual footer.' );
penalis_assert( false !== strpos( Penalis_Email_Template::render( 'Body' ), 'kamu terdaftar sebagai penulis di platform Penalis' ), 'Fallback footer uses the approved writer-account copy.' );
penalis_assert( false !== strpos( Penalis_Email_Template::render( 'Body' ), 'hubungi <a href="mailto:admin@penalis.com" style="color:#2873c8;">admin@penalis.com</a>.' ), 'Fallback footer renders the approved clickable support contact.' );

/* -------------------------------------------------------------------------
 * Editorial workflow policy (V2)
 * ---------------------------------------------------------------------- */

penalis_same( 5, count( Penalis_Editorial_Policy::states() ), 'There are five editorial states.' );
penalis_same( 6, count( Penalis_Editorial_Policy::actions() ), 'There are six editorial actions.' );

foreach ( Penalis_Editorial_Policy::states() as $state ) {
	penalis_assert( Penalis_Editorial_Policy::is_valid_state( $state ), "Editorial state {$state} is valid." );
}

penalis_assert( ! Penalis_Editorial_Policy::is_valid_state( 'publish' ), 'WordPress post status is not an editorial state.' );
penalis_assert( ! Penalis_Editorial_Policy::is_valid_state( null ), 'null is not an editorial state.' );
penalis_assert( ! Penalis_Editorial_Policy::is_valid_action( 'publish' ), 'publish is not a workflow action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_valid_action( '' ), 'An empty action is invalid.' );

// Valid transitions.
penalis_same(
	Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Policy::ACTION_SUBMIT ),
	'Submitting a draft moves it to pending review.'
);
penalis_same(
	Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED,
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ),
	'Requesting changes returns the submission to the writer.'
);
penalis_same(
	Penalis_Editorial_Policy::STATE_REJECTED,
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::ACTION_REJECT ),
	'Rejecting a submission records the rejected state.'
);
penalis_same(
	Penalis_Editorial_Policy::STATE_PUBLISHED,
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::ACTION_APPROVE ),
	'Approval publishes the submission.'
);
penalis_same(
	Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, Penalis_Editorial_Policy::ACTION_RESUBMIT ),
	'Resubmitting after requested changes returns to pending review.'
);
penalis_same(
	Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED,
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_REJECTED, Penalis_Editorial_Policy::ACTION_REOPEN ),
	'Reopening a rejected submission makes it editable again.'
);

// Invalid transitions.
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Policy::ACTION_APPROVE ),
	'A draft cannot be approved without being submitted.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Policy::ACTION_REJECT ),
	'A draft cannot be rejected.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_REJECTED, Penalis_Editorial_Policy::ACTION_RESUBMIT ),
	'A rejected submission cannot be resubmitted without an administrator reopening it.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_REJECTED, Penalis_Editorial_Policy::ACTION_APPROVE ),
	'A rejected submission cannot be approved directly.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, Penalis_Editorial_Policy::ACTION_APPROVE ),
	'Requested changes must be resubmitted before approval.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::ACTION_SUBMIT ),
	'A submission already under review cannot be submitted again.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::ACTION_RESUBMIT ),
	'Resubmit is only valid after requested changes.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::ACTION_REOPEN ),
	'Reopen is only valid for rejected submissions.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, Penalis_Editorial_Policy::ACTION_REJECT ),
	'Requested changes cannot be rejected without a resubmission.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( 'unknown', Penalis_Editorial_Policy::ACTION_SUBMIT ),
	'An unknown state has no transitions.'
);
penalis_same(
	'',
	Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_DRAFT, 'unknown' ),
	'An unknown action has no transitions.'
);

// Published is terminal for the workflow: writers cannot edit it and no action
// moves it anywhere.
foreach ( Penalis_Editorial_Policy::actions() as $action ) {
	penalis_same(
		'',
		Penalis_Editorial_Policy::transition( Penalis_Editorial_Policy::STATE_PUBLISHED, $action ),
		"Published articles have no workflow transition for {$action}."
	);
}

// Approval is the only path to published.
$reaching_published = array();
foreach ( Penalis_Editorial_Policy::states() as $state ) {
	foreach ( Penalis_Editorial_Policy::actions() as $action ) {
		if ( Penalis_Editorial_Policy::STATE_PUBLISHED === Penalis_Editorial_Policy::transition( $state, $action ) ) {
			$reaching_published[] = $state . ':' . $action;
		}
	}
}
penalis_same(
	array( 'pending_review:approve' ),
	$reaching_published,
	'Only administrator approval can reach the published state.'
);

// Feedback requirements.
penalis_assert(
	Penalis_Editorial_Policy::requires_feedback( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ),
	'Requesting changes requires feedback.'
);
penalis_assert(
	Penalis_Editorial_Policy::requires_feedback( Penalis_Editorial_Policy::ACTION_REJECT ),
	'Rejection requires feedback.'
);
penalis_assert(
	! Penalis_Editorial_Policy::requires_feedback( Penalis_Editorial_Policy::ACTION_APPROVE ),
	'Approval does not require feedback.'
);
penalis_assert(
	! Penalis_Editorial_Policy::requires_feedback( Penalis_Editorial_Policy::ACTION_SUBMIT ),
	'Submission does not require feedback.'
);
penalis_assert(
	! Penalis_Editorial_Policy::has_required_feedback( Penalis_Editorial_Policy::ACTION_REJECT, '   ' ),
	'Whitespace-only feedback does not satisfy the requirement.'
);
penalis_assert(
	! Penalis_Editorial_Policy::has_required_feedback( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES, null ),
	'Missing feedback does not satisfy the requirement.'
);
penalis_assert(
	Penalis_Editorial_Policy::has_required_feedback( Penalis_Editorial_Policy::ACTION_REJECT, 'Tidak sesuai prinsip komunitas.' ),
	'Real feedback satisfies the requirement.'
);
penalis_assert(
	Penalis_Editorial_Policy::has_required_feedback( Penalis_Editorial_Policy::ACTION_APPROVE, '' ),
	'Approval passes without feedback.'
);

// Reviewer permission.
penalis_assert( Penalis_Editorial_Policy::can_review( true ), 'A user who can edit others may review.' );
penalis_assert( ! Penalis_Editorial_Policy::can_review( false ), 'A user without edit-others may not review.' );

// Writers may edit drafts only. Published posts are immutable to writers, and a
// submission under review is not editable either.
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_post_status( 'publish' ), 'Writers cannot edit published posts.' );
penalis_assert( Penalis_Editorial_Policy::writer_can_edit_post_status( 'draft' ), 'Writers can edit drafts.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_post_status( 'pending' ), 'Writers cannot edit a submission under review.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_post_status( 'private' ), 'Writers cannot edit private posts.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_post_status( 'future' ), 'Writers cannot edit scheduled posts.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_post_status( null ), 'A missing post status is not editable.' );

// Action ownership.
penalis_assert( Penalis_Editorial_Policy::is_writer_action( Penalis_Editorial_Policy::ACTION_SUBMIT ), 'Submit is a writer action.' );
penalis_assert( Penalis_Editorial_Policy::is_writer_action( Penalis_Editorial_Policy::ACTION_RESUBMIT ), 'Resubmit is a writer action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_writer_action( Penalis_Editorial_Policy::ACTION_APPROVE ), 'Approve is not a writer action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_writer_action( Penalis_Editorial_Policy::ACTION_REJECT ), 'Reject is not a writer action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_writer_action( Penalis_Editorial_Policy::ACTION_REOPEN ), 'Reopen is not a writer action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_writer_action( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ), 'Request changes is not a writer action.' );

penalis_assert( Penalis_Editorial_Policy::is_reviewer_action( Penalis_Editorial_Policy::ACTION_APPROVE ), 'Approve is a reviewer action.' );
penalis_assert( Penalis_Editorial_Policy::is_reviewer_action( Penalis_Editorial_Policy::ACTION_REJECT ), 'Reject is a reviewer action.' );
penalis_assert( Penalis_Editorial_Policy::is_reviewer_action( Penalis_Editorial_Policy::ACTION_REOPEN ), 'Reopen is a reviewer action.' );
penalis_assert( Penalis_Editorial_Policy::is_reviewer_action( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ), 'Request changes is a reviewer action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_reviewer_action( Penalis_Editorial_Policy::ACTION_SUBMIT ), 'Submit is not a reviewer action.' );
penalis_assert( ! Penalis_Editorial_Policy::is_reviewer_action( Penalis_Editorial_Policy::ACTION_RESUBMIT ), 'Resubmit is not a reviewer action.' );

// Every action belongs to exactly one side of the workflow.
foreach ( Penalis_Editorial_Policy::actions() as $action ) {
	$writer_side   = Penalis_Editorial_Policy::is_writer_action( $action );
	$reviewer_side = Penalis_Editorial_Policy::is_reviewer_action( $action );
	penalis_assert(
		$writer_side !== $reviewer_side,
		"Action {$action} belongs to exactly one side of the workflow."
	);
}

// Editorial state to WordPress post status mapping.
penalis_same( 'draft', Penalis_Editorial_Policy::post_status_for_state( Penalis_Editorial_Policy::STATE_DRAFT ), 'A draft maps to the draft post status.' );
penalis_same( 'pending', Penalis_Editorial_Policy::post_status_for_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ), 'Pending review maps to the pending post status.' );
penalis_same( 'draft', Penalis_Editorial_Policy::post_status_for_state( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ), 'Requested changes return the post to draft.' );
penalis_same( 'draft', Penalis_Editorial_Policy::post_status_for_state( Penalis_Editorial_Policy::STATE_REJECTED ), 'A rejected submission is not publicly visible.' );
penalis_same( 'publish', Penalis_Editorial_Policy::post_status_for_state( Penalis_Editorial_Policy::STATE_PUBLISHED ), 'Only the published state maps to publish.' );
penalis_same( '', Penalis_Editorial_Policy::post_status_for_state( 'unknown' ), 'An unknown state has no post status.' );

// Only one editorial state may map to publish.
$publish_states = array();
foreach ( Penalis_Editorial_Policy::states() as $state ) {
	if ( 'publish' === Penalis_Editorial_Policy::post_status_for_state( $state ) ) {
		$publish_states[] = $state;
	}
}
penalis_same( array( Penalis_Editorial_Policy::STATE_PUBLISHED ), $publish_states, 'Only the published state maps to the publish post status.' );

// Writer editability by editorial state.
penalis_assert( Penalis_Editorial_Policy::writer_can_edit_state( Penalis_Editorial_Policy::STATE_DRAFT ), 'Writers can edit drafts.' );
penalis_assert( Penalis_Editorial_Policy::writer_can_edit_state( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ), 'Writers can edit after requested changes.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ), 'Writers cannot edit a submission under review.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_state( Penalis_Editorial_Policy::STATE_REJECTED ), 'Writers cannot edit a rejected submission.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_state( Penalis_Editorial_Policy::STATE_PUBLISHED ), 'Writers cannot edit published articles.' );
penalis_assert( ! Penalis_Editorial_Policy::writer_can_edit_state( 'unknown' ), 'An unknown state is not editable.' );

// Review cycle.
penalis_same( 1, Penalis_Editorial_Policy::next_review_cycle( 0 ), 'The first submission starts review cycle 1.' );
penalis_same( 1, Penalis_Editorial_Policy::next_review_cycle( '' ), 'A missing cycle starts at 1.' );
penalis_same( 2, Penalis_Editorial_Policy::next_review_cycle( 1 ), 'A resubmission increments the review cycle.' );
penalis_same( 4, Penalis_Editorial_Policy::next_review_cycle( 3 ), 'The review cycle keeps incrementing.' );
penalis_same( 1, Penalis_Editorial_Policy::next_review_cycle( -5 ), 'A negative cycle restarts at 1.' );

// Stale review cycles: two administrators must not apply conflicting decisions.
penalis_assert( ! Penalis_Editorial_Policy::is_stale_decision( 1, 1 ), 'A decision on the current cycle is accepted.' );
penalis_assert( Penalis_Editorial_Policy::is_stale_decision( 1, 2 ), 'A decision on an older cycle is stale.' );
penalis_assert( Penalis_Editorial_Policy::is_stale_decision( 3, 2 ), 'A decision from a future cycle is stale.' );
penalis_assert( Penalis_Editorial_Policy::is_stale_decision( 0, 1 ), 'A missing expected cycle is stale.' );
penalis_assert( Penalis_Editorial_Policy::is_stale_decision( 1, 0 ), 'A missing stored cycle is stale.' );
penalis_assert( Penalis_Editorial_Policy::is_stale_decision( null, null ), 'Two missing cycles are stale.' );
penalis_assert( ! Penalis_Editorial_Policy::is_stale_decision( '2', 2 ), 'A numeric string cycle matches the stored integer.' );

// Only actions that resolve an active review require a cycle.
penalis_assert( Penalis_Editorial_Policy::requires_review_cycle( Penalis_Editorial_Policy::ACTION_APPROVE ), 'Approval requires a review cycle.' );
penalis_assert( Penalis_Editorial_Policy::requires_review_cycle( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ), 'Requesting changes requires a review cycle.' );
penalis_assert( Penalis_Editorial_Policy::requires_review_cycle( Penalis_Editorial_Policy::ACTION_REJECT ), 'Rejection requires a review cycle.' );
penalis_assert( ! Penalis_Editorial_Policy::requires_review_cycle( Penalis_Editorial_Policy::ACTION_REOPEN ), 'Reopening a rejected submission does not require a cycle.' );
penalis_assert( ! Penalis_Editorial_Policy::requires_review_cycle( Penalis_Editorial_Policy::ACTION_SUBMIT ), 'Submission starts a new cycle and does not carry one.' );
penalis_assert( ! Penalis_Editorial_Policy::requires_review_cycle( Penalis_Editorial_Policy::ACTION_RESUBMIT ), 'Resubmission starts a new cycle and does not carry one.' );

// Reopening a rejected submission with no cycle is allowed.
$open_rejected = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'          => Penalis_Editorial_Policy::STATE_REJECTED,
		'action'         => Penalis_Editorial_Policy::ACTION_REOPEN,
		'can_review'     => true,
		'expected_cycle' => 0,
		'current_cycle'  => 0,
	)
);
penalis_assert( $open_rejected['allowed'], 'Reopening a rejected submission does not need a cycle.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $open_rejected['state'], 'Reopening returns the changes-requested state.' );

// Initial state for posts that predate the workflow.
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, Penalis_Editorial_Policy::initial_state_for_post_status( 'publish' ), 'A legacy published post starts as published.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Policy::initial_state_for_post_status( 'pending' ), 'A legacy pending post starts as pending review.' );
penalis_same( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Policy::initial_state_for_post_status( 'draft' ), 'A legacy draft starts as draft.' );
penalis_same( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Policy::initial_state_for_post_status( 'private' ), 'A private post is treated as a draft.' );
penalis_same( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Policy::initial_state_for_post_status( '' ), 'A missing post status is treated as a draft.' );

// A legacy published post is read-only to its writer.
penalis_assert(
	! Penalis_Editorial_Policy::writer_can_edit_state(
		Penalis_Editorial_Policy::initial_state_for_post_status( 'publish' )
	),
	'A legacy published post is not editable by its writer.'
);

// Bounded history append.
$history_entry = array(
	'action'       => Penalis_Editorial_Policy::ACTION_SUBMIT,
	'actor_id'     => 7,
	'created_at'   => 1730000000,
	'review_cycle' => 1,
);
$history = array();
for ( $i = 1; $i <= Penalis_Editorial_Policy::HISTORY_LIMIT + 5; $i++ ) {
	$entry = $history_entry;
	$entry['review_cycle'] = $i;
	$history = Penalis_Editorial_Policy::append_history_entry( $history, $entry );
}
penalis_same( Penalis_Editorial_Policy::HISTORY_LIMIT, count( $history ), 'History is trimmed to the limit.' );
penalis_same( Penalis_Editorial_Policy::HISTORY_LIMIT + 5, $history[ count( $history ) - 1 ]['review_cycle'], 'The newest history entry is kept.' );
penalis_same( 6, $history[0]['review_cycle'], 'The oldest history entries are dropped first.' );
penalis_assert( Penalis_Editorial_Policy::is_valid_history( $history ), 'A trimmed history is still valid.' );
penalis_same( 1, count( Penalis_Editorial_Policy::append_history_entry( 'not-an-array', $history_entry ) ), 'A non-array history is replaced by a fresh list.' );

// Combined action guard.
$writer_submit = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'    => Penalis_Editorial_Policy::STATE_DRAFT,
		'action'   => Penalis_Editorial_Policy::ACTION_SUBMIT,
		'is_owner' => true,
	)
);
penalis_assert( $writer_submit['allowed'], 'The owner may submit a draft.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $writer_submit['state'], 'Submitting returns the pending review state.' );

$not_owner = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'    => Penalis_Editorial_Policy::STATE_DRAFT,
		'action'   => Penalis_Editorial_Policy::ACTION_SUBMIT,
		'is_owner' => false,
	)
);
penalis_assert( ! $not_owner['allowed'], 'A writer cannot submit someone else\'s draft.' );
penalis_assert( '' !== $not_owner['error'], 'A denied action explains why.' );

$writer_approve = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'      => Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
		'action'     => Penalis_Editorial_Policy::ACTION_APPROVE,
		'is_owner'   => true,
		'can_review' => false,
	)
);
penalis_assert( ! $writer_approve['allowed'], 'A writer cannot approve their own submission.' );

$reviewer_approve = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'          => Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
		'action'         => Penalis_Editorial_Policy::ACTION_APPROVE,
		'can_review'     => true,
		'expected_cycle' => 2,
		'current_cycle'  => 2,
	)
);
penalis_assert( $reviewer_approve['allowed'], 'A reviewer may approve the current cycle.' );
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, $reviewer_approve['state'], 'Approval returns the published state.' );

$stale_approve = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'          => Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
		'action'         => Penalis_Editorial_Policy::ACTION_APPROVE,
		'can_review'     => true,
		'expected_cycle' => 1,
		'current_cycle'  => 2,
	)
);
penalis_assert( ! $stale_approve['allowed'], 'A stale reviewer decision is denied.' );

$missing_feedback = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'          => Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
		'action'         => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
		'can_review'     => true,
		'feedback'       => '   ',
		'expected_cycle' => 1,
		'current_cycle'  => 1,
	)
);
penalis_assert( ! $missing_feedback['allowed'], 'Requesting changes without feedback is denied.' );

$with_feedback = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'          => Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
		'action'         => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
		'can_review'     => true,
		'feedback'       => 'Perbaiki paragraf kedua.',
		'expected_cycle' => 1,
		'current_cycle'  => 1,
	)
);
penalis_assert( $with_feedback['allowed'], 'Requesting changes with feedback is allowed.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $with_feedback['state'], 'Requested changes return the writer state.' );

$published_edit = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'    => Penalis_Editorial_Policy::STATE_PUBLISHED,
		'action'   => Penalis_Editorial_Policy::ACTION_SUBMIT,
		'is_owner' => true,
	)
);
penalis_assert( ! $published_edit['allowed'], 'A published article cannot be resubmitted.' );

$unknown_action = Penalis_Editorial_Policy::evaluate_action(
	array(
		'state'    => Penalis_Editorial_Policy::STATE_DRAFT,
		'action'   => 'publish',
		'is_owner' => true,
	)
);
penalis_assert( ! $unknown_action['allowed'], 'An unknown action is denied.' );

// Metadata contract.
penalis_same( 8, count( Penalis_Editorial_Policy::metadata_keys() ), 'The workflow owns eight metadata keys.' );
penalis_assert(
	in_array( '_penalis_editorial_state', Penalis_Editorial_Policy::metadata_keys(), true ),
	'The editorial state key is part of the contract.'
);
penalis_assert(
	! in_array( '_penalis_pending_revision_id', Penalis_Editorial_Policy::metadata_keys(), true ),
	'No pending-revision key exists after the published-article decision.'
);

// History validation.
$valid_entry = array(
	'action'       => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	'actor_id'     => 12,
	'created_at'   => 1730000000,
	'review_cycle' => 1,
	'feedback'     => 'Mohon tambahkan sumber pada paragraf kedua.',
);
penalis_assert( Penalis_Editorial_Policy::is_valid_history_entry( $valid_entry ), 'A complete history entry is valid.' );
penalis_assert( Penalis_Editorial_Policy::is_valid_history( array( $valid_entry ) ), 'A bounded history list is valid.' );
penalis_assert( Penalis_Editorial_Policy::is_valid_history( array() ), 'An empty history is valid.' );
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( 'not-an-array' ), 'A non-array history entry is invalid.' );

$missing_action = $valid_entry;
unset( $missing_action['action'] );
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( $missing_action ), 'A history entry without an action is invalid.' );

$bad_action = $valid_entry;
$bad_action['action'] = 'publish';
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( $bad_action ), 'A history entry with an unknown action is invalid.' );

$bad_actor = $valid_entry;
$bad_actor['actor_id'] = 0;
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( $bad_actor ), 'A history entry without an actor is invalid.' );

$bad_time = $valid_entry;
$bad_time['created_at'] = 0;
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( $bad_time ), 'A history entry without a timestamp is invalid.' );

$bad_cycle = $valid_entry;
$bad_cycle['review_cycle'] = 0;
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( $bad_cycle ), 'A history entry without a review cycle is invalid.' );

$bad_feedback = $valid_entry;
$bad_feedback['feedback'] = array( 'not', 'a', 'string' );
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history_entry( $bad_feedback ), 'Non-string feedback is invalid.' );

$oversized = array();
for ( $i = 0; $i <= Penalis_Editorial_Policy::HISTORY_LIMIT; $i++ ) {
	$oversized[] = $valid_entry;
}
penalis_assert( ! Penalis_Editorial_Policy::is_valid_history( $oversized ), 'History beyond the limit is invalid.' );
penalis_assert(
	Penalis_Editorial_Policy::is_valid_history( array_slice( $oversized, 0, Penalis_Editorial_Policy::HISTORY_LIMIT ) ),
	'History at the limit is valid.'
);

/* -------------------------------------------------------------------------
 * Editorial workflow persistence (V2)
 * ---------------------------------------------------------------------- */

/**
 * Register a test post.
 *
 * @param int    $id     Post ID.
 * @param int    $author Author ID.
 * @param string $status Post status.
 * @return WP_Post
 */
function penalis_editorial_post( $id, $author, $status = 'draft' ) {
	$post              = new WP_Post();
	$post->ID          = (int) $id;
	$post->post_author = (int) $author;
	$post->post_status = $status;
	$post->post_title  = 'Karya ' . $id;

	$GLOBALS['penalis_test_posts'][ (int) $id ] = $post;

	return $post;
}

/**
 * Register an active writer account.
 *
 * @param int $id User ID.
 * @return WP_User
 */
function penalis_editorial_writer( $id ) {
	$user = new WP_User( $id, array( 'penalis_writer' ) );
	$user->meta[ Penalis_Account_Status::META_STATUS ] = array( Penalis_Policy::STATUS_ACTIVE );
	$user->meta[ Penalis_Account_Status::META_ACCESS ] = array( '1' );

	$GLOBALS['penalis_test_users'][ (int) $id ] = $user;

	return $user;
}

/**
 * Register an administrator account.
 *
 * @param int $id User ID.
 * @return WP_User
 */
function penalis_editorial_admin( $id ) {
	$user = new WP_User( $id, array( 'administrator' ) );

	$GLOBALS['penalis_test_users'][ (int) $id ] = $user;

	return $user;
}

// Legacy posts infer their state from the WordPress post status.
penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_post( 100, 10, 'publish' );
penalis_editorial_post( 101, 10, 'pending' );
penalis_editorial_post( 102, 10, 'draft' );

penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, Penalis_Editorial_Workflow::get_state( 100 ), 'A legacy published post reads as published.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Workflow::get_state( 101 ), 'A legacy pending post reads as pending review.' );
penalis_same( Penalis_Editorial_Policy::STATE_DRAFT, Penalis_Editorial_Workflow::get_state( 102 ), 'A legacy draft reads as draft.' );
penalis_same( '', Penalis_Editorial_Workflow::get_state( 999 ), 'A missing post has no state.' );
penalis_same( 0, Penalis_Editorial_Workflow::get_review_cycle( 102 ), 'A legacy post has no review cycle.' );
penalis_same( '', Penalis_Editorial_Workflow::get_feedback( 102 ), 'A legacy post has no feedback.' );
penalis_same( array(), Penalis_Editorial_Workflow::get_history( 102 ), 'A legacy post has no history.' );

// Reading state never writes metadata.
penalis_assert( ! isset( $GLOBALS['penalis_test_posts'][100]->meta[ Penalis_Editorial_Policy::META_STATE ] ), 'Reading a legacy post does not write workflow metadata.' );

// Writer editability.
penalis_assert( Penalis_Editorial_Workflow::can_writer_edit( 102, 10 ), 'A writer may edit their own draft.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 101, 10 ), 'A writer may not edit a submission under review.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 100, 10 ), 'A writer may not edit a published article.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 102, 11 ), 'A writer may not edit another writer\'s draft.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 102, 0 ), 'A guest may not edit a draft.' );

// A writer without write access cannot edit even their own draft.
$GLOBALS['penalis_test_users'][10]->meta[ Penalis_Account_Status::META_ACCESS ] = array( '0' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 102, 10 ), 'A writer without access cannot edit a draft.' );
$GLOBALS['penalis_test_users'][10]->meta[ Penalis_Account_Status::META_ACCESS ] = array( '1' );

// Reviewer permission.
penalis_editorial_admin( 20 );
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'editor' ) );
penalis_assert( Penalis_Editorial_Workflow::can_review( 20 ), 'An administrator may review.' );
penalis_assert( Penalis_Editorial_Workflow::can_review( 21 ), 'An editor may review.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_review( 10 ), 'A writer may not review.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_review( 0 ), 'A guest may not review.' );

// Penalis admin access is capability-based: editors curate, administrators manage.
$GLOBALS['penalis_test_current_user'] = 21;
penalis_assert( Penalis_Admin::can_access(), 'An editor may open the Penalis screens.' );
penalis_assert( ! Penalis_Admin::can_manage_writers(), 'An editor may not manage writer accounts.' );
penalis_assert( ! Penalis_Admin::can_manage_settings(), 'An editor may not change site-wide settings.' );

$GLOBALS['penalis_test_current_user'] = 20;
penalis_assert( Penalis_Admin::can_access(), 'An administrator may open the Penalis screens.' );
penalis_assert( Penalis_Admin::can_manage_writers(), 'An administrator may manage writer accounts.' );
penalis_assert( Penalis_Admin::can_manage_settings(), 'An administrator may change site-wide settings.' );

$GLOBALS['penalis_test_current_user'] = 10;
penalis_assert( ! Penalis_Admin::can_access(), 'A writer may not open the Penalis screens.' );
unset( $GLOBALS['penalis_test_current_user'] );

// Submit: draft -> pending.
penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_post( 200, 10, 'draft' );

$result = Penalis_Editorial_Workflow::apply( 200, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( ! is_wp_error( $result ), 'The owner may submit a draft.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $result['state'], 'Submission moves the editorial state to pending review.' );
penalis_same( 'pending', $result['status'], 'Submission moves the post to the pending status.' );
penalis_same( 1, $result['cycle'], 'The first submission starts review cycle 1.' );
penalis_same( 'pending', $GLOBALS['penalis_test_posts'][200]->post_status, 'The stored post status is pending.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Workflow::get_state( 200 ), 'The stored editorial state is pending review.' );
penalis_assert( Penalis_Editorial_Workflow::get_submitted_at( 200 ) > 0, 'Submission records a timestamp.' );
penalis_same( 1, count( Penalis_Editorial_Workflow::get_history( 200 ) ), 'Submission appends one history entry.' );
penalis_same( Penalis_Editorial_Policy::ACTION_SUBMIT, Penalis_Editorial_Workflow::get_history( 200 )[0]['action'], 'The history entry records the action.' );
penalis_same( 10, Penalis_Editorial_Workflow::get_history( 200 )[0]['actor_id'], 'The history entry records the actor.' );

// A submission cannot be submitted twice.
$again = Penalis_Editorial_Workflow::apply( 200, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $again ), 'A submission under review cannot be submitted again.' );
penalis_same( 'penalis_editorial_denied', $again->get_error_code(), 'A denied action returns the editorial error code.' );

// A writer cannot submit someone else's draft.
penalis_editorial_writer( 11 );
penalis_editorial_post( 201, 11, 'draft' );
$foreign = Penalis_Editorial_Workflow::apply( 201, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $foreign ), 'A writer cannot submit another writer\'s draft.' );
penalis_same( 'draft', $GLOBALS['penalis_test_posts'][201]->post_status, 'A denied submission leaves the post untouched.' );

// A writer cannot approve their own submission.
$self_approve = Penalis_Editorial_Workflow::apply( 200, Penalis_Editorial_Policy::ACTION_APPROVE, array( 'actor_id' => 10, 'expected_cycle' => 1 ) );
penalis_assert( is_wp_error( $self_approve ), 'A writer cannot approve their own submission.' );
penalis_same( 'pending', $GLOBALS['penalis_test_posts'][200]->post_status, 'A denied approval leaves the post pending.' );

// Request changes: pending -> draft with feedback.
penalis_editorial_admin( 20 );
$changes = Penalis_Editorial_Workflow::apply(
	200,
	Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Tambahkan sumber pada paragraf kedua.',
		'expected_cycle' => 1,
	)
);
penalis_assert( ! is_wp_error( $changes ), 'An administrator may request changes.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $changes['state'], 'Requested changes set the writer state.' );
penalis_same( 'draft', $changes['status'], 'Requested changes return the post to draft.' );
penalis_same( 1, $changes['cycle'], 'A reviewer decision does not start a new cycle.' );
penalis_same( 'Tambahkan sumber pada paragraf kedua.', Penalis_Editorial_Workflow::get_feedback( 200 ), 'Feedback is stored for the writer.' );
penalis_assert( Penalis_Editorial_Workflow::get_reviewed_at( 200 ) > 0, 'A reviewer decision records a timestamp.' );
penalis_same( 2, count( Penalis_Editorial_Workflow::get_history( 200 ) ), 'A reviewer decision appends a history entry.' );
penalis_assert( Penalis_Editorial_Workflow::can_writer_edit( 200, 10 ), 'The writer may edit after requested changes.' );

// Feedback is required for request-changes.
penalis_editorial_post( 202, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 202, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
$no_feedback = Penalis_Editorial_Workflow::apply(
	202,
	Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	array( 'actor_id' => 20, 'expected_cycle' => 1 )
);
penalis_assert( is_wp_error( $no_feedback ), 'Requesting changes without feedback is denied.' );
penalis_same( 'pending', $GLOBALS['penalis_test_posts'][202]->post_status, 'A denied request-changes leaves the post pending.' );

// Resubmit: changes_requested -> pending, cycle 2.
$resubmit = Penalis_Editorial_Workflow::apply( 200, Penalis_Editorial_Policy::ACTION_RESUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( ! is_wp_error( $resubmit ), 'The writer may resubmit after requested changes.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $resubmit['state'], 'Resubmission returns to pending review.' );
penalis_same( 2, $resubmit['cycle'], 'Resubmission starts review cycle 2.' );
penalis_same( '', Penalis_Editorial_Workflow::get_feedback( 200 ), 'Resubmission clears the addressed feedback.' );

// A stale decision from the previous cycle is refused.
$stale = Penalis_Editorial_Workflow::apply(
	200,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array( 'actor_id' => 20, 'expected_cycle' => 1 )
);
penalis_assert( is_wp_error( $stale ), 'A decision from an older review cycle is refused.' );
penalis_same( 'pending', $GLOBALS['penalis_test_posts'][200]->post_status, 'A stale decision leaves the post pending.' );

// Approve: pending -> publish.
$approve = Penalis_Editorial_Workflow::apply(
	200,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array( 'actor_id' => 20, 'expected_cycle' => 2 )
);
penalis_assert( ! is_wp_error( $approve ), 'An administrator may approve the current cycle.' );
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, $approve['state'], 'Approval sets the published state.' );
penalis_same( 'publish', $approve['status'], 'Approval publishes the post.' );
penalis_same( 'publish', $GLOBALS['penalis_test_posts'][200]->post_status, 'The stored post status is publish.' );
penalis_same( '', Penalis_Editorial_Workflow::get_feedback( 200 ), 'Approval leaves no feedback behind.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 200, 10 ), 'A published article is read-only to its writer.' );

// A published article cannot be submitted again.
$republish = Penalis_Editorial_Workflow::apply( 200, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $republish ), 'A published article cannot be resubmitted.' );

// Reject: pending -> draft, rejected state.
penalis_editorial_post( 203, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 203, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
$reject = Penalis_Editorial_Workflow::apply(
	203,
	Penalis_Editorial_Policy::ACTION_REJECT,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Isinya tidak sesuai prinsip komunitas.',
		'expected_cycle' => 1,
	)
);
penalis_assert( ! is_wp_error( $reject ), 'An administrator may reject a submission.' );
penalis_same( Penalis_Editorial_Policy::STATE_REJECTED, $reject['state'], 'Rejection sets the rejected state.' );
penalis_same( 'draft', $reject['status'], 'A rejected submission is not publicly visible.' );
penalis_same( 'Isinya tidak sesuai prinsip komunitas.', Penalis_Editorial_Workflow::get_feedback( 203 ), 'Rejection feedback is stored.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 203, 10 ), 'A rejected submission is closed to the writer.' );

// A rejected submission cannot be resubmitted.
$rejected_resubmit = Penalis_Editorial_Workflow::apply( 203, Penalis_Editorial_Policy::ACTION_RESUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $rejected_resubmit ), 'A rejected submission cannot be resubmitted.' );
penalis_same( Penalis_Editorial_Policy::STATE_REJECTED, Penalis_Editorial_Workflow::get_state( 203 ), 'A denied resubmission keeps the rejected state.' );

// Reopen: rejected -> changes_requested.
$reopen = Penalis_Editorial_Workflow::apply( 203, Penalis_Editorial_Policy::ACTION_REOPEN, array( 'actor_id' => 20 ) );
penalis_assert( ! is_wp_error( $reopen ), 'An administrator may reopen a rejected submission.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $reopen['state'], 'Reopening makes the submission editable again.' );
penalis_assert( Penalis_Editorial_Workflow::can_writer_edit( 203, 10 ), 'The writer may edit after an administrator reopens the submission.' );

// A writer cannot reopen.
penalis_editorial_post( 204, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 204, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
Penalis_Editorial_Workflow::apply(
	204,
	Penalis_Editorial_Policy::ACTION_REJECT,
	array( 'actor_id' => 20, 'feedback' => 'Ditolak.', 'expected_cycle' => 1 )
);
$writer_reopen = Penalis_Editorial_Workflow::apply( 204, Penalis_Editorial_Policy::ACTION_REOPEN, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $writer_reopen ), 'A writer cannot reopen a rejected submission.' );

// A failed post update leaves the workflow metadata untouched.
penalis_editorial_post( 205, 10, 'draft' );
$GLOBALS['penalis_test_post_update_fails'] = true;
$failed = Penalis_Editorial_Workflow::apply( 205, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $failed ), 'A failed post update returns an error.' );
penalis_same( 'penalis_editorial_failed', $failed->get_error_code(), 'A failed post update uses the workflow error code.' );
penalis_assert(
	! isset( $GLOBALS['penalis_test_posts'][205]->meta[ Penalis_Editorial_Policy::META_STATE ] ),
	'A failed update writes no editorial state.'
);
penalis_same( 'draft', $GLOBALS['penalis_test_posts'][205]->post_status, 'A failed update leaves the post status untouched.' );
penalis_same( array(), Penalis_Editorial_Workflow::get_history( 205 ), 'A failed update writes no history.' );
unset( $GLOBALS['penalis_test_post_update_fails'] );

// History stays bounded across many cycles.
penalis_editorial_post( 206, 10, 'draft' );
for ( $i = 0; $i < 30; $i++ ) {
	$submit = 0 === $i
		? Penalis_Editorial_Policy::ACTION_SUBMIT
		: Penalis_Editorial_Policy::ACTION_RESUBMIT;

	$submitted = Penalis_Editorial_Workflow::apply( 206, $submit, array( 'actor_id' => 10 ) );
	penalis_assert( ! is_wp_error( $submitted ), "Cycle {$i} submission is accepted." );

	Penalis_Editorial_Workflow::apply(
		206,
		Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
		array(
			'actor_id'       => 20,
			'feedback'       => 'Revisi putaran ' . $i,
			'expected_cycle' => $i + 1,
		)
	);
}
penalis_same( Penalis_Editorial_Policy::HISTORY_LIMIT, count( Penalis_Editorial_Workflow::get_history( 206 ) ), 'Review history stays within the limit.' );
penalis_assert( Penalis_Editorial_Policy::is_valid_history( Penalis_Editorial_Workflow::get_history( 206 ) ), 'The stored history is valid.' );

// A missing post is reported, not silently ignored.
$missing = Penalis_Editorial_Workflow::apply( 999, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( is_wp_error( $missing ), 'A missing post returns an error.' );
penalis_same( 'penalis_not_found', $missing->get_error_code(), 'A missing post uses the not-found error code.' );

// An unauthenticated actor is refused.
penalis_editorial_post( 207, 10, 'draft' );
$guest = Penalis_Editorial_Workflow::apply( 207, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 0 ) );
penalis_assert( is_wp_error( $guest ), 'A guest cannot apply a workflow action.' );
penalis_same( 'penalis_not_authenticated', $guest->get_error_code(), 'A guest action uses the authentication error code.' );

/* -------------------------------------------------------------------------
 * Authorization and native REST hardening (Phase 3)
 * ---------------------------------------------------------------------- */
/**
 * Register a post for the authorization tests.
 *
 * @param int    $id     Post ID.
 * @param int    $author Author ID.
 * @param string $status Post status.
 * @param string $type   Post type.
 * @return void
 */
function penalis_auth_post( $id, $author, $status = 'draft', $type = 'post' ) {
	$post              = new WP_Post();
	$post->ID          = (int) $id;
	$post->post_author = (int) $author;
	$post->post_status = $status;
	$post->post_type   = $type;
	$GLOBALS['penalis_test_posts'][ (int) $id ] = $post;
}

/**
 * Register an active writer for the authorization tests.
 *
 * @param int $id User ID.
 * @return void
 */
function penalis_auth_writer( $id ) {
	$user = new WP_User( (int) $id, array( 'penalis_writer' ) );
	$user->meta[ Penalis_Account_Status::META_STATUS ] = array( Penalis_Policy::STATUS_ACTIVE );
	$user->meta[ Penalis_Account_Status::META_ACCESS ] = array( '1' );
	$GLOBALS['penalis_test_users'][ (int) $id ] = $user;
}

/**
 * Register an account in a given status.
 *
 * @param int    $id     User ID.
 * @param string $status Account status.
 * @param string $role   Role.
 * @return void
 */
function penalis_auth_account( $id, $status, $role = 'subscriber' ) {
	$user = new WP_User( (int) $id, array( $role ) );
	$user->meta[ Penalis_Account_Status::META_STATUS ] = array( $status );
	$user->meta[ Penalis_Account_Status::META_ACCESS ] = array( '0' );
	$GLOBALS['penalis_test_users'][ (int) $id ] = $user;
}

/**
 * Run the REST dispatch guard as the given user.
 *
 * @param int                 $user_id Acting user.
 * @param string              $route   Route.
 * @param string              $method  HTTP method.
 * @return mixed
 */
function penalis_guard( $user_id, $route, $method ) {
	$GLOBALS['penalis_test_current_user'] = (int) $user_id;

	return Penalis_Access_Control::guard_rest_request( null, null, new WP_REST_Request( $method, $route ) );
}

// The retired writer publish endpoints are gone and refuse direct requests.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_post( 100, 10, 'draft' );
$GLOBALS['penalis_test_current_user'] = 10;

penalis_assert( ! method_exists( 'Penalis_Rest_Api', 'publish_article' ), 'The writer publish handler no longer exists.' );
penalis_assert( ! method_exists( 'Penalis_Rest_Api', 'unpublish_article' ), 'The writer unpublish handler no longer exists.' );

$legacy_publish = Penalis_Rest_Api::reject_legacy_publish( new WP_REST_Request( 'POST', '/penalis/v1/articles/100/publish' ) );
penalis_assert( is_wp_error( $legacy_publish ), 'The retired publish endpoint answers with an error.' );
penalis_same( 'penalis_publish_forbidden', $legacy_publish->get_error_code(), 'The retired publish endpoint reports the publish error code.' );
penalis_same( 403, $legacy_publish->get_error_data()['status'], 'The retired publish endpoint answers 403.' );

$legacy_unpublish = Penalis_Rest_Api::reject_legacy_publish( new WP_REST_Request( 'POST', '/penalis/v1/articles/100/unpublish' ) );
penalis_same( 'penalis_publish_forbidden', $legacy_unpublish->get_error_code(), 'The retired unpublish endpoint reports the publish error code.' );

// A direct REST request to the retired route is refused before dispatch.
$direct_publish = penalis_guard( 10, '/penalis/v1/articles/100/publish', 'POST' );
penalis_assert( is_wp_error( $direct_publish ), 'A writer cannot reach the retired publish route.' );
penalis_same( 'penalis_rest_forbidden', $direct_publish->get_error_code(), 'The retired publish route is outside the write allowlist.' );

// Account self-service routes stay reachable for pending verification accounts.
penalis_auth_account( 12, Penalis_Policy::STATUS_PENDING_VERIFICATION );
penalis_assert( null === penalis_guard( 12, '/penalis/v1/account/profile', 'POST' ), 'An unverified account may update its own profile.' );
penalis_assert( null === penalis_guard( 12, '/penalis/v1/account/password', 'POST' ), 'An unverified account may change its own password.' );
penalis_same( 'penalis_account_inactive', penalis_guard( 12, '/wp/v2/posts', 'POST' )->get_error_code(), 'An unverified account cannot write posts.' );

// Anonymous requests are passed through so core can answer unauthenticated.
penalis_assert( null === penalis_guard( 0, '/wp/v2/posts', 'POST' ), 'The guard leaves anonymous requests to core.' );

// Taxonomies are readable but never writable for writers; the dashboard uses
// its own narrow tag endpoint instead of taxonomy administration.
penalis_reset();
penalis_auth_writer( 10 );
penalis_assert( null === penalis_guard( 10, '/wp/v2/categories', 'GET' ), 'A writer may read categories.' );
penalis_assert( null === penalis_guard( 10, '/wp/v2/tags', 'GET' ), 'A writer may read tags.' );
penalis_same( 'penalis_rest_forbidden', penalis_guard( 10, '/wp/v2/categories', 'POST' )->get_error_code(), 'A writer cannot create categories through core REST.' );
penalis_same( 'penalis_rest_forbidden', penalis_guard( 10, '/wp/v2/categories/5', 'DELETE' )->get_error_code(), 'A writer cannot delete categories through core REST.' );
penalis_same( 'penalis_rest_forbidden', penalis_guard( 10, '/wp/v2/tags/5', 'POST' )->get_error_code(), 'A writer cannot update tags through core REST.' );
penalis_assert( null === penalis_guard( 10, '/penalis/v1/tags', 'POST' ), 'A writer may use the narrow tag endpoint.' );

// Administrator and editor workflows are untouched by the guard.
$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'editor' ) );
penalis_assert( null === penalis_guard( 20, '/wp/v2/posts', 'POST' ), 'An administrator may write posts through core REST.' );
penalis_assert( null === penalis_guard( 20, '/penalis/v1/articles/100/publish', 'POST' ), 'An administrator is not restricted by the writer allowlist.' );
penalis_assert( null === penalis_guard( 21, '/wp/v2/posts/100', 'POST' ), 'An editor is not restricted by the writer allowlist.' );

// Reviewers without a Penalis writer status may still open the dashboard API.
$GLOBALS['penalis_test_current_user'] = 21;
penalis_assert( true === Penalis_Rest_Api::require_logged_in(), 'An editor may reach the dashboard API without a writer status.' );

$editor_account = Penalis_Rest_Api::get_account();
penalis_assert( $editor_account instanceof WP_REST_Response, 'An editor receives an account payload.' );
$editor_data = $editor_account->get_data();
penalis_assert( true === $editor_data['isReviewer'], 'The account payload marks an editor as a reviewer.' );
penalis_assert( false === $editor_data['isAdministrator'], 'The account payload does not mark an editor as an administrator.' );
penalis_assert( false === $editor_data['canWrite'], 'An editor does not gain writer capabilities in the dashboard.' );
penalis_assert( true === $editor_data['canView'], 'An editor may view the dashboard.' );
penalis_same( 'Reviewer', $editor_data['statusLabel'], 'An editor without a writer status is labelled Reviewer.' );
penalis_same( 'editor', $editor_data['role'], 'The account payload resolves an editor to the staff role.' );

$GLOBALS['penalis_test_current_user'] = 20;
penalis_assert( true === Penalis_Rest_Api::require_logged_in(), 'An administrator may reach the dashboard API.' );
$admin_account = Penalis_Rest_Api::get_account()->get_data();
penalis_assert( true === $admin_account['isReviewer'], 'The account payload marks an administrator as a reviewer.' );
penalis_same( 'Administrator', $admin_account['statusLabel'], 'An administrator keeps the Administrator label.' );
penalis_same( 'administrator', $admin_account['role'], 'The account payload resolves an administrator to the staff role.' );

// A staff account that also holds writer access is still staff: Penalis policy
// forbids the hybrid case, so the dashboard must not open the writer UI.
penalis_same(
	'editor',
	Penalis_Policy::dashboard_role( false, true, Penalis_Policy::STATUS_ACTIVE, true ),
	'A reviewer with writer access still resolves to staff.'
);
penalis_same(
	'administrator',
	Penalis_Policy::dashboard_role( true, true, Penalis_Policy::STATUS_ACTIVE, true ),
	'An administrator with writer access still resolves to staff.'
);
penalis_same(
	'writer',
	Penalis_Policy::dashboard_role( false, false, Penalis_Policy::STATUS_ACTIVE, true ),
	'An active writer resolves to the writer role.'
);
penalis_same(
	'pending',
	Penalis_Policy::dashboard_role( false, false, Penalis_Policy::STATUS_PENDING_VERIFICATION, false ),
	'A published author without access resolves to pending.'
);
penalis_same(
	'suspended',
	Penalis_Policy::dashboard_role( false, false, Penalis_Policy::STATUS_SUSPENDED, false ),
	'A suspended account resolves to suspended.'
);

// A writer without an active status is still refused by the dashboard API.
penalis_auth_account( 13, Penalis_Policy::STATUS_SUSPENDED );
$GLOBALS['penalis_test_current_user'] = 13;
$suspended_check = Penalis_Rest_Api::require_logged_in();
penalis_assert( is_wp_error( $suspended_check ), 'A suspended account is refused by the dashboard API.' );
penalis_same( 'penalis_account_inactive', $suspended_check->get_error_code(), 'A suspended account reports the inactive error code.' );
unset( $GLOBALS['penalis_test_current_user'] );

// Single-item IDOR: another writer's draft, pending post, and media.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_writer( 11 );
penalis_auth_post( 100, 10, 'publish' );
penalis_auth_post( 101, 10, 'pending' );
penalis_auth_post( 102, 10, 'draft' );
penalis_auth_post( 310, 10, 'inherit', 'attachment' );

penalis_assert( null === penalis_guard( 11, '/wp/v2/posts/100', 'GET' ), 'A writer may read another writer\'s published article.' );
penalis_same( 'penalis_rest_forbidden', penalis_guard( 11, '/wp/v2/posts/101', 'GET' )->get_error_code(), 'A writer cannot read another writer\'s pending submission.' );
penalis_same( 'penalis_rest_forbidden', penalis_guard( 11, '/wp/v2/posts/102', 'GET' )->get_error_code(), 'A writer cannot read another writer\'s draft.' );
penalis_same( 'penalis_rest_forbidden', penalis_guard( 11, '/wp/v2/media/310', 'GET' )->get_error_code(), 'A writer cannot read another writer\'s media.' );
penalis_assert( null === penalis_guard( 10, '/wp/v2/media/310', 'GET' ), 'A writer may read their own media.' );
penalis_assert( null === penalis_guard( 10, '/wp/v2/posts/102', 'GET' ), 'A writer may read their own draft.' );
penalis_assert( null === penalis_guard( 11, '/wp/v2/media/999', 'GET' ), 'A missing item is left to core for its own 404.' );
penalis_assert( null === penalis_guard( 11, '/wp/v2/posts', 'GET' ), 'The list endpoint is not affected by the single-item guard.' );

// An administrator reads every item.
$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
penalis_assert( null === penalis_guard( 20, '/wp/v2/posts/101', 'GET' ), 'An administrator may read any submission.' );
penalis_assert( null === penalis_guard( 20, '/wp/v2/media/310', 'GET' ), 'An administrator may read any media item.' );

// Core REST insert guard: status, author, ownership and editorial lock.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_writer( 11 );
penalis_auth_post( 100, 10, 'draft' );
penalis_auth_post( 101, 10, 'publish' );
penalis_auth_post( 102, 10, 'pending' );
penalis_auth_post( 310, 10, 'inherit', 'attachment' );
penalis_auth_post( 311, 11, 'inherit', 'attachment' );
$GLOBALS['penalis_test_current_user'] = 10;

$prepared = new stdClass();
$created  = Penalis_Access_Control::enforce_post_insert(
	$prepared,
	new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'draft' ) )
);
penalis_assert( ! is_wp_error( $created ), 'A writer may create a draft.' );
penalis_same( 10, $created->post_author, 'The server derives the author of a new draft.' );

$publish = Penalis_Access_Control::enforce_post_insert(
	new stdClass(),
	new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'publish' ) )
);
penalis_assert( is_wp_error( $publish ), 'A writer cannot set a post status to publish.' );
penalis_same( 'penalis_publish_forbidden', $publish->get_error_code(), 'Publishing through core REST uses the publish error code.' );

$pending = Penalis_Access_Control::enforce_post_insert(
	new stdClass(),
	new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'pending' ) )
);
penalis_same( 'penalis_invalid_status', $pending->get_error_code(), 'A writer cannot park a post in pending.' );

$spoof = Penalis_Access_Control::enforce_post_insert(
	new stdClass(),
	new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'author' => 11 ) )
);
penalis_same( 'penalis_invalid_author', $spoof->get_error_code(), 'A writer cannot assign another author.' );

$foreign = new stdClass();
$foreign->ID = 100;
$GLOBALS['penalis_test_current_user'] = 11;
penalis_same(
	'penalis_not_owner',
	Penalis_Access_Control::enforce_post_insert( $foreign, new WP_REST_Request( 'POST', '/wp/v2/posts/100', array( 'status' => 'draft' ) ) )->get_error_code(),
	'A writer cannot update another writer\'s draft.'
);

$GLOBALS['penalis_test_current_user'] = 10;
$published_update = new stdClass();
$published_update->ID = 101;
$published_result = Penalis_Access_Control::enforce_post_insert( $published_update, new WP_REST_Request( 'POST', '/wp/v2/posts/101' ) );
penalis_same( 'penalis_editorial_locked', $published_result->get_error_code(), 'A writer cannot update their own published article.' );

$review_update = new stdClass();
$review_update->ID = 102;
$review_result = Penalis_Access_Control::enforce_post_insert( $review_update, new WP_REST_Request( 'POST', '/wp/v2/posts/102' ) );
penalis_same( 'penalis_editorial_locked', $review_result->get_error_code(), 'A writer cannot update a submission under review.' );

$own_draft_update = new stdClass();
$own_draft_update->ID = 100;
penalis_assert(
	! is_wp_error( Penalis_Access_Control::enforce_post_insert( $own_draft_update, new WP_REST_Request( 'POST', '/wp/v2/posts/100', array( 'status' => 'draft' ) ) ) ),
	'A writer may update their own draft.'
);

$foreign_media = new stdClass();
$foreign_media_result = Penalis_Access_Control::enforce_post_insert(
	$foreign_media,
	new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'draft', 'featured_media' => 311 ) )
);
penalis_same( 'penalis_invalid_media', $foreign_media_result->get_error_code(), 'A writer cannot use another writer\'s media as a featured image.' );

$own_media = new stdClass();
penalis_assert(
	! is_wp_error(
		Penalis_Access_Control::enforce_post_insert(
			$own_media,
			new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'draft', 'featured_media' => 310 ) )
		)
	),
	'A writer may use their own media as a featured image.'
);

$privileged = Penalis_Access_Control::enforce_post_insert(
	new stdClass(),
	new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'draft', 'sticky' => true ) )
);
penalis_same( 'penalis_invalid_field', $privileged->get_error_code(), 'A writer cannot make a post sticky.' );

// Administrators keep native post behaviour, including publishing.
$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 20;
$admin_prepared = new stdClass();
penalis_assert(
	! is_wp_error(
		Penalis_Access_Control::enforce_post_insert(
			$admin_prepared,
			new WP_REST_Request( 'POST', '/wp/v2/posts', array( 'status' => 'publish' ) )
		)
	),
	'An administrator may publish through core REST.'
);
$admin_update = new stdClass();
$admin_update->ID = 101;
penalis_assert(
	! is_wp_error( Penalis_Access_Control::enforce_post_insert( $admin_update, new WP_REST_Request( 'POST', '/wp/v2/posts/101' ) ) ),
	'An administrator may update a published article through core REST.'
);

// Attachment insert guard: ownership, parent, author and status.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_writer( 11 );
penalis_auth_post( 100, 10, 'draft' );
penalis_auth_post( 101, 11, 'draft' );
penalis_auth_post( 310, 10, 'inherit', 'attachment' );
penalis_auth_post( 311, 11, 'inherit', 'attachment' );
$GLOBALS['penalis_test_current_user'] = 10;

$media_create = new stdClass();
penalis_assert(
	! is_wp_error( Penalis_Access_Control::enforce_attachment_insert( $media_create, new WP_REST_Request( 'POST', '/wp/v2/media' ) ) ),
	'A writer may upload media.'
);
penalis_same( 10, $media_create->post_author, 'The server derives the author of a new attachment.' );

$media_spoof = new stdClass();
penalis_same(
	'penalis_invalid_author',
	Penalis_Access_Control::enforce_attachment_insert( $media_spoof, new WP_REST_Request( 'POST', '/wp/v2/media', array( 'author' => 11 ) ) )->get_error_code(),
	'A writer cannot upload media for another account.'
);

$media_foreign_update = new stdClass();
$media_foreign_update->ID = 311;
penalis_same(
	'penalis_not_found',
	Penalis_Access_Control::enforce_attachment_insert( $media_foreign_update, new WP_REST_Request( 'POST', '/wp/v2/media/311' ) )->get_error_code(),
	'A writer cannot update another writer\'s media (404, not 403).'
);

$media_status = new stdClass();
penalis_same(
	'penalis_invalid_status',
	Penalis_Access_Control::enforce_attachment_insert( $media_status, new WP_REST_Request( 'POST', '/wp/v2/media', array( 'status' => 'publish' ) ) )->get_error_code(),
	'A writer cannot publish an attachment post.'
);

$media_parent_foreign = new stdClass();
penalis_same(
	'penalis_invalid_media_parent',
	Penalis_Access_Control::enforce_attachment_insert( $media_parent_foreign, new WP_REST_Request( 'POST', '/wp/v2/media', array( 'post' => 101 ) ) )->get_error_code(),
	'A writer cannot attach media to another writer\'s article.'
);

$media_parent_own = new stdClass();
penalis_assert(
	! is_wp_error( Penalis_Access_Control::enforce_attachment_insert( $media_parent_own, new WP_REST_Request( 'POST', '/wp/v2/media', array( 'post' => 100 ) ) ) ),
	'A writer may attach media to their own article.'
);

$media_own_update = new stdClass();
$media_own_update->ID = 310;
penalis_assert(
	! is_wp_error( Penalis_Access_Control::enforce_attachment_insert( $media_own_update, new WP_REST_Request( 'POST', '/wp/v2/media/310' ) ) ),
	'A writer may update their own media.'
);

$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 20;
penalis_assert(
	! is_wp_error( Penalis_Access_Control::enforce_attachment_insert( new stdClass(), new WP_REST_Request( 'POST', '/wp/v2/media', array( 'post' => 101, 'author' => 11 ) ) ) ),
	'An administrator is unaffected by the attachment insert guard.'
);

// Media parameters are also refused before the upload is written: core's
// attachment controller casts the prepared object to an array without checking
// for WP_Error, so the filter alone would refuse only after the file exists.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_writer( 11 );
penalis_auth_post( 100, 10, 'draft' );
penalis_auth_post( 101, 11, 'draft' );

penalis_assert(
	null === penalis_guard( 10, '/wp/v2/media', 'POST' ),
	'A writer may upload media through the dispatch guard: the author is derived server-side.'
);

$GLOBALS['penalis_test_current_user'] = 10;
$media_dispatch_spoof = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'POST', '/wp/v2/media', array( 'author' => 11 ) )
);
penalis_same( 'penalis_invalid_author', $media_dispatch_spoof->get_error_code(), 'A writer cannot spoof the media author before dispatch.' );

$media_dispatch_status = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'POST', '/wp/v2/media', array( 'status' => 'publish' ) )
);
penalis_same( 'penalis_invalid_status', $media_dispatch_status->get_error_code(), 'A writer cannot publish an attachment before dispatch.' );

$media_dispatch_parent = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'POST', '/wp/v2/media', array( 'post' => 101 ) )
);
penalis_same( 'penalis_invalid_media_parent', $media_dispatch_parent->get_error_code(), 'A writer cannot attach media to another writer\'s article before dispatch.' );

$media_dispatch_own = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'POST', '/wp/v2/media', array( 'post' => 100, 'status' => 'inherit' ) )
);
penalis_assert( null === $media_dispatch_own, 'A writer may attach media to their own article before dispatch.' );

$media_dispatch_update = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'POST', '/wp/v2/media/310' )
);
penalis_assert( null === $media_dispatch_update, 'The media dispatch guard leaves updates to the insert filter.' );

$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 20;
$media_dispatch_admin = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'POST', '/wp/v2/media', array( 'author' => 11, 'post' => 101, 'status' => 'publish' ) )
);
penalis_assert( null === $media_dispatch_admin, 'An administrator is unaffected by the media dispatch guard.' );

// Meta capability mapping for writers, editors and administrators.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_writer( 11 );
penalis_auth_post( 100, 10, 'draft' );
penalis_auth_post( 101, 10, 'publish' );
penalis_auth_post( 102, 10, 'pending' );
penalis_auth_post( 103, 11, 'draft' );
penalis_auth_post( 310, 10, 'inherit', 'attachment' );
penalis_auth_post( 311, 11, 'inherit', 'attachment' );
penalis_auth_post( 400, 10, 'publish', 'page' );

/**
 * Whether a capability check ends with do_not_allow.
 *
 * @param string $cap     Capability.
 * @param int    $user_id User ID.
 * @param int    $post_id Post ID.
 * @return bool
 */
function penalis_cap_denied( $cap, $user_id, $post_id ) {
	$caps = Penalis_Access_Control::map_meta_cap( array( $cap ), $cap, (int) $user_id, array( (int) $post_id ) );

	return in_array( 'do_not_allow', $caps, true );
}

penalis_assert( ! penalis_cap_denied( 'edit_post', 10, 100 ), 'A writer may edit their own draft.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 101 ), 'A writer may not edit their own published article.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 102 ), 'A writer may not edit a submission under review.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 103 ), 'A writer may not edit another writer\'s draft.' );
penalis_assert( penalis_cap_denied( 'publish_post', 10, 100 ), 'A writer never gets publish_post, even on their own draft.' );
penalis_assert( penalis_cap_denied( 'delete_post', 10, 101 ), 'A writer may not delete their own published article.' );
penalis_assert( ! penalis_cap_denied( 'delete_post', 10, 100 ), 'A writer may still delete their own draft.' );
penalis_assert( penalis_cap_denied( 'delete_post', 10, 103 ), 'A writer may not delete another writer\'s article.' );
penalis_assert( ! penalis_cap_denied( 'edit_post', 10, 310 ), 'A writer may edit their own attachment.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 311 ), 'A writer may not edit another writer\'s attachment.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 400 ), 'A writer may not reach a page through a meta capability.' );

$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'editor' ) );
penalis_assert( ! penalis_cap_denied( 'edit_post', 20, 101 ), 'An administrator may edit a published article.' );
penalis_assert( ! penalis_cap_denied( 'delete_post', 20, 101 ), 'An administrator may take down a published article.' );
penalis_assert( ! penalis_cap_denied( 'publish_post', 20, 100 ), 'An administrator keeps publish_post.' );
penalis_assert( ! penalis_cap_denied( 'edit_post', 21, 101 ), 'An editor may edit a published article.' );
penalis_assert( ! penalis_cap_denied( 'edit_post', 21, 311 ), 'An editor may edit any media item.' );

// An inactive writer loses every meta capability.
$GLOBALS['penalis_test_users'][11]->meta[ Penalis_Account_Status::META_ACCESS ] = array( '0' );
penalis_assert( penalis_cap_denied( 'edit_post', 11, 103 ), 'A writer without access cannot edit their own draft.' );
penalis_assert( penalis_cap_denied( 'delete_post', 11, 103 ), 'A writer without access cannot delete their own draft.' );

// List queries stay narrowed for writers and wide for administrators.
penalis_reset();
penalis_auth_writer( 10 );
$GLOBALS['penalis_test_current_user'] = 10;
$writer_query = Penalis_Access_Control::force_own_post_query( array(), new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
penalis_same( 10, $writer_query['author'], 'Post list queries are forced to the current writer.' );
$writer_media_query = Penalis_Access_Control::force_own_media_query( array(), new WP_REST_Request( 'GET', '/wp/v2/media' ) );
penalis_same( 10, $writer_media_query['author'], 'Media list queries are forced to the current writer.' );

$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 20;
penalis_assert( ! isset( Penalis_Access_Control::force_own_post_query( array(), new WP_REST_Request( 'GET', '/wp/v2/posts' ) )['author'] ), 'An administrator\'s post query is not rewritten.' );
penalis_assert( ! isset( Penalis_Access_Control::force_own_media_query( array(), new WP_REST_Request( 'GET', '/wp/v2/media' ) )['author'] ), 'An administrator\'s media query is not rewritten.' );

// A tampered editorial state never reopens a published article.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_post( 500, 10, 'publish' );
$GLOBALS['penalis_test_posts'][500]->meta[ Penalis_Editorial_Policy::META_STATE ] = array( Penalis_Editorial_Policy::STATE_DRAFT );
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, Penalis_Editorial_Workflow::get_state( 500 ), 'Stored draft metadata cannot override a published status.' );
penalis_assert( ! Penalis_Editorial_Workflow::can_writer_edit( 500, 10 ), 'A tampered state does not reopen a published article.' );

penalis_auth_post( 501, 10, 'pending' );
$GLOBALS['penalis_test_posts'][501]->meta[ Penalis_Editorial_Policy::META_STATE ] = array( Penalis_Editorial_Policy::STATE_DRAFT );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Workflow::get_state( 501 ), 'Stored draft metadata cannot override a pending status.' );

// The insert-data guard is the last line of defence for any write path.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_post( 100, 10, 'draft' );
penalis_auth_post( 101, 10, 'publish' );
$GLOBALS['penalis_test_current_user'] = 10;

$downgraded = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'publish' ), array() );
penalis_same( 'draft', $downgraded['post_status'], 'A writer publish attempt is downgraded to draft.' );

$future = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'future' ), array() );
penalis_same( 'draft', $future['post_status'], 'A writer scheduled post is downgraded to draft.' );

$private = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'private' ), array() );
penalis_same( 'draft', $private['post_status'], 'A writer private post is downgraded to draft.' );

$draft_kept = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'draft' ), array() );
penalis_same( 'draft', $draft_kept['post_status'], 'A writer draft is left alone.' );

$live_kept = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'publish' ), array( 'ID' => 101 ) );
penalis_same( 'publish', $live_kept['post_status'], 'The guard never unpublishes an already published article.' );

$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 20;
$admin_publish = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'publish' ), array() );
penalis_same( 'publish', $admin_publish['post_status'], 'An administrator publish is untouched by the insert-data guard.' );

$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'editor' ) );
$GLOBALS['penalis_test_current_user'] = 21;
$editor_publish = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'publish' ), array() );
penalis_same( 'publish', $editor_publish['post_status'], 'An editor publish is untouched by the insert-data guard.' );

$GLOBALS['penalis_test_current_user'] = 0;
$guest_publish = Penalis_Access_Control::guard_writer_publish_status( array( 'post_status' => 'publish' ), array() );
penalis_same( 'publish', $guest_publish['post_status'], 'An anonymous insert is left to core.' );

// wp-admin access restriction tests.
$GLOBALS['penalis_test_users'][30] = new WP_User( 30, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_current_user'] = 30;
penalis_assert( true === Penalis_Access_Control::restrict_admin_access( false ), 'A writer is restricted from accessing wp-admin.' );
penalis_same( Penalis_Dashboard::dashboard_url(), $GLOBALS['penalis_test_redirect']['location'] ?? '', 'A restricted user is redirected to the dashboard.' );

$GLOBALS['penalis_test_doing_ajax'] = true;
penalis_assert( false === Penalis_Access_Control::restrict_admin_access( false ), 'An AJAX request is allowed through restrict_admin_access.' );
$GLOBALS['penalis_test_doing_ajax'] = false;

$GLOBALS['penalis_test_users'][31] = new WP_User( 31, array( 'editor' ) );
$GLOBALS['penalis_test_current_user'] = 31;
penalis_assert( false === Penalis_Access_Control::restrict_admin_access( false ), 'An editor is allowed in wp-admin.' );

$GLOBALS['penalis_test_users'][32] = new WP_User( 32, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 32;
penalis_assert( false === Penalis_Access_Control::restrict_admin_access( false ), 'An administrator is allowed in wp-admin.' );

// login_redirect tests.
penalis_same( Penalis_Dashboard::dashboard_url(), Penalis_Access_Control::redirect_after_login( 'https://example.com/wp-admin/', 'https://example.com/wp-admin/', $GLOBALS['penalis_test_users'][30] ), 'Non-staff user is redirected to dashboard upon login.' );
penalis_same( 'https://example.com/wp-admin/', Penalis_Access_Control::redirect_after_login( 'https://example.com/wp-admin/', 'https://example.com/wp-admin/', $GLOBALS['penalis_test_users'][32] ), 'Administrator login redirect is untouched.' );

// Staff-only wp-login.php authentication tests.
$GLOBALS['pagenow'] = 'wp-login.php';
$blocked_writer_login = Penalis_Access_Control::block_login( $GLOBALS['penalis_test_users'][30], 'user30', 'pass' );
penalis_assert( is_wp_error( $blocked_writer_login ) && 'penalis_staff_only_login' === $blocked_writer_login->get_error_code(), 'Non-staff account is blocked from authenticating via wp-login.php.' );
$allowed_admin_login = Penalis_Access_Control::block_login( $GLOBALS['penalis_test_users'][32], 'user32', 'pass' );
penalis_assert( $allowed_admin_login instanceof WP_User, 'Administrator is allowed to authenticate via wp-login.php.' );
$GLOBALS['pagenow'] = 'index.php';

// generic_login_error preservation tests.
penalis_assert( false !== strpos( Penalis_Access_Control::generic_login_error( 'Halaman login ini khusus untuk tim pengelola. Silakan login melalui dashboard.' ), 'dashboard' ), 'Staff login error pointing to dashboard is preserved.' );
penalis_same( 'Username atau password salah.', Penalis_Access_Control::generic_login_error( 'Unknown error' ), 'Arbitrary error is replaced with generic message.' );

/* -------------------------------------------------------------------------
 * Administrator review surface (Phase 4)
 * ---------------------------------------------------------------------- */

// Labels are user-facing Indonesian copy, not internal state codes.
penalis_same( 'Draft', Penalis_Editorial_Policy::state_label( Penalis_Editorial_Policy::STATE_DRAFT ), 'Draft has an Indonesian label.' );
penalis_same( 'Menunggu review', Penalis_Editorial_Policy::state_label( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ), 'Pending review has an Indonesian label.' );
penalis_same( 'Perlu revisi', Penalis_Editorial_Policy::state_label( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ), 'Requested changes has an Indonesian label.' );
penalis_same( 'Ditolak', Penalis_Editorial_Policy::state_label( Penalis_Editorial_Policy::STATE_REJECTED ), 'Rejected has an Indonesian label.' );
penalis_same( 'Terbit', Penalis_Editorial_Policy::state_label( Penalis_Editorial_Policy::STATE_PUBLISHED ), 'Published has an Indonesian label.' );
penalis_same( 'Tidak diketahui', Penalis_Editorial_Policy::state_label( 'unknown' ), 'An unknown state has a safe label.' );

penalis_same( 'Disetujui dan diterbitkan', Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::ACTION_APPROVE ), 'Approval has an Indonesian label.' );
penalis_same( 'Diminta revisi', Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ), 'Requested changes has an Indonesian label.' );
penalis_same( 'Ditolak', Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::ACTION_REJECT ), 'Rejection has an Indonesian label.' );
penalis_same( 'Dibuka kembali', Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::ACTION_REOPEN ), 'Reopen has an Indonesian label.' );
penalis_same( 'Di-submit penulis', Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::ACTION_SUBMIT ), 'Submission has an Indonesian label.' );
penalis_same( 'Di-submit ulang penulis', Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::ACTION_RESUBMIT ), 'Resubmission has an Indonesian label.' );

// The buttons shown must match the transitions the state machine accepts.
penalis_same(
	array(
		Penalis_Editorial_Policy::ACTION_APPROVE,
		Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
		Penalis_Editorial_Policy::ACTION_REJECT,
	),
	Penalis_Editorial_Admin::actions_for_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ),
	'A submission under review offers approve, request changes, and reject.'
);
penalis_same(
	array( Penalis_Editorial_Policy::ACTION_REOPEN ),
	Penalis_Editorial_Admin::actions_for_state( Penalis_Editorial_Policy::STATE_REJECTED ),
	'A rejected submission offers only reopen.'
);
penalis_same(
	array(),
	Penalis_Editorial_Admin::actions_for_state( Penalis_Editorial_Policy::STATE_PUBLISHED ),
	'A published article offers no reviewer action.'
);
penalis_same(
	array(),
	Penalis_Editorial_Admin::actions_for_state( Penalis_Editorial_Policy::STATE_DRAFT ),
	'A draft offers no reviewer action.'
);
penalis_same(
	array(),
	Penalis_Editorial_Admin::actions_for_state( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ),
	'A submission waiting for the writer offers no reviewer action.'
);

// Every offered action is a reviewer action and is accepted by the policy.
foreach ( Penalis_Editorial_Policy::states() as $state ) {
	foreach ( Penalis_Editorial_Admin::actions_for_state( $state ) as $action ) {
		penalis_assert(
			Penalis_Editorial_Policy::is_reviewer_action( $action ),
			"The review screen never offers the writer action {$action}."
		);
		penalis_assert(
			Penalis_Editorial_Policy::can_transition( $state, $action ),
			"The review screen only offers transitions the policy accepts ({$state}:{$action})."
		);
	}
}

// Review queue filters are normalized once and shared by the screen and URLs.
penalis_same(
	array(
		's'       => '',
		'author'  => 0,
		'cat'     => 0,
		'from'    => '',
		'to'      => '',
		'cycle'   => 0,
		'orderby' => 'modified',
		'order'   => 'DESC',
	),
	Penalis_Editorial_Admin::parse_list_filters( array() ),
	'An empty filter request keeps the historical default sort.'
);
penalis_same(
	array(
		's'       => 'Hujan',
		'author'  => 12,
		'cat'     => 7,
		'from'    => '2026-11-01',
		'to'      => '2026-11-18',
		'cycle'   => 2,
		'orderby' => 'submitted',
		'order'   => 'ASC',
	),
	Penalis_Editorial_Admin::parse_list_filters(
		array(
			's'       => '  <b>Hujan</b>  ',
			'author'  => '12',
			'cat'     => '7',
			'from'    => '2026-11-18',
			'to'      => '2026-11-01',
			'cycle'   => '2',
			'orderby' => 'submitted',
			'order'   => 'asc',
		)
	),
	'Filters are sanitized, an inverted date range is swapped, and order is uppercased.'
);

$invalid_filters = Penalis_Editorial_Admin::parse_list_filters(
	array(
		's'       => str_repeat( 'a', 120 ),
		'author'  => '-4',
		'cat'     => 'not-a-number',
		'from'    => '2026-02-30',
		'to'      => '18-11-2026',
		'cycle'   => '-2',
		'orderby' => 'drop table',
		'order'   => 'sideways',
	)
);
penalis_assert( 100 === strlen( $invalid_filters['s'] ), 'A long search string is capped.' );
penalis_same( 0, $invalid_filters['author'], 'A negative author ID is rejected.' );
penalis_same( 0, $invalid_filters['cat'], 'A non-numeric category is rejected.' );
penalis_same( '', $invalid_filters['from'], 'An impossible calendar date is rejected.' );
penalis_same( '', $invalid_filters['to'], 'A date in the wrong format is rejected.' );
penalis_same( 0, $invalid_filters['cycle'], 'A negative review cycle is rejected.' );
penalis_same( 'modified', $invalid_filters['orderby'], 'An unknown sort field falls back to modified.' );
penalis_same( 'DESC', $invalid_filters['order'], 'An unknown sort direction falls back to DESC.' );

$plain_args = Penalis_Editorial_Admin::query_args( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, 1, 20 );
penalis_same( 'modified', $plain_args['orderby'], 'The unfiltered queue keeps its original sort.' );
penalis_assert( ! isset( $plain_args['meta_query'] ), 'The unfiltered pending queue needs no extra meta clauses.' );

$filtered_args = Penalis_Editorial_Admin::query_args(
	Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
	1,
	20,
	array(
		's'      => 'Hujan',
		'author' => 12,
		'cat'    => 7,
		'cycle'  => 2,
		'from'   => '2026-11-01',
		'to'     => '2026-11-18',
	)
);
penalis_same( array( 'pending' ), $filtered_args['post_status'], 'Filters never change the editorial state clause.' );
penalis_same( 'Hujan', $filtered_args['s'], 'Search text reaches WP_Query.' );
penalis_same( 12, $filtered_args['author'], 'Author reaches WP_Query.' );
penalis_same( 7, $filtered_args['cat'], 'Category reaches WP_Query.' );
penalis_same( Penalis_Editorial_Policy::META_REVIEW_CYCLE, $filtered_args['meta_query'][0]['key'], 'A cycle filter becomes a meta clause.' );
penalis_same( 2, $filtered_args['meta_query'][0]['value'], 'The requested cycle is used as the meta value.' );
penalis_same( Penalis_Editorial_Policy::META_SUBMITTED_AT, $filtered_args['meta_query'][1]['key'], 'The lower date bound uses the submitted timestamp.' );
penalis_same( '>=', $filtered_args['meta_query'][1]['compare'], 'The from date is an inclusive lower bound.' );
penalis_same( Penalis_Editorial_Policy::META_SUBMITTED_AT, $filtered_args['meta_query'][2]['key'], 'The upper date bound uses the submitted timestamp.' );
penalis_same( '<=', $filtered_args['meta_query'][2]['compare'], 'The to date is an inclusive upper bound.' );
penalis_assert( $filtered_args['meta_query'][2]['value'] > $filtered_args['meta_query'][1]['value'], 'The upper timestamp is later than the lower one.' );

$merged_args = Penalis_Editorial_Admin::query_args(
	Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED,
	1,
	20,
	array( 'cycle' => 2 )
);
penalis_same( Penalis_Editorial_Policy::META_STATE, $merged_args['meta_query'][0]['key'], 'The state meta clause is preserved.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $merged_args['meta_query'][0]['value'], 'The state value is preserved.' );
penalis_same( Penalis_Editorial_Policy::META_REVIEW_CYCLE, $merged_args['meta_query'][1]['key'], 'The cycle clause is appended to the state clause.' );

$wrapped_args = Penalis_Editorial_Admin::query_args( Penalis_Editorial_Policy::STATE_DRAFT, 1, 20, array( 'cycle' => 1 ) );
penalis_same( 'AND', $wrapped_args['meta_query']['relation'], 'An OR state clause is wrapped in AND before filtering.' );
penalis_same( 'OR', $wrapped_args['meta_query'][0]['relation'], 'The original OR group is kept intact.' );
penalis_same( Penalis_Editorial_Policy::META_REVIEW_CYCLE, $wrapped_args['meta_query'][1]['key'], 'The filter applies outside the OR group.' );

$sorted_args = Penalis_Editorial_Admin::query_args(
	Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
	1,
	20,
	array(
		'orderby' => 'submitted',
		'order'   => 'asc',
	)
);
penalis_same( array( 'penalis_sort' => 'ASC' ), $sorted_args['orderby'], 'Sorting by submitted uses the named meta clause.' );
penalis_same( Penalis_Editorial_Policy::META_SUBMITTED_AT, $sorted_args['meta_query']['penalis_sort']['key'], 'The submitted timestamp drives the sort.' );
penalis_same( 'EXISTS', $sorted_args['meta_query']['penalis_sort']['compare'], 'The sort clause does not add a value restriction.' );

penalis_reset();
penalis_editorial_post( 700, 10, 'pending' );
penalis_editorial_post( 701, 11, 'pending' );
penalis_same(
	2,
	Penalis_Editorial_Workflow::count_matching_posts( Penalis_Editorial_Admin::query_args( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, 1, 20 ) ),
	'Both pending submissions are counted without a filter.'
);
penalis_same(
	1,
	Penalis_Editorial_Workflow::count_matching_posts( Penalis_Editorial_Admin::query_args( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, 1, 20, array( 'author' => 11 ) ) ),
	'The author filter narrows the submission count.'
);

$filtered_list_url = Penalis_Editorial_Admin::list_url(
	Penalis_Editorial_Policy::STATE_REJECTED,
	array(
		's'       => 'Hujan',
		'orderby' => 'title',
	)
);
penalis_assert( false !== strpos( $filtered_list_url, 'state=rejected' ), 'State is kept in filtered list URLs.' );
penalis_assert( false !== strpos( $filtered_list_url, 's=Hujan' ), 'Search is kept in filtered list URLs.' );
penalis_assert( false !== strpos( $filtered_list_url, 'orderby=title' ), 'Sort is kept in filtered list URLs.' );
penalis_assert( false === strpos( $filtered_list_url, 'order=DESC' ), 'Default filter values are omitted from URLs.' );

$return_args = Penalis_Editorial_Admin::return_args(
	Penalis_Editorial_Policy::STATE_REJECTED,
	array( 's' => 'Hujan' )
);
$filtered_detail_url = Penalis_Editorial_Admin::detail_url( 700, $return_args );
penalis_assert( false !== strpos( $filtered_detail_url, 'post_id=700' ), 'Detail URLs still identify the post.' );
penalis_assert( false !== strpos( $filtered_detail_url, 'state=rejected' ), 'Detail URLs carry the state for the back link.' );
penalis_assert( false !== strpos( $filtered_detail_url, 's=Hujan' ), 'Detail URLs carry the active filters.' );

// Published review tab defaults to publish date (`date DESC`) and displays
// `post_date` instead of falling back to `post_modified` on admin edits.
penalis_same( 'date', Penalis_Editorial_Admin::default_orderby_for_state( Penalis_Editorial_Policy::STATE_PUBLISHED ), 'Published review tab defaults to publish-date sort.' );
penalis_same( 'modified', Penalis_Editorial_Admin::default_orderby_for_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ), 'Pending review tab keeps modified sort by default.' );

$pub_default_filters = Penalis_Editorial_Admin::parse_list_filters( array(), Penalis_Editorial_Policy::STATE_PUBLISHED );
penalis_same( 'date', $pub_default_filters['orderby'], 'Empty filters on the Published tab default to date sort.' );

$pub_default_args = Penalis_Editorial_Admin::query_args( Penalis_Editorial_Policy::STATE_PUBLISHED, 1, 20 );
penalis_same( 'date', $pub_default_args['orderby'], 'Published review tab queries sort by publish date by default.' );
penalis_same( 'DESC', $pub_default_args['order'], 'Published review tab queries sort descending by default.' );

$pub_modified_args = Penalis_Editorial_Admin::query_args(
	Penalis_Editorial_Policy::STATE_PUBLISHED,
	1,
	20,
	array( 'orderby' => 'modified' )
);
penalis_same( 'modified', $pub_modified_args['orderby'], 'Reviewers can still explicitly sort the Published tab by last modified.' );

$pub_default_url = Penalis_Editorial_Admin::list_url( Penalis_Editorial_Policy::STATE_PUBLISHED, $pub_default_filters );
penalis_assert( false === strpos( $pub_default_url, 'orderby=' ), 'Default publish-date sort is omitted from Published tab URLs.' );

$pub_modified_url = Penalis_Editorial_Admin::list_url( Penalis_Editorial_Policy::STATE_PUBLISHED, array( 'orderby' => 'modified' ) );
penalis_assert( false !== strpos( $pub_modified_url, 'orderby=modified' ), 'Explicit modified sort is preserved on Published tab URLs.' );

penalis_reset();
$admin_edited_pub                    = penalis_editorial_post( 790, 10, 'publish', Penalis_Editorial_Policy::STATE_PUBLISHED );
$admin_edited_pub->post_date         = '2022-05-10 14:30:00';
$admin_edited_pub->post_date_gmt     = '2022-05-10 07:30:00';
$admin_edited_pub->post_modified     = '2026-11-26 10:03:00';
$admin_edited_pub->post_modified_gmt = '2026-11-26 03:03:00';
penalis_same(
	Penalis_Datetime::format_local( '2022-05-10 14:30:00', 'j M Y H:i' ),
	Penalis_Editorial_Admin::format_list_date( $admin_edited_pub, Penalis_Editorial_Policy::STATE_PUBLISHED ),
	'Published review tab formats post_date even when an older published article was modified later in wp-admin.'
);

$pending_with_submit                    = penalis_editorial_post( 791, 10, 'pending', Penalis_Editorial_Policy::STATE_PENDING_REVIEW );
$pending_with_submit->post_date         = '2026-11-01 08:00:00';
$pending_with_submit->post_modified     = '2026-11-26 10:03:00';
$pending_submit_ts                      = 1795410000;
update_post_meta( 791, Penalis_Editorial_Policy::META_SUBMITTED_AT, $pending_submit_ts );
penalis_same(
	Penalis_Datetime::format( $pending_submit_ts, 'j M Y H:i' ),
	Penalis_Editorial_Admin::format_list_date( $pending_with_submit, Penalis_Editorial_Policy::STATE_PENDING_REVIEW ),
	'Pending review tab continues to display the submission timestamp.'
);

penalis_assert( Penalis_Editorial_Admin::should_show_pending_notice( 'dashboard' ), 'Pending review notice renders on the WordPress Dashboard screen.' );
penalis_assert( Penalis_Editorial_Admin::should_show_pending_notice( 'edit-post' ), 'Pending review notice renders on the Posts list screen.' );
penalis_assert( ! Penalis_Editorial_Admin::should_show_pending_notice( 'toplevel_page_penalis-event' ), 'Pending review notice stays hidden on Penalis Event screens.' );
penalis_assert( ! Penalis_Editorial_Admin::should_show_pending_notice( 'toplevel_page_penalis-platform' ), 'Pending review notice stays hidden on Penalis Platform screens.' );
penalis_assert( ! Penalis_Editorial_Admin::should_show_pending_notice( 'plugins' ), 'Pending review notice stays hidden on unrelated admin screens like Plugins.' );
penalis_assert( ! Penalis_Editorial_Admin::should_show_pending_notice( '' ), 'Pending review notice stays hidden when no screen ID is available.' );

// Published-article takedown is audited and recoverable; drafts stay disposable.
penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_admin( 20 );
penalis_editorial_post( 800, 10, 'publish' );
penalis_editorial_post( 801, 10, 'draft' );

penalis_assert( Penalis_Article_Takedown::is_protected_post( get_post( 800 ) ), 'A published article is protected from writer deletion.' );
penalis_assert( ! Penalis_Article_Takedown::is_protected_post( get_post( 801 ) ), 'A draft is not protected from writer deletion.' );
penalis_assert( penalis_cap_denied( 'delete_post', 10, 800 ), 'The capability layer refuses a writer deleting a published article.' );
penalis_assert( ! penalis_cap_denied( 'delete_post', 10, 801 ), 'The capability layer still allows a writer deleting a draft.' );
penalis_assert( ! penalis_cap_denied( 'delete_post', 20, 800 ), 'A reviewer keeps the ability to take down a published article.' );

$GLOBALS['penalis_test_current_user'] = 10;
$delete_published = Penalis_Access_Control::guard_rest_request(
	null,
	null,
	new WP_REST_Request( 'DELETE', '/wp/v2/posts/800' )
);
penalis_assert( is_wp_error( $delete_published ), 'REST refuses a writer deleting a published article.' );
penalis_same( 'penalis_published_delete_denied', $delete_published->get_error_code(), 'REST explains why the deletion was refused.' );
penalis_same(
	null,
	Penalis_Access_Control::guard_rest_request( null, null, new WP_REST_Request( 'DELETE', '/wp/v2/posts/801' ) ),
	'REST leaves draft deletion untouched.'
);

$GLOBALS['penalis_test_current_user'] = 20;
$mails_before = count( $GLOBALS['penalis_test_mail'] );
Penalis_Article_Takedown::handle_status_transition( 'trash', 'publish', get_post( 800 ) );
penalis_assert( Penalis_Article_Takedown::has_takedown_record( get_post( 800 ) ), 'A published article entering trash is recorded.' );
penalis_same( 20, (int) get_post_meta( 800, Penalis_Article_Takedown::META_TRASHED_BY, true ), 'The acting reviewer is recorded.' );
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, (string) get_post_meta( 800, Penalis_Article_Takedown::META_TRASHED_STATE, true ), 'The pre-takedown editorial state is recorded.' );
penalis_assert( count( $GLOBALS['penalis_test_mail'] ) > $mails_before, 'Reviewers are emailed about the takedown.' );

$trashed_at = (int) get_post_meta( 800, Penalis_Article_Takedown::META_TRASHED_AT, true );
Penalis_Article_Takedown::handle_status_transition( 'trash', 'publish', get_post( 800 ) );
penalis_same( $trashed_at, (int) get_post_meta( 800, Penalis_Article_Takedown::META_TRASHED_AT, true ), 'A repeated transition does not duplicate the record.' );

$GLOBALS['penalis_test_posts'][800]->post_status = 'trash';
update_post_meta( 800, '_wp_trash_meta_status', 'publish' );
penalis_editorial_post( 802, 10, 'draft' );
$GLOBALS['penalis_test_posts'][802]->post_status = 'trash';
penalis_same( 1, Penalis_Article_Takedown::trashed_count(), 'Only recorded published articles appear in recovery.' );
penalis_same( 800, (int) Penalis_Article_Takedown::trashed_posts()[0]->ID, 'Recovery lists the recorded article.' );

penalis_assert( true === Penalis_Article_Takedown::restore( 800, 20 ), 'A reviewer can restore a recorded article.' );
penalis_same( 'publish', get_post( 800 )->post_status, 'Restoring returns the article to its published status.' );
penalis_assert( ! Penalis_Article_Takedown::has_takedown_record( get_post( 800 ) ), 'Restoring clears the active takedown record.' );
penalis_same( 20, (int) get_post_meta( 800, Penalis_Article_Takedown::META_RESTORED_BY, true ), 'The restoring reviewer is recorded.' );
penalis_same( 0, Penalis_Article_Takedown::trashed_count(), 'A restored article leaves the recovery list.' );
penalis_assert( is_wp_error( Penalis_Article_Takedown::restore( 802, 20 ) ), 'A draft without a takedown record cannot be restored here.' );

$trashed_template = Penalis_Email_Settings::get_template( 'editorial_trashed' );
penalis_assert( false !== strpos( $trashed_template['body'], '{link_trash_admin}' ), 'The takedown email exposes the recovery link placeholder.' );

// Decisions are serialized per post.
penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_admin( 20 );
penalis_editorial_post( 400, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 400, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );

$GLOBALS['wpdb'] = new Penalis_Test_WPDB();

$locked = Penalis_Editorial_Workflow::apply(
	400,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array( 'actor_id' => 20, 'expected_cycle' => 1 )
);
penalis_assert( ! is_wp_error( $locked ), 'A decision is applied while the lock is available.' );
penalis_same( 'publish', $GLOBALS['penalis_test_posts'][400]->post_status, 'The locked decision publishes the article.' );

// A lock that cannot be acquired fails closed.
penalis_editorial_post( 401, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 401, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
$GLOBALS['penalis_test_lock_available'] = false;

$busy = Penalis_Editorial_Workflow::apply(
	401,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array( 'actor_id' => 20, 'expected_cycle' => 1 )
);
penalis_assert( is_wp_error( $busy ), 'A decision is refused when the lock cannot be acquired.' );
penalis_same( 'penalis_editorial_busy', $busy->get_error_code(), 'A busy submission reports the busy error code.' );
penalis_same( 'pending', $GLOBALS['penalis_test_posts'][401]->post_status, 'A refused decision leaves the post pending.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, Penalis_Editorial_Workflow::get_state( 401 ), 'A refused decision leaves the editorial state untouched.' );

unset( $GLOBALS['penalis_test_lock_available'] );
unset( $GLOBALS['wpdb'] );

// Without a database handle the workflow still works (unit-test bootstrap).
penalis_editorial_post( 402, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 402, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
$no_db = Penalis_Editorial_Workflow::apply(
	402,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array( 'actor_id' => 20, 'expected_cycle' => 1 )
);
penalis_assert( ! is_wp_error( $no_db ), 'A missing database handle does not block the workflow.' );

// Two administrators deciding the same round: the second decision is refused
// because the post is re-read inside the lock.
penalis_editorial_admin( 22 );
penalis_editorial_post( 403, 10, 'draft' );
Penalis_Editorial_Workflow::apply( 403, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );

$first = Penalis_Editorial_Workflow::apply(
	403,
	Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Perbaiki paragraf pembuka.',
		'expected_cycle' => 1,
	)
);
penalis_assert( ! is_wp_error( $first ), 'The first reviewer decision is applied.' );

$second = Penalis_Editorial_Workflow::apply(
	403,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array( 'actor_id' => 22, 'expected_cycle' => 1 )
);
penalis_assert( is_wp_error( $second ), 'The second reviewer decision on the same round is refused.' );
penalis_same( 'draft', $GLOBALS['penalis_test_posts'][403]->post_status, 'A refused second decision leaves the post status untouched.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, Penalis_Editorial_Workflow::get_state( 403 ), 'A refused second decision leaves the first decision intact.' );
penalis_same( 'Perbaiki paragraf pembuka.', Penalis_Editorial_Workflow::get_feedback( 403 ), 'A refused second decision does not overwrite the first feedback.' );

/* -------------------------------------------------------------------------
 * Writer dashboard workflow and REST surface (Phase 5)
 * ---------------------------------------------------------------------- */

// Dispatch guard allows writer submission and resubmission routes while keeping
// the retired publish/unpublish routes blocked.
penalis_reset();
penalis_auth_writer( 10 );
penalis_auth_writer( 11 );
penalis_auth_account( 12, Penalis_Policy::STATUS_PENDING_VERIFICATION );

penalis_assert(
	null === penalis_guard( 10, '/penalis/v1/articles/500/submit', 'POST' ),
	'An active writer may reach the article submit route.'
);
penalis_assert(
	null === penalis_guard( 10, '/penalis/v1/articles/500/resubmit', 'POST' ),
	'An active writer may reach the article resubmit route.'
);
penalis_assert(
	null === penalis_guard( 10, '/penalis/v1/articles/500/review', 'GET' ),
	'An active writer may reach the article review route.'
);
penalis_same(
	'penalis_rest_forbidden',
	penalis_guard( 10, '/penalis/v1/articles/500/publish', 'POST' )->get_error_code(),
	'The publish route stays blocked by the dispatch guard.'
);
penalis_same(
	'penalis_rest_forbidden',
	penalis_guard( 10, '/penalis/v1/articles/500/unpublish', 'POST' )->get_error_code(),
	'The unpublish route stays blocked by the dispatch guard.'
);
penalis_same(
	'penalis_account_inactive',
	penalis_guard( 12, '/penalis/v1/articles/500/submit', 'POST' )->get_error_code(),
	'A pending account cannot submit an article.'
);

// Query filtering by editorial state separates draft, changes_requested,
// rejected, pending_review, and published.
$all_args = Penalis_Rest_Api::editorial_query_args( 10, 'all', 1, 10 );
penalis_same( array( 'publish', 'draft', 'pending', 'future' ), $all_args['post_status'], 'The all filter includes all dashboard post statuses.' );
penalis_assert( ! isset( $all_args['meta_query'] ), 'The all filter does not add a meta query.' );
penalis_same( true, ! empty( $all_args['penalis_effective_order'] ), 'The all filter enables effective publish/modified ordering.' );

$draft_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_DRAFT, 1, 10 );
penalis_same( array( 'draft' ), $draft_args['post_status'], 'The draft filter queries draft posts.' );
penalis_same( 'OR', $draft_args['meta_query']['relation'], 'The draft filter matches both legacy drafts without meta and explicit draft state.' );
penalis_same( 'modified', $draft_args['orderby'], 'The draft filter orders by last modified time.' );
penalis_assert( empty( $draft_args['penalis_effective_order'] ), 'The draft filter does not set effective ordering.' );

$changes_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, 1, 10 );
penalis_same( array( 'draft' ), $changes_args['post_status'], 'Requested changes use the draft post status.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $changes_args['meta_query'][0]['value'], 'Requested changes filter by editorial state meta.' );
penalis_same( 'modified', $changes_args['orderby'], 'Requested changes order by last modified time.' );

$rejected_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_REJECTED, 1, 10 );
penalis_same( array( 'draft' ), $rejected_args['post_status'], 'Rejected submissions use the draft post status.' );
penalis_same( Penalis_Editorial_Policy::STATE_REJECTED, $rejected_args['meta_query'][0]['value'], 'Rejected submissions filter by editorial state meta.' );
penalis_same( 'modified', $rejected_args['orderby'], 'Rejected submissions order by last modified time.' );

$pending_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_PENDING_REVIEW, 1, 10 );
penalis_same( array( 'pending' ), $pending_args['post_status'], 'Pending review queries pending posts.' );
penalis_same( 'modified', $pending_args['orderby'], 'Pending review orders by last modified time.' );

$published_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_PUBLISHED, 1, 10 );
penalis_same( array( 'publish' ), $published_args['post_status'], 'Published queries publish posts.' );
penalis_same( 'date', $published_args['orderby'], 'Published queries order by publish date, not last modified time.' );
penalis_assert( empty( $published_args['penalis_effective_order'] ), 'Published queries rely on native date ordering without effective-order SQL.' );

// Effective ORDER BY filter only rewrites queries explicitly marked with
// `penalis_effective_order` and keeps published posts anchored to post_date_gmt.
$unrelated_query = new WP_Query( array( 'orderby' => 'modified' ) );
penalis_same(
	'wp_posts.post_modified DESC',
	Penalis_Rest_Api::filter_effective_article_orderby( 'wp_posts.post_modified DESC', $unrelated_query ),
	'Effective orderby filter leaves unrelated queries untouched.'
);
$effective_query = new WP_Query( $all_args );
$effective_sql   = Penalis_Rest_Api::filter_effective_article_orderby( 'wp_posts.post_modified DESC', $effective_query );
penalis_assert(
	false !== strpos( $effective_sql, "CASE WHEN wp_posts.post_status = 'publish' AND wp_posts.post_date_gmt <> '0000-00-00 00:00:00' THEN wp_posts.post_date_gmt ELSE wp_posts.post_modified_gmt END DESC, wp_posts.ID DESC" ),
	'Effective orderby SQL sorts published posts by post_date_gmt and unpublished posts by post_modified_gmt.'
);

// End-to-end writer article listing: a wp-admin SEO/tag edit on an older
// published article must not bump it above newer published articles or active drafts.
penalis_reset();
penalis_editorial_writer( 10 );
$GLOBALS['penalis_test_current_user'] = 10;

$old_published                    = penalis_editorial_post( 801, 10, 'publish', Penalis_Editorial_Policy::STATE_PUBLISHED );
$old_published->post_title        = 'Karya Lama Dirapikan Admin';
$old_published->post_date_gmt     = '2026-08-14 08:00:00';
$old_published->post_modified_gmt = '2026-11-26 10:00:00';

$newer_published                    = penalis_editorial_post( 802, 10, 'publish', Penalis_Editorial_Policy::STATE_PUBLISHED );
$newer_published->post_title        = 'Karya Baru Terbit';
$newer_published->post_date_gmt     = '2026-11-20 08:00:00';
$newer_published->post_modified_gmt = '2026-11-20 08:00:00';

$recent_draft                    = penalis_editorial_post( 803, 10, 'draft', Penalis_Editorial_Policy::STATE_DRAFT );
$recent_draft->post_title        = 'Draft Hari Ini';
$recent_draft->post_date_gmt     = '0000-00-00 00:00:00';
$recent_draft->post_modified_gmt = '2026-11-26 09:00:00';

$all_order_resp = Penalis_Rest_Api::list_articles(
	new WP_REST_Request( 'GET', '/penalis/v1/articles', array( 'status' => 'all' ) )
);
$all_order_ids  = array_map(
	static function ( $item ) {
		return $item['id'];
	},
	$all_order_resp->get_data()['items']
);
penalis_same(
	array( 803, 802, 801 ),
	$all_order_ids,
	'GET /articles?status=all places today\'s draft first, then newer published, keeping an older admin-edited published post anchored to its publish date.'
);
penalis_assert(
	empty( $GLOBALS['penalis_test_filters']['posts_orderby'] ),
	'GET /articles removes its scoped posts_orderby filter after running WP_Query.'
);

$pub_order_resp = Penalis_Rest_Api::list_articles(
	new WP_REST_Request( 'GET', '/penalis/v1/articles', array( 'status' => 'published' ) )
);
$pub_order_ids  = array_map(
	static function ( $item ) {
		return $item['id'];
	},
	$pub_order_resp->get_data()['items']
);
penalis_same(
	array( 802, 801 ),
	$pub_order_ids,
	'GET /articles?status=published sorts strictly by publish date even when an older post was modified later in wp-admin.'
);

// Incomplete drafts (missing title or content) cannot be submitted for review.
penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_writer( 11 );
penalis_editorial_admin( 20 );
penalis_editorial_post( 500, 10, 'draft' );
$GLOBALS['penalis_test_current_user'] = 10;

$empty_submit = Penalis_Rest_Api::submit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/submit', array( 'id' => 500 ) ) );
penalis_assert( is_wp_error( $empty_submit ), 'An empty draft cannot be submitted.' );
penalis_same( 'penalis_editorial_incomplete', $empty_submit->get_error_code(), 'Submitting an empty draft returns the incomplete error code.' );
penalis_same( 'draft', $GLOBALS['penalis_test_posts'][500]->post_status, 'A refused incomplete submission leaves the post in draft.' );

$GLOBALS['penalis_test_posts'][500]->post_title   = 'Senja di Pelabuhan';
$GLOBALS['penalis_test_posts'][500]->post_content = '<p>Puisi tentang laut dan pulang.</p>';

// Title + content alone is still incomplete without thumbnail, category, and tag.
$missing_meta_submit = Penalis_Rest_Api::submit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/submit', array( 'id' => 500 ) ) );
penalis_assert( is_wp_error( $missing_meta_submit ), 'Draft without thumbnail, category, and tag cannot be submitted.' );
penalis_same( 'penalis_editorial_incomplete', $missing_meta_submit->get_error_code(), 'Missing media/taxonomy returns the incomplete error code.' );
$missing_fields = $missing_meta_submit->get_error_data()['fieldErrors'];
penalis_assert( isset( $missing_fields['featuredMedia'], $missing_fields['categories'], $missing_fields['tags'] ), 'Incomplete error reports missing featuredMedia, categories, and tags.' );

// Uncategorized alone does not satisfy the category requirement.
$GLOBALS['penalis_test_post_meta'][500]['_thumbnail_id'] = 901;
$GLOBALS['penalis_test_post_terms'][500] = array(
	'category' => array(
		(object) array(
			'term_id'  => 1,
			'name'     => 'Uncategorized',
			'slug'     => 'uncategorized',
			'taxonomy' => 'category',
		),
	),
	'post_tag' => array(
		(object) array(
			'term_id'  => 20,
			'name'     => 'Puisi',
			'slug'     => 'puisi',
			'taxonomy' => 'post_tag',
		),
	),
);

$uncat_submit = Penalis_Rest_Api::submit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/submit', array( 'id' => 500 ) ) );
penalis_assert( is_wp_error( $uncat_submit ), 'Uncategorized alone is rejected on submit.' );
penalis_same( 'penalis_editorial_incomplete', $uncat_submit->get_error_code(), 'Uncategorized-only submission returns incomplete error.' );
penalis_assert( isset( $uncat_submit->get_error_data()['fieldErrors']['categories'] ), 'Uncategorized-only submission flags the categories field.' );

// Adding a real category makes the draft complete for submission.
$GLOBALS['penalis_test_post_terms'][500]['category'][] = (object) array(
	'term_id'  => 2,
	'name'     => 'Sastra',
	'slug'     => 'sastra',
	'taxonomy' => 'category',
);

// Full REST lifecycle: draft -> submit -> admin request changes -> resubmit -> admin approve.
$initial_article = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/500', array( 'id' => 500 ) ) );
penalis_assert( $initial_article instanceof WP_REST_Response, 'The owner may fetch their draft article.' );
$initial_data = $initial_article->get_data();
penalis_same( Penalis_Editorial_Policy::STATE_DRAFT, $initial_data['editorialState'], 'A new draft reports the draft editorial state.' );
penalis_same( 'Draft', $initial_data['editorialStateLabel'], 'A new draft reports the Indonesian state label.' );
penalis_assert( true === $initial_data['canEdit'], 'A draft is editable by its owner.' );
penalis_assert( true === $initial_data['canSubmit'], 'A draft can be submitted by its owner.' );
penalis_assert( false === $initial_data['canResubmit'], 'A draft cannot be resubmitted before a review.' );

$submit_resp = Penalis_Rest_Api::submit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/submit', array( 'id' => 500 ) ) );
penalis_assert( $submit_resp instanceof WP_REST_Response, 'A complete draft is accepted for review.' );
$submit_data = $submit_resp->get_data();
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $submit_data['editorialState'], 'Submission moves the article to pending review.' );
penalis_same( 'Menunggu review', $submit_data['editorialStateLabel'], 'Submission reports the pending review label.' );
penalis_assert( false === $submit_data['canEdit'], 'A submission under review is locked from writer edits.' );
penalis_assert( false === $submit_data['canSubmit'], 'A submission under review cannot be submitted again.' );
penalis_assert( false === $submit_data['canResubmit'], 'A submission under review cannot be resubmitted.' );
penalis_same( 1, $submit_data['reviewCycle'], 'Submission starts review cycle 1.' );
penalis_same( 1, count( $submit_data['reviewHistory'] ), 'Submission records one review history entry.' );

// Another writer cannot read or submit the pending submission (404, no existence leak).
$GLOBALS['penalis_test_current_user'] = 11;
$foreign_read = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/500', array( 'id' => 500 ) ) );
penalis_assert( is_wp_error( $foreign_read ), 'Another writer cannot read the pending submission.' );
penalis_same( 'penalis_not_found', $foreign_read->get_error_code(), 'Cross-writer article read answers 404.' );

$foreign_review = Penalis_Rest_Api::get_article_review( new WP_REST_Request( 'GET', '/penalis/v1/articles/500/review', array( 'id' => 500 ) ) );
penalis_assert( is_wp_error( $foreign_review ), 'Another writer cannot read the review history.' );
penalis_same( 'penalis_not_found', $foreign_review->get_error_code(), 'Cross-writer review read answers 404.' );

$foreign_submit = Penalis_Rest_Api::submit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/submit', array( 'id' => 500 ) ) );
penalis_same( 'penalis_not_found', $foreign_submit->get_error_code(), 'Cross-writer submit attempt answers 404.' );

// An administrator may read the pending submission and its review state.
$GLOBALS['penalis_test_current_user'] = 20;
penalis_assert( true === Penalis_Rest_Api::require_active_writer_or_reviewer(), 'A reviewer without writer status passes the read-only article/review permission callback.' );
$admin_writer_check = Penalis_Rest_Api::require_active_writer();
penalis_assert( is_wp_error( $admin_writer_check ) && 'penalis_account_inactive' === $admin_writer_check->get_error_code(), 'A reviewer without writer status is still blocked from active-writer routes.' );

$admin_article = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/500', array( 'id' => 500 ) ) );
penalis_assert( $admin_article instanceof WP_REST_Response, 'An administrator may read a pending submission detail.' );

$admin_review = Penalis_Rest_Api::get_article_review( new WP_REST_Request( 'GET', '/penalis/v1/articles/500/review', array( 'id' => 500 ) ) );
penalis_assert( $admin_review instanceof WP_REST_Response, 'An administrator may read a pending submission review.' );
penalis_assert( false === $admin_review->get_data()['canSubmit'], 'A non-owner administrator does not get writer submit flags.' );

// Active writers pass the combined permission callback, while pending and logged-out users are rejected.
$GLOBALS['penalis_test_current_user'] = 10;
penalis_assert( true === Penalis_Rest_Api::require_active_writer_or_reviewer(), 'An active writer passes the read-only article/review permission callback.' );

$GLOBALS['penalis_test_current_user'] = 12;
$pending_read_check = Penalis_Rest_Api::require_active_writer_or_reviewer();
penalis_assert( is_wp_error( $pending_read_check ) && 'penalis_account_inactive' === $pending_read_check->get_error_code(), 'A pending writer fails the read-only article/review permission callback.' );

$GLOBALS['penalis_test_current_user'] = 0;
$anon_read_check = Penalis_Rest_Api::require_active_writer_or_reviewer();
penalis_assert( is_wp_error( $anon_read_check ) && 'penalis_not_authenticated' === $anon_read_check->get_error_code(), 'A logged-out user fails the read-only article/review permission callback.' );

$GLOBALS['penalis_test_current_user'] = 20;

// Administrator requests changes; writer sees feedback and can edit + resubmit.
Penalis_Editorial_Workflow::apply(
	500,
	Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Perkuat bait ketiga sebelum diterbitkan.',
		'expected_cycle' => 1,
	)
);

$GLOBALS['penalis_test_current_user'] = 10;
$changes_review = Penalis_Rest_Api::get_article_review( new WP_REST_Request( 'GET', '/penalis/v1/articles/500/review', array( 'id' => 500 ) ) );
$changes_data   = $changes_review->get_data();
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $changes_data['editorialState'], 'Writer sees the requested-changes state.' );
penalis_same( 'Perlu revisi', $changes_data['editorialStateLabel'], 'Writer sees the requested-changes label.' );
penalis_same( 'Perkuat bait ketiga sebelum diterbitkan.', $changes_data['feedback'], 'Writer receives the reviewer feedback.' );
penalis_assert( true === $changes_data['canEdit'], 'Writer may edit the article after requested changes.' );
penalis_assert( false === $changes_data['canSubmit'], 'First-time submit is disabled on requested changes.' );
penalis_assert( true === $changes_data['canResubmit'], 'Resubmit is enabled on requested changes.' );
penalis_same( 2, count( $changes_data['reviewHistory'] ), 'Review history contains both submission and decision.' );
penalis_same( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES, $changes_data['reviewHistory'][0]['action'], 'Review history is ordered newest first.' );

// Calling initial submit on a changes_requested article is refused with 409.
$wrong_action = Penalis_Rest_Api::submit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/submit', array( 'id' => 500 ) ) );
penalis_assert( is_wp_error( $wrong_action ), 'Initial submit is refused when changes were requested.' );
penalis_same( 'penalis_editorial_denied', $wrong_action->get_error_code(), 'Wrong action returns editorial denied.' );
penalis_same( 409, $wrong_action->get_error_data()['status'], 'Wrong action answers 409 Conflict for stale-state handling.' );

// Writer resubmits; feedback is cleared and cycle increments to 2.
$resubmit_resp = Penalis_Rest_Api::resubmit_article( new WP_REST_Request( 'POST', '/penalis/v1/articles/500/resubmit', array( 'id' => 500 ) ) );
penalis_assert( $resubmit_resp instanceof WP_REST_Response, 'The writer may resubmit after addressing feedback.' );
$resubmit_data = $resubmit_resp->get_data();
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $resubmit_data['editorialState'], 'Resubmission moves back to pending review.' );
penalis_same( 2, $resubmit_data['reviewCycle'], 'Resubmission increments the review cycle to 2.' );
penalis_same( '', $resubmit_data['feedback'], 'Resubmission clears active feedback.' );

// Administrator approves; published article becomes read-only to the writer.
Penalis_Editorial_Workflow::apply(
	500,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array(
		'actor_id'       => 20,
		'expected_cycle' => 2,
	)
);

$published_review = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/500', array( 'id' => 500 ) ) );
$published_data   = $published_review->get_data();
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, $published_data['editorialState'], 'Approved article reports the published state.' );
penalis_same( 'Terbit', $published_data['editorialStateLabel'], 'Approved article reports the Terbit label.' );
penalis_assert( false === $published_data['canEdit'], 'Published article is read-only to its writer.' );
penalis_assert( false === $published_data['canSubmit'], 'Published article cannot be submitted.' );
penalis_assert( false === $published_data['canResubmit'], 'Published article cannot be resubmitted.' );
penalis_assert( '' !== $published_data['link'], 'Published article exposes its public permalink.' );

/* -------------------------------------------------------------------------
 * Editorial notifications & emails (Phase 6)
 * ---------------------------------------------------------------------- */

// Pure policy rules for notification recipient scoping, validation, bounding, and read state.
penalis_assert(
	Penalis_Editorial_Policy::can_receive_notification( Penalis_Editorial_Policy::ACTION_SUBMIT, false, true ),
	'An administrator may receive a submission notification.'
);
penalis_assert(
	Penalis_Editorial_Policy::can_receive_notification( Penalis_Editorial_Policy::ACTION_SUBMIT, true, false ),
	'The submitting writer may receive a submission confirmation notification.'
);
penalis_assert(
	! Penalis_Editorial_Policy::can_receive_notification( Penalis_Editorial_Policy::ACTION_SUBMIT, false, false ),
	'Another writer cannot receive a submission notification.'
);
penalis_assert(
	Penalis_Editorial_Policy::can_receive_notification( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES, true, false ),
	'The owner receives a requested-changes notification.'
);
penalis_assert(
	! Penalis_Editorial_Policy::can_receive_notification( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES, false, true ),
	'A non-owner administrator does not receive the writer decision notification.'
);
penalis_assert(
	! Penalis_Editorial_Policy::can_receive_notification( 'unknown', true, true ),
	'Unknown actions never produce notifications.'
);

$valid_item = array(
	'id'         => 'notif_1',
	'type'       => Penalis_Editorial_Policy::ACTION_SUBMIT,
	'article_id' => 600,
	'message'    => 'Karya tulis menunggu review.',
	'created_at' => 1700000000,
	'read_at'    => 0,
);
penalis_assert( Penalis_Editorial_Policy::is_valid_notification( $valid_item ), 'A well-formed notification is valid.' );
penalis_assert(
	! Penalis_Editorial_Policy::is_valid_notification( array_merge( $valid_item, array( 'article_id' => 0 ) ) ),
	'A notification with article_id 0 is rejected.'
);
penalis_assert(
	! Penalis_Editorial_Policy::is_valid_notification( array_merge( $valid_item, array( 'type' => 'invalid' ) ) ),
	'A notification with an unknown type is rejected.'
);
penalis_assert(
	! Penalis_Editorial_Policy::is_valid_notification( array_merge( $valid_item, array( 'message' => '' ) ) ),
	'A notification with an empty message is rejected.'
);
penalis_assert(
	! Penalis_Editorial_Policy::is_valid_notification( array_merge( $valid_item, array( 'read_at' => -1 ) ) ),
	'A notification with a negative read_at is rejected.'
);

// Bounding to NOTIFICATION_LIMIT (50) and chronological storage order.
$bounded_list = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$bounded_list = Penalis_Editorial_Policy::append_notification(
		$bounded_list,
		array(
			'id'         => 'notif_' . $i,
			'type'       => Penalis_Editorial_Policy::ACTION_APPROVE,
			'article_id' => $i,
			'message'    => 'Pesan ' . $i,
			'created_at' => 1700000000 + $i,
			'read_at'    => 0,
		)
	);
}
penalis_same( Penalis_Editorial_Policy::NOTIFICATION_LIMIT, count( $bounded_list ), 'Notifications are bounded to NOTIFICATION_LIMIT.' );
penalis_same( 'notif_11', $bounded_list[0]['id'], 'Oldest notifications beyond the limit are dropped.' );
penalis_same( 'notif_60', $bounded_list[49]['id'], 'Newest notification is kept at the tail of storage.' );
penalis_assert( Penalis_Editorial_Policy::is_valid_notifications( $bounded_list ), 'Bounded notification list passes validation.' );
penalis_same( 50, Penalis_Editorial_Policy::count_unread_notifications( $bounded_list ), 'All 50 notifications start unread.' );

$one_read = Penalis_Editorial_Policy::mark_notifications_read( $bounded_list, 'notif_60', 1700000999 );
penalis_assert( true === $one_read['changed'], 'Marking an unread notification reports changed = true.' );
penalis_same( 1700000999, $one_read['notifications'][49]['read_at'], 'Marking a single notification read sets its read_at timestamp.' );
penalis_same( 49, Penalis_Editorial_Policy::count_unread_notifications( $one_read['notifications'] ), 'Unread count decrements by one.' );

$all_read = Penalis_Editorial_Policy::mark_notifications_read( $one_read['notifications'], '', 1700001000 );
penalis_assert( true === $all_read['changed'], 'Marking remaining unread notifications reports changed = true.' );
penalis_same( 0, Penalis_Editorial_Policy::count_unread_notifications( $all_read['notifications'] ), 'Marking all notifications read leaves zero unread.' );
penalis_same( 1700000999, $all_read['notifications'][49]['read_at'], 'Already-read notifications preserve their original read_at timestamp.' );

// Email template CTA labels for review and editor links.
$review_mail = Penalis_Email_Template::render(
	"Ada kiriman baru.\n\nhttps://example.com/wp-admin/admin.php?page=penalis-review&post_id=600",
	'Karya Tulis Baru'
);
penalis_assert( false !== strpos( $review_mail, 'Review Karya Tulis' ), 'Review email CTA uses the Review Karya Tulis label.' );

penalis_same(
	'https://example.com/wp-admin/admin.php?page=penalis-platform',
	Penalis_Admin::overview_url(),
	'Overview URL points to the unified Penalis Platform admin menu.'
);
penalis_same(
	'https://example.com/wp-admin/admin.php?page=penalis-writers&status=pending_verification',
	Penalis_Admin::writers_url( array( 'status' => Penalis_Policy::STATUS_PENDING_VERIFICATION ) ),
	'Writers URL points to the Penulis Penalis submenu under admin.php.'
);
penalis_same(
	'https://example.com/wp-admin/admin.php?page=penalis-review&state=pending_review',
	Penalis_Editorial_Admin::list_url(),
	'Editorial review list URL points to the Review Karya Tulis submenu under admin.php.'
);
penalis_same(
	'https://example.com/wp-admin/admin.php?page=penalis-review&post_id=600',
	Penalis_Editorial_Admin::detail_url( 600 ),
	'Editorial review detail URL points to the Review Karya Tulis submenu under admin.php.'
);
penalis_assert(
	Penalis_Admin::is_penalis_screen( 'toplevel_page_penalis-platform' ),
	'Overview hook is recognized as a Penalis screen.'
);
penalis_assert(
	Penalis_Admin::is_penalis_screen( 'penalis-platform-1_page_penalis-review' ),
	'Review submenu hook with badge suffix is still recognized as a Penalis screen.'
);
penalis_assert(
	Penalis_Admin::is_penalis_screen( 'penalis-platform-3_page_penalis-writers' ),
	'Writers submenu hook with badge suffix is still recognized as a Penalis screen.'
);
penalis_assert(
	! Penalis_Admin::is_penalis_screen( 'plugins.php' ),
	'Unrelated admin hooks are not treated as Penalis screens.'
);

$editor_mail = Penalis_Email_Template::render(
	"Silakan perbaiki.\n\nhttps://example.com/dashboard/?view=editor&id=600",
	'Perlu Revisi'
);
penalis_assert( false !== strpos( $editor_mail, 'Buka Karya Tulis' ), 'Editor email CTA uses the Buka Karya Tulis label.' );

// End-to-end notifications & emails across all 5 editorial events, plus privacy and REST routes.
penalis_reset();
update_option( 'admin_email', 'admin@example.com' );
penalis_editorial_writer( 10 );
penalis_editorial_writer( 11 );
penalis_editorial_admin( 20 );
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'editor' ) );
penalis_editorial_post( 600, 10, 'draft' );
$GLOBALS['penalis_test_posts'][600]->post_title   = 'Malam di Pelabuhan';
$GLOBALS['penalis_test_posts'][600]->post_content = '<p>RAHASIA_ISI_ARTIKEL_PRIVAT_YANG_TIDAK_BOLEH_BOCOR</p>';

// Dispatch guard allows notification routes for active accounts.
penalis_assert(
	null === penalis_guard( 10, '/penalis/v1/notifications', 'GET' ),
	'An active writer may reach GET /notifications.'
);
penalis_assert(
	null === penalis_guard( 10, '/penalis/v1/notifications/read', 'POST' ),
	'An active writer may reach POST /notifications/read.'
);
penalis_assert(
	null === penalis_guard( 10, '/penalis/v1/notifications/notif_1/read', 'POST' ),
	'An active writer may reach POST /notifications/{id}/read.'
);

// 1. Submit: emails all reviewers (admin + editor + admin_email), records admin + owner dashboard notifications, never leaks post_content.
Penalis_Editorial_Workflow::apply( 600, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_same( 3, count( $GLOBALS['penalis_test_mail'] ), 'Submitting an article sends individual emails to administrator, editor, and admin_email.' );
$submit_recipients = array(
	$GLOBALS['penalis_test_mail'][0][0],
	$GLOBALS['penalis_test_mail'][1][0],
	$GLOBALS['penalis_test_mail'][2][0],
);
penalis_assert( in_array( 'user20@example.com', $submit_recipients, true ), 'Administrator reviewer receives a submission email.' );
penalis_assert( in_array( 'user21@example.com', $submit_recipients, true ), 'Editor reviewer receives a submission email.' );
penalis_assert( in_array( 'admin@example.com', $submit_recipients, true ), 'Submission email is also addressed to admin_email.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][1], 'Karya Tulis Baru' ), 'Submission email has the new-submission subject.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Malam di Pelabuhan' ), 'Submission email names the article title.' );
penalis_assert(
	false === strpos( $GLOBALS['penalis_test_mail'][0][2], 'RAHASIA_ISI_ARTIKEL_PRIVAT_YANG_TIDAK_BOLEH_BOCOR' ),
	'Submission email never includes private post_content.'
);
penalis_same( 1, Penalis_Editorial_Notifications::count_unread( 20 ), 'Administrator receives one unread dashboard notification on submit.' );
penalis_same( 1, Penalis_Editorial_Notifications::count_unread( 21 ), 'Editor reviewer receives one unread dashboard notification on submit.' );
penalis_same( 1, Penalis_Editorial_Notifications::count_unread( 10 ), 'Submitting writer receives one submission confirmation notification.' );
penalis_same( 0, Penalis_Editorial_Notifications::count_unread( 11 ), 'Another writer receives no notification on submit.' );

$admin_notifs = Penalis_Editorial_Notifications::get_for_user( 20 );
penalis_same( 1, count( $admin_notifs ), 'Administrator notification list has one entry.' );
penalis_same( Penalis_Editorial_Policy::ACTION_SUBMIT, $admin_notifs[0]['type'], 'Admin notification type is submit.' );
penalis_assert(
	false !== strpos( $admin_notifs[0]['reviewUrl'], 'page=penalis-review' ) && false !== strpos( $admin_notifs[0]['reviewUrl'], 'post_id=600' ),
	'Admin notification links to the wp-admin editorial review detail view.'
);
penalis_assert(
	false === strpos( $admin_notifs[0]['targetUrl'], 'penalis_focus' ),
	'A submission notification without feedback does not deep-link to the revision notes.'
);

// 2. Request changes: emails the author only, records writer notification with editor link.
Penalis_Editorial_Workflow::apply(
	600,
	Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Mohon rapikan bait kedua.',
		'expected_cycle' => 1,
	)
);
penalis_same( 4, count( $GLOBALS['penalis_test_mail'] ), 'Requesting changes sends an email to the writer.' );
penalis_same( 'user10@example.com', $GLOBALS['penalis_test_mail'][3][0], 'Requested-changes email goes only to the article owner.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][3][2], 'Mohon rapikan bait kedua.' ), 'Requested-changes email includes the reviewer feedback.' );
penalis_assert(
	false === strpos( $GLOBALS['penalis_test_mail'][3][2], 'RAHASIA_ISI_ARTIKEL_PRIVAT_YANG_TIDAK_BOLEH_BOCOR' ),
	'Requested-changes email never includes private post_content.'
);
penalis_same( 2, Penalis_Editorial_Notifications::count_unread( 10 ), 'Writer has two unread notifications after requested changes.' );
penalis_same( 0, Penalis_Editorial_Notifications::count_unread( 11 ), 'Other writer still has zero notifications.' );

$writer_notifs = Penalis_Editorial_Notifications::get_for_user( 10 );
penalis_same( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES, $writer_notifs[0]['type'], 'Newest writer notification type is request_changes.' );
penalis_assert(
	false !== strpos( $writer_notifs[0]['targetUrl'], 'view=editor' ) && false !== strpos( $writer_notifs[0]['targetUrl'], 'id=600' ),
	'Writer notification links directly to the dashboard editor for the article.'
);
penalis_assert(
	false !== strpos( $writer_notifs[0]['targetUrl'], 'penalis_focus=feedback' ),
	'A requested-changes notification deep-links to the revision notes panel.'
);
penalis_same( '', $writer_notifs[0]['reviewUrl'], 'Non-admin writer notification does not expose an admin reviewUrl.' );

// 3. Resubmit: emails reviewers, records admin + owner resubmit notifications.
Penalis_Editorial_Workflow::apply( 600, Penalis_Editorial_Policy::ACTION_RESUBMIT, array( 'actor_id' => 10 ) );
penalis_same( 7, count( $GLOBALS['penalis_test_mail'] ), 'Resubmitting sends individual emails to all reviewers.' );
$resubmit_recipients = array(
	$GLOBALS['penalis_test_mail'][4][0],
	$GLOBALS['penalis_test_mail'][5][0],
	$GLOBALS['penalis_test_mail'][6][0],
);
penalis_assert( in_array( 'user20@example.com', $resubmit_recipients, true ), 'Administrator receives resubmission email.' );
penalis_assert( in_array( 'user21@example.com', $resubmit_recipients, true ), 'Editor receives resubmission email.' );
penalis_assert( in_array( 'admin@example.com', $resubmit_recipients, true ), 'admin_email receives resubmission email.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][4][1], 'Revisi Karya Tulis Menunggu Review' ), 'Resubmission email has the resubmission subject.' );
penalis_same( 2, Penalis_Editorial_Notifications::count_unread( 20 ), 'Administrator now has two unread notifications.' );
penalis_same( 3, Penalis_Editorial_Notifications::count_unread( 10 ), 'Writer now has three unread notifications.' );

// 4. Reject: emails author, records writer reject notification.
Penalis_Editorial_Workflow::apply(
	600,
	Penalis_Editorial_Policy::ACTION_REJECT,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Tema belum sesuai kurasi bulan ini.',
		'expected_cycle' => 2,
	)
);
penalis_same( 8, count( $GLOBALS['penalis_test_mail'] ), 'Rejecting sends an email to the writer.' );
penalis_same( 'user10@example.com', $GLOBALS['penalis_test_mail'][7][0], 'Rejection email goes only to the article owner.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][7][2], 'Tema belum sesuai kurasi bulan ini.' ), 'Rejection email includes the feedback.' );
penalis_same( 4, Penalis_Editorial_Notifications::count_unread( 10 ), 'Writer now has four unread notifications.' );

// 5. Reopen: emails author, records writer reopen notification.
Penalis_Editorial_Workflow::apply( 600, Penalis_Editorial_Policy::ACTION_REOPEN, array( 'actor_id' => 20 ) );
penalis_same( 9, count( $GLOBALS['penalis_test_mail'] ), 'Reopening sends an email to the writer.' );
penalis_same( 'user10@example.com', $GLOBALS['penalis_test_mail'][8][0], 'Reopen email goes only to the article owner.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][8][1], 'Dibuka Kembali' ), 'Reopen email has the reopen subject.' );
penalis_same( 5, Penalis_Editorial_Notifications::count_unread( 10 ), 'Writer now has five unread notifications.' );

// 6. Resubmit and Approve (publication): emails author with public link, records writer approve notification.
Penalis_Editorial_Workflow::apply( 600, Penalis_Editorial_Policy::ACTION_RESUBMIT, array( 'actor_id' => 10 ) );
Penalis_Editorial_Workflow::apply(
	600,
	Penalis_Editorial_Policy::ACTION_APPROVE,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Selamat, karya siap tayang.',
		'expected_cycle' => 3,
	)
);
penalis_same( 13, count( $GLOBALS['penalis_test_mail'] ), 'Resubmit (3) + approve (1) sends four more emails.' );
penalis_same( 'user10@example.com', $GLOBALS['penalis_test_mail'][12][0], 'Publication email goes to the article owner.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][12][1], 'Telah Terbit' ), 'Publication email has the published subject.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][12][2], 'Selamat, karya siap tayang.' ), 'Publication email includes optional approval note.' );
penalis_same( 7, Penalis_Editorial_Notifications::count_unread( 10 ), 'Writer now has seven unread notifications.' );

// REST notifications list and read/unread mutations for the writer.
$GLOBALS['penalis_test_current_user'] = 10;
$notif_resp = Penalis_Rest_Api::list_notifications();
penalis_assert( $notif_resp instanceof WP_REST_Response, 'GET /notifications returns a REST response.' );
$notif_data = $notif_resp->get_data();
penalis_same( 7, $notif_data['unreadCount'], 'REST payload reports 7 unread notifications.' );
penalis_same( 7, count( $notif_data['items'] ), 'REST payload returns 7 notification items.' );
penalis_same( Penalis_Editorial_Policy::ACTION_APPROVE, $notif_data['items'][0]['type'], 'Newest notification is approve.' );

$first_notif_id = $notif_data['items'][0]['id'];
$mark_one_resp  = Penalis_Rest_Api::mark_notifications_read(
	new WP_REST_Request( 'POST', '/penalis/v1/notifications/' . $first_notif_id . '/read', array( 'id' => $first_notif_id ) )
);
$mark_one_data = $mark_one_resp->get_data();
penalis_same( 6, $mark_one_data['unreadCount'], 'Marking one notification read reduces unreadCount to 6.' );
penalis_assert( true === $mark_one_data['items'][0]['isRead'], 'Marked notification reports isRead = true.' );

$mark_all_resp = Penalis_Rest_Api::mark_notifications_read( new WP_REST_Request( 'POST', '/penalis/v1/notifications/read' ) );
$mark_all_data = $mark_all_resp->get_data();
penalis_same( 0, $mark_all_data['unreadCount'], 'Marking all notifications read reduces unreadCount to 0.' );

// Cross-recipient isolation: even if a notification entry for writer 10's private article is
// injected into writer 11's user meta, writer 11 cannot see or count it.
penalis_editorial_post( 601, 10, 'pending' );
$GLOBALS['penalis_test_posts'][601]->post_title = 'Judul Rahasia Penulis 10';
update_user_meta(
	11,
	Penalis_Editorial_Policy::META_NOTIFICATIONS,
	array(
		array(
			'id'         => 'notif_leaked',
			'type'       => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
			'article_id' => 601,
			'message'    => 'Judul Rahasia Penulis 10 perlu revisi.',
			'created_at' => 1700000500,
			'read_at'    => 0,
		),
	)
);
penalis_same( 0, Penalis_Editorial_Notifications::count_unread( 11 ), 'Cross-writer notification is filtered out of unread counts.' );
penalis_same( array(), Penalis_Editorial_Notifications::get_for_user( 11 ), 'Cross-writer notification is never returned to another writer.' );

// Mail failure handling: when wp_mail fails, the editorial transition and dashboard notification
// still succeed, and the email failure marker is recorded on the author's account.
$GLOBALS['penalis_test_mail_fails'] = true;
penalis_editorial_post( 602, 10, 'draft' );
$GLOBALS['penalis_test_posts'][602]->post_title   = 'Puisi Saat Server Email Gangguan';
$GLOBALS['penalis_test_posts'][602]->post_content = '<p>Isi puisi lengkap.</p>';

$submit_with_mail_fail = Penalis_Editorial_Workflow::apply( 602, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_assert( ! is_wp_error( $submit_with_mail_fail ), 'Submission succeeds even when wp_mail fails.' );
penalis_same(
	Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
	Penalis_Editorial_Workflow::get_state( 602 ),
	'Post transitions to pending_review despite mail failure.'
);
penalis_same(
	'editorial_submit',
	(string) get_user_meta( 10, Penalis_Account_Status::META_MAIL_CONTEXT, true ),
	'Mail failure on submit records the editorial_submit failure context on the author.'
);

$decision_with_mail_fail = Penalis_Editorial_Workflow::apply(
	602,
	Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
	array(
		'actor_id'       => 20,
		'feedback'       => 'Revisi saat email gagal.',
		'expected_cycle' => 1,
	)
);
penalis_assert( ! is_wp_error( $decision_with_mail_fail ), 'Editorial decision succeeds even when wp_mail fails.' );
penalis_same(
	Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED,
	Penalis_Editorial_Workflow::get_state( 602 ),
	'Post transitions to changes_requested despite mail failure.'
);
penalis_same(
	'editorial_request_changes',
	(string) get_user_meta( 10, Penalis_Account_Status::META_MAIL_CONTEXT, true ),
	'Mail failure on request_changes records the editorial_request_changes context on the author.'
);
penalis_same( 2, Penalis_Editorial_Notifications::count_unread( 10 ), 'Dashboard notifications are still recorded when wp_mail fails.' );
unset( $GLOBALS['penalis_test_mail_fails'] );

/* -------------------------------------------------------------------------
 * Notification concurrency
 * ---------------------------------------------------------------------- */

/*
 * Notifications live in one serialized user-meta array, so every read-modify-
 * write cycle must run inside a per-user advisory lock. Without it, two
 * submissions for the same reviewer in the same second both read the same
 * snapshot and the later write erases the earlier notification, while the
 * email for the lost entry has already been sent.
 */

penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
penalis_editorial_writer( 60 );
penalis_editorial_post( 700, 60, 'draft' );
$GLOBALS['penalis_test_posts'][700]->post_title = 'Judul Notifikasi Bersamaan';

$GLOBALS['penalis_test_db_events'] = array();
penalis_assert(
	Penalis_Editorial_Notifications::record_for_user( 60, 700, Penalis_Editorial_Policy::ACTION_SUBMIT ),
	'A submission notification is stored for the article owner.'
);
penalis_same(
	array( 'lock', 'meta:' . Penalis_Editorial_Policy::META_NOTIFICATIONS, 'unlock' ),
	$GLOBALS['penalis_test_db_events'],
	'Recording a notification reads and writes the stored list inside the per-user advisory lock.'
);

$GLOBALS['penalis_test_lock_queries'] = array();
Penalis_Editorial_Notifications::record_for_user( 60, 700, Penalis_Editorial_Policy::ACTION_RESUBMIT );
$owner_lock_query = isset( $GLOBALS['penalis_test_lock_queries'][0] ) ? (string) $GLOBALS['penalis_test_lock_queries'][0] : '';
penalis_assert(
	false !== strpos( $owner_lock_query, 'penalis_notif_' ),
	'Notification writes take a Penalis notification advisory lock.'
);

// The lock name is scoped per recipient, so two reviewers never serialize on
// each other's notification list.
$GLOBALS['penalis_test_lock_queries'] = array();
penalis_editorial_writer( 61 );
penalis_editorial_post( 701, 61, 'draft' );
Penalis_Editorial_Notifications::record_for_user( 61, 701, Penalis_Editorial_Policy::ACTION_SUBMIT );
$other_lock_query = isset( $GLOBALS['penalis_test_lock_queries'][0] ) ? (string) $GLOBALS['penalis_test_lock_queries'][0] : '';
penalis_assert(
	'' !== $owner_lock_query && '' !== $other_lock_query && $owner_lock_query !== $other_lock_query,
	'Each recipient serializes notification writes on their own advisory lock.'
);
unset( $GLOBALS['penalis_test_lock_queries'] );

// Two notifications recorded in the same second for the same post and action
// must both survive and must carry distinct IDs.
$GLOBALS['penalis_test_db_events'] = array();
Penalis_Editorial_Notifications::record_for_user( 60, 700, Penalis_Editorial_Policy::ACTION_REOPEN );
$notif_ids = array();
foreach ( Penalis_Editorial_Notifications::get_raw_for_user( 60 ) as $notif_entry ) {
	$notif_ids[] = $notif_entry['id'];
}
penalis_same( 3, count( $notif_ids ), 'Every recorded notification is preserved instead of overwriting the previous one.' );
penalis_same( 3, count( array_unique( $notif_ids ) ), 'Notification IDs stay unique within the same second.' );

/*
 * The decisive regression: another request commits a notification for the same
 * recipient between our initial read and the lock grant. Because the list is
 * re-read inside the lock, both entries survive; a read-before-lock
 * implementation would write back the stale snapshot and lose the concurrent
 * notification even though its email was already delivered.
 */
$GLOBALS['penalis_test_on_lock'] = static function () {
	$existing   = get_user_meta( 60, Penalis_Editorial_Policy::META_NOTIFICATIONS, true );
	$existing   = is_array( $existing ) ? $existing : array();
	$existing[] = array(
		'id'         => 'notif_concurrent_request',
		'type'       => Penalis_Editorial_Policy::ACTION_SUBMIT,
		'article_id' => 700,
		'message'    => 'Notifikasi dari request lain.',
		'created_at' => 1700000600,
		'read_at'    => 0,
	);

	update_user_meta( 60, Penalis_Editorial_Policy::META_NOTIFICATIONS, $existing );
};

Penalis_Editorial_Notifications::record_for_user( 60, 700, Penalis_Editorial_Policy::ACTION_APPROVE );
unset( $GLOBALS['penalis_test_on_lock'] );

$concurrent_ids = array();
foreach ( Penalis_Editorial_Notifications::get_raw_for_user( 60 ) as $notif_entry ) {
	$concurrent_ids[] = $notif_entry['id'];
}
penalis_same( 5, count( $concurrent_ids ), 'A notification committed before the lock grant is preserved.' );
penalis_assert(
	in_array( 'notif_concurrent_request', $concurrent_ids, true ),
	'The concurrently written notification is never clobbered by the lock holder.'
);

// A lock that cannot be acquired must not fall back to an unlocked write.
$notif_before = get_user_meta( 60, Penalis_Editorial_Policy::META_NOTIFICATIONS, true );
$GLOBALS['penalis_test_lock_available'] = false;
penalis_assert(
	false === Penalis_Editorial_Notifications::record_for_user( 60, 700, Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ),
	'A busy notification lock reports that nothing was stored.'
);
unset( $GLOBALS['penalis_test_lock_available'] );
penalis_same(
	$notif_before,
	get_user_meta( 60, Penalis_Editorial_Policy::META_NOTIFICATIONS, true ),
	'A busy notification lock leaves the stored list untouched.'
);

// Read receipts use the same lock, so they cannot revert a concurrent append.
$GLOBALS['penalis_test_db_events'] = array();
$marked_read = Penalis_Editorial_Notifications::mark_read( 60 );
penalis_same( 0, $marked_read['unreadCount'], 'Marking all notifications read clears the unread count.' );
penalis_same(
	array( 'lock', 'meta:' . Penalis_Editorial_Policy::META_NOTIFICATIONS, 'unlock' ),
	$GLOBALS['penalis_test_db_events'],
	'Marking notifications read rewrites the stored list inside the per-user advisory lock.'
);

$GLOBALS['penalis_test_db_events'] = array();
$GLOBALS['penalis_test_lock_available'] = false;
$busy_read = Penalis_Editorial_Notifications::mark_read( 60, 'notif_any' );
unset( $GLOBALS['penalis_test_lock_available'] );
penalis_same(
	array( 'lock' ),
	$GLOBALS['penalis_test_db_events'],
	'A busy notification lock performs no unlocked write and releases nothing on read receipts.'
);
penalis_same( 0, $busy_read['unreadCount'], 'A busy read receipt reports the unchanged unread count.' );
unset( $GLOBALS['penalis_test_db_events'] );

/* -------------------------------------------------------------------------
 * Self-service registration verification, legacy activation, and Instagram REST profile
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][50] = new WP_User( 50, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 50 );
Penalis_Account_Status::set_instagram( 50, '@penalis.komunitas' );
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$verify_challenge = Penalis_Otp::issue( 'registration', 50, 'user50@example.com' );
penalis_assert( ! is_wp_error( $verify_challenge ), 'A registration challenge is issued for the new account.' );
$verify_code   = penalis_last_code();
$verify_result = Penalis_Registration::verify( $verify_challenge['challenge_id'], $verify_code );
penalis_assert( ! is_wp_error( $verify_result ), 'Email verification succeeds.' );
penalis_same( Penalis_Policy::STATUS_ACTIVE, $verify_result['status'], 'Email verification response reports active status.' );
penalis_same( Penalis_Policy::STATUS_ACTIVE, Penalis_Account_Status::get_status( 50 ), 'Verified user account is immediately active.' );
penalis_assert( Penalis_Account_Status::can_write( 50 ), 'Verified user can write immediately without admin approval.' );
penalis_same( 2, count( $GLOBALS['penalis_test_mail'] ), 'Verification sends the code and the active writer notification email.' );
penalis_same( 'user50@example.com', $GLOBALS['penalis_test_mail'][1][0], 'Active notification is sent to the verified writer, not admin.' );

// A consumed code cannot be replayed.
$replay = Penalis_Registration::verify( $verify_challenge['challenge_id'], $verify_code );
penalis_assert( is_wp_error( $replay ), 'A consumed verification code cannot be replayed.' );

// Self-service legacy activation immediately activates the account.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][51] = new WP_User( 51, array( 'contributor' ) );
$legacy_challenge = Penalis_Otp::issue( 'legacy', 51, 'user51@example.com' );
penalis_assert( ! is_wp_error( $legacy_challenge ), 'A legacy activation challenge is issued.' );
$legacy_result = Penalis_Legacy_Activation::activate(
	array(
		'challenge_id' => $legacy_challenge['challenge_id'],
		'code'         => penalis_last_code(),
		'password'     => 'twelve chars password',
	)
);
penalis_assert( ! is_wp_error( $legacy_result ), 'Legacy self-service activation succeeds.' );
penalis_same( Penalis_Policy::STATUS_ACTIVE, $legacy_result['status'], 'Legacy activation response reports active status.' );
penalis_same( Penalis_Policy::STATUS_ACTIVE, Penalis_Account_Status::get_status( 51 ), 'Activated legacy account is immediately active.' );
penalis_assert( Penalis_Account_Status::can_write( 51 ), 'Activated legacy account can write immediately.' );
penalis_same( 2, count( $GLOBALS['penalis_test_mail'] ), 'Legacy activation sends the code and the active writer notification email.' );

// A weak password is rejected before the code is consumed.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$GLOBALS['penalis_test_users'][53] = new WP_User( 53, array( 'contributor' ) );
$weak_challenge = Penalis_Otp::issue( 'legacy', 53, 'user53@example.com' );
$weak_result    = Penalis_Legacy_Activation::activate(
	array(
		'challenge_id' => $weak_challenge['challenge_id'],
		'code'         => penalis_last_code(),
		'password'     => 'short',
	)
);
penalis_assert( is_wp_error( $weak_result ), 'A weak password is rejected.' );
penalis_assert( is_array( get_user_meta( 53, '_penalis_otp_legacy', true ) ), 'A rejected password does not consume the code.' );

// REST profile endpoint updates and normalizes Instagram.
penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
penalis_auth_writer( 52 );
Penalis_Account_Status::set_instagram( 52, '@awal_ig' );
penalis_same( 'awal_ig', Penalis_Account_Status::get_instagram( 52 ), 'Initial Instagram handle is stored without leading @.' );
$GLOBALS['penalis_test_current_user'] = 52;

$account_resp = Penalis_Rest_Api::get_account();
penalis_assert( $account_resp instanceof WP_REST_Response, 'GET /account returns a response.' );
penalis_same( 'awal_ig', $account_resp->get_data()['instagram'], 'GET /account includes the normalized Instagram handle.' );

$profile_resp = Penalis_Rest_Api::update_profile(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/account/profile',
		array(
			'display_name' => 'Penulis 52',
			'description'  => 'Menulis puisi.',
			'instagram'    => '  @penulis.baru_52  ',
		)
	)
);
penalis_assert( $profile_resp instanceof WP_REST_Response, 'POST /account/profile with Instagram succeeds.' );
penalis_same( 'penulis.baru_52', $profile_resp->get_data()['instagram'], 'Profile response returns the updated normalized Instagram handle.' );
penalis_same( 'penulis.baru_52', Penalis_Account_Status::get_instagram( 52 ), 'Instagram handle is persisted in user meta.' );

$invalid_ig_resp = Penalis_Rest_Api::update_profile(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/account/profile',
		array(
			'instagram' => 'invalid handle!',
		)
	)
);
penalis_assert( is_wp_error( $invalid_ig_resp ), 'POST /account/profile with invalid Instagram fails.' );
penalis_assert( isset( $invalid_ig_resp->get_error_data()['fields']['instagram'] ), 'Field error is attached to instagram.' );

/* -------------------------------------------------------------------------
 * Transactional email settings & Markdown formatting
 * ---------------------------------------------------------------------- */

penalis_reset();

// 1. Penalis_Email_Template Markdown rendering
$html_link = Penalis_Email_Template::render( 'Yuk bergabung ke [WhatsApp Group](https://chat.whatsapp.com/test) sekarang.' );
penalis_assert( false !== strpos( $html_link, 'href="https://chat.whatsapp.com/test"' ), 'Markdown link href is properly rendered in email HTML.' );
penalis_assert( false !== strpos( $html_link, '>WhatsApp Group</a>' ), 'Markdown link label is properly rendered.' );

$html_bold = Penalis_Email_Template::render( 'Karya tulismu yang berjudul **Testing Bold** sudah terbit.' );
penalis_assert( false !== strpos( $html_bold, '<strong>Testing Bold</strong>' ), 'Markdown bold is rendered with strong tag.' );

$html_button = Penalis_Email_Template::render( "[Baca Tulisanmu](https://penalis.com/karya/testing)" );
penalis_assert( false !== strpos( $html_button, 'Baca Tulisanmu' ), 'Standalone markdown link renders button text.' );
penalis_assert( false !== strpos( $html_button, 'href="https://penalis.com/karya/testing"' ), 'Standalone markdown link renders button URL.' );
penalis_assert( false !== strpos( $html_button, '<table role="presentation"' ), 'Standalone markdown link renders as styled CTA button table.' );

$html_raw_url = Penalis_Email_Template::render( 'Kunjungi https://penalis.com/panduan untuk info.' );
penalis_assert( false !== strpos( $html_raw_url, 'href="https://penalis.com/panduan"' ), 'Raw URLs are converted to clickable links.' );

$html_safe = Penalis_Email_Template::render( '[Klik Disini](javascript:alert(1))' );
penalis_assert( false === strpos( $html_safe, 'href="javascript:' ), 'Unsafe link scheme in markdown is rejected.' );

$html_otp_box = Penalis_Email_Template::render( "Kode verifikasi kamu:\n\n779137\n\nBerlaku 10 menit." );
penalis_assert( false !== strpos( $html_otp_box, 'user-select:all' ) && false !== strpos( $html_otp_box, '>779137</p>' ), 'Standalone six-digit OTP code renders as a highlighted code box.' );

$html_inline_digits = Penalis_Email_Template::render( 'Nomor referensi 123456 bukan kode OTP.' );
penalis_assert( false === strpos( $html_inline_digits, 'user-select:all' ), 'Inline six-digit numbers inside prose are not promoted to an OTP box.' );

// 2. Penalis_Email_Settings registry & capabilities
penalis_same( 17, count( Penalis_Email_Settings::get_registry() ), 'There are exactly 17 configurable email templates in the registry.' );

$admin_user  = new WP_User( 100, array( 'administrator' ) );
$editor_user = new WP_User( 101, array( 'editor' ) );
$writer_user = new WP_User( 102, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][100] = $admin_user;
$GLOBALS['penalis_test_users'][101] = $editor_user;
$GLOBALS['penalis_test_users'][102] = $writer_user;

$GLOBALS['penalis_test_current_user'] = 100;
penalis_assert( Penalis_Email_Settings::can_manage(), 'Administrator with manage_options can manage email settings.' );

$GLOBALS['penalis_test_current_user'] = 101;
penalis_assert( ! Penalis_Email_Settings::can_manage(), 'Editor without manage_options cannot manage email settings.' );

$GLOBALS['penalis_test_current_user'] = 102;
penalis_assert( ! Penalis_Email_Settings::can_manage(), 'Writer cannot manage email settings.' );

$GLOBALS['penalis_test_current_user'] = 0;
penalis_assert( ! Penalis_Email_Settings::can_manage(), 'Logged-out user cannot manage email settings.' );

// 3. Default template retrieval and rendering
$default_reg = Penalis_Email_Settings::get_template( 'otp_registration' );
penalis_same( '[Penalis] Verifikasi pendaftaran', $default_reg['subject'], 'Default registration OTP subject matches specification.' );
penalis_assert( false !== strpos( $default_reg['body'], '{kode_otp}' ), 'Default registration OTP body includes {kode_otp}.' );
penalis_assert( false !== strpos( $default_reg['footer'], 'admin@penalis.com' ), 'Default registration OTP footer includes support email.' );

$rendered_reg = Penalis_Email_Settings::render(
	'otp_registration',
	array(
		'{kode_otp}'           => '123456',
		'{menit_berlaku}'      => '10',
		'{maksimal_percobaan}' => '5',
		'{nama_penulis}'       => 'Penulis Uji',
	)
);
penalis_same( '[Penalis] Verifikasi pendaftaran', $rendered_reg['subject'], 'Rendered default subject is clean.' );
penalis_assert( false !== strpos( $rendered_reg['body'], '123456' ), 'Rendered body replaces {kode_otp} with actual code.' );
penalis_assert( false === strpos( $rendered_reg['body'], '{kode_otp}' ), 'No unresolved placeholder remains in body.' );
penalis_assert( false !== strpos( $rendered_reg['footer'], 'admin@penalis.com' ), 'Rendered footer preserves contact copy.' );

// 4. Custom template saving, overriding, and placeholder substitution
update_option(
	Penalis_Email_Settings::OPTION_NAME,
	array(
		'editorial_approve' => array(
			'subject' => 'Selamat {nama_penulis}! Karya {judul_karya} Terbit!',
			'body'    => "Halo {nama_penulis},\n\nKarya tulismu **{judul_karya}** sudah terbit!\n\n[Baca Tulisanmu]({link_karya})\n\nGabung ke [WhatsApp Group](https://chat.whatsapp.com/test) ya!\n\nSalam,\n**Penalis**",
			'footer'  => 'Email resmi kurasi Penalis untuk {nama_penulis}.',
		),
	)
);

$custom_appr = Penalis_Email_Settings::get_template( 'editorial_approve' );
penalis_same( 'Selamat {nama_penulis}! Karya {judul_karya} Terbit!', $custom_appr['subject'], 'Custom subject is retrieved from option.' );
penalis_assert( false !== strpos( $custom_appr['body'], 'WhatsApp Group' ), 'Custom body is retrieved from option.' );
penalis_same( 'Email resmi kurasi Penalis untuk {nama_penulis}.', $custom_appr['footer'], 'Custom footer is retrieved from option.' );

$rendered_appr = Penalis_Email_Settings::render(
	'editorial_approve',
	array(
		'{nama_penulis}' => 'Erlandzzz',
		'{judul_karya}'  => 'Testing Deleted Soon',
		'{link_karya}'   => 'https://penalis.com/testing-deleted-soon/',
	)
);
penalis_same( 'Selamat Erlandzzz! Karya Testing Deleted Soon Terbit!', $rendered_appr['subject'], 'Variables in custom subject are substituted.' );
penalis_assert( false !== strpos( $rendered_appr['body'], 'Testing Deleted Soon' ), 'Variables in custom body are substituted.' );
penalis_same( 'Email resmi kurasi Penalis untuk Erlandzzz.', $rendered_appr['footer'], 'Variables in custom footer are substituted.' );

// Check full HTML output of customized email with Markdown links and CTA button
$full_html = Penalis_Email_Template::render( $rendered_appr['body'], $rendered_appr['subject'], $rendered_appr['footer'] );
penalis_assert( false !== strpos( $full_html, '<strong>Testing Deleted Soon</strong>' ), 'Custom body bold Markdown is rendered.' );
penalis_assert( false !== strpos( $full_html, 'href="https://chat.whatsapp.com/test"' ), 'Custom body WhatsApp Markdown link is rendered.' );
penalis_assert( false !== strpos( $full_html, 'href="https://penalis.com/testing-deleted-soon/"' ), 'Custom body CTA link is rendered.' );
penalis_assert( false !== strpos( $full_html, 'Baca Tulisanmu' ), 'Custom body CTA button label is rendered.' );
penalis_assert( false !== strpos( $full_html, 'Email resmi kurasi Penalis untuk Erlandzzz.' ), 'Custom contextual footer is rendered in HTML.' );

// 5. Safety-Net for required secret placeholders
update_option(
	Penalis_Email_Settings::OPTION_NAME,
	array(
		'otp_registration' => array(
			'subject' => 'Verifikasi Akun Baru',
			'body'    => 'Halo, selamat datang di Penalis! Silakan konfirmasi akunmu.', // Missing {kode_otp}!
			'footer'  => 'Footer aman.',
		),
		'password_reset'   => array(
			'subject' => 'Atur Ulang Sandi',
			'body'    => 'Klik tombol untuk reset password akun kamu.', // Missing {link_reset_password}!
			'footer'  => 'Footer reset.',
		),
	)
);

$safety_otp = Penalis_Email_Settings::render(
	'otp_registration',
	array(
		'{kode_otp}' => '987654',
	)
);
penalis_assert( false !== strpos( $safety_otp['body'], '987654' ), 'Safety net auto-appends missing {kode_otp} to rendered body.' );

$safety_pwd = Penalis_Email_Settings::render(
	'password_reset',
	array(
		'{link_reset_password}' => 'https://penalis.com/dashboard/?action=reset&key=secret123',
	)
);
penalis_assert( false !== strpos( $safety_pwd['body'], 'https://penalis.com/dashboard/?action=reset&key=secret123' ), 'Safety net auto-appends missing {link_reset_password} to rendered body.' );

// 6. Reset single and reset all
Penalis_Email_Settings::reset_single( 'otp_registration' );
$after_single = Penalis_Email_Settings::get_template( 'otp_registration' );
penalis_same( '[Penalis] Verifikasi pendaftaran', $after_single['subject'], 'Reset single reverts template to default.' );

$still_custom = Penalis_Email_Settings::get_template( 'password_reset' );
penalis_same( 'Atur Ulang Sandi', $still_custom['subject'], 'Reset single leaves other custom templates intact.' );

Penalis_Email_Settings::reset_all();
$after_all = Penalis_Email_Settings::get_template( 'password_reset' );
penalis_same( '[{nama_situs}] Reset Password Kamu', $after_all['subject'], 'Reset all reverts all templates to plugin defaults.' );

/* -------------------------------------------------------------------------
 * Admin pagination component
 * ---------------------------------------------------------------------- */

// 1. First page of multi-page list.
ob_start();
Penalis_Admin::render_pagination( 381, 20, 1, 'https://example.com/admin.php?page=penalis-review' );
$p1_html = ob_get_clean();

penalis_assert( false !== strpos( $p1_html, 'class="tablenav bottom"' ), 'Pagination renders tablenav bottom container.' );
penalis_assert( false !== strpos( $p1_html, '381 item' ), 'Pagination renders total item count.' );
penalis_assert( false !== strpos( $p1_html, 'tablenav-pages-navspan button disabled' ), 'First page has disabled prev buttons.' );
penalis_assert( false !== strpos( $p1_html, '<span class="current-page">1</span>' ), 'Current page indicator renders page 1.' );
penalis_assert( false !== strpos( $p1_html, '<span class="total-pages">20</span>' ), 'Total pages indicator renders 20.' );
penalis_assert( false !== strpos( $p1_html, 'class="next-page button"' ), 'Next page button is active.' );
penalis_assert( false !== strpos( $p1_html, 'paged=2' ), 'Next page button links to page 2.' );
penalis_assert( false !== strpos( $p1_html, 'paged=20' ), 'Last page button links to page 20.' );

// 2. Middle page of multi-page list.
ob_start();
Penalis_Admin::render_pagination( 381, 20, 5, 'https://example.com/admin.php?page=penalis-review' );
$p5_html = ob_get_clean();

penalis_assert( false !== strpos( $p5_html, 'class="first-page button"' ), 'Middle page has active first-page link.' );
penalis_assert( false !== strpos( $p5_html, 'class="prev-page button"' ), 'Middle page has active prev-page link.' );
penalis_assert( false !== strpos( $p5_html, 'paged=4' ), 'Prev page link points to page 4.' );
penalis_assert( false !== strpos( $p5_html, '<span class="current-page">5</span>' ), 'Current page indicator renders page 5.' );
penalis_assert( false !== strpos( $p5_html, 'paged=6' ), 'Next page link points to page 6.' );

// 3. Last page of multi-page list.
ob_start();
Penalis_Admin::render_pagination( 381, 20, 20, 'https://example.com/admin.php?page=penalis-review' );
$p20_html = ob_get_clean();

penalis_assert( false !== strpos( $p20_html, '<span class="current-page">20</span>' ), 'Current page indicator renders page 20.' );
penalis_assert( false === strpos( $p20_html, 'class="next-page button"' ), 'Last page does not have active next link.' );
penalis_assert( false === strpos( $p20_html, 'class="last-page button"' ), 'Last page does not have active last link.' );

// 4. Single page list (items <= per_page).
ob_start();
Penalis_Admin::render_pagination( 15, 20, 1, 'https://example.com/admin.php?page=penalis-writers' );
$single_html = ob_get_clean();

penalis_assert( false !== strpos( $single_html, 'tablenav-pages one-page' ), 'Single page list uses one-page class.' );
penalis_assert( false !== strpos( $single_html, '15 item' ), 'Single page list displays item count.' );
penalis_assert( false === strpos( $single_html, 'pagination-links' ), 'Single page list does not render navigation buttons.' );

// 5. Empty list.
ob_start();
Penalis_Admin::render_pagination( 0, 20, 1, 'https://example.com/admin.php?page=penalis-writers' );
$empty_html = ob_get_clean();

penalis_same( '', trim( $empty_html ), 'Empty list renders no pagination markup.' );

/* -------------------------------------------------------------------------
 * Registration duplicate and collision handling
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_options']['penalis_registration_enabled'] = '1';
$GLOBALS['penalis_test_users'][200]                             = new WP_User( 200, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][200]->user_login                 = 'penulis_aktif';
$GLOBALS['penalis_test_users'][200]->user_email                 = 'aktif@example.com';
$GLOBALS['penalis_test_users'][200]->display_name               = 'Penulis Aktif';
Penalis_Account_Status::transition( 200, Penalis_Policy::STATUS_ACTIVE, 'test', 0 );

$GLOBALS['penalis_test_users'][201]               = new WP_User( 201, array( 'contributor' ) );
$GLOBALS['penalis_test_users'][201]->user_login   = 'penulis_legacy';
$GLOBALS['penalis_test_users'][201]->user_email   = 'legacy@example.com';
$GLOBALS['penalis_test_users'][201]->display_name = 'Penulis Legacy';

// 1. Taken username is rejected directly with field error.
$reg_username_conflict = Penalis_Registration::register(
	array(
		'display_name'    => 'Calon Penulis',
		'user_login'      => 'penulis_aktif',
		'user_email'      => 'calon_baru@example.com',
		'password'        => 'P@ssw0rdKuat1234!',
		'penalis_consent' => '1',
		'penalis_website' => '',
	)
);
penalis_assert( is_wp_error( $reg_username_conflict ), 'Registration with existing username returns WP_Error.' );
penalis_same( 'penalis_registration_invalid', $reg_username_conflict->get_error_code(), 'Registration with existing username returns invalid error code.' );
penalis_same( 'Username tersebut sudah digunakan. Silakan pilih username lain.', $reg_username_conflict->get_error_data()['fields']['user_login'], 'Field error explains username is taken.' );

// 2. Duplicate email for active account returns generic challenge and sends duplicate_notice.
$GLOBALS['penalis_test_mail'] = array();
$reg_email_active             = Penalis_Registration::register(
	array(
		'display_name'    => 'Calon Penulis',
		'user_login'      => 'username_baru_1',
		'user_email'      => 'aktif@example.com',
		'password'        => 'P@ssw0rdKuat1234!',
		'penalis_consent' => '1',
		'penalis_website' => '',
	)
);
penalis_assert( ! is_wp_error( $reg_email_active ), 'Duplicate active email returns generic success challenge.' );
penalis_assert( isset( $reg_email_active['challenge_id'] ), 'Generic challenge ID is returned.' );
penalis_same( 1, count( $GLOBALS['penalis_test_mail'] ), 'One email is dispatched.' );
penalis_same( 'aktif@example.com', $GLOBALS['penalis_test_mail'][0][0], 'Email is sent to existing active account owner.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][1], 'Percobaan Pendaftaran' ), 'Email subject matches duplicate_notice.' );

// 3. Duplicate email for legacy account returns generic challenge and sends legacy_register_notice.
$GLOBALS['penalis_test_mail'] = array();
$reg_email_legacy             = Penalis_Registration::register(
	array(
		'display_name'    => 'Calon Penulis',
		'user_login'      => 'username_baru_2',
		'user_email'      => 'legacy@example.com',
		'password'        => 'P@ssw0rdKuat1234!',
		'penalis_consent' => '1',
		'penalis_website' => '',
	)
);
penalis_assert( ! is_wp_error( $reg_email_legacy ), 'Duplicate legacy email returns generic success challenge.' );
penalis_assert( isset( $reg_email_legacy['challenge_id'] ), 'Generic challenge ID is returned.' );
penalis_same( 1, count( $GLOBALS['penalis_test_mail'] ), 'One email is dispatched.' );
penalis_same( 'legacy@example.com', $GLOBALS['penalis_test_mail'][0][0], 'Email is sent to legacy contributor.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][1], 'Akun Kontributor Kamu Sudah Terdaftar' ), 'Email subject matches legacy_register_notice.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'penalis_activate=1' ), 'Email body includes direct link to activate.' );

/* -------------------------------------------------------------------------
 * Timezone-aware date formatting
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_options']['timezone_string'] = 'Asia/Jakarta';
$GLOBALS['penalis_test_options']['date_format']     = 'j M Y';
$GLOBALS['penalis_test_options']['time_format']     = 'H:i';

// 2025-11-12T20:30:00Z is 2025-11-13 03:30 in Jakarta.
$jakarta_timestamp = 1762979400;

penalis_same(
	'2025-11-13 03:30',
	Penalis_Datetime::format( $jakarta_timestamp, 'Y-m-d H:i' ),
	'Unix timestamps are rendered in the site timezone, not the server timezone.'
);
penalis_same(
	'2025-11-13 03:30',
	Penalis_Datetime::format_gmt( '2025-11-12 20:30:00', 'Y-m-d H:i' ),
	'GMT MySQL strings are parsed as UTC and rendered in the site timezone.'
);
penalis_same(
	'2025-11-12 20:30',
	Penalis_Datetime::format_local( '2025-11-12 20:30:00', 'Y-m-d H:i' ),
	'Local post_modified values are rendered without a timezone shift.'
);
penalis_same(
	'2026-01-01 03:00',
	Penalis_Datetime::format_gmt( '2025-12-31 20:00:00', 'Y-m-d H:i' ),
	'UTC to Jakarta conversion rolls over into the next calendar year.'
);
penalis_same(
	'2025-12-31 20:00',
	Penalis_Datetime::format_local( '2025-12-31 20:00:00', 'Y-m-d H:i' ),
	'Local fallback keeps the stored calendar day across a year boundary.'
);
penalis_same(
	'2025-11-13 03:30',
	Penalis_Datetime::format_gmt( '2025-11-12T20:30:00', 'Y-m-d H:i' ),
	'ISO-style GMT strings without an offset are parsed as UTC.'
);
penalis_same(
	$jakarta_timestamp,
	Penalis_Datetime::parse_gmt( '2025-11-12T20:30:00Z' ),
	'Explicit Z designators are parsed as UTC.'
);
penalis_same(
	1762954200,
	Penalis_Datetime::parse_gmt( '2025-11-12T20:30:00+07:00' ),
	'Explicit numeric offsets are preserved instead of being treated as UTC.'
);
penalis_same( 0, Penalis_Datetime::parse_gmt( '2025-02-30 10:00:00' ), 'Impossible calendar dates are rejected.' );
penalis_same( 0, Penalis_Datetime::parse_gmt( '' ), 'Empty GMT strings are rejected.' );
penalis_same( '', Penalis_Datetime::format( 0 ), 'Zero timestamps render as an empty string.' );
penalis_same( '', Penalis_Datetime::format_local( 'not-a-date' ), 'Unparseable local values render as an empty string.' );

$jakarta_payload = Penalis_Datetime::site_timezone_payload( $jakarta_timestamp );
penalis_same( 'Asia/Jakarta', $jakarta_payload['string'], 'Timezone payload exposes the IANA site timezone.' );
penalis_same( 25200, $jakarta_payload['offsetSeconds'], 'Timezone payload exposes the Jakarta offset in seconds.' );
penalis_same( 'WIB', $jakarta_payload['label'], 'Timezone payload exposes the short Jakarta label.' );

// The wp-admin call shapes: site date/time formats applied to GMT columns.
penalis_same( '13 Nov 2025', Penalis_Datetime::format_gmt( '2025-11-12 20:30:00', Penalis_Datetime::date_format() ), 'Admin date column uses the site date format in the site timezone.' );
penalis_same( '03:30', Penalis_Datetime::format_gmt( '2025-11-12 20:30:00', Penalis_Datetime::time_format() ), 'Admin time column uses the site time format in the site timezone.' );
penalis_same( '13 Nov 2025 03:30', Penalis_Datetime::format_gmt( '2025-11-12 20:30:00' ), 'Default format combines the site date and time formats.' );

// Missing options fall back to the documented defaults.
unset( $GLOBALS['penalis_test_options']['date_format'], $GLOBALS['penalis_test_options']['time_format'] );
penalis_same( 'j M Y', Penalis_Datetime::date_format(), 'Missing date_format falls back to the default.' );
penalis_same( 'H:i', Penalis_Datetime::time_format(), 'Missing time_format falls back to the default.' );
$GLOBALS['penalis_test_options']['date_format'] = 'j M Y';
$GLOBALS['penalis_test_options']['time_format'] = 'H:i';

// Fixed-offset sites (no IANA timezone) fall back to gmt_offset.
$GLOBALS['penalis_test_options']['timezone_string'] = '';
$GLOBALS['penalis_test_options']['gmt_offset']      = 7;
penalis_same(
	'2025-11-13 03:30',
	Penalis_Datetime::format_gmt( '2025-11-12 20:30:00', 'Y-m-d H:i' ),
	'Fixed gmt_offset sites convert UTC values correctly.'
);
$offset_payload = Penalis_Datetime::site_timezone_payload( $jakarta_timestamp );
penalis_same( '+07:00', $offset_payload['string'], 'Fixed-offset sites expose a numeric timezone string.' );
penalis_same( 'UTC+07:00', $offset_payload['label'], 'Fixed-offset sites expose a UTC-prefixed label.' );

// Half-hour offsets must not be truncated to whole hours.
$GLOBALS['penalis_test_options']['gmt_offset'] = 5.5;
penalis_same(
	'2025-11-13 02:00',
	Penalis_Datetime::format_gmt( '2025-11-12 20:30:00', 'Y-m-d H:i' ),
	'Half-hour gmt_offset sites keep their minutes.'
);

// Guard: wp-admin screens must not fall back to server-timezone formatting.
// Comments are stripped first so documentation that names the legacy
// functions does not trip the guard.
$datetime_offenders = array();
foreach ( glob( __DIR__ . '/../includes/*.php' ) as $datetime_file ) {
	$datetime_code = '';
	foreach ( token_get_all( (string) file_get_contents( $datetime_file ) ) as $datetime_token ) {
		if ( is_array( $datetime_token ) && in_array( $datetime_token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$datetime_code .= is_array( $datetime_token ) ? $datetime_token[1] : $datetime_token;
	}

	if ( 1 === preg_match( '/\b(?:date_i18n|mysql2date)\s*\(/', $datetime_code ) ) {
		$datetime_offenders[] = basename( $datetime_file );
	}
}
penalis_same(
	array(),
	$datetime_offenders,
	'No include file calls date_i18n() or mysql2date(); use Penalis_Datetime instead.'
);

/* -------------------------------------------------------------------------
 * Public authors directory policy
 * ---------------------------------------------------------------------- */

penalis_same(
	array( 'penalis_writer', 'contributor' ),
	Penalis_Policy::public_author_roles(),
	'Public author directory strictly includes penalis_writer and contributor roles.'
);

penalis_assert(
	Penalis_Policy::is_public_author_eligible( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE, 1 ),
	'Active penalis_writer with >= 1 published post is eligible.'
);
penalis_assert(
	Penalis_Policy::is_public_author_eligible( array( 'contributor' ), '', 3 ),
	'Legacy contributor with >= 1 published post is eligible.'
);
penalis_assert(
	! Penalis_Policy::is_public_author_eligible( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE, 0 ),
	'Writer with 0 published posts is excluded.'
);
penalis_assert(
	! Penalis_Policy::is_public_author_eligible( array( 'penalis_writer' ), Penalis_Policy::STATUS_SUSPENDED, 25 ),
	'Suspended writer is excluded even with many published posts.'
);
penalis_assert(
	! Penalis_Policy::is_public_author_eligible( array( 'contributor' ), Penalis_Policy::STATUS_SUSPENDED, 5 ),
	'Suspended legacy contributor is excluded.'
);
penalis_assert(
	! Penalis_Policy::is_public_author_eligible( array( 'administrator' ), Penalis_Policy::STATUS_ACTIVE, 50 ),
	'Administrator role is excluded from the public authors directory.'
);
penalis_assert(
	! Penalis_Policy::is_public_author_eligible( array( 'editor' ), Penalis_Policy::STATUS_ACTIVE, 50 ),
	'Editor role is excluded from the public authors directory.'
);

penalis_same( 100, Penalis_Policy::AUTHORS_PER_PAGE, 'Default authors per page is 100.' );
penalis_assert(
	Penalis_Policy::is_verified_author( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE, true ),
	'Active penalis_writer with access enabled qualifies for verified badge.'
);
penalis_assert(
	! Penalis_Policy::is_verified_author( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE, false ),
	'Writer without access enabled is not verified.'
);
penalis_assert(
	! Penalis_Policy::is_verified_author( array( 'penalis_writer' ), Penalis_Policy::STATUS_PENDING_VERIFICATION, true ),
	'Pending verification writer is not verified.'
);
penalis_assert(
	! Penalis_Policy::is_verified_author( array( 'contributor' ), Penalis_Policy::STATUS_ACTIVE, true ),
	'Legacy contributor is not verified until activated as penalis_writer.'
);
penalis_assert(
	! Penalis_Policy::is_verified_author( array( 'contributor' ), '', false ),
	'Unactivated legacy contributor is not verified.'
);

penalis_assert(
	Penalis_Policy::is_senator_author( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE, true ),
	'Active penalis_writer marked as Senator qualifies for the Senator badge.'
);
penalis_assert(
	Penalis_Policy::is_senator_author( array( 'contributor' ), '', true ),
	'Legacy contributor marked as Senator qualifies for the Senator badge.'
);
penalis_assert(
	! Penalis_Policy::is_senator_author( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE, false ),
	'Writer without Senator flag does not qualify for the Senator badge.'
);
penalis_assert(
	! Penalis_Policy::is_senator_author( array( 'penalis_writer' ), Penalis_Policy::STATUS_SUSPENDED, true ),
	'Suspended writer does not expose a public Senator badge.'
);
penalis_assert(
	! Penalis_Policy::is_senator_author( array( 'administrator' ), '', true ),
	'Administrator role is excluded from the public Senator author badge.'
);
penalis_assert(
	! Penalis_Policy::is_senator_author( array( 'editor' ), '', true ),
	'Editor role is excluded from the public Senator author badge.'
);

penalis_assert(
	Penalis_Policy::can_mark_senator( array( 'penalis_writer' ), Penalis_Policy::STATUS_ACTIVE ),
	'Active penalis_writer can be marked as Senator.'
);
penalis_assert(
	Penalis_Policy::can_mark_senator( array( 'contributor' ), '' ),
	'Legacy contributor can be marked as Senator.'
);
penalis_assert(
	! Penalis_Policy::can_mark_senator( array( 'penalis_writer' ), Penalis_Policy::STATUS_PENDING_VERIFICATION ),
	'Pending verification account cannot be marked as Senator.'
);
penalis_assert(
	! Penalis_Policy::can_mark_senator( array( 'penalis_writer' ), Penalis_Policy::STATUS_SUSPENDED ),
	'Suspended account cannot be marked as Senator.'
);
penalis_assert(
	! Penalis_Policy::can_mark_senator( array( 'administrator' ), '' ),
	'Administrator account cannot be marked as Senator.'
);

penalis_reset();
penalis_editorial_writer( 10 );
$GLOBALS['penalis_test_users'][11] = new WP_User( 11, array( 'contributor' ) );
$GLOBALS['penalis_test_users'][12] = new WP_User( 12, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_users'][12]->meta[ Penalis_Account_Status::META_STATUS ] = array( Penalis_Policy::STATUS_SUSPENDED );

penalis_assert( ! Penalis_Account_Status::is_senator( 10 ), 'Writer starts unmarked as Senator.' );
Penalis_Account_Status::set_senator( 10, true );
Penalis_Account_Status::set_senator( 11, true );
Penalis_Account_Status::set_senator( 12, true );
penalis_assert( Penalis_Account_Status::is_senator( 10 ), 'set_senator(true) persists the Senator meta flag.' );
penalis_assert( Penalis_Authors::is_user_senator( 10 ), 'Active writer with Senator flag resolves as public Senator.' );
penalis_assert( Penalis_Authors::is_user_senator( 11 ), 'Legacy contributor with Senator flag resolves as public Senator.' );
penalis_assert( ! Penalis_Authors::is_user_senator( 12 ), 'Suspended writer does not resolve as public Senator.' );

$senator_badge = Penalis_Authors::senator_badge_html();
penalis_assert( false !== strpos( $senator_badge, 'penalis-senator-badge-inline' ), 'Senator badge HTML includes the inline class.' );
penalis_assert( false !== strpos( $senator_badge, 'Senator Penalis' ), 'Senator badge HTML includes accessible title.' );

Penalis_Account_Status::set_senator( 10, false );
penalis_assert( ! Penalis_Account_Status::is_senator( 10 ), 'set_senator(false) clears the Senator meta flag.' );
penalis_assert( ! Penalis_Authors::is_user_senator( 10 ), 'Unmarked writer no longer resolves as public Senator.' );

set_transient(
	Penalis_Authors::CACHE_KEY,
	array(
		array(
			'id'          => 10,
			'displayName' => 'Penulis Biasa',
			'username'    => 'penulis-biasa',
			'avatarUrl'   => '',
			'authorUrl'   => 'https://example.com/author/penulis-biasa/',
			'postCount'   => 3,
			'views'       => 100,
			'isVerified'  => true,
			'isSenator'   => false,
		),
		array(
			'id'          => 11,
			'displayName' => 'Senator Lama',
			'username'    => 'senator-lama',
			'avatarUrl'   => '',
			'authorUrl'   => 'https://example.com/author/senator-lama/',
			'postCount'   => 5,
			'views'       => 250,
			'isVerified'  => false,
			'isSenator'   => true,
		),
		array(
			'id'          => 13,
			'displayName' => 'Senator Aktif',
			'username'    => 'senator-aktif',
			'avatarUrl'   => '',
			'authorUrl'   => 'https://example.com/author/senator-aktif/',
			'postCount'   => 8,
			'views'       => 400,
			'isVerified'  => true,
			'isSenator'   => true,
		),
	),
	600
);

$authors_all = Penalis_Authors::query_authors();
penalis_same( 3, $authors_all['totalAll'], 'Directory reports totalAll across cached authors.' );
penalis_same( 2, $authors_all['totalVerified'], 'Directory reports totalVerified count.' );
penalis_same( 2, $authors_all['totalSenator'], 'Directory reports totalSenator count including legacy contributors.' );

$authors_senator = Penalis_Authors::query_authors( array( 'senator' => true ) );
penalis_same( 2, $authors_senator['total'], 'Senator filter returns only authors marked as Senator.' );
penalis_same( array( 13, 11 ), array_column( $authors_senator['items'], 'id' ), 'Senator filter keeps default sort order.' );

Penalis_Authors::maybe_invalidate_cache_on_meta( 1, 10, Penalis_Account_Status::META_SENATOR );
penalis_same( false, get_transient( Penalis_Authors::CACHE_KEY ), 'Changing Senator meta invalidates the cached authors directory.' );

penalis_same( 'Desember 2025', Penalis_Datetime::format_indonesian_month_year_gmt( '2025-12-15 10:00:00' ), 'Indonesian month-year helper formats December independently of site locale.' );
penalis_same( 'Agustus 2026', Penalis_Datetime::format_indonesian_month_year_gmt( '2026-08-01 00:00:00' ), 'Indonesian month-year helper formats August in Indonesian.' );
penalis_same( '', Penalis_Datetime::format_indonesian_month_year_gmt( '' ), 'Indonesian month-year helper returns empty string for empty input.' );

penalis_assert( Penalis_Author_Archive::is_enabled(), 'Bundled author archive template is enabled by default.' );
penalis_same( 'penalis.com/author/erland', Penalis_Author_Archive::format_profile_url_label( 'https://penalis.com/author/erland/' ), 'Profile URL label strips protocol and trailing slash.' );
penalis_same( 'localhost:2121/author/erland', Penalis_Author_Archive::format_profile_url_label( 'http://localhost:2121/author/erland/' ), 'Profile URL label preserves non-standard port.' );
penalis_same( '45.335', Penalis_Author_Archive::format_number( 45335 ), 'Author archive counters format with Indonesian thousand separators.' );
penalis_same( '0', Penalis_Author_Archive::format_number( -3 ), 'Negative counters clamp to zero.' );
penalis_assert( is_readable( PENALIS_PLATFORM_DIR . 'templates/author-archive.php' ), 'Author archive template file exists.' );
penalis_assert( is_readable( PENALIS_PLATFORM_DIR . 'assets/css/author-archive.css' ), 'Author archive stylesheet exists.' );
penalis_assert( is_readable( PENALIS_PLATFORM_DIR . 'assets/js/author-archive.js' ), 'Author archive script exists.' );

penalis_same( 'most_posts', Penalis_Policy::normalize_authors_sort( 'invalid' ), 'Invalid sort falls back to most_posts.' );
penalis_same( 'name_asc', Penalis_Policy::normalize_authors_sort( 'name_asc' ), 'Valid sort mode is preserved.' );
penalis_same( 'most_views', Penalis_Policy::normalize_authors_sort( 'most_views' ), 'most_views sort mode is preserved.' );
penalis_same( 'least_views', Penalis_Policy::normalize_authors_sort( 'least_views' ), 'least_views sort mode is preserved.' );
penalis_same( 'random', Penalis_Policy::normalize_authors_sort( 'random' ), 'random sort mode is preserved.' );

$unsorted_authors = array(
	array( 'id' => 1, 'displayName' => 'Citra', 'postCount' => 5, 'views' => 500 ),
	array( 'id' => 2, 'displayName' => 'Adi', 'postCount' => 20, 'views' => 100 ),
	array( 'id' => 3, 'displayName' => 'Budi', 'postCount' => 20, 'views' => 1200 ),
);
$sorted_most = Penalis_Policy::sort_public_authors( $unsorted_authors, 'most_posts' );
penalis_same( array( 'Adi', 'Budi', 'Citra' ), array_column( $sorted_most, 'displayName' ), 'most_posts sorts descending by postCount then ascending by displayName.' );

$sorted_least = Penalis_Policy::sort_public_authors( $unsorted_authors, 'least_posts' );
penalis_same( array( 'Citra', 'Adi', 'Budi' ), array_column( $sorted_least, 'displayName' ), 'least_posts sorts ascending by postCount then ascending by displayName.' );

$sorted_most_views = Penalis_Policy::sort_public_authors( $unsorted_authors, 'most_views' );
penalis_same( array( 'Budi', 'Citra', 'Adi' ), array_column( $sorted_most_views, 'displayName' ), 'most_views sorts descending by views then ascending by displayName.' );

$sorted_least_views = Penalis_Policy::sort_public_authors( $unsorted_authors, 'least_views' );
penalis_same( array( 'Adi', 'Citra', 'Budi' ), array_column( $sorted_least_views, 'displayName' ), 'least_views sorts ascending by views then ascending by displayName.' );

$sorted_az = Penalis_Policy::sort_public_authors( $unsorted_authors, 'name_asc' );
penalis_same( array( 'Adi', 'Budi', 'Citra' ), array_column( $sorted_az, 'displayName' ), 'name_asc sorts alphabetically A-Z.' );

$sorted_za = Penalis_Policy::sort_public_authors( $unsorted_authors, 'name_desc' );
penalis_same( array( 'Citra', 'Budi', 'Adi' ), array_column( $sorted_za, 'displayName' ), 'name_desc sorts alphabetically Z-A.' );

$sorted_random = Penalis_Policy::sort_public_authors( $unsorted_authors, 'random' );
penalis_same( 3, count( $sorted_random ), 'random sort preserves all author records.' );
$random_ids = array_column( $sorted_random, 'id' );
sort( $random_ids );
penalis_same( array( 1, 2, 3 ), $random_ids, 'random sort preserves the exact set of author IDs.' );

/* -------------------------------------------------------------------------
 * Post Views Counter integration in REST API
 * ---------------------------------------------------------------------- */

penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_writer( 11 );

// Writer 10 has two published posts, one draft, and one pending submission.
penalis_editorial_post( 701, 10, 'publish' );
$GLOBALS['penalis_test_posts'][701]->post_title = 'Puisi Terbit Pertama';
penalis_editorial_post( 702, 10, 'publish' );
$GLOBALS['penalis_test_posts'][702]->post_title = 'Esai Terbit Kedua';
penalis_editorial_post( 703, 10, 'draft' );
$GLOBALS['penalis_test_posts'][703]->post_title = 'Draft Belum Terbit';
penalis_editorial_post( 704, 10, 'pending' );
$GLOBALS['penalis_test_posts'][704]->post_title = 'Naskah Menunggu Review';

// Writer 11 has one published post with views that must not bleed into writer 10's totals.
penalis_editorial_post( 705, 11, 'publish' );

// Populate Post Views Counter totals, including stray counts on unpublished posts.
$GLOBALS['penalis_test_post_views'] = array(
	701 => 1250,
	702 => 340,
	703 => 99,
	704 => 45,
	705 => 5000,
);

$GLOBALS['penalis_test_current_user'] = 10;

// 1. Single published article exposes its view count.
$article_701_resp = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/701', array( 'id' => 701 ) ) );
penalis_assert( $article_701_resp instanceof WP_REST_Response, 'Published article 701 can be fetched.' );
penalis_same( 1250, $article_701_resp->get_data()['views'], 'Single published article returns its Post Views Counter total.' );

// 2. Unpublished articles always report 0 views even if stray view rows exist.
$article_703_resp = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/703', array( 'id' => 703 ) ) );
penalis_same( 0, $article_703_resp->get_data()['views'], 'Draft article always reports 0 views.' );

$article_704_resp = Penalis_Rest_Api::get_article( new WP_REST_Request( 'GET', '/penalis/v1/articles/704', array( 'id' => 704 ) ) );
penalis_same( 0, $article_704_resp->get_data()['views'], 'Pending review article always reports 0 views.' );

// 3. Article list summaries include per-article views and author-scoped total published views.
$list_resp = Penalis_Rest_Api::list_articles(
	new WP_REST_Request(
		'GET',
		'/penalis/v1/articles',
		array(
			'page'   => 1,
			'status' => 'all',
		)
	)
);
penalis_assert( $list_resp instanceof WP_REST_Response, 'GET /articles returns a response.' );
$list_data     = $list_resp->get_data();
$views_by_post = array();
foreach ( $list_data['items'] as $summary_item ) {
	$views_by_post[ $summary_item['id'] ] = $summary_item['views'];
}
penalis_same( 1250, $views_by_post[701], 'List summary for published post 701 reports 1250 views.' );
penalis_same( 340, $views_by_post[702], 'List summary for published post 702 reports 340 views.' );
penalis_same( 0, $views_by_post[703], 'List summary for draft post 703 reports 0 views.' );
penalis_same( 0, $views_by_post[704], 'List summary for pending post 704 reports 0 views.' );
penalis_same( 1590, $list_data['counts']['views'], 'counts.views sums only published articles owned by the current writer (1250 + 340).' );

// 4. Account payload also includes counts.views for the Overview summary card.
$account_views_resp = Penalis_Rest_Api::get_account();
penalis_same( 1590, $account_views_resp->get_data()['counts']['views'], 'GET /account includes counts.views across published articles.' );

/* -------------------------------------------------------------------------
 * Turnstile adapter (graceful degradation when plugin is inactive)
 * ---------------------------------------------------------------------- */

penalis_same( false, Penalis_Turnstile::is_available(), 'Turnstile is unavailable when Simple Cloudflare Turnstile is not loaded.' );
penalis_same( false, Penalis_Turnstile::is_enabled_for( 'login' ), 'Turnstile login check is disabled when plugin is inactive.' );
penalis_same( false, Penalis_Turnstile::is_enabled_for( 'unknown' ), 'Unknown Turnstile context is always disabled.' );
penalis_same( array( 'enabled' => false ), Penalis_Turnstile::frontend_config(), 'Frontend config disables Turnstile when plugin is inactive.' );
penalis_same( true, Penalis_Turnstile::verify( '', 'register' ), 'Turnstile verification passes through when plugin is inactive.' );

/* -------------------------------------------------------------------------
 * Settings screen: dashboard CAPTCHA switch
 * ---------------------------------------------------------------------- */

penalis_reset();

penalis_same( 'penalis-settings', Penalis_Settings::PAGE_SLUG, 'The settings screen uses the penalis-settings slug.' );
penalis_assert( isset( Penalis_Settings::tabs()['security'] ), 'The settings screen exposes a Keamanan & Batas tab.' );
penalis_same( 'general', (string) array_key_first( Penalis_Settings::tabs() ), 'The Umum & Halaman tab is the first and default tab.' );
penalis_same( true, Penalis_Settings::is_dashboard_captcha_enabled(), 'Dashboard CAPTCHA is enabled when the option has never been saved.' );
penalis_same( '1', Penalis_Settings::sanitize_state( '1' ), 'An explicit "1" keeps the switch enabled.' );
penalis_same( '0', Penalis_Settings::sanitize_state( '0' ), 'An explicit "0" disables the switch.' );
penalis_same( '1', Penalis_Settings::sanitize_state( 'garbage' ), 'A malformed value falls back to enabled.' );
penalis_same( '1', Penalis_Settings::sanitize_state( null ), 'A missing value falls back to enabled.' );

$GLOBALS['penalis_test_options'][ Penalis_Settings::OPTION_CAPTCHA_ENABLED ] = '0';
penalis_same( false, Penalis_Settings::is_dashboard_captcha_enabled(), 'The switch reports disabled when the stored option is "0".' );

$GLOBALS['penalis_test_options'][ Penalis_Settings::OPTION_CAPTCHA_ENABLED ] = '1';
penalis_same( true, Penalis_Settings::is_dashboard_captcha_enabled(), 'The switch reports enabled when the stored option is "1".' );

penalis_same( array(), Penalis_Settings::audit(), 'The audit trail is empty until the switch is changed.' );

/* -------------------------------------------------------------------------
 * Settings screen: tab registry and shared feedback
 * ---------------------------------------------------------------------- */

$penalis_settings_tabs = Penalis_Settings::tabs();

penalis_same(
	array( 'general', 'registration', 'editorial', 'article-display', 'email', 'security', 'data' ),
	array_keys( $penalis_settings_tabs ),
	'The settings screen exposes the 7 grouped tabs in order.'
);

foreach ( $penalis_settings_tabs as $penalis_tab_slug => $penalis_tab_meta ) {
	penalis_assert( '' !== (string) $penalis_tab_meta['label'], 'Tab "' . $penalis_tab_slug . '" has a menu label.' );
	penalis_assert( in_array( $penalis_tab_meta['group'], array( 'main', 'sensitive' ), true ), 'Tab "' . $penalis_tab_slug . '" declares a valid sidebar group.' );
	penalis_assert( is_callable( $penalis_tab_meta['render'] ), 'Tab "' . $penalis_tab_slug . '" points at a callable renderer.' );
}

penalis_same( 'sensitive', $penalis_settings_tabs['security']['group'], 'Security tab lives in the sensitive sidebar group.' );
penalis_same( 'sensitive', $penalis_settings_tabs['data']['group'], 'Data tab lives in the sensitive sidebar group.' );
penalis_same( 'security', Penalis_Settings::resolve_tab( 'captcha' ), 'Legacy captcha tab alias resolves to security.' );
penalis_same( 'security', Penalis_Settings::resolve_tab( 'rate-limit' ), 'Legacy rate-limit tab alias resolves to security.' );
penalis_same( 'registration', Penalis_Settings::resolve_tab( 'avatar' ), 'Legacy avatar tab alias resolves to registration.' );
penalis_same( 'article-display', Penalis_Settings::resolve_tab( 'thumbnail' ), 'Legacy thumbnail tab alias resolves to Tampilan Artikel.' );
penalis_same( 'article-display', Penalis_Settings::resolve_tab( 'views' ), 'Legacy views tab alias resolves to article-display.' );
penalis_same( 'article-display', Penalis_Settings::resolve_tab( 'toc' ), 'Legacy toc tab alias resolves to article-display.' );
penalis_same( 'general', Penalis_Settings::resolve_tab( 'unknown-tab' ), 'Unknown tab slug falls back to the first tab.' );

penalis_same(
	Penalis_Settings::url( array( 'tab' => 'email' ) ),
	Penalis_Email_Settings::tab_url(),
	'The email templates link back to the Email tab on the settings screen.'
);

penalis_same(
	Penalis_Settings::url( array( 'tab' => 'email', 'penalis_message' => 'saved' ) ),
	Penalis_Email_Settings::tab_url( array( 'penalis_message' => 'saved' ) ),
	'The email tab URL accepts extra query arguments.'
);

penalis_same( 'penalis-email-settings', Penalis_Email_Settings::PAGE_SLUG, 'The retired email settings slug is kept so old links can be redirected.' );

penalis_same( 'Pengaturan berhasil disimpan.', Penalis_Settings::message_for( 'saved' ), 'The shell reports a successful save.' );
penalis_same( 'Template email telah dikembalikan ke teks bawaan.', Penalis_Settings::message_for( 'reset_single' ), 'The shell reports a single template reset.' );
penalis_same( 'Semua template email telah dikembalikan ke teks bawaan.', Penalis_Settings::message_for( 'reset_all' ), 'The shell reports a full template reset.' );
penalis_same( 'Foto profil bawaan telah diperbarui.', Penalis_Settings::message_for( 'avatar_saved' ), 'The shell reports a saved default avatar.' );
penalis_same( 'Thumbnail bawaan karya tulis telah diperbarui.', Penalis_Settings::message_for( 'thumbnail_saved' ), 'The shell reports a saved default featured image.' );
penalis_same( 'Thumbnail bawaan karya tulis telah dihapus.', Penalis_Settings::message_for( 'thumbnail_cleared' ), 'The shell reports a cleared default featured image.' );
penalis_same( 'Pengaturan penghitung views berhasil disimpan.', Penalis_Settings::message_for( 'views_saved' ), 'The shell reports saved views settings.' );
penalis_same( 'Data total views dari Post Views Counter telah disinkronkan ke Penalis Platform.', Penalis_Settings::message_for( 'views_migrated' ), 'The shell reports synced PVC views.' );
penalis_same( 'Permintaan telah diselesaikan.', Penalis_Settings::message_for( 'unknown_key' ), 'An unknown message key falls back to a neutral notice.' );
penalis_same( 'Pilih gambar yang valid untuk foto profil bawaan.', Penalis_Settings::error_for( 'avatar_invalid' ), 'The shell explains an invalid avatar selection.' );
penalis_same( 'Pilih gambar yang valid untuk thumbnail bawaan karya tulis.', Penalis_Settings::error_for( 'thumbnail_invalid' ), 'The shell explains an invalid default featured image selection.' );
penalis_same(
	'Terjadi kesalahan saat memproses pengaturan. Silakan coba lagi.',
	Penalis_Settings::error_for( 'unknown_key' ),
	'An unknown error key falls back to a generic error notice.'
);

/* -------------------------------------------------------------------------
 * Turnstile adapter with the plugin configured (site-wide vs dashboard)
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'cfturnstile_check' ) ) {
	/**
	 * Minimal Simple Cloudflare Turnstile verification double.
	 *
	 * @param string $token  Submitted token.
	 * @param string $action Turnstile action name.
	 * @return array<string,mixed>
	 */
	function cfturnstile_check( $token = '', $action = '' ) {
		return array( 'success' => 'token-ok' === (string) $token );
	}
}

penalis_reset();
$GLOBALS['penalis_test_options']['cfturnstile_key']      = 'site-key';
$GLOBALS['penalis_test_options']['cfturnstile_secret']   = 'secret-key';
$GLOBALS['penalis_test_options']['cfturnstile_tested']   = 'yes';
$GLOBALS['penalis_test_options']['cfturnstile_login']    = '1';
$GLOBALS['penalis_test_options']['cfturnstile_register'] = '1';
$GLOBALS['penalis_test_options']['cfturnstile_reset']    = '1';

penalis_same( true, Penalis_Turnstile::is_available(), 'Turnstile is available once the plugin is installed and configured.' );
penalis_same( true, Penalis_Turnstile::is_dashboard_enabled(), 'The dashboard switch defaults to enabled.' );
penalis_same( true, Penalis_Turnstile::is_enabled_for( 'login' ), 'Login CAPTCHA follows the site-wide Turnstile toggle by default.' );
penalis_same( true, Penalis_Turnstile::has_any_enabled_form(), 'At least one dashboard form uses Turnstile by default.' );
penalis_same( true, Penalis_Turnstile::verify( 'token-ok', 'login' ), 'A valid token passes verification.' );
penalis_assert( is_wp_error( Penalis_Turnstile::verify( '', 'login' ) ), 'A missing token is rejected while the CAPTCHA is active.' );

$enabled_config = Penalis_Turnstile::frontend_config();
penalis_same( true, $enabled_config['enabled'], 'The SPA bootstrap reports CAPTCHA as enabled.' );
penalis_same( 'site-key', $enabled_config['siteKey'], 'The SPA bootstrap carries the Turnstile site key.' );
penalis_same( true, $enabled_config['forms']['login'], 'The SPA bootstrap marks the login form as protected.' );

$GLOBALS['penalis_test_options'][ Penalis_Settings::OPTION_CAPTCHA_ENABLED ] = '0';

foreach ( array( 'login', 'register', 'activate', 'forgot', 'reset' ) as $penalis_turnstile_context ) {
	penalis_same(
		false,
		Penalis_Turnstile::is_enabled_for( $penalis_turnstile_context ),
		'The dashboard switch disables CAPTCHA for the "' . $penalis_turnstile_context . '" form.'
	);
}

penalis_same( false, Penalis_Turnstile::has_any_enabled_form(), 'No dashboard form uses Turnstile while the switch is off.' );
penalis_same( array( 'enabled' => false ), Penalis_Turnstile::frontend_config(), 'The SPA receives an explicit "disabled" bootstrap while the switch is off.' );
penalis_same( true, Penalis_Turnstile::verify( '', 'register' ), 'Verification passes through while the switch is off.' );
penalis_same( true, Penalis_Turnstile::is_available(), 'The Turnstile plugin itself stays available; only the dashboard is silenced.' );

penalis_same( '1', (string) $GLOBALS['penalis_test_options']['cfturnstile_login'], 'Disabling the dashboard CAPTCHA never rewrites the site-wide login toggle.' );
penalis_same( '1', (string) $GLOBALS['penalis_test_options']['cfturnstile_register'], 'Disabling the dashboard CAPTCHA never rewrites the site-wide registration toggle.' );
penalis_same( '1', (string) $GLOBALS['penalis_test_options']['cfturnstile_reset'], 'Disabling the dashboard CAPTCHA never rewrites the site-wide reset toggle.' );

$GLOBALS['penalis_test_options'][ Penalis_Settings::OPTION_CAPTCHA_ENABLED ] = '1';

penalis_same( true, Penalis_Turnstile::is_enabled_for( 'reset' ), 'Turning the switch back on restores the dashboard CAPTCHA immediately.' );

/* -------------------------------------------------------------------------
 * Turnstile availability reasons and the English status card
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'cfturnstile_whitelisted' ) ) {
	/**
	 * Whitelist double driven by a test flag.
	 *
	 * @return bool
	 */
	function cfturnstile_whitelisted() {
		return ! empty( $GLOBALS['penalis_test_turnstile_whitelisted'] );
	}
}

if ( ! function_exists( 'cfturnstile_is_cloudflare_down' ) ) {
	/**
	 * Outage double driven by a test flag.
	 *
	 * @return bool
	 */
	function cfturnstile_is_cloudflare_down() {
		return ! empty( $GLOBALS['penalis_test_turnstile_down'] );
	}
}

$GLOBALS['penalis_test_turnstile_whitelisted'] = false;
$GLOBALS['penalis_test_turnstile_down']        = false;

penalis_same( 'available', Penalis_Turnstile::availability()['reason'], 'A configured, untampered site reports the "available" reason.' );
penalis_same( true, Penalis_Turnstile::availability()['available'], 'The availability result carries the boolean and the reason together.' );

$penalis_status_rows = Penalis_Settings::turnstile_status_rows();
penalis_same(
	array( 'Plugin', 'Configuration', 'Site key', 'Secret key', 'Protected WordPress forms', 'Current status' ),
	array_column( $penalis_status_rows, 'label' ),
	'The status card labels are English.'
);
penalis_same(
	array( 'Active', 'Keys set and tested', 'Set', 'Set', 'Login, Registration, Password reset', 'Enforcing on this request' ),
	array_column( $penalis_status_rows, 'value' ),
	'The status card describes a fully configured site in English.'
);

// A whitelist match is a bypass, not a configuration problem: this is the case
// that used to read "Aktif, tetapi belum terkonfigurasi" on a protected site.
$GLOBALS['penalis_test_turnstile_whitelisted'] = true;

penalis_same( 'whitelisted', Penalis_Turnstile::availability()['reason'], 'A whitelist match is reported as its own reason.' );
penalis_same( false, Penalis_Turnstile::is_available(), 'Turnstile stays unavailable for the whitelisted request.' );

$penalis_whitelisted_rows = array_column( Penalis_Settings::turnstile_status_rows(), 'value' );

penalis_same( 'Keys set and tested', $penalis_whitelisted_rows[1], 'A whitelist bypass still reports the configuration as complete.' );
penalis_assert(
	false !== strpos( $penalis_whitelisted_rows[5], 'whitelist rule' ),
	'The current status names the whitelist rule instead of claiming the plugin is unconfigured.'
);

$GLOBALS['penalis_test_turnstile_whitelisted'] = false;

// Outage failover bypasses Turnstile only while Cloudflare is actually down.
$GLOBALS['penalis_test_options']['cfturnstile_failover']      = '1';
$GLOBALS['penalis_test_options']['cfturnstile_failsafe_type'] = 'allow';
$GLOBALS['penalis_test_turnstile_down']                       = true;

penalis_same( 'failover', Penalis_Turnstile::availability()['reason'], 'An allowed outage failover is reported as its own reason.' );

$GLOBALS['penalis_test_turnstile_down'] = false;

penalis_same( 'available', Penalis_Turnstile::availability()['reason'], 'A reachable Cloudflare keeps Turnstile enforcing while failover is configured.' );

unset( $GLOBALS['penalis_test_options']['cfturnstile_failover'], $GLOBALS['penalis_test_options']['cfturnstile_failsafe_type'] );

// Unsaved test flag: the keys exist but the third-party plugin refuses to load.
$GLOBALS['penalis_test_options']['cfturnstile_tested'] = 'no';

penalis_same( 'untested', Penalis_Turnstile::availability()['reason'], 'Keys that were never tested are reported as "untested".' );

$penalis_untested_rows = array_column( Penalis_Settings::turnstile_status_rows(), 'value' );

penalis_same( 'Keys saved but not tested yet', $penalis_untested_rows[1], 'The configuration row names the missing test.' );
penalis_same( 'Inactive, the keys have not been tested yet', $penalis_untested_rows[5], 'The current status explains why Turnstile is not loading.' );

$GLOBALS['penalis_test_options']['cfturnstile_tested'] = 'yes';

// Missing key: the one case that really is a configuration problem.
unset( $GLOBALS['penalis_test_options']['cfturnstile_key'] );

penalis_same( 'keys_missing', Penalis_Turnstile::availability()['reason'], 'A missing site key is reported as "keys_missing".' );

$penalis_missing_key_rows = array_column( Penalis_Settings::turnstile_status_rows(), 'value' );

penalis_same( 'Incomplete, the site key or secret key is missing', $penalis_missing_key_rows[1], 'The configuration row names the missing key.' );
penalis_same( 'Missing', $penalis_missing_key_rows[2], 'The site key row reports the key as missing.' );

$GLOBALS['penalis_test_options']['cfturnstile_key'] = 'site-key';

/* -------------------------------------------------------------------------
 * Settings screen: registration switch and uninstall opt-in
 * ---------------------------------------------------------------------- */

penalis_reset();

penalis_same(
	'penalis_registration_enabled',
	Penalis_Settings::OPTION_REGISTRATION_ENABLED,
	'The registration switch writes the option the signup workflow already reads.'
);
penalis_same(
	'penalis_remove_data_on_uninstall',
	Penalis_Settings::OPTION_REMOVE_DATA,
	'The data opt-in writes the option uninstall.php reads.'
);

penalis_same( true, Penalis_Settings::is_registration_enabled(), 'Registration is open by default, matching the activator.' );
penalis_same( false, Penalis_Settings::should_remove_data(), 'Data removal stays off until an administrator opts in.' );
penalis_same( array(), Penalis_Settings::registration_audit(), 'The registration audit trail is empty until the switch is changed.' );
penalis_same( array(), Penalis_Settings::remove_data_audit(), 'The opt-in audit trail is empty until the switch is changed.' );

$GLOBALS['penalis_test_options']['penalis_registration_enabled'] = '0';

penalis_same( false, Penalis_Settings::is_registration_enabled(), 'The settings reader follows a closed registration switch.' );

$penalis_closed_signup = Penalis_Registration::register(
	array(
		'display_name'    => 'Calon Penulis',
		'user_login'      => 'calon_penulis',
		'user_email'      => 'calon@example.com',
		'password'        => 'P@ssw0rdKuat1234!',
		'penalis_consent' => '1',
		'penalis_website' => '',
	)
);

penalis_same(
	'penalis_registration_closed',
	$penalis_closed_signup->get_error_code(),
	'A closed registration rejects signups on the server, not only in the interface.'
);
penalis_same(
	403,
	$penalis_closed_signup->get_error_data()['status'],
	'The closed registration error keeps the 403 status the SPA expects.'
);

$GLOBALS['penalis_test_options']['penalis_registration_enabled'] = '1';

penalis_same( true, Penalis_Settings::is_registration_enabled(), 'Reopening registration restores signups.' );

penalis_same( '0', Penalis_Settings::sanitize_opt_in( '' ), 'A missing opt-in value keeps the data.' );
penalis_same( '0', Penalis_Settings::sanitize_opt_in( 'garbage' ), 'A malformed opt-in value keeps the data.' );
penalis_same( '1', Penalis_Settings::sanitize_opt_in( '1' ), 'An explicit 1 opts in to data removal.' );

$GLOBALS['penalis_test_options']['penalis_remove_data_on_uninstall'] = '1';

penalis_same( true, Penalis_Settings::should_remove_data(), 'The opt-in reader follows a stored 1.' );

penalis_same(
	'Ketik HAPUS pada kolom konfirmasi untuk mengaktifkan penghapusan data.',
	Penalis_Settings::error_for( 'data_confirm' ),
	'The data tab explains the typed confirmation when it is missing.'
);

penalis_same( true, Penalis_Settings::is_data_confirmation_valid( 'HAPUS' ), 'The exact confirmation word is accepted.' );
penalis_same( true, Penalis_Settings::is_data_confirmation_valid( ' hapus ' ), 'Case and surrounding whitespace are ignored.' );
penalis_same( false, Penalis_Settings::is_data_confirmation_valid( '' ), 'An empty confirmation is rejected.' );
penalis_same( false, Penalis_Settings::is_data_confirmation_valid( 'HAPUSKAN' ), 'A longer word is not mistaken for the confirmation.' );

/* -------------------------------------------------------------------------
 * Draft revision limits
 * ---------------------------------------------------------------------- */

$GLOBALS['penalis_test_filters'] = array();

$draft_post              = new WP_Post();
$draft_post->ID          = 501;
$draft_post->post_type   = 'post';
$draft_post->post_status = 'draft';

penalis_same(
	Penalis_Revisions::DRAFT_LIMIT,
	Penalis_Revisions::limit_draft_revisions( -1, $draft_post ),
	'An unlimited site is capped for drafts.'
);
penalis_same(
	Penalis_Revisions::DRAFT_LIMIT,
	Penalis_Revisions::limit_draft_revisions( 500, $draft_post ),
	'A very loose site limit is tightened for drafts.'
);
penalis_same( 5, Penalis_Revisions::limit_draft_revisions( 5, $draft_post ), 'A stricter site limit is kept.' );
penalis_same( 0, Penalis_Revisions::limit_draft_revisions( 0, $draft_post ), 'A site that disabled revisions keeps them disabled.' );

$published_post              = new WP_Post();
$published_post->post_type   = 'post';
$published_post->post_status = 'publish';

penalis_same(
	-1,
	Penalis_Revisions::limit_draft_revisions( -1, $published_post ),
	'Published articles keep the site limit.'
);

$page_post              = new WP_Post();
$page_post->post_type   = 'page';
$page_post->post_status = 'draft';

penalis_same(
	-1,
	Penalis_Revisions::limit_draft_revisions( -1, $page_post ),
	'Other post types keep the site limit.'
);
penalis_same( -1, Penalis_Revisions::limit_draft_revisions( -1, null ), 'A missing post is left alone.' );

Penalis_Revisions::init();

penalis_assert(
	isset( $GLOBALS['penalis_test_filters']['wp_revisions_to_keep'] ),
	'init() hooks the limit to the core revision filter.'
);

add_filter(
	'penalis_draft_revisions',
	static function () {
		return 7;
	}
);

penalis_same( 7, Penalis_Revisions::limit_draft_revisions( -1, $draft_post ), 'The filter can change the draft limit.' );
penalis_same( 5, Penalis_Revisions::limit_draft_revisions( 5, $draft_post ), 'A stricter site limit still wins over the filter.' );

$GLOBALS['penalis_test_filters'] = array();

add_filter(
	'penalis_draft_revisions',
	static function () {
		return 0;
	}
);

penalis_same(
	-1,
	Penalis_Revisions::limit_draft_revisions( -1, $draft_post ),
	'Returning 0 from the filter opts out of the plugin cap instead of disabling revisions.'
);

$GLOBALS['penalis_test_filters'] = array();

/* -------------------------------------------------------------------------
 * Single editorial notification dispatch (hardening)
 * ---------------------------------------------------------------------- */

penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_admin( 20 );
penalis_editorial_post( 900, 10, 'draft' );

$GLOBALS['penalis_test_hook_calls'] = 0;
add_action(
	'penalis_editorial_action',
	static function () {
		++$GLOBALS['penalis_test_hook_calls'];
	},
	20,
	6
);

$mails_before = count( $GLOBALS['penalis_test_mail'] );
Penalis_Editorial_Workflow::apply( 900, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_same( 1, $GLOBALS['penalis_test_hook_calls'], 'A workflow action fires the editorial hook exactly once.' );
penalis_assert( count( $GLOBALS['penalis_test_mail'] ) > $mails_before, 'The default listener sends mail through the hook.' );

// Removing the default listener must actually silence notifications: a hidden
// direct call used to re-send them and made the result depend on hook state.
remove_action( 'penalis_editorial_action', array( 'Penalis_Editorial_Notifications', 'handle_editorial_action' ), 10 );
penalis_editorial_post( 901, 10, 'draft' );
$GLOBALS['penalis_test_hook_calls'] = 0;
$mails_before = count( $GLOBALS['penalis_test_mail'] );
Penalis_Editorial_Workflow::apply( 901, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
penalis_same( 1, $GLOBALS['penalis_test_hook_calls'], 'The hook still fires after the listener is removed.' );
penalis_same( $mails_before, count( $GLOBALS['penalis_test_mail'] ), 'No direct dispatch bypasses the removed listener.' );

/* -------------------------------------------------------------------------
 * OTP lock partitioning (hardening)
 * ---------------------------------------------------------------------- */

$registration_lock = Penalis_Otp::lock_key( 'registration', 10, 'wp_users' );
penalis_same(
	$registration_lock,
	Penalis_Otp::lock_key( 'email_change', 10, 'wp_users' ),
	'One account keeps a single lock because failure counters and budgets are shared.'
);
penalis_assert(
	false !== strpos( $registration_lock, '_user_10' ),
	'The OTP lock name identifies the account.'
);
penalis_assert(
	$registration_lock !== Penalis_Otp::lock_key( 'registration', 11, 'wp_users' ),
	'Different accounts no longer serialize on one site-wide OTP lock.'
);
penalis_assert(
	$registration_lock !== Penalis_Otp::lock_key( 'registration', 10, 'wp_2_users' ),
	'Different users tables remain isolated.'
);
penalis_assert(
	false !== strpos( Penalis_Otp::lock_key( 'unknown_purpose', 0, 'wp_users' ), '_unknown_anonymous' ),
	'An unresolved user gets a purpose-scoped anonymous lock.'
);

penalis_reset();
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
penalis_auth_account( 70, Penalis_Policy::STATUS_PENDING_VERIFICATION );
penalis_auth_account( 71, Penalis_Policy::STATUS_PENDING_VERIFICATION );

$GLOBALS['penalis_test_lock_queries'] = array();
$issued_70 = Penalis_Otp::issue( 'registration', 70, 'user70@example.com' );
$code_70   = penalis_last_code();
$query_70  = isset( $GLOBALS['penalis_test_lock_queries'][0] ) ? (string) $GLOBALS['penalis_test_lock_queries'][0] : '';
penalis_assert( false !== strpos( $query_70, '_user_70' ), 'Issuing a code takes the account-scoped lock.' );

$GLOBALS['penalis_test_lock_queries'] = array();
$issued_71 = Penalis_Otp::issue( 'registration', 71, 'user71@example.com' );
$query_71  = isset( $GLOBALS['penalis_test_lock_queries'][0] ) ? (string) $GLOBALS['penalis_test_lock_queries'][0] : '';
penalis_assert( '' !== $query_70 && $query_70 !== $query_71, 'Two concurrent registrations take different advisory locks.' );

$GLOBALS['penalis_test_lock_queries'] = array();
Penalis_Otp::consume(
	'registration',
	(string) $issued_70['challenge_id'],
	$code_70,
	static function () {
		return true;
	}
);
$consume_query = isset( $GLOBALS['penalis_test_lock_queries'][0] ) ? (string) $GLOBALS['penalis_test_lock_queries'][0] : '';
penalis_same( $query_70, $consume_query, 'Consume reuses the same account lock resolved from the challenge locator.' );
unset( $GLOBALS['penalis_test_lock_queries'] );

/* -------------------------------------------------------------------------
 * Notification channel preferences (hardening)
 * ---------------------------------------------------------------------- */

$defaults = Penalis_Editorial_Notifications::normalize_preferences( array() );
penalis_same( true, $defaults['dashboard']['submit'], 'Preferences are opt-out: an absent action stays enabled.' );
penalis_same( true, $defaults['email']['approve'], 'Email notifications are enabled by default.' );
penalis_same(
	6,
	count( $defaults['email'] ),
	'Both channels expose every editorial action.'
);

$normalized = Penalis_Editorial_Notifications::normalize_preferences(
	array(
		'dashboard' => array(
			'submit' => false,
			'reject' => '0',
		),
		'email'     => array(
			'approve'     => 'off',
			'request_changes' => 'false',
		),
	)
);
penalis_same( false, $normalized['dashboard']['submit'], 'An explicit boolean false disables a channel.' );
penalis_same( false, $normalized['dashboard']['reject'], 'The string zero disables a channel.' );
penalis_same( false, $normalized['email']['approve'], 'The string off disables a channel.' );
penalis_same( false, $normalized['email']['request_changes'], 'The string false disables a channel.' );
penalis_same( true, $normalized['email']['submit'], 'An omitted action remains enabled.' );

penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_admin( 20 );
penalis_editorial_post( 950, 10, 'draft' );
Penalis_Editorial_Notifications::update_preferences(
	20,
	array(
		'dashboard' => array( 'submit' => false ),
	)
);
penalis_assert( ! Penalis_Editorial_Notifications::wants( 20, 'submit', 'dashboard' ), 'A disabled dashboard action is refused.' );
penalis_assert( Penalis_Editorial_Notifications::wants( 20, 'submit', 'email' ), 'A partial save does not disable the other channel.' );
penalis_same(
	false,
	Penalis_Editorial_Notifications::record_for_user( 20, get_post( 950 ), Penalis_Editorial_Policy::ACTION_SUBMIT ),
	'No dashboard notification is stored for an opted-out action.'
);

Penalis_Editorial_Notifications::update_preferences( 10, array( 'email' => array( 'approve' => false ) ) );
$mails_before = count( $GLOBALS['penalis_test_mail'] );
Penalis_Emails::send_editorial_decision_notification( 950, Penalis_Editorial_Policy::ACTION_APPROVE, 'Catatan.' );
penalis_same( $mails_before, count( $GLOBALS['penalis_test_mail'] ), 'A decision email is skipped when the author opted out.' );

$GLOBALS['penalis_test_current_user'] = 10;
$preferences_request = new WP_REST_Request(
	'POST',
	'/penalis/v1/notifications/preferences',
	array(
		'dashboard' => array( 'submit' => false ),
		'email'     => array( 'reject' => '0' ),
	)
);
$preferences_response = Penalis_Rest_Api::save_notification_preferences( $preferences_request );
$preferences_data     = $preferences_response->get_data();
penalis_same( false, $preferences_data['preferences']['dashboard']['submit'], 'The REST endpoint persists dashboard opt-outs.' );
penalis_same( false, $preferences_data['preferences']['email']['reject'], 'The REST endpoint persists email opt-outs.' );
penalis_same( true, $preferences_data['preferences']['email']['submit'], 'The REST endpoint keeps unsent actions enabled.' );

// Email delivery log: every attempt is kept, newest first, capped, and never
// stores a one-time secret.
Penalis_Email_Log::clear();

$log_sent = Penalis_Email_Log::record(
	array(
		'to'       => 'penulis@example.com',
		'template' => 'editorial_approve',
		'subject'  => 'Karya tulis kamu disetujui',
		'status'   => Penalis_Email_Log::STATUS_SENT,
		'vars'     => array(
			'{judul_karya}'  => 'Puisi Senja',
			'{nama_penulis}' => 'Siti',
		),
		'user_id'  => 10,
		'post_id'  => 500,
	)
);
penalis_same( Penalis_Email_Log::STATUS_SENT, $log_sent['status'], 'A successful attempt is stored as sent.' );
penalis_assert( ! empty( $log_sent['resendable'] ), 'An entry without a secret can be resent.' );
penalis_same( 'penulis@example.com', $log_sent['to'], 'The recipient is stored.' );

$log_secret = Penalis_Email_Log::record(
	array(
		'to'       => 'baru@example.com',
		'template' => 'otp_registration',
		'subject'  => 'Kode verifikasi',
		'status'   => Penalis_Email_Log::STATUS_SENT,
		'vars'     => array( '{kode_otp}' => '123456' ),
	)
);
penalis_same( array(), $log_secret['vars'], 'A payload containing an OTP is not stored.' );
penalis_assert( empty( $log_secret['resendable'] ), 'An entry carrying a secret cannot be resent.' );
penalis_assert( Penalis_Email_Log::has_secret( array( '{link_reset_password}' => 'https://example.com/reset' ) ), 'Reset links are treated as secrets.' );
penalis_assert( ! Penalis_Email_Log::has_secret( array( '{judul_karya}' => 'Puisi' ) ), 'Ordinary placeholders are not secrets.' );

$log_test = Penalis_Email_Log::record(
	array(
		'to'       => 'admin@example.com',
		'template' => 'editorial_approve',
		'status'   => Penalis_Email_Log::STATUS_SENT,
		'vars'     => array( '{judul_karya}' => 'Contoh' ),
		'test'     => true,
	)
);
penalis_assert( empty( $log_test['resendable'] ), 'A test send cannot be resent.' );

$log_failed = Penalis_Email_Log::record(
	array(
		'to'       => 'gagal@example.com',
		'template' => 'editorial_reject',
		'status'   => Penalis_Email_Log::STATUS_FAILED,
		'error'    => 'Could not instantiate mail function.',
	)
);
penalis_same( 'Could not instantiate mail function.', $log_failed['error'], 'The failure reason is kept for the log.' );

$log_entries = Penalis_Email_Log::entries();
penalis_same( 4, count( $log_entries ), 'Every attempt is stored.' );
penalis_same( $log_failed['id'], $log_entries[0]['id'], 'The newest attempt is first.' );
penalis_same( 1, count( Penalis_Email_Log::filter( $log_entries, Penalis_Email_Log::STATUS_FAILED, '' ) ), 'Filtering by status returns only failures.' );
penalis_same( 2, count( Penalis_Email_Log::filter( $log_entries, '', 'editorial_approve' ) ), 'Filtering by template returns only that template.' );
penalis_same( $log_sent['id'], Penalis_Email_Log::find( $log_sent['id'] )['id'], 'An entry can be found by identifier.' );
penalis_same( null, Penalis_Email_Log::find( 'e_missing' ), 'An unknown identifier returns nothing.' );

for ( $i = 0; $i < Penalis_Email_Log::MAX_ENTRIES + 5; $i++ ) {
	Penalis_Email_Log::record(
		array(
			'to'     => 'bulk@example.com',
			'status' => Penalis_Email_Log::STATUS_SENT,
		)
	);
}
penalis_same( Penalis_Email_Log::MAX_ENTRIES, count( Penalis_Email_Log::entries() ), 'The log is capped at the configured length.' );

Penalis_Email_Log::clear();
penalis_same( 0, count( Penalis_Email_Log::entries() ), 'Clearing removes every entry.' );

// A real send through the mailer writes a log entry with the template context.
$GLOBALS['penalis_test_mail'] = array();
Penalis_Emails::send_mail(
	'penulis@example.com',
	'Subjek uji',
	'Isi uji',
	'Footer uji',
	array(
		'template' => 'editorial_approve',
		'vars'     => array( '{judul_karya}' => 'Puisi Senja' ),
	)
);
$mailer_entries = Penalis_Email_Log::entries();
penalis_same( 1, count( $mailer_entries ), 'Sending an email records exactly one attempt.' );
penalis_same( 'editorial_approve', $mailer_entries[0]['template'], 'The log keeps the template key from the caller.' );
penalis_same( Penalis_Email_Log::STATUS_SENT, $mailer_entries[0]['status'], 'A send accepted by wp_mail is logged as sent.' );
Penalis_Email_Log::clear();

penalis_assert(
	Penalis_Admin::is_penalis_screen( 'penalis_page_penalis-email-log' ),
	'The email log screen is recognized so shared admin styles load.'
);
penalis_same(
	array( 'history', 'tools' ),
	array_keys( Penalis_Email_Log_Admin::tabs() ),
	'The email log exposes history and tools tabs in order.'
);
penalis_same(
	'history',
	Penalis_Email_Log_Admin::current_tab(),
	'History is the default email log tab.'
);
$email_log_samples = Penalis_Email_Log_Admin::sample_variables( 'otp_registration' );
penalis_same(
	'123456',
	$email_log_samples['{kode_otp}'],
	'Test previews populate OTP placeholders with sample data.'
);

$account_payload = Penalis_Rest_Api::get_account()->get_data();
penalis_same(
	false,
	$account_payload['notificationPreferences']['dashboard']['submit'],
	'The account payload exposes saved preferences to the dashboard.'
);

/* -------------------------------------------------------------------------
 * Rate limiter: proxy-aware client IP and configurable rules
 * ---------------------------------------------------------------------- */

penalis_same( true, Penalis_Rate_Limiter::ip_in_range( '203.0.113.10', '203.0.113.10' ), 'An address matches itself.' );
penalis_same( true, Penalis_Rate_Limiter::ip_in_range( '203.0.113.10', '203.0.113.0/24' ), 'An address matches its IPv4 range.' );
penalis_same( false, Penalis_Rate_Limiter::ip_in_range( '203.0.114.10', '203.0.113.0/24' ), 'A neighbouring range does not match.' );
penalis_same( true, Penalis_Rate_Limiter::ip_in_range( '203.0.113.10', '0.0.0.0/0' ), 'A /0 range matches every IPv4 address.' );
penalis_same( true, Penalis_Rate_Limiter::ip_in_range( '2001:db8::1', '2001:db8::/32' ), 'An address matches its IPv6 range.' );
penalis_same( false, Penalis_Rate_Limiter::ip_in_range( '2001:db9::1', '2001:db8::/32' ), 'A neighbouring IPv6 range does not match.' );
penalis_same( false, Penalis_Rate_Limiter::ip_in_range( '203.0.113.10', '2001:db8::/32' ), 'An IPv4 address never matches an IPv6 range.' );
penalis_same( false, Penalis_Rate_Limiter::ip_in_range( 'bukan-ip', '203.0.113.0/24' ), 'A malformed address matches nothing.' );

penalis_same( true, Penalis_Rate_Limiter::is_valid_proxy_entry( '10.0.0.1' ), 'A plain address is a valid proxy entry.' );
penalis_same( true, Penalis_Rate_Limiter::is_valid_proxy_entry( '10.0.0.0/8' ), 'A CIDR range is a valid proxy entry.' );
penalis_same( false, Penalis_Rate_Limiter::is_valid_proxy_entry( '10.0.0.0/33' ), 'A prefix longer than the address is rejected.' );
penalis_same( false, Penalis_Rate_Limiter::is_valid_proxy_entry( 'proxy.internal' ), 'A hostname is not a proxy entry.' );
penalis_same( false, Penalis_Rate_Limiter::is_valid_proxy_entry( '' ), 'An empty entry is rejected.' );

$penalis_original_server = isset( $_SERVER ) ? $_SERVER : array();

$GLOBALS['penalis_test_options']['penalis_trusted_proxies'] = '';
$_SERVER = array(
	'REMOTE_ADDR'          => '198.51.100.7',
	'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
);
penalis_same( '198.51.100.7', Penalis_Rate_Limiter::client_ip(), 'Without a trusted proxy the forwarded header is ignored.' );

$GLOBALS['penalis_test_options']['penalis_trusted_proxies'] = "10.0.0.1\n10.0.0.2";
$_SERVER = array(
	'REMOTE_ADDR'          => '10.0.0.1',
	'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.10',
);
penalis_same( '203.0.113.10', Penalis_Rate_Limiter::client_ip(), 'The chain is read from the right, so a spoofed left entry is ignored.' );

$_SERVER = array(
	'REMOTE_ADDR'          => '10.0.0.2',
	'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 10.0.0.1',
);
penalis_same( '203.0.113.10', Penalis_Rate_Limiter::client_ip(), 'Trusted hops are skipped while walking the chain.' );

$_SERVER = array(
	'REMOTE_ADDR'          => '198.51.100.7',
	'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
);
penalis_same( '198.51.100.7', Penalis_Rate_Limiter::client_ip(), 'A request from an untrusted peer keeps its own address.' );

$_SERVER = array(
	'REMOTE_ADDR'            => '10.0.0.1',
	'HTTP_CF_CONNECTING_IP'  => '203.0.113.99',
	'HTTP_X_FORWARDED_FOR'   => '9.9.9.9',
);
penalis_same( '203.0.113.99', Penalis_Rate_Limiter::client_ip(), 'The Cloudflare header wins when the peer is trusted.' );

$_SERVER = array( 'REMOTE_ADDR' => 'bukan-ip' );
penalis_same( 'unknown', Penalis_Rate_Limiter::client_ip(), 'An unusable connection address falls back to unknown.' );

$_SERVER = $penalis_original_server;

$penalis_defaults = Penalis_Rate_Limiter::defaults();
penalis_same( 5, $penalis_defaults['registration_ip']['limit'], 'Registration allows five attempts by default.' );
penalis_same( HOUR_IN_SECONDS, $penalis_defaults['registration_ip']['window'], 'Default windows are one hour.' );
penalis_same( 16, count( $penalis_defaults ), 'Every throttled action has a rule.' );

$GLOBALS['penalis_test_options']['penalis_rate_limits'] = array(
	'registration_ip' => array(
		'limit'  => 2,
		'window' => 120,
	),
);
$penalis_rule = Penalis_Rate_Limiter::rule( 'registration_ip' );
penalis_same( 2, $penalis_rule['limit'], 'A stored limit overrides the default.' );
penalis_same( 120, $penalis_rule['window'], 'A stored window overrides the default.' );

$GLOBALS['penalis_test_options']['penalis_rate_limits'] = array(
	'registration_ip' => array(
		'limit'  => 0,
		'window' => 5,
	),
);
$penalis_fallback = Penalis_Rate_Limiter::rule( 'registration_ip' );
penalis_same( 5, $penalis_fallback['limit'], 'A zero limit falls back to the default.' );
penalis_same( HOUR_IN_SECONDS, $penalis_fallback['window'], 'A window under a minute falls back to the default.' );

// "Kembalikan ke Default" deletes the option; the rules must snap back to the
// built-in values with no second copy to keep in sync.
delete_option( Penalis_Rate_Limiter::OPTION_LIMITS );
$penalis_after_reset = Penalis_Rate_Limiter::rule( 'registration_ip' );
penalis_same( $penalis_defaults['registration_ip'], $penalis_after_reset, 'Deleting the option restores the defaults.' );

// The audit stores only what moved, so the record reads as a sentence.
$penalis_before_diff = array(
	'registration_ip' => array(
		'limit'  => 5,
		'window' => HOUR_IN_SECONDS,
	),
	'tag_create'      => array(
		'limit'  => 30,
		'window' => HOUR_IN_SECONDS,
	),
);
$penalis_after_diff  = array(
	'registration_ip' => array(
		'limit'  => 8,
		'window' => HOUR_IN_SECONDS,
	),
	'tag_create'      => array(
		'limit'  => 30,
		'window' => 1800,
	),
);
$penalis_diff        = Penalis_Rate_Limiter::diff( $penalis_before_diff, $penalis_after_diff );
penalis_same( 2, count( $penalis_diff ), 'Only the actions that moved appear in the audit.' );
penalis_same( 5, $penalis_diff['registration_ip']['from']['limit'], 'The audit keeps the previous limit.' );
penalis_same( 8, $penalis_diff['registration_ip']['to']['limit'], 'The audit keeps the new limit.' );
penalis_same( 1800, $penalis_diff['tag_create']['to']['window'], 'A window-only change is recorded too.' );
penalis_same( array(), Penalis_Rate_Limiter::diff( $penalis_before_diff, $penalis_before_diff ), 'Saving without changes writes no audit entry.' );

$GLOBALS['penalis_test_options']['penalis_rate_limits'] = array(
	'tag_create' => array(
		'limit'  => 2,
		'window' => 60,
	),
);
Penalis_Rate_Limiter::clear( 'tag_create', 'writer-1' );
penalis_same( true, Penalis_Rate_Limiter::guard( 'tag_create', 'writer-1' ), 'A fresh subject is allowed.' );
Penalis_Rate_Limiter::register( 'tag_create', 'writer-1' );
Penalis_Rate_Limiter::register( 'tag_create', 'writer-1' );
penalis_same( false, Penalis_Rate_Limiter::guard( 'tag_create', 'writer-1' ), 'The configured limit blocks the third attempt.' );
penalis_same( true, Penalis_Rate_Limiter::guard( 'tag_create', 'writer-2' ), 'A different subject keeps its own quota.' );

unset( $GLOBALS['penalis_test_options']['penalis_rate_limits'] );

/* -------------------------------------------------------------------------
 * Review queue REST endpoints
 * ---------------------------------------------------------------------- */

penalis_reset();
penalis_editorial_writer( 10 );
penalis_editorial_admin( 20 );

$penalis_queue_post               = penalis_editorial_post( 900, 10, 'draft' );
$penalis_queue_post->post_title   = 'Puisi Senja';
$penalis_queue_post->post_content = '<p>Bait pertama puisi senja.</p><p>Bait kedua.</p>';
$penalis_queue_post->post_excerpt = 'Baris pertama puisi.';

$GLOBALS['penalis_test_current_user'] = 10;
penalis_assert(
	! is_wp_error( Penalis_Editorial_Workflow::apply( 900, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) ) ),
	'The writer can submit the article for review.'
);

// A writer is refused before any query runs.
$penalis_denied = Penalis_Rest_Api::require_reviewer();
penalis_assert( is_wp_error( $penalis_denied ), 'A writer is refused by the reviewer guard.' );
penalis_same( 'penalis_review_forbidden', $penalis_denied->get_error_code(), 'The reviewer guard returns a specific error.' );

$GLOBALS['penalis_test_current_user'] = 20;
penalis_same( true, Penalis_Rest_Api::require_reviewer(), 'An administrator passes the reviewer guard.' );

$penalis_queue      = Penalis_Rest_Api::list_review_queue(
	new WP_REST_Request( 'GET', '/penalis/v1/review/queue', array( 'page' => 1, 'per_page' => 10 ) )
);
$penalis_queue_data = $penalis_queue->get_data();
penalis_same( 1, count( $penalis_queue_data['items'] ), 'The queue lists the pending article.' );
penalis_same( 1, $penalis_queue_data['total'], 'The queue reports the pending total.' );
penalis_same( 900, $penalis_queue_data['items'][0]['id'], 'The queue row carries the article ID.' );
penalis_same( 'Puisi Senja', $penalis_queue_data['items'][0]['title'], 'The queue row carries the title.' );
penalis_same( 'User 10', $penalis_queue_data['items'][0]['authorName'], 'The queue row carries the author name.' );
penalis_same( 'user10', $penalis_queue_data['items'][0]['authorLogin'], 'The queue row carries the author login.' );
penalis_same( 1, $penalis_queue_data['items'][0]['reviewCycle'], 'The queue row carries the review cycle.' );
penalis_same( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $penalis_queue_data['items'][0]['editorialState'], 'The queue row carries the editorial state.' );
penalis_assert( '' !== $penalis_queue_data['items'][0]['submittedAt'], 'The queue row carries the submission time.' );

$penalis_reader_resp = Penalis_Rest_Api::get_article(
	new WP_REST_Request( 'GET', '/penalis/v1/articles/900', array( 'id' => 900 ) )
);
penalis_assert( $penalis_reader_resp instanceof WP_REST_Response, 'A reviewer can read the full submission before deciding.' );
$penalis_reader_data = $penalis_reader_resp->get_data();
penalis_same( '<p>Bait pertama puisi senja.</p><p>Bait kedua.</p>', $penalis_reader_data['content'], 'The article detail returns the complete content.' );
penalis_same( 'User 10', $penalis_reader_data['authorName'], 'The article detail includes the author display name.' );
penalis_same( 'user10', $penalis_reader_data['authorLogin'], 'The article detail includes the author login.' );
penalis_same( 'https://example.com/author/user10/', $penalis_reader_data['authorUrl'], 'The article detail includes the public author archive URL.' );
penalis_assert( array_key_exists( 'thumbnail', $penalis_reader_data ), 'The article detail includes the thumbnail field.' );
penalis_assert( is_array( $penalis_reader_data['categories'] ), 'The article detail includes categories.' );
penalis_assert( is_array( $penalis_reader_data['tags'] ), 'The article detail includes tags.' );
penalis_assert( is_array( $penalis_reader_data['reviewHistory'] ), 'The article detail includes review history.' );

$penalis_decision      = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/900/decision',
		array(
			'id'             => 900,
			'action'         => Penalis_Editorial_Policy::ACTION_APPROVE,
			'feedback'       => '',
			'expected_cycle' => 1,
		)
	)
);
$penalis_decision_data = $penalis_decision->get_data();
penalis_same( 'publish', get_post( 900 )->post_status, 'Approving through REST publishes the article.' );
penalis_same( Penalis_Editorial_Policy::STATE_PUBLISHED, $penalis_decision_data['review']['editorialState'], 'The response carries the new editorial state.' );
penalis_same( 'Karya tulis disetujui dan sudah terbit.', $penalis_decision_data['message'], 'The response explains what happened.' );

$penalis_bad_action = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/900/decision',
		array(
			'id'             => 900,
			'action'         => 'publish',
			'expected_cycle' => 1,
		)
	)
);
penalis_assert( is_wp_error( $penalis_bad_action ), 'A writer action is refused by the decision endpoint.' );
penalis_same( 'penalis_invalid_action', $penalis_bad_action->get_error_code(), 'An unknown action reports the invalid-action code.' );

$penalis_reopen_action = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/900/decision',
		array(
			'id'             => 900,
			'action'         => Penalis_Editorial_Policy::ACTION_REOPEN,
			'expected_cycle' => 1,
		)
	)
);
penalis_assert( is_wp_error( $penalis_reopen_action ), 'Reopen remains wp-admin only and is refused by the decision endpoint.' );
penalis_same( 'penalis_invalid_action', $penalis_reopen_action->get_error_code(), 'Reopen reports the invalid-action code on REST.' );

// A decision without the cycle the reviewer saw is stale by definition.
penalis_editorial_post( 901, 10, 'draft' );
$GLOBALS['penalis_test_current_user'] = 10;
Penalis_Editorial_Workflow::apply( 901, Penalis_Editorial_Policy::ACTION_SUBMIT, array( 'actor_id' => 10 ) );
$GLOBALS['penalis_test_current_user'] = 20;

$penalis_stale = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/901/decision',
		array(
			'id'             => 901,
			'action'         => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
			'feedback'       => 'Perbaiki paragraf kedua.',
			'expected_cycle' => 0,
		)
	)
);
penalis_assert( is_wp_error( $penalis_stale ), 'A decision without the reviewed cycle is refused.' );
penalis_same( 'penalis_editorial_denied', $penalis_stale->get_error_code(), 'The stale decision reports the editorial denial.' );

// Requesting changes needs feedback, and the rule lives in the workflow, not here.
$penalis_no_feedback = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/901/decision',
		array(
			'id'             => 901,
			'action'         => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
			'feedback'       => '   ',
			'expected_cycle' => 1,
		)
	)
);
penalis_assert( is_wp_error( $penalis_no_feedback ), 'Requesting changes without feedback is refused.' );

$penalis_changes = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/901/decision',
		array(
			'id'             => 901,
			'action'         => Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
			'feedback'       => 'Perbaiki paragraf kedua.',
			'expected_cycle' => 1,
		)
	)
);
penalis_same( 'draft', get_post( 901 )->post_status, 'Requesting changes returns the article to draft.' );
penalis_same( 'Catatan revisi dikirim ke penulis.', $penalis_changes->get_data()['message'], 'The response names the outcome.' );

// A writer cannot decide even by calling the handler directly.
$GLOBALS['penalis_test_current_user'] = 10;
$penalis_writer_decision = Penalis_Rest_Api::decide_article(
	new WP_REST_Request(
		'POST',
		'/penalis/v1/articles/901/decision',
		array(
			'id'             => 901,
			'action'         => Penalis_Editorial_Policy::ACTION_APPROVE,
			'expected_cycle' => 1,
		)
	)
);
penalis_assert( is_wp_error( $penalis_writer_decision ), 'The workflow refuses a decision from a writer even when the guard is bypassed.' );

/* -------------------------------------------------------------------------
 * Analytics: week buckets, median, approval rate
 * ---------------------------------------------------------------------- */

penalis_same( null, Penalis_Analytics::median( array() ), 'The median of nothing is null, not zero.' );
penalis_same( 3.0, Penalis_Analytics::median( array( 5, 1, 3 ) ), 'An odd sample returns the middle value.' );
penalis_same( 2.5, Penalis_Analytics::median( array( 4, 1, 3, 2 ) ), 'An even sample averages the two middle values.' );
penalis_same( 7.0, Penalis_Analytics::median( array( 7, 7, 7 ) ), 'Identical values return that value.' );

$penalis_duration = Penalis_Analytics::duration( 0.00087 );
penalis_same( 'menit', $penalis_duration['unit'], 'A sub-hour turnaround is shown in minutes.' );
penalis_same( 1.0, $penalis_duration['value'], 'A minute and a quarter rounds to one minute.' );
penalis_same( array( 'value' => 12.0, 'unit' => 'jam' ), Penalis_Analytics::duration( 0.5 ), 'Half a day is twelve hours.' );
penalis_same( array( 'value' => 3.4, 'unit' => 'hari' ), Penalis_Analytics::duration( 3.44 ), 'Days keep one decimal.' );
penalis_same( null, Penalis_Analytics::duration( null ), 'No data yields no duration.' );

penalis_same( null, Penalis_Analytics::rate( 0, 0 ), 'No decisions means no percentage.' );
penalis_same( 100.0, Penalis_Analytics::rate( 3, 0 ), 'All approvals is 100%.' );
penalis_same( 75.0, Penalis_Analytics::rate( 3, 1 ), 'Three of four is 75%.' );
penalis_same( 66.7, Penalis_Analytics::rate( 2, 1 ), 'The rate is rounded to one decimal.' );

$penalis_bounds = array( 1000, 2000, 3000 );
penalis_same( array( 1, 1 ), Penalis_Analytics::bucket_timestamps( array( 1000, 2500 ), $penalis_bounds ), 'Each timestamp lands in its own week.' );
penalis_same( array( 2, 0 ), Penalis_Analytics::bucket_timestamps( array( 1000, 1999 ), $penalis_bounds ), 'The upper bound of a week is exclusive.' );
penalis_same( array( 0, 0 ), Penalis_Analytics::bucket_timestamps( array( 999, 3000 ), $penalis_bounds ), 'Timestamps outside the window are ignored.' );
penalis_same( array(), Penalis_Analytics::bucket_timestamps( array( 1000 ), array( 1000 ) ), 'A window without weeks yields no buckets.' );

$penalis_week_bounds = Penalis_Analytics::week_boundaries( 8, strtotime( '2026-11-19 10:00:00' ) );
penalis_same( 9, count( $penalis_week_bounds ), 'Eight weeks produce nine boundaries.' );

$penalis_first_week = ( new DateTimeImmutable( '@' . $penalis_week_bounds[0] ) )->setTimezone( Penalis_Datetime::site_timezone() );
penalis_same( '1', $penalis_first_week->format( 'N' ), 'Weeks start on Monday.' );
penalis_same( '00:00', $penalis_first_week->format( 'H:i' ), 'Weeks start at midnight.' );
penalis_assert( $penalis_week_bounds[8] > strtotime( '2026-11-19 10:00:00' ), 'The window ends after the reference date.' );
penalis_same( 8, count( Penalis_Analytics::bucket_timestamps( array(), $penalis_week_bounds ) ), 'Buckets match the requested week count.' );

/* -------------------------------------------------------------------------
 * #KalimatHariIni: pure policy, 24h expiry, 3x/24h quota, public feed, page
 * ---------------------------------------------------------------------- */

penalis_same(
	"Baris satu\nBaris dua",
	Penalis_Policy::normalize_daily_quote( "  <b>Baris   satu</b> \r\n\r\n   Baris\tdua  \n " ),
	'Daily quote normalization strips HTML, collapses spaces, and removes blank lines.'
);
penalis_assert(
	'' !== Penalis_Policy::validate_daily_quote( '   ' ),
	'Empty #KalimatHariIni is rejected.'
);
penalis_assert(
	'' !== Penalis_Policy::validate_daily_quote( "Satu\nDua\nTiga\nEmpat" ),
	'#KalimatHariIni with more than 3 lines is rejected.'
);
penalis_same(
	'',
	Penalis_Policy::validate_daily_quote( str_repeat( 'a', 200 ) ),
	'#KalimatHariIni at exactly 200 characters is accepted.'
);
penalis_assert(
	'' !== Penalis_Policy::validate_daily_quote( str_repeat( 'a', 201 ) ),
	'#KalimatHariIni above 200 characters is rejected.'
);
penalis_same(
	'newest',
	Penalis_Policy::normalize_daily_quotes_sort( 'invalid' ),
	'Unknown daily quotes sort falls back to newest.'
);

$penalis_q_now   = 1800000000;
$penalis_q_quota = Penalis_Policy::daily_quote_rate_state(
	array( $penalis_q_now - 90000, $penalis_q_now - 1000, $penalis_q_now - 500 ),
	$penalis_q_now
);
penalis_same( 2, $penalis_q_quota['used'], 'Timestamps older than 24 hours roll out of the quota window.' );
penalis_same( 1, $penalis_q_quota['remaining'], 'One publish slot remains when two occurred within 24 hours.' );
penalis_same( 0, $penalis_q_quota['reset_at'], 'Reset timestamp is zero while quota remains.' );

penalis_same(
	'Spam atau promosi tidak relevan',
	Penalis_Policy::normalize_daily_quote_removal_reason( "  <b>Spam</b>   atau\npromosi\ttidak relevan  " ),
	'Removal reason normalization strips HTML and collapses whitespace.'
);
penalis_assert(
	'' !== Penalis_Policy::validate_daily_quote_removal_reason( '   ' ),
	'Empty removal reason is rejected.'
);
penalis_same(
	'',
	Penalis_Policy::validate_daily_quote_removal_reason( str_repeat( 'a', Penalis_Policy::DAILY_QUOTE_REASON_MAX_LENGTH ) ),
	'Removal reason at max length is accepted.'
);
penalis_assert(
	'' !== Penalis_Policy::validate_daily_quote_removal_reason( str_repeat( 'a', Penalis_Policy::DAILY_QUOTE_REASON_MAX_LENGTH + 1 ) ),
	'Removal reason above max length is rejected.'
);
penalis_assert(
	count( Penalis_Policy::daily_quote_removal_presets() ) >= 3,
	'Quick-reason presets are provided for Senator moderation.'
);
penalis_same(
	'#KalimatHariIni dihapus',
	Penalis_Editorial_Policy::action_label( Penalis_Editorial_Policy::NOTIFICATION_QUOTE_REMOVED ),
	'quote_removed notification type has an Indonesian label.'
);
penalis_assert(
	! Penalis_Editorial_Policy::is_valid_action( Penalis_Editorial_Policy::NOTIFICATION_QUOTE_REMOVED ),
	'quote_removed stays outside the article workflow action list.'
);
penalis_assert(
	Penalis_Editorial_Policy::is_valid_notification_type( Penalis_Editorial_Policy::NOTIFICATION_QUOTE_REMOVED ),
	'quote_removed is accepted as a valid dashboard notification type.'
);
penalis_assert(
	! Penalis_Editorial_Policy::is_valid_notification(
		array(
			'id'         => 'n_bad_article',
			'type'       => Penalis_Editorial_Policy::ACTION_APPROVE,
			'article_id' => 0,
			'message'    => 'Harus ditolak',
			'created_at' => $penalis_q_now,
			'read_at'    => 0,
		)
	),
	'Editorial article notifications still require article_id > 0.'
);

// Setup active writers and a non-writer for #KalimatHariIni workflow tests.
$GLOBALS['penalis_test_users'][501]                = new WP_User( 501, array( Penalis_Roles::ROLE ) );
$GLOBALS['penalis_test_users'][501]->display_name  = 'Erland Ramdhani';
$GLOBALS['penalis_test_users'][501]->user_nicename = 'erland';
update_user_meta( 501, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 501, Penalis_Account_Status::META_ACCESS, '1' );

$GLOBALS['penalis_test_users'][502]                = new WP_User( 502, array( Penalis_Roles::ROLE ) );
$GLOBALS['penalis_test_users'][502]->display_name  = 'Nadia Aksara';
$GLOBALS['penalis_test_users'][502]->user_nicename = 'nadia';
update_user_meta( 502, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 502, Penalis_Account_Status::META_ACCESS, '1' );

$GLOBALS['penalis_test_users'][503]                = new WP_User( 503, array( 'subscriber' ) );
$GLOBALS['penalis_test_users'][503]->display_name  = 'Pending User';
update_user_meta( 503, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_PENDING_VERIFICATION );
update_user_meta( 503, Penalis_Account_Status::META_ACCESS, '0' );

$penalis_q_pending = Penalis_Daily_Quotes::publish( 503, 'Halo dunia', $penalis_q_now );
penalis_assert( is_wp_error( $penalis_q_pending ), 'Pending accounts cannot publish #KalimatHariIni.' );

$penalis_q_1 = Penalis_Daily_Quotes::publish( 501, 'Kita gak punya apa-apa selain semangat', $penalis_q_now );
penalis_assert( is_array( $penalis_q_1 ), 'Active writer can publish #KalimatHariIni.' );
penalis_same( 'Kita gak punya apa-apa selain semangat', $penalis_q_1['quote']['text'], 'Published quote text is stored.' );
penalis_same( $penalis_q_now + 86400, $penalis_q_1['quote']['expiresAt'], 'Published quote expires in exactly 24 hours.' );
penalis_same( 2, $penalis_q_1['quota']['remaining'], 'Publishing once leaves 2 remaining slots in 24 hours.' );
penalis_same( false, $penalis_q_1['canPublish'], 'Writer cannot publish a second quote while one is active.' );

$penalis_q_dup = Penalis_Daily_Quotes::publish( 501, 'Kalimat kedua tanpa hapus', $penalis_q_now + 10 );
penalis_assert( is_wp_error( $penalis_q_dup ), 'Publishing while a quote is still active returns an error.' );
penalis_same( 'penalis_quote_already_active', $penalis_q_dup->get_error_code(), 'Duplicate active quote uses penalis_quote_already_active code.' );

// Delete and publish 2nd & 3rd quotes within 24 hours; 4th must be rate-limited.
Penalis_Daily_Quotes::delete_for_user( 501, $penalis_q_now + 60 );
penalis_same( null, Penalis_Daily_Quotes::get_active_for_user( 501, $penalis_q_now + 60 ), 'Deleted quote disappears immediately.' );

$penalis_q_2 = Penalis_Daily_Quotes::publish( 501, 'Kalimat kedua hari ini', $penalis_q_now + 120 );
penalis_assert( is_array( $penalis_q_2 ), 'Writer can republish after deleting (2nd of 3).' );
penalis_same( 1, $penalis_q_2['quota']['remaining'], 'Second publish leaves 1 slot remaining.' );

Penalis_Daily_Quotes::delete_for_user( 501, $penalis_q_now + 180 );
$penalis_q_3 = Penalis_Daily_Quotes::publish( 501, 'Kalimat ketiga hari ini', $penalis_q_now + 240 );
penalis_assert( is_array( $penalis_q_3 ), 'Writer can publish 3rd quote within 24 hours.' );
penalis_same( 0, $penalis_q_3['quota']['remaining'], 'Third publish exhausts the 24-hour quota.' );
penalis_same( $penalis_q_now + 86400, $penalis_q_3['quota']['resetAt'], 'Reset time matches 24 hours after oldest publish in window.' );

Penalis_Daily_Quotes::delete_for_user( 501, $penalis_q_now + 300 );
$penalis_q_4 = Penalis_Daily_Quotes::publish( 501, 'Kalimat keempat harus ditolak', $penalis_q_now + 360 );
penalis_assert( is_wp_error( $penalis_q_4 ), 'Fourth publish within 24 hours is blocked.' );
penalis_same( 'penalis_quote_limit_reached', $penalis_q_4->get_error_code(), '4th publish returns penalis_quote_limit_reached.' );

// After 24 hours have passed since the first publish, one slot opens up again.
$penalis_q_after_24h = Penalis_Daily_Quotes::publish( 501, 'Kalimat esok hari', $penalis_q_now + 86401 );
penalis_assert( is_array( $penalis_q_after_24h ), 'Writer can publish again once oldest publish rolls past 24 hours.' );

// Second writer publishes later so newest/oldest sort and search can be verified.
Penalis_Daily_Quotes::publish( 502, 'Menulis adalah cara paling sunyi untuk bicara', $penalis_q_now + 86500 );

$penalis_feed_newest = Penalis_Daily_Quotes::query_quotes( array( 'sort' => 'newest' ), $penalis_q_now + 86600 );
penalis_same( 2, $penalis_feed_newest['total'], 'Public feed returns both active quotes.' );
penalis_same( 502, $penalis_feed_newest['items'][0]['authorId'], 'Newest sort puts latest quote first.' );

$penalis_feed_oldest = Penalis_Daily_Quotes::query_quotes( array( 'sort' => 'oldest' ), $penalis_q_now + 86600 );
penalis_same( 501, $penalis_feed_oldest['items'][0]['authorId'], 'Oldest sort puts earliest quote first.' );

$penalis_feed_search = Penalis_Daily_Quotes::query_quotes( array( 'search' => 'sunyi' ), $penalis_q_now + 86600 );
penalis_same( 1, $penalis_feed_search['total'], 'Search matches quote content.' );
penalis_same( 502, $penalis_feed_search['items'][0]['authorId'], 'Search returns the matching author.' );

// Expiry hides both quotes automatically once 24 hours elapse.
$penalis_feed_expired = Penalis_Daily_Quotes::query_quotes( array(), $penalis_q_now + 86500 + 86400 );
penalis_same( 0, $penalis_feed_expired['total'], 'Expired quotes disappear automatically after 24 hours.' );

// Suspending an active writer immediately hides their active quote.
update_user_meta( 502, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_SUSPENDED );
update_user_meta( 502, Penalis_Account_Status::META_ACCESS, '0' );
penalis_same( null, Penalis_Daily_Quotes::get_active_for_user( 502, $penalis_q_now + 86600 ), 'Suspended writer quote is hidden immediately.' );

// Re-activate 502, clear old future-timestamp test quote, and check author archive data + Senator moderation delete.
update_user_meta( 502, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_ACTIVE );
update_user_meta( 502, Penalis_Account_Status::META_ACCESS, '1' );
Penalis_Daily_Quotes::delete_for_user( 502, time() );
Penalis_Daily_Quotes::publish( 502, 'Kutipan aktif di profil', time() );
$penalis_archive_profile = Penalis_Author_Archive::get_profile_data( $GLOBALS['penalis_test_users'][502] );
penalis_assert( is_array( $penalis_archive_profile['daily_quote'] ), 'Author archive profile includes active #KalimatHariIni.' );
penalis_same( 'Kutipan aktif di profil', $penalis_archive_profile['daily_quote']['text'], 'Author archive profile carries normalized quote text.' );

// Senator moderation delete requires a reason, deletes the quote, sends a dashboard-only notification (no email).
delete_user_meta( 502, Penalis_Editorial_Policy::META_NOTIFICATIONS );
$GLOBALS['penalis_test_mail'] = array();

$penalis_mod_no_reason = Penalis_Rest_Api::handle_moderate_delete_quote(
	new WP_REST_Request( 'DELETE', '/penalis/v1/quotes/502', array( 'user_id' => 502, 'reason' => '   ' ) )
);
penalis_assert( is_wp_error( $penalis_mod_no_reason ), 'Senator moderation delete refuses empty reason.' );
penalis_same( 'penalis_validation_error', $penalis_mod_no_reason->get_error_code(), 'Empty reason returns penalis_validation_error.' );
penalis_assert( is_array( Penalis_Daily_Quotes::get_active_for_user( 502 ) ), 'Quote stays active when takedown reason is missing.' );
penalis_same( 0, count( Penalis_Editorial_Notifications::get_for_user( 502 ) ), 'No notification is recorded when moderation delete fails.' );

$penalis_mod_delete = Penalis_Rest_Api::handle_moderate_delete_quote(
	new WP_REST_Request(
		'DELETE',
		'/penalis/v1/quotes/502',
		array(
			'user_id' => 502,
			'reason'  => '  Spam atau promosi   tidak relevan  ',
		)
	)
);
penalis_same( true, $penalis_mod_delete->get_data()['deleted'], 'Senator moderation delete succeeds with a valid reason.' );
penalis_same( 'Spam atau promosi tidak relevan', $penalis_mod_delete->get_data()['reason'], 'Moderation delete returns the normalized reason.' );
penalis_same( null, Penalis_Daily_Quotes::get_active_for_user( 502 ), 'Moderated quote is removed.' );
penalis_same( 0, count( $GLOBALS['penalis_test_mail'] ), 'Quote moderation takedown sends no email.' );

$penalis_q_notifs = Penalis_Editorial_Notifications::get_for_user( 502 );
penalis_same( 1, count( $penalis_q_notifs ), 'Writer receives one dashboard notification after Senator takedown.' );
penalis_same( Penalis_Editorial_Policy::NOTIFICATION_QUOTE_REMOVED, $penalis_q_notifs[0]['type'], 'Notification type is quote_removed.' );
penalis_same( '#KalimatHariIni dihapus', $penalis_q_notifs[0]['typeLabel'], 'Notification type label is Indonesian.' );
penalis_same( 0, $penalis_q_notifs[0]['articleId'], 'Quote takedown notification has articleId 0.' );
penalis_same( '#KalimatHariIni', $penalis_q_notifs[0]['articleTitle'], 'Quote takedown notification uses #KalimatHariIni title.' );
penalis_same( 'overview', $penalis_q_notifs[0]['targetView'], 'Quote takedown notification targets overview.' );
penalis_same(
	'#KalimatHariIni kamu telah dihapus oleh Senator. Alasan: Spam atau promosi tidak relevan',
	$penalis_q_notifs[0]['message'],
	'Quote takedown notification carries the exact general message with reason.'
);
penalis_same( 1, Penalis_Editorial_Notifications::count_unread( 502 ), 'Quote takedown notification increments unread count.' );
Penalis_Editorial_Notifications::mark_read( 502, $penalis_q_notifs[0]['id'] );
penalis_same( 0, Penalis_Editorial_Notifications::count_unread( 502 ), 'Quote takedown notification can be marked read.' );

// Attempting to moderate an already-removed quote returns 404 without adding a duplicate notification.
$penalis_mod_again = Penalis_Rest_Api::handle_moderate_delete_quote(
	new WP_REST_Request(
		'DELETE',
		'/penalis/v1/quotes/502',
		array(
			'user_id' => 502,
			'reason'  => 'Spam atau promosi tidak relevan',
		)
	)
);
penalis_assert( is_wp_error( $penalis_mod_again ), 'Moderating an already-deleted quote returns WP_Error.' );
penalis_same( 'penalis_quote_not_found', $penalis_mod_again->get_error_code(), 'Missing active quote returns penalis_quote_not_found.' );
penalis_same( 1, count( Penalis_Editorial_Notifications::get_for_user( 502 ) ), 'Failed duplicate moderation does not append another notification.' );

// Self-deletion never creates a takedown notification.
delete_user_meta( 501, Penalis_Editorial_Policy::META_NOTIFICATIONS );

// Layer 4 (rest_pre_dispatch) allows active writers to publish and delete their own quote,
// while still refusing cross-writer moderation routes and pending accounts.
$GLOBALS['penalis_test_current_user'] = 501;
penalis_same(
	null,
	Penalis_Access_Control::guard_rest_request( null, null, new WP_REST_Request( 'POST', '/penalis/v1/quotes' ) ),
	'Active writer is allowed through rest_pre_dispatch for POST /penalis/v1/quotes.'
);
penalis_same(
	null,
	Penalis_Access_Control::guard_rest_request( null, null, new WP_REST_Request( 'DELETE', '/penalis/v1/quotes/me' ) ),
	'Active writer is allowed through rest_pre_dispatch for DELETE /penalis/v1/quotes/me.'
);
$penalis_guard_cross_delete = Penalis_Access_Control::guard_rest_request( null, null, new WP_REST_Request( 'DELETE', '/penalis/v1/quotes/502' ) );
penalis_assert( is_wp_error( $penalis_guard_cross_delete ), 'Active writer is blocked at rest_pre_dispatch from DELETE /penalis/v1/quotes/{user_id}.' );
penalis_same( 'penalis_rest_forbidden', $penalis_guard_cross_delete->get_error_code(), 'Cross-writer quote delete fails with penalis_rest_forbidden.' );

$GLOBALS['penalis_test_current_user'] = 503;
$penalis_guard_pending_quote          = Penalis_Access_Control::guard_rest_request( null, null, new WP_REST_Request( 'POST', '/penalis/v1/quotes' ) );
penalis_assert( is_wp_error( $penalis_guard_pending_quote ), 'Pending user is blocked at rest_pre_dispatch from POST /penalis/v1/quotes.' );
penalis_same( 'penalis_account_inactive', $penalis_guard_pending_quote->get_error_code(), 'Pending user quote publish fails with penalis_account_inactive.' );

// Activator creates `/kalimat-hari-ini/` page and is idempotent.
delete_option( Penalis_Daily_Quotes::OPTION_PAGE_ID );
delete_option( Penalis_Daily_Quotes::OPTION_PAGE_OWNED );
$penalis_quotes_page_1 = Penalis_Activator::ensure_daily_quotes_page();
penalis_assert( $penalis_quotes_page_1 > 0, 'Activator creates the /kalimat-hari-ini/ page.' );
penalis_same( 'kalimat-hari-ini', get_post( $penalis_quotes_page_1 )->post_name, 'Created page uses slug kalimat-hari-ini.' );
penalis_same( '[penalis_kalimat_hari_ini]', get_post( $penalis_quotes_page_1 )->post_content, 'Created page contains the #KalimatHariIni shortcode.' );
penalis_same( $penalis_quotes_page_1, Penalis_Activator::ensure_daily_quotes_page(), 'ensure_daily_quotes_page() is idempotent.' );

// wp-admin moderation screen registration and screen detection.
penalis_assert(
	Penalis_Admin::is_penalis_screen( 'penalis_page_' . Penalis_Daily_Quotes_Admin::PAGE_SLUG ),
	'Penalis_Admin::is_penalis_screen() recognizes the Kalimat Hari Ini submenu.'
);
penalis_assert(
	false !== strpos( Penalis_Daily_Quotes_Admin::page_url( array( 's' => 'semangat' ) ), 'page=penalis-quotes' ),
	'Penalis_Daily_Quotes_Admin::page_url() builds the wp-admin moderation URL.'
);
penalis_same(
	1,
	Penalis_Daily_Quotes_Admin::active_count(),
	'active_count() reports 1 when one writer has an active quote.'
);
Penalis_Daily_Quotes::delete_for_user( 501 );
penalis_same(
	0,
	Penalis_Daily_Quotes_Admin::active_count(),
	'active_count() drops to 0 after the remaining active quote is deleted.'
);
penalis_same(
	0,
	count( Penalis_Editorial_Notifications::get_for_user( 501 ) ),
	'Self-deleting a quote does not trigger a takedown notification.'
);

$penalis_brand = Penalis_Dashboard::brand_assets();
penalis_assert(
	false !== strpos( $penalis_brand['logoUrl'], 'assets/images/logo.png' ),
	'brand_assets() exposes the bundled Penalis wordmark logo.'
);
penalis_assert(
	false !== strpos( $penalis_brand['iconUrl'], 'assets/images/icon.png' ),
	'brand_assets() exposes the bundled Penalis square icon.'
);
penalis_assert(
	is_readable( PENALIS_PLATFORM_DIR . 'assets/images/logo.png' ) && is_readable( PENALIS_PLATFORM_DIR . 'assets/images/icon.png' ),
	'Bundled logo.png and icon.png exist inside assets/images/.'
);

// Article share card: clean excerpt spacing, payload, shortcode, and auto-append deduplication.
Penalis_Article_Share::reset_state();
$penalis_poem_post                = new WP_Post();
$penalis_poem_post->ID            = 710;
$penalis_poem_post->post_author   = 501;
$penalis_poem_post->post_type     = 'post';
$penalis_poem_post->post_status   = 'publish';
$penalis_poem_post->post_title    = 'Wayang Topeng';
$penalis_poem_post->post_excerpt  = '';
$penalis_poem_post->post_content  = '<p>Sejatinya setiap masa<br>Adalah<br/>Bermain peran</p><p>Berbagi peran</p>';
$penalis_poem_post->post_date_gmt = '2026-05-22 08:00:00';
$GLOBALS['penalis_test_posts'][710] = $penalis_poem_post;

penalis_same(
	'Sejatinya setiap masa Adalah Bermain peran Berbagi peran',
	Penalis_Article_Share::clean_excerpt( $penalis_poem_post, 20 ),
	'clean_excerpt() inserts spaces at <br> and </p> boundaries so poetry lines do not glue together.'
);

$penalis_article_payload = Penalis_Article_Share::build_article_payload( $penalis_poem_post );
penalis_same( 'Wayang Topeng', $penalis_article_payload['title'], 'build_article_payload() returns decoded article title.' );
penalis_same( 'Erland Ramdhani', $penalis_article_payload['displayName'], 'build_article_payload() includes author display name.' );
penalis_same( 'erland', $penalis_article_payload['username'], 'build_article_payload() includes author username.' );
penalis_same( '22/05/2026', $penalis_article_payload['publishedLabel'], 'build_article_payload() formats publishedLabel as dd/mm/yyyy.' );

$penalis_embed_first  = Penalis_Article_Share::render_embedded_payload( $penalis_poem_post );
$penalis_embed_second = Penalis_Article_Share::render_embedded_payload( $penalis_poem_post );
penalis_assert( false !== strpos( $penalis_embed_first, 'data-penalis-article-card="710"' ), 'render_embedded_payload() outputs JSON script tag for article.' );
penalis_same( '', $penalis_embed_second, 'render_embedded_payload() emits at most once per post per request.' );

Penalis_Article_Share::reset_state();
$penalis_shortcode_html = Penalis_Article_Share::render_shortcode( array( 'id' => 710 ) );
penalis_assert( false !== strpos( $penalis_shortcode_html, 'data-penalis-open-card="article"' ), '[penalis_share_article] renders share trigger button for published posts.' );
penalis_assert( false !== strpos( $penalis_shortcode_html, 'data-penalis-article-id="710"' ), '[penalis_share_article] tags button with post ID.' );

$penalis_draft_post              = new WP_Post();
$penalis_draft_post->ID          = 711;
$penalis_draft_post->post_author = 501;
$penalis_draft_post->post_type   = 'post';
$penalis_draft_post->post_status = 'draft';
$penalis_draft_post->post_title  = 'Draft Rahasia';
$GLOBALS['penalis_test_posts'][711] = $penalis_draft_post;
penalis_same(
	'',
	Penalis_Article_Share::render_shortcode( array( 'id' => 711 ) ),
	'[penalis_share_article] renders nothing for unpublished drafts.'
);

// Auto-append on single posts skips when shortcode was already rendered for the same post.
$GLOBALS['penalis_test_is_singular']     = 'post';
$GLOBALS['penalis_test_in_the_loop']     = true;
$GLOBALS['penalis_test_is_main_query']   = true;
$GLOBALS['penalis_test_is_feed']         = false;
$GLOBALS['penalis_test_current_post_id'] = 710;
$GLOBALS['post']                         = $penalis_poem_post;

penalis_same(
	'<p>Isi puisi</p>',
	Penalis_Article_Share::append_single_post_bar( '<p>Isi puisi</p>' ),
	'append_single_post_bar() skips auto-bar when [penalis_share_article] already rendered on the post.'
);

Penalis_Article_Share::reset_state();
$penalis_auto_bar_html = Penalis_Article_Share::append_single_post_bar( '<p>Isi puisi</p>' );
penalis_assert( false !== strpos( $penalis_auto_bar_html, 'penalis-article-share-bar' ), 'append_single_post_bar() appends share bar on single published posts when shortcode is absent.' );
penalis_assert( false !== strpos( $penalis_auto_bar_html, 'data-penalis-article-card="710"' ), 'append_single_post_bar() embeds JSON payload once.' );

// Theme below-article hook renders cleanly and never duplicates after the_content bar.
ob_start();
Penalis_Article_Share::render_below_article_hook();
$penalis_dup_below = ob_get_clean();
penalis_same( '', $penalis_dup_below, 'render_below_article_hook() does not render a duplicate bar on the same post.' );

Penalis_Article_Share::reset_state();
ob_start();
Penalis_Article_Share::render_below_article_hook();
$penalis_below_html = ob_get_clean();
penalis_assert( false !== strpos( $penalis_below_html, 'penalis-article-share-bar' ), 'render_below_article_hook() renders share bar below content/tags on Atlas.' );

unset(
	$GLOBALS['penalis_test_is_singular'],
	$GLOBALS['penalis_test_in_the_loop'],
	$GLOBALS['penalis_test_is_main_query'],
	$GLOBALS['penalis_test_is_feed'],
	$GLOBALS['penalis_test_current_post_id'],
	$GLOBALS['post']
);

/* -------------------------------------------------------------------------
 * Default featured image provider (Penalis_Default_Thumbnail)
 * ---------------------------------------------------------------------- */

delete_option( Penalis_Default_Thumbnail::OPTION_ID );
$GLOBALS['penalis_test_is_admin'] = false;

$penalis_dfi_img                 = new WP_Post();
$penalis_dfi_img->ID             = 980;
$penalis_dfi_img->post_author    = 1;
$penalis_dfi_img->post_type      = 'attachment';
$penalis_dfi_img->post_status    = 'inherit';
$penalis_dfi_img->post_mime_type = 'image/jpeg';
$GLOBALS['penalis_test_posts'][980] = $penalis_dfi_img;

$penalis_dfi_pdf                 = new WP_Post();
$penalis_dfi_pdf->ID             = 981;
$penalis_dfi_pdf->post_author    = 1;
$penalis_dfi_pdf->post_type      = 'attachment';
$penalis_dfi_pdf->post_status    = 'inherit';
$penalis_dfi_pdf->post_mime_type = 'application/pdf';
$GLOBALS['penalis_test_posts'][981] = $penalis_dfi_pdf;

$penalis_dfi_pub_no_thumb              = new WP_Post();
$penalis_dfi_pub_no_thumb->ID          = 982;
$penalis_dfi_pub_no_thumb->post_author = 501;
$penalis_dfi_pub_no_thumb->post_type   = 'post';
$penalis_dfi_pub_no_thumb->post_status = 'publish';
$GLOBALS['penalis_test_posts'][982]    = $penalis_dfi_pub_no_thumb;

$penalis_dfi_pub_with_thumb              = new WP_Post();
$penalis_dfi_pub_with_thumb->ID          = 983;
$penalis_dfi_pub_with_thumb->post_author = 501;
$penalis_dfi_pub_with_thumb->post_type   = 'post';
$penalis_dfi_pub_with_thumb->post_status = 'publish';
$penalis_dfi_pub_with_thumb->meta        = array( '_thumbnail_id' => array( '777' ) );
$GLOBALS['penalis_test_posts'][983]      = $penalis_dfi_pub_with_thumb;

$penalis_dfi_draft              = new WP_Post();
$penalis_dfi_draft->ID          = 984;
$penalis_dfi_draft->post_author = 501;
$penalis_dfi_draft->post_type   = 'post';
$penalis_dfi_draft->post_status = 'draft';
$GLOBALS['penalis_test_posts'][984] = $penalis_dfi_draft;

$penalis_dfi_pending              = new WP_Post();
$penalis_dfi_pending->ID          = 985;
$penalis_dfi_pending->post_author = 501;
$penalis_dfi_pending->post_type   = 'post';
$penalis_dfi_pending->post_status = 'pending';
$GLOBALS['penalis_test_posts'][985] = $penalis_dfi_pending;

$penalis_dfi_page              = new WP_Post();
$penalis_dfi_page->ID          = 986;
$penalis_dfi_page->post_author = 1;
$penalis_dfi_page->post_type   = 'page';
$penalis_dfi_page->post_status = 'publish';
$GLOBALS['penalis_test_posts'][986] = $penalis_dfi_page;

// When unset, provider returns 0 and leaves filters untouched.
penalis_same( 0, Penalis_Default_Thumbnail::get_id(), 'Default featured image ID is 0 when option is unset.' );
penalis_same( 0, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 0, $penalis_dfi_pub_no_thumb ), 'filter_post_thumbnail_id() returns 0 when no default image is configured.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 982, '_thumbnail_id', true ), 'filter_post_metadata() returns null when no default image is configured.' );

// Non-image or missing attachment resolves safely to 0 while preserving configured ID for settings warning.
update_option( Penalis_Default_Thumbnail::OPTION_ID, 981 );
penalis_same( 981, Penalis_Default_Thumbnail::get_configured_id(), 'get_configured_id() reports the stored option ID.' );
penalis_same( 0, Penalis_Default_Thumbnail::get_id(), 'get_id() ignores non-image attachments.' );

update_option( Penalis_Default_Thumbnail::OPTION_ID, 999999 );
penalis_same( 0, Penalis_Default_Thumbnail::get_id(), 'get_id() ignores deleted or nonexistent attachment IDs.' );

// Valid image configured: applies strictly to published `post` records without explicit thumbnail.
update_option( Penalis_Default_Thumbnail::OPTION_ID, 980 );
penalis_same( 980, Penalis_Default_Thumbnail::get_id(), 'get_id() returns the configured image attachment ID.' );

penalis_same( 980, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 0, $penalis_dfi_pub_no_thumb ), 'Published post without thumbnail receives default featured image ID.' );
penalis_same( array( '980' ), Penalis_Default_Thumbnail::filter_post_metadata( null, 982, '_thumbnail_id', true ), 'filter_post_metadata() returns single-element array for published post without thumbnail.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 982, '_other_meta', true ), 'filter_post_metadata() ignores non-_thumbnail_id meta keys.' );
penalis_same( array( '555' ), Penalis_Default_Thumbnail::filter_post_metadata( array( '555' ), 982, '_thumbnail_id', true ), 'filter_post_metadata() respects existing non-null short-circuit values.' );

// Published post with an explicit thumbnail keeps its own thumbnail.
penalis_same( 777, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 777, $penalis_dfi_pub_with_thumb ), 'Published post with explicit thumbnail keeps its own ID.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 983, '_thumbnail_id', true ), 'filter_post_metadata() does not override an explicit _thumbnail_id.' );

// Drafts, pending reviews, and pages NEVER receive the fallback.
penalis_same( 0, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 0, $penalis_dfi_draft ), 'Draft posts never receive the default featured image.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 984, '_thumbnail_id', true ), 'filter_post_metadata() never injects fallback into draft posts.' );
penalis_same( 0, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 0, $penalis_dfi_pending ), 'Pending review posts never receive the default featured image.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 985, '_thumbnail_id', true ), 'filter_post_metadata() never injects fallback into pending posts.' );
penalis_same( 0, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 0, $penalis_dfi_page ), 'Published pages never receive the post-only default featured image.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 986, '_thumbnail_id', true ), 'filter_post_metadata() skips page post type.' );

// Non-AJAX wp-admin screens see the real DB state.
$GLOBALS['penalis_test_is_admin'] = true;
penalis_same( 0, Penalis_Default_Thumbnail::filter_post_thumbnail_id( 0, $penalis_dfi_pub_no_thumb ), 'Non-AJAX wp-admin screens do not receive the fallback thumbnail.' );
penalis_same( null, Penalis_Default_Thumbnail::filter_post_metadata( null, 982, '_thumbnail_id', true ), 'filter_post_metadata() skips non-AJAX wp-admin screens.' );
$GLOBALS['penalis_test_is_admin'] = false;

// Media usage inspection protects the configured default featured image from deletion.
$penalis_dfi_usage = Penalis_Media_Usage::inspect( 980 );
penalis_same( true, $penalis_dfi_usage['inUse'], 'Penalis_Media_Usage::inspect() marks the default featured image as in use.' );
penalis_same( false, $penalis_dfi_usage['canDelete'], 'Penalis_Media_Usage::inspect() blocks deleting the default featured image.' );
penalis_assert( false !== strpos( $penalis_dfi_usage['reason'], 'thumbnail bawaan' ), 'Penalis_Media_Usage::inspect() explains that the image is used as default thumbnail.' );

$penalis_dfi_batch = Penalis_Media_Usage::summarize_for_user( array( 980 ), 501 );
penalis_same( true, $penalis_dfi_batch[980]['inUse'], 'Penalis_Media_Usage::summarize_for_user() marks the default featured image as in use.' );
penalis_same( false, $penalis_dfi_batch[980]['canDelete'], 'Penalis_Media_Usage::summarize_for_user() blocks deleting the default featured image.' );

delete_option( Penalis_Default_Thumbnail::OPTION_ID );
unset( $GLOBALS['penalis_test_is_admin'] );

/* -------------------------------------------------------------------------
 * Post reactions (Penalis_Reactions) & legacy PLD compatibility
 * ---------------------------------------------------------------------- */

Penalis_Reactions::reset_state();

$penalis_react_post              = new WP_Post();
$penalis_react_post->ID          = 990;
$penalis_react_post->post_author = 501;
$penalis_react_post->post_type   = 'post';
$penalis_react_post->post_status = 'publish';
$penalis_react_post->post_title  = 'Senja di Stasiun';
$penalis_react_post->meta        = array(
	Penalis_Reactions::LEGACY_META_LIKE    => array( '14' ),
	Penalis_Reactions::LEGACY_META_DISLIKE => array( '2' ),
);
$GLOBALS['penalis_test_posts'][990] = $penalis_react_post;

$penalis_react_draft              = new WP_Post();
$penalis_react_draft->ID          = 991;
$penalis_react_draft->post_author = 501;
$penalis_react_draft->post_type   = 'post';
$penalis_react_draft->post_status = 'draft';
$GLOBALS['penalis_test_posts'][991] = $penalis_react_draft;

$penalis_react_page              = new WP_Post();
$penalis_react_page->ID          = 992;
$penalis_react_page->post_author = 1;
$penalis_react_page->post_type   = 'page';
$penalis_react_page->post_status = 'publish';
$GLOBALS['penalis_test_posts'][992] = $penalis_react_page;

// Legacy PLD counts are read transparently before any Penalis reaction has occurred.
penalis_same(
	array(
		'like'    => 14,
		'dislike' => 2,
	),
	Penalis_Reactions::get_counts( 990 ),
	'get_counts() falls back to legacy pld_like_count and pld_dislike_count meta.'
);

// Pure transition rules: initial click, undo, and direct switch.
penalis_same(
	array(
		'next'         => 'like',
		'likeDelta'    => 1,
		'dislikeDelta' => 0,
	),
	Penalis_Reactions::compute_transition( '', 'like' ),
	'Clicking like with no existing reaction increments like by 1.'
);
penalis_same(
	array(
		'next'         => '',
		'likeDelta'    => -1,
		'dislikeDelta' => 0,
	),
	Penalis_Reactions::compute_transition( 'like', 'like' ),
	'Clicking like again undoes the like.'
);
penalis_same(
	array(
		'next'         => 'dislike',
		'likeDelta'    => -1,
		'dislikeDelta' => 1,
	),
	Penalis_Reactions::compute_transition( 'like', 'dislike' ),
	'Clicking dislike while liked switches directly from like to dislike.'
);

// Cookie precedence: penalis_react_{id} wins over legacy pld_{id}, including explicit "none".
penalis_same( 'like', Penalis_Reactions::read_cookie_reaction( 990, array( 'pld_990' => 'like' ) ), 'Legacy PLD cookie is recognized.' );
penalis_same( 'dislike', Penalis_Reactions::read_cookie_reaction( 990, array( 'pld_990' => 'like', 'penalis_react_990' => 'dislike' ) ), 'Penalis reaction cookie overrides legacy PLD cookie.' );
penalis_same( '', Penalis_Reactions::read_cookie_reaction( 990, array( 'pld_990' => 'like', 'penalis_react_990' => 'none' ) ), 'Explicit "none" Penalis cookie suppresses stale legacy PLD cookie.' );

// Applying a reaction carries forward legacy counts, supports switch & undo, and leaves legacy meta untouched.
$penalis_r1 = Penalis_Reactions::apply_reaction( 990, 'like', '' );
penalis_same( 15, $penalis_r1['like'], 'First Penalis like increments from legacy PLD count (14 -> 15).' );
penalis_same( 2, $penalis_r1['dislike'], 'Dislike count carries forward from legacy PLD count (2).' );
penalis_same( 'like', $penalis_r1['reaction'], 'Result records active reaction "like".' );
penalis_same( '14', get_post_meta( 990, Penalis_Reactions::LEGACY_META_LIKE, true ), 'Legacy pld_like_count meta is never modified or deleted.' );

$penalis_r2 = Penalis_Reactions::apply_reaction( 990, 'dislike', 'like' );
penalis_same( 14, $penalis_r2['like'], 'Switching from like to dislike decrements like back to 14.' );
penalis_same( 3, $penalis_r2['dislike'], 'Switching from like to dislike increments dislike to 3.' );
penalis_same( 'dislike', $penalis_r2['reaction'], 'Result records active reaction "dislike".' );

$penalis_r3 = Penalis_Reactions::apply_reaction( 990, 'dislike', 'dislike' );
penalis_same( 14, $penalis_r3['like'], 'Undoing dislike leaves like count unchanged.' );
penalis_same( 2, $penalis_r3['dislike'], 'Undoing dislike decrements dislike back to 2.' );
penalis_same( '', $penalis_r3['reaction'], 'Result records empty active reaction after undo.' );

// Undoing the final legacy like back to 0 does not fall back to legacy PLD count again.
$penalis_zero_post              = new WP_Post();
$penalis_zero_post->ID          = 993;
$penalis_zero_post->post_author = 501;
$penalis_zero_post->post_type   = 'post';
$penalis_zero_post->post_status = 'publish';
$penalis_zero_post->meta        = array(
	Penalis_Reactions::LEGACY_META_LIKE => array( '1' ),
);
$GLOBALS['penalis_test_posts'][993] = $penalis_zero_post;
$penalis_zero_res                   = Penalis_Reactions::apply_reaction( 993, 'like', 'like' );
penalis_same( 0, $penalis_zero_res['like'], 'Undoing the last legacy like brings count to 0.' );
penalis_same( 0, Penalis_Reactions::get_counts( 993 )['like'], 'Stored 0 in _penalis_like_count does not fall back to legacy pld_like_count.' );
penalis_same( 14, Penalis_Author_Archive::get_post_likes( 990 ), 'Penalis_Author_Archive::get_post_likes() returns published post like count.' );

// Author total likes sums published posts across Penalis-native and legacy PLD meta, ignoring drafts.
$penalis_author_like_draft              = new WP_Post();
$penalis_author_like_draft->ID          = 994;
$penalis_author_like_draft->post_author = 888;
$penalis_author_like_draft->post_type   = 'post';
$penalis_author_like_draft->post_status = 'draft';
$penalis_author_like_draft->meta        = array( Penalis_Reactions::LEGACY_META_LIKE => array( '99' ) );
$GLOBALS['penalis_test_posts'][994]     = $penalis_author_like_draft;

$penalis_author_like_pub_a              = new WP_Post();
$penalis_author_like_pub_a->ID          = 998;
$penalis_author_like_pub_a->post_author = 888;
$penalis_author_like_pub_a->post_type   = 'post';
$penalis_author_like_pub_a->post_status = 'publish';
$penalis_author_like_pub_a->meta        = array( Penalis_Reactions::LEGACY_META_LIKE => array( '7' ) );
$GLOBALS['penalis_test_posts'][998]     = $penalis_author_like_pub_a;

$penalis_author_like_pub_b              = new WP_Post();
$penalis_author_like_pub_b->ID          = 999;
$penalis_author_like_pub_b->post_author = 888;
$penalis_author_like_pub_b->post_type   = 'post';
$penalis_author_like_pub_b->post_status = 'publish';
$penalis_author_like_pub_b->meta        = array(
	Penalis_Reactions::META_LIKE        => array( '5' ),
	Penalis_Reactions::LEGACY_META_LIKE => array( '99' ),
);
$GLOBALS['penalis_test_posts'][999]     = $penalis_author_like_pub_b;

penalis_same( 12, Penalis_Reactions::get_author_total_likes( 888 ), 'get_author_total_likes() sums published likes (7 legacy + 5 native) and ignores drafts and superseded legacy meta.' );
penalis_same( 12, Penalis_Author_Archive::get_author_total_likes( 888 ), 'Penalis_Author_Archive::get_author_total_likes() delegates cleanly to Penalis_Reactions.' );
unset( $GLOBALS['penalis_test_posts'][994], $GLOBALS['penalis_test_posts'][998], $GLOBALS['penalis_test_posts'][999] );

// Admin manual override via set_counts() updates atomically, supports zeroing legacy counts, and refuses non-posts.
$penalis_override_ok = Penalis_Reactions::set_counts( 990, 225, 4 );
penalis_same( 225, $penalis_override_ok['like'], 'set_counts() sets explicit like count.' );
penalis_same( 4, $penalis_override_ok['dislike'], 'set_counts() sets explicit dislike count.' );
penalis_same(
	array(
		'like'    => 225,
		'dislike' => 4,
	),
	Penalis_Reactions::get_counts( 990 ),
	'get_counts() reflects admin override values.'
);

$penalis_override_zero = Penalis_Reactions::set_counts( 990, 0, 0 );
penalis_same( 0, $penalis_override_zero['like'], 'set_counts() allows resetting likes to 0.' );
penalis_same( 0, Penalis_Reactions::get_counts( 990 )['like'], 'Resetting likes to 0 overrides legacy pld_like_count.' );

$penalis_override_page = Penalis_Reactions::set_counts( 992, 10, 1 );
penalis_assert( is_wp_error( $penalis_override_page ) && 'penalis_not_found' === $penalis_override_page->get_error_code(), 'set_counts() rejects page post type.' );

// Restore 990 count for subsequent rendering assertions.
Penalis_Reactions::set_counts( 990, 14, 2 );

// Posts list column and Quick Edit markup render for administrators only.
$penalis_prev_user                   = $GLOBALS['penalis_test_current_user'] ?? 0;
$GLOBALS['penalis_test_users'][1]    = new WP_User( 1, array( 'administrator' ) );
$GLOBALS['penalis_test_current_user'] = 1;

$penalis_admin_cols = Penalis_Reactions::register_posts_column(
	array(
		'cb'    => '<input type="checkbox" />',
		'title' => 'Title',
		'date'  => 'Date',
	)
);
penalis_assert( isset( $penalis_admin_cols[ Penalis_Reactions::ADMIN_COLUMN_KEY ] ), 'Administrators receive the Likes / Dislikes column on Posts list.' );

ob_start();
Penalis_Reactions::render_posts_column( Penalis_Reactions::ADMIN_COLUMN_KEY, 990 );
$penalis_col_html = ob_get_clean();
penalis_assert( false !== strpos( $penalis_col_html, 'data-penalis-like="14"' ), 'Posts column embeds current like count for Quick Edit hydration.' );
penalis_assert( false !== strpos( $penalis_col_html, 'data-penalis-dislike="2"' ), 'Posts column embeds current dislike count for Quick Edit hydration.' );

ob_start();
Penalis_Reactions::render_quick_edit_fields( Penalis_Reactions::ADMIN_COLUMN_KEY, 'post' );
$penalis_qe_html = ob_get_clean();
penalis_assert( false !== strpos( $penalis_qe_html, 'name="penalis_reactions_like"' ), 'Quick Edit renders Likes input for administrators.' );
penalis_assert( false !== strpos( $penalis_qe_html, 'name="penalis_reactions_dislike"' ), 'Quick Edit renders Dislikes input for administrators.' );

ob_start();
Penalis_Reactions::render_quick_edit_fields( Penalis_Reactions::ADMIN_COLUMN_KEY, 'page' );
$penalis_qe_page_html = ob_get_clean();
penalis_same( '', $penalis_qe_page_html, 'Quick Edit omits Likes/Dislikes fields on non-post screens.' );

// Non-administrators do not receive Quick Edit override inputs.
$GLOBALS['penalis_test_users'][996]  = new WP_User( 996, array( 'editor' ) );
$GLOBALS['penalis_test_current_user'] = 996;
ob_start();
Penalis_Reactions::render_quick_edit_fields( Penalis_Reactions::ADMIN_COLUMN_KEY, 'post' );
penalis_same( '', ob_get_clean(), 'Quick Edit omits Likes/Dislikes override for non-administrators.' );
unset( $GLOBALS['penalis_test_users'][996] );

$GLOBALS['penalis_test_current_user'] = $penalis_prev_user;

// Unpublished posts, pages, and invalid reaction types are refused.
$penalis_err_draft = Penalis_Reactions::apply_reaction( 991, 'like', '' );
penalis_assert( is_wp_error( $penalis_err_draft ) && 'penalis_not_found' === $penalis_err_draft->get_error_code(), 'Draft posts cannot receive reactions.' );

$penalis_err_page = Penalis_Reactions::apply_reaction( 992, 'like', '' );
penalis_assert( is_wp_error( $penalis_err_page ) && 'penalis_not_found' === $penalis_err_page->get_error_code(), 'Pages cannot receive post reactions.' );

$penalis_err_type = Penalis_Reactions::apply_reaction( 990, 'love', '' );
penalis_assert( is_wp_error( $penalis_err_type ) && 'penalis_invalid_reaction' === $penalis_err_type->get_error_code(), 'Unknown reaction types are rejected.' );

// Controls and unified share bar render cache-safe HTML with counts and endpoint.
$penalis_controls_html = Penalis_Reactions::render_controls( $penalis_react_post, 'bar' );
penalis_assert( false !== strpos( $penalis_controls_html, 'data-penalis-reactions' ), 'render_controls() renders reactions container.' );
penalis_assert( false !== strpos( $penalis_controls_html, 'data-reaction="like"' ), 'render_controls() renders like button.' );
penalis_assert( false !== strpos( $penalis_controls_html, 'data-reaction="dislike"' ), 'render_controls() renders dislike button.' );
penalis_assert( false !== strpos( $penalis_controls_html, 'aria-pressed="false"' ), 'render_controls() keeps server HTML cache-safe without visitor active state.' );
penalis_same( '', Penalis_Reactions::render_shortcode( array( 'id' => 991 ) ), '[penalis_reactions] renders nothing for drafts.' );

Penalis_Article_Share::reset_state();
$GLOBALS['penalis_test_is_singular']     = 'post';
$GLOBALS['penalis_test_in_the_loop']     = true;
$GLOBALS['penalis_test_is_main_query']   = true;
$GLOBALS['penalis_test_is_feed']         = false;
$GLOBALS['penalis_test_current_post_id'] = 990;
$GLOBALS['post']                         = $penalis_react_post;
$penalis_unified_bar                     = Penalis_Article_Share::append_single_post_bar( '<p>Isi karya</p>' );
penalis_assert( false !== strpos( $penalis_unified_bar, 'penalis-article-share-bar__actions' ), 'Unified article bar wraps reactions and share button together.' );
penalis_assert( false !== strpos( $penalis_unified_bar, 'data-penalis-reactions' ), 'Unified article bar includes like/dislike controls.' );
penalis_assert( false !== strpos( $penalis_unified_bar, 'data-penalis-open-card="article"' ), 'Unified article bar retains the Bagikan button.' );

unset(
	$GLOBALS['penalis_test_is_singular'],
	$GLOBALS['penalis_test_in_the_loop'],
	$GLOBALS['penalis_test_is_main_query'],
	$GLOBALS['penalis_test_is_feed'],
	$GLOBALS['penalis_test_current_post_id'],
	$GLOBALS['post']
);

/* -------------------------------------------------------------------------
 * Legacy One User Avatar protection & migration (Penalis_Avatar)
 * ---------------------------------------------------------------------- */

delete_option( Penalis_Avatar::OPTION_DEFAULT_AVATAR_ID );
delete_option( Penalis_Avatar::OPTION_LEGACY_MIGRATED );

$penalis_oua_img                 = new WP_Post();
$penalis_oua_img->ID             = 995;
$penalis_oua_img->post_author    = 1;
$penalis_oua_img->post_type      = 'attachment';
$penalis_oua_img->post_status    = 'inherit';
$penalis_oua_img->post_mime_type = 'image/jpeg';
$GLOBALS['penalis_test_posts'][995] = $penalis_oua_img;

$penalis_oua_default                 = new WP_Post();
$penalis_oua_default->ID             = 996;
$penalis_oua_default->post_author    = 1;
$penalis_oua_default->post_type      = 'attachment';
$penalis_oua_default->post_status    = 'inherit';
$penalis_oua_default->post_mime_type = 'image/webp';
$GLOBALS['penalis_test_posts'][996]  = $penalis_oua_default;

$penalis_oua_user                   = new WP_User( 997, array( 'penalis_writer' ) );
$penalis_oua_user->user_login       = 'penulis_oua';
$penalis_oua_user->display_name     = 'Penulis OUA';
$GLOBALS['penalis_test_users'][997] = $penalis_oua_user;

update_user_meta( 997, Penalis_Avatar::meta_key(), 995 );
update_option( 'avatar_default', 'wp_user_avatar' );
update_option( 'avatar_default_wp_user_avatar', 996 );

// Before migration, status detects both unmigrated user and default avatar.
$penalis_oua_before = Penalis_Avatar::legacy_migration_status();
penalis_assert( $penalis_oua_before['pendingUsers'] >= 1, 'legacy_migration_status() detects unmigrated OUA user avatar.' );
penalis_same( true, $penalis_oua_before['defaultNeedsBackup'], 'legacy_migration_status() detects unmigrated OUA default avatar.' );

// Running migration copies both into Penalis-owned keys without deleting OUA keys.
$penalis_oua_result = Penalis_Avatar::migrate_legacy_avatars();
penalis_assert( $penalis_oua_result['migrated'] >= 1, 'migrate_legacy_avatars() copies OUA user avatar into _penalis_legacy_avatar_id.' );
penalis_same( true, $penalis_oua_result['defaultBackedUp'], 'migrate_legacy_avatars() mirrors default avatar into penalis_default_avatar_id.' );
penalis_same( 995, (int) get_user_meta( 997, Penalis_Avatar::META_LEGACY_ID, true ), '_penalis_legacy_avatar_id stores the migrated attachment ID.' );
penalis_same( 995, (int) get_user_meta( 997, Penalis_Avatar::meta_key(), true ), 'Original OUA user meta is preserved untouched by migration.' );
penalis_same( '1', (string) get_option( Penalis_Avatar::OPTION_LEGACY_MIGRATED, '0' ), 'Migration flag is set so subsequent requests skip the scan.' );

// Simulate One User Avatar uninstall.php deleting OUA usermeta and resetting OUA options.
delete_user_meta( 997, Penalis_Avatar::meta_key() );
delete_option( 'avatar_default_wp_user_avatar' );
update_option( 'avatar_default', 'mystery' );

penalis_same( 995, Penalis_Avatar::get_id( 997 ), 'User avatar survives One User Avatar uninstallation via _penalis_legacy_avatar_id.' );
penalis_same( true, Penalis_Avatar::has_avatar( 997 ), 'has_avatar() remains true after OUA uninstall.' );
penalis_same( 996, Penalis_Avatar::default_avatar_id(), 'Site default avatar survives One User Avatar uninstallation via penalis_default_avatar_id.' );

// Penalis_Media_Usage protects both the backed-up legacy avatar and backed-up default avatar.
$penalis_oua_usage_user = Penalis_Media_Usage::inspect( 995 );
penalis_same( true, $penalis_oua_usage_user['inUse'], 'Penalis_Media_Usage protects attachment referenced by _penalis_legacy_avatar_id.' );
penalis_same( false, $penalis_oua_usage_user['canDelete'], 'Penalis_Media_Usage blocks deleting _penalis_legacy_avatar_id attachment.' );

$penalis_oua_usage_def = Penalis_Media_Usage::inspect( 996 );
penalis_same( true, $penalis_oua_usage_def['inUse'], 'Penalis_Media_Usage protects attachment referenced by penalis_default_avatar_id.' );

// Explicitly clearing user avatar removes both _penalis_legacy_avatar_id and OUA meta.
Penalis_Avatar::clear( 997 );
penalis_same( 0, Penalis_Avatar::get_id( 997 ), 'Penalis_Avatar::clear() detaches _penalis_legacy_avatar_id.' );

// Explicitly clearing site default avatar (stored as "0") returns 0 even if OUA option still exists.
update_option( 'avatar_default', 'wp_user_avatar' );
update_option( 'avatar_default_wp_user_avatar', 996 );
update_option( Penalis_Avatar::OPTION_DEFAULT_AVATAR_ID, '0' );
penalis_same( 0, Penalis_Avatar::default_avatar_id(), 'Explicitly cleared penalis_default_avatar_id ("0") does not fall back to OUA option.' );

delete_option( Penalis_Avatar::OPTION_DEFAULT_AVATAR_ID );
delete_option( Penalis_Avatar::OPTION_LEGACY_MIGRATED );
delete_option( 'avatar_default_wp_user_avatar' );
unset( $GLOBALS['penalis_test_users'][997], $GLOBALS['penalis_test_posts'][995], $GLOBALS['penalis_test_posts'][996] );

/* -------------------------------------------------------------------------
 * Built-in post views counter (Penalis_Views) & Atlas compatibility bridge
 * ---------------------------------------------------------------------- */

$GLOBALS['penalis_test_post_views'] = array();
$GLOBALS['penalis_test_pvc_views']  = array(
	970 => 1065,
	971 => 420,
);
delete_option( Penalis_Views::OPTION_DEDUP_MINUTES );
delete_option( Penalis_Views::OPTION_EXCLUDE_STAFF );
delete_option( Penalis_Views::OPTION_MIGRATED );

$penalis_v_pub_a              = new WP_Post();
$penalis_v_pub_a->ID          = 970;
$penalis_v_pub_a->post_author = 870;
$penalis_v_pub_a->post_type   = 'post';
$penalis_v_pub_a->post_status = 'publish';
$GLOBALS['penalis_test_posts'][970] = $penalis_v_pub_a;

$penalis_v_pub_b              = new WP_Post();
$penalis_v_pub_b->ID          = 971;
$penalis_v_pub_b->post_author = 870;
$penalis_v_pub_b->post_type   = 'post';
$penalis_v_pub_b->post_status = 'publish';
$GLOBALS['penalis_test_posts'][971] = $penalis_v_pub_b;

$penalis_v_draft              = new WP_Post();
$penalis_v_draft->ID          = 972;
$penalis_v_draft->post_author = 870;
$penalis_v_draft->post_type   = 'post';
$penalis_v_draft->post_status = 'draft';
$GLOBALS['penalis_test_posts'][972] = $penalis_v_draft;

$penalis_v_page              = new WP_Post();
$penalis_v_page->ID          = 973;
$penalis_v_page->post_author = 1;
$penalis_v_page->post_type   = 'page';
$penalis_v_page->post_status = 'publish';
$GLOBALS['penalis_test_posts'][973] = $penalis_v_page;

// Settings defaults and sanitization.
penalis_same( 30, Penalis_Views::dedup_minutes(), 'Default view dedup window is 30 minutes.' );
penalis_same( 0, Penalis_Views::sanitize_dedup_minutes( '0' ), '0 minutes is accepted to disable cookie dedup.' );
penalis_same( 0, Penalis_Views::sanitize_dedup_minutes( -5 ), 'Negative dedup minutes clamp to 0.' );
penalis_same( 1440, Penalis_Views::sanitize_dedup_minutes( 9999 ), 'Dedup minutes cap at 1440 (24 hours).' );
penalis_same( 30, Penalis_Views::sanitize_dedup_minutes( 'abc' ), 'Non-numeric dedup input falls back to default 30.' );
penalis_same( true, Penalis_Views::should_exclude_staff(), 'Logged-in staff exclusion is enabled by default.' );

// Staff exclusion excludes administrators & editors, while counting writers & anonymous visitors.
$penalis_v_prev_user                 = $GLOBALS['penalis_test_current_user'] ?? 0;
$GLOBALS['penalis_test_users'][1]    = new WP_User( 1, array( 'administrator' ) );
$GLOBALS['penalis_test_users'][871]  = new WP_User( 871, array( 'editor' ) );
$GLOBALS['penalis_test_users'][872]  = new WP_User( 872, array( 'penalis_writer' ) );
$GLOBALS['penalis_test_current_user'] = 1;
penalis_same( true, Penalis_Views::is_current_visitor_excluded(), 'Administrator visits are excluded by default.' );
$GLOBALS['penalis_test_current_user'] = 871;
penalis_same( true, Penalis_Views::is_current_visitor_excluded(), 'Editor visits are excluded by default.' );
$GLOBALS['penalis_test_current_user'] = 872;
penalis_same( false, Penalis_Views::is_current_visitor_excluded(), 'Active writer visits are counted.' );
$GLOBALS['penalis_test_current_user'] = 0;
penalis_same( false, Penalis_Views::is_current_visitor_excluded(), 'Anonymous visitor visits are counted.' );

// Bot and prefetch filtering.
penalis_same( true, Penalis_Views::is_bot_request( '', array() ), 'Empty User-Agent is treated as a bot.' );
penalis_same( true, Penalis_Views::is_bot_request( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', array() ), 'Googlebot is filtered out.' );
penalis_same( true, Penalis_Views::is_bot_request( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0)', array() ), 'GPTBot is filtered out.' );
penalis_same( true, Penalis_Views::is_bot_request( 'curl/8.5.0', array() ), 'curl client is filtered out.' );
penalis_same( true, Penalis_Views::is_bot_request( 'Mozilla/5.0 (Linux; Android 14)', array( 'HTTP_SEC_PURPOSE' => 'prefetch;prerender' ) ), 'Browser prefetch/prerender probes are filtered out.' );
penalis_same( false, Penalis_Views::is_bot_request( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', array() ), 'Real mobile browser User-Agent is accepted.' );

// Migration from Post Views Counter is non-destructive and never lowers higher Penalis counts.
$penalis_v_before = Penalis_Views::pvc_migration_status();
penalis_same( 2, $penalis_v_before['pendingUpdates'], 'pvc_migration_status() reports pending PVC rows.' );

$penalis_v_mig = Penalis_Views::migrate_from_pvc();
penalis_same( true, $penalis_v_mig['tableFound'], 'migrate_from_pvc() detects PVC table.' );
penalis_same( 2, $penalis_v_mig['importedRows'], 'migrate_from_pvc() imports all lifetime PVC rows.' );
penalis_same( 1065, Penalis_Views::get_views( 970 ), 'Migrated view count matches PVC total.' );
penalis_same( 1485, Penalis_Views::get_author_total_views( 870 ), 'get_author_total_views() sums all published posts for author.' );
penalis_same( 1485, Penalis_Author_Archive::get_author_total_views( 870 ), 'Penalis_Author_Archive::get_author_total_views() reads Penalis_Views.' );

// Incrementing, re-migrating (GREATEST guard), and admin override.
penalis_same( 1066, Penalis_Views::increment_views( 970 ), 'increment_views() increments published post by 1.' );
Penalis_Views::migrate_from_pvc();
penalis_same( 1066, Penalis_Views::get_views( 970 ), 'Re-running migrate_from_pvc() never lowers a higher Penalis view count.' );

$penalis_v_err_draft = Penalis_Views::increment_views( 972 );
penalis_assert( is_wp_error( $penalis_v_err_draft ) && 'penalis_not_found' === $penalis_v_err_draft->get_error_code(), 'Draft posts cannot increment public views.' );
$penalis_v_err_page = Penalis_Views::increment_views( 973 );
penalis_assert( is_wp_error( $penalis_v_err_page ) && 'penalis_not_found' === $penalis_v_err_page->get_error_code(), 'Pages cannot increment post views.' );

$penalis_v_set = Penalis_Views::set_views( 970, 2000 );
penalis_same( 2000, $penalis_v_set['views'], 'set_views() updates post views explicitly.' );
penalis_same( 2000, Penalis_Views::get_views( 970 ), 'get_views() reflects admin override.' );
$penalis_v_set_page = Penalis_Views::set_views( 973, 50 );
penalis_assert( is_wp_error( $penalis_v_set_page ) && 'penalis_not_found' === $penalis_v_set_page->get_error_code(), 'set_views() refuses non-post types.' );

// WP_Query `orderby=post_views` clause rewrite for Atlas theme.
$penalis_v_query = (object) array(
	'query_vars' => array(
		'orderby' => 'post_views',
		'order'   => 'DESC',
	),
);
$penalis_v_clauses = Penalis_Views::filter_posts_clauses(
	array(
		'join'    => '',
		'orderby' => 'wp_posts.post_date DESC',
	),
	$penalis_v_query
);
penalis_assert( false !== strpos( $penalis_v_clauses['join'], 'penalis_post_views AS penalis_pv' ), 'filter_posts_clauses() joins penalis_post_views for orderby=post_views.' );
penalis_assert( false !== strpos( $penalis_v_clauses['orderby'], 'COALESCE(penalis_pv.views, 0) DESC' ), 'filter_posts_clauses() orders by COALESCE(penalis_pv.views, 0) DESC.' );

$penalis_v_other_query = (object) array(
	'query_vars' => array(
		'orderby' => 'date',
		'order'   => 'DESC',
	),
);
$penalis_v_untouched = Penalis_Views::filter_posts_clauses(
	array(
		'join'    => '',
		'orderby' => 'wp_posts.post_date DESC',
	),
	$penalis_v_other_query
);
penalis_same( '', $penalis_v_untouched['join'], 'filter_posts_clauses() leaves non-post_views queries untouched.' );

// Atlas compatibility bridge defines pluggable pvc_get_post_views() and Post_Views_Counter shim.
require_once __DIR__ . '/../includes/pvc-compat-class.php';
require_once __DIR__ . '/../includes/pvc-compat.php';
penalis_assert( class_exists( 'Post_Views_Counter' ) && defined( 'PENALIS_PVC_COMPAT_SHIM' ), 'pvc-compat.php defines minimal Post_Views_Counter shim for Atlas.' );
penalis_same( false, Penalis_Views::is_pvc_active(), 'is_pvc_active() distinguishes Penalis shim from real Post Views Counter plugin.' );
penalis_same( 2000, pvc_get_post_views( 970 ), 'Pluggable pvc_get_post_views() returns single post views from Penalis_Views.' );
penalis_same( 2420, pvc_get_post_views( array( 970, 971 ) ), 'Pluggable pvc_get_post_views() sums array of post IDs from Penalis_Views.' );

// Admin Posts list column and Quick Edit markup for Views (administrators only).
$GLOBALS['penalis_test_current_user'] = 1;
$penalis_v_cols                       = Penalis_Views::register_posts_column(
	array(
		'title' => 'Title',
		'date'  => 'Date',
	)
);
penalis_assert( isset( $penalis_v_cols[ Penalis_Views::ADMIN_COLUMN_KEY ] ), 'Administrators receive the Views column on Posts list.' );

ob_start();
Penalis_Views::render_posts_column( Penalis_Views::ADMIN_COLUMN_KEY, 970 );
$penalis_v_col_html = ob_get_clean();
penalis_assert( false !== strpos( $penalis_v_col_html, 'data-penalis-views="2000"' ), 'Views column embeds current count for Quick Edit hydration.' );

ob_start();
Penalis_Views::render_quick_edit_fields( Penalis_Views::ADMIN_COLUMN_KEY, 'post' );
$penalis_v_qe_html = ob_get_clean();
penalis_assert( false !== strpos( $penalis_v_qe_html, 'name="penalis_views_count"' ), 'Quick Edit renders Views input for administrators.' );

$GLOBALS['penalis_test_current_user'] = 871;
ob_start();
Penalis_Views::render_quick_edit_fields( Penalis_Views::ADMIN_COLUMN_KEY, 'post' );
penalis_same( '', ob_get_clean(), 'Quick Edit omits Views override for non-administrators.' );

$GLOBALS['penalis_test_current_user'] = $penalis_v_prev_user;
unset(
	$GLOBALS['penalis_test_post_views'],
	$GLOBALS['penalis_test_pvc_views'],
	$GLOBALS['penalis_test_posts'][970],
	$GLOBALS['penalis_test_posts'][971],
	$GLOBALS['penalis_test_posts'][972],
	$GLOBALS['penalis_test_posts'][973],
	$GLOBALS['penalis_test_users'][871],
	$GLOBALS['penalis_test_users'][872]
);

/* -------------------------------------------------------------------------
 * Built-in Table of Contents (Penalis_Toc)
 * ---------------------------------------------------------------------- */

delete_option( Penalis_Toc::OPTION_SETTINGS );
Penalis_Toc::reset_state();

$penalis_toc_defaults = Penalis_Toc::get_settings();
penalis_same( true, $penalis_toc_defaults['enabled'], 'TOC auto-insert is enabled by default.' );
penalis_same( 'Table of Contents', $penalis_toc_defaults['title'], 'TOC default box title is Table of Contents.' );
penalis_same( 4, $penalis_toc_defaults['min_headings'], 'TOC default minimum headings is 4.' );
penalis_same( array( 2, 3 ), $penalis_toc_defaults['levels'], 'TOC scans H2 and H3 by default.' );
penalis_same( 'top', $penalis_toc_defaults['position'], 'TOC inserts at top of content by default.' );
penalis_same( true, $penalis_toc_defaults['default_collapsed'], 'TOC is collapsed by default.' );
penalis_same( true, $penalis_toc_defaults['smooth_scroll'], 'TOC smooth scroll is enabled by default.' );

$penalis_toc_sanitized = Penalis_Toc::sanitize_settings(
	array(
		'enabled'           => false,
		'title'             => '   ',
		'min_headings'      => 99,
		'levels'            => array( 1, 4, 2, 4, 6 ),
		'position'          => 'invalid',
		'default_collapsed' => false,
		'smooth_scroll'     => false,
	)
);
penalis_same( false, $penalis_toc_sanitized['enabled'], 'sanitize_settings() preserves false enabled flag.' );
penalis_same( 'Table of Contents', $penalis_toc_sanitized['title'], 'Empty TOC title falls back to default.' );
penalis_same( 20, $penalis_toc_sanitized['min_headings'], 'TOC minimum headings clamps at 20.' );
penalis_same( array( 2, 4 ), $penalis_toc_sanitized['levels'], 'TOC levels filters to allowed H2-H4 and sorts uniquely.' );
penalis_same( 'top', $penalis_toc_sanitized['position'], 'Invalid TOC position falls back to top.' );
penalis_same( false, $penalis_toc_sanitized['default_collapsed'], 'sanitize_settings() allows expanded default.' );

// Slugify headings into lowercase unique anchors.
$penalis_toc_ids = array();
penalis_same( 'pendahuluan-awal', Penalis_Toc::slugify_heading( 'Pendahuluan Awal!', $penalis_toc_ids ), 'slugify_heading() builds lowercase hyphenated slugs.' );
penalis_same( 'pendahuluan-awal-2', Penalis_Toc::slugify_heading( 'Pendahuluan Awal', $penalis_toc_ids ), 'slugify_heading() deduplicates repeated headings with -2.' );
penalis_same( 'bagian', Penalis_Toc::slugify_heading( '!!!', $penalis_toc_ids ), 'slugify_heading() falls back to bagian when no alphanumerics exist.' );

// Content below min_headings stays untouched.
$penalis_short_article = '<p>Pembuka</p><h2>Satu</h2><p>Isi</p><h2>Dua</h2>';
$penalis_short_res     = Penalis_Toc::process_content( $penalis_short_article );
penalis_same( false, $penalis_short_res['rendered'], 'Articles below min_headings do not render a TOC.' );
penalis_same( $penalis_short_article, $penalis_short_res['content'], 'Articles below min_headings are returned untouched.' );

// Content meeting min_headings renders collapsed TOC with nested numbering and lowercase anchors.
$penalis_long_article = '<p>Pengantar cerita.</p>'
	. '<h2 class="wp-block-heading">Bagian Pertama</h2><p>Isi 1.</p>'
	. '<h3>Sub Bagian A</h3><p>Isi 1a.</p>'
	. '<h4>Diabaikan</h4><p>Level 4 dilewati default.</p>'
	. '<h3 id="custom-anchor">Sub Bagian B</h3><p>Isi 1b.</p>'
	. '<h2>Bagian Pertama</h2><p>Penutup.</p>';

$penalis_long_res = Penalis_Toc::process_content( $penalis_long_article );
penalis_same( true, $penalis_long_res['rendered'], 'Articles with >= 4 matching headings render a TOC.' );
penalis_same( 4, count( $penalis_long_res['headings'] ), 'Default levels H2/H3 skip H4.' );
penalis_same( 'bagian-pertama', $penalis_long_res['headings'][0]['id'], 'First H2 gets lowercase slug anchor.' );
penalis_same( '1.', $penalis_long_res['headings'][0]['number'], 'First H2 numbered 1.' );
penalis_same( '1.1.', $penalis_long_res['headings'][1]['number'], 'First H3 numbered 1.1.' );
penalis_same( 'custom-anchor', $penalis_long_res['headings'][2]['id'], 'Existing heading id attribute is preserved.' );
penalis_same( '1.2.', $penalis_long_res['headings'][2]['number'], 'Second H3 numbered 1.2.' );
penalis_same( 'bagian-pertama-2', $penalis_long_res['headings'][3]['id'], 'Duplicate heading gets -2 suffix.' );
penalis_same( '2.', $penalis_long_res['headings'][3]['number'], 'Second H2 numbered 2.' );
penalis_assert( 0 === strpos( $penalis_long_res['content'], '<nav class="penalis-toc is-collapsed"' ), 'Top position prepends collapsed TOC box.' );
penalis_assert( false !== strpos( $penalis_long_res['content'], 'aria-expanded="false"' ), 'Collapsed TOC sets aria-expanded="false".' );
penalis_assert( false !== strpos( $penalis_long_res['content'], 'Tampilkan' ), 'Collapsed TOC renders Tampilkan toggle label.' );
penalis_assert( false !== strpos( $penalis_long_res['content'], 'class="wp-block-heading penalis-toc-heading"' ), 'Heading class merges cleanly with existing classes.' );

// Expanded default + before_first_heading position.
$penalis_expanded_res = Penalis_Toc::process_content(
	$penalis_long_article,
	array(
		'title'             => 'Daftar Isi',
		'position'          => 'before_first_heading',
		'default_collapsed' => false,
	)
);
penalis_assert( 0 === strpos( $penalis_expanded_res['content'], '<p>Pengantar cerita.</p><nav class="penalis-toc is-expanded"' ), 'before_first_heading inserts TOC right before the first matched heading.' );
penalis_assert( false !== strpos( $penalis_expanded_res['content'], 'aria-expanded="true"' ), 'Expanded TOC sets aria-expanded="true".' );
penalis_assert( false !== strpos( $penalis_expanded_res['content'], 'Sembunyikan' ), 'Expanded TOC renders Sembunyikan toggle label.' );
penalis_assert( false !== strpos( $penalis_expanded_res['content'], '>Daftar Isi<' ), 'Custom TOC title is rendered.' );

// Shortcode placeholder placement.
$penalis_sc_article = '<p>Atas</p>' . Penalis_Toc::render_shortcode() . '<h2>Satu</h2><h2>Dua</h2>';
$penalis_sc_res     = Penalis_Toc::process_content(
	$penalis_sc_article,
	array(
		'min_headings'    => 1,
		'has_placeholder' => true,
	)
);
penalis_same( true, $penalis_sc_res['rendered'], 'Shortcode placeholder renders TOC even below auto min_headings.' );
penalis_assert( 0 === strpos( $penalis_sc_res['content'], '<p>Atas</p><nav class="penalis-toc' ), 'Shortcode placeholder places TOC at exact shortcode location.' );

// Per-post override & legacy _lwptoc_settings fallback.
update_post_meta( 980, Penalis_Toc::LEGACY_META_KEY, array( 'enabled' => false, 'processHeadings' => true ) );
penalis_same( true, Penalis_Toc::is_post_disabled( 980 ), 'Legacy _lwptoc_settings enabled=false disables TOC for that post.' );
update_post_meta( 980, Penalis_Toc::META_DISABLED, '0' );
penalis_same( false, Penalis_Toc::is_post_disabled( 980 ), 'Explicit _penalis_toc_disabled=0 overrides legacy disabled flag.' );
update_post_meta( 980, Penalis_Toc::META_DISABLED, '1' );
penalis_same( true, Penalis_Toc::is_post_disabled( 980 ), 'Explicit _penalis_toc_disabled=1 disables TOC.' );
delete_post_meta( 980, Penalis_Toc::META_DISABLED );
delete_post_meta( 980, Penalis_Toc::LEGACY_META_KEY );

// Rank Math TOC SEO integration.
$penalis_rm_plugins = Penalis_Toc::register_rank_math_toc( array() );
penalis_assert( ! empty( $penalis_rm_plugins ), 'Enabled TOC registers Penalis Platform with Rank Math TOC filter.' );
update_option( Penalis_Toc::OPTION_SETTINGS, array( 'enabled' => false ), false );
penalis_same( array(), Penalis_Toc::register_rank_math_toc( array() ), 'Disabled TOC does not register with Rank Math TOC filter.' );
delete_option( Penalis_Toc::OPTION_SETTINGS );

/* -------------------------------------------------------------------------
 * Centralized configuration store (Penalis_Config — Phase 2 high-priority)
 * ---------------------------------------------------------------------- */

penalis_reset();

// 1. General & Pages sanitization and defaults.
$cfg_general_default = Penalis_Config::general();
penalis_same( '/ketentuan-layanan/', $cfg_general_default['terms_url'], 'General config defaults terms URL.' );
penalis_same( '/kebijakan-privasi/', $cfg_general_default['privacy_url'], 'General config defaults privacy URL.' );
penalis_same( true, $cfg_general_default['use_author_archive_template'], 'Author archive template is enabled by default.' );

$cfg_general_clean = Penalis_Config::sanitize_general(
	array(
		'terms_url'                   => 'javascript:alert(1)',
		'privacy_url'                 => '/privasi-baru/',
		'authors_directory_url'       => '/kontributor/',
		'use_author_archive_template' => false,
	)
);
penalis_same( '/ketentuan-layanan/', $cfg_general_clean['terms_url'], 'Unsafe javascript: scheme in terms_url falls back to default.' );
penalis_same( '/privasi-baru/', $cfg_general_clean['privacy_url'], 'Relative site path is accepted for privacy_url.' );
penalis_same( false, $cfg_general_clean['use_author_archive_template'], 'Bundled template toggle can be disabled.' );

update_option( Penalis_Config::OPTION_GENERAL, $cfg_general_clean, false );
penalis_same( false, Penalis_Author_Archive::is_enabled(), 'Author archive template toggle drives Penalis_Author_Archive::is_enabled().' );
penalis_assert( false !== strpos( Penalis_Author_Archive::directory_url(), '/kontributor/' ), 'Configured authors_directory_url drives Penalis_Author_Archive::directory_url().' );

// 2. Email branding sanitization and HTML output.
$cfg_email_clean = Penalis_Config::sanitize_email_branding(
	array(
		'logo_url'      => 'https://penalis.com/logo-baru.png',
		'contact_email' => 'redaksi@penalis.com',
		'footer_text'   => 'Kontak kami di {email_kontak}.',
		'button_color'  => '#123456',
		'link_color'    => 'not-a-color',
	)
);
penalis_same( '#123456', $cfg_email_clean['button_color'], 'Valid hex color is accepted for email CTA buttons.' );
penalis_same( Penalis_Config::DEFAULT_LINK_COLOR, $cfg_email_clean['link_color'], 'Invalid hex color falls back to default link color.' );
update_option( Penalis_Config::OPTION_EMAIL_BRANDING, $cfg_email_clean, false );
$branded_email_html = Penalis_Email_Template::render( "Halo\n\nhttps://penalis.com/dashboard/" );
penalis_assert( false !== strpos( $branded_email_html, 'https://penalis.com/logo-baru.png' ), 'Rendered email uses configured logo URL.' );
penalis_assert( false !== strpos( $branded_email_html, 'background-color:#123456' ), 'Rendered email applies configured button color.' );
penalis_assert( false !== strpos( $branded_email_html, 'mailto:redaksi@penalis.com' ), 'Rendered email replaces {email_kontak} with configured contact email.' );

// 3. Editorial rules & #KalimatHariIni sanitization and clamping.
$cfg_editorial = Penalis_Config::sanitize_editorial(
	array(
		'require_thumbnail'       => false,
		'min_tags'                => 99,
		'draft_revisions'         => 500,
		'allowed_image_types'     => array( 'jpeg', 'svg', 'webp' ),
		'quote_enabled'           => false,
		'quote_max_length'        => 10,
		'quote_max_lines'         => 12,
		'quote_ttl_hours'         => 999,
		'quote_daily_limit'       => 0,
		'quote_removal_presets'   => "Alasan Satu\n\n  Alasan   Dua  \nAlasan Satu",
	)
);
penalis_same( false, $cfg_editorial['require_thumbnail'], 'Editorial thumbnail requirement can be disabled.' );
penalis_same( 10, $cfg_editorial['min_tags'], 'min_tags is clamped at upper bound 10.' );
penalis_same( 100, $cfg_editorial['draft_revisions'], 'draft_revisions is clamped at upper bound 100.' );
penalis_same( array( 'jpeg', 'webp' ), $cfg_editorial['allowed_image_types'], 'Unsafe upload formats like svg are stripped.' );
penalis_same( 40, $cfg_editorial['quote_max_length'], 'quote_max_length is clamped at lower bound 40.' );
penalis_same( 6, $cfg_editorial['quote_max_lines'], 'quote_max_lines is clamped at upper bound 6.' );
penalis_same( 168, $cfg_editorial['quote_ttl_hours'], 'quote_ttl_hours is clamped at upper bound 168.' );
penalis_same( 1, $cfg_editorial['quote_daily_limit'], 'quote_daily_limit is clamped at lower bound 1.' );
penalis_same( array( 'Alasan Satu', 'Alasan Dua' ), $cfg_editorial['quote_removal_presets'], 'Quote removal presets are trimmed, normalized, and deduplicated.' );

update_option( Penalis_Config::OPTION_EDITORIAL, $cfg_editorial, false );
penalis_editorial_writer( 10 );
$disabled_quote_res = Penalis_Daily_Quotes::publish( 10, 'Halo dunia.' );
penalis_assert( is_wp_error( $disabled_quote_res ) && 'penalis_quote_disabled' === $disabled_quote_res->get_error_code(), 'Publishing #KalimatHariIni is blocked when quote_enabled is false.' );

// 4. Article display (Share bar & Reactions modes).
$cfg_display = Penalis_Config::sanitize_article_display(
	array(
		'auto_append_share_bar' => true,
		'share_bar_title'       => 'Apresiasi Karya Ini',
		'reactions_mode'        => 'like_only',
		'reactions_cookie_days' => 9999,
	)
);
penalis_same( 'like_only', $cfg_display['reactions_mode'], 'Reactions mode accepts like_only.' );
penalis_same( 365, $cfg_display['reactions_cookie_days'], 'Reactions cookie days is clamped to 365.' );
update_option( Penalis_Config::OPTION_ARTICLE_DISPLAY, $cfg_display, false );

$react_post = penalis_editorial_post( 991, 10, 'publish', Penalis_Editorial_Policy::STATE_PUBLISHED );
$like_only_html = Penalis_Reactions::render_controls( $react_post, 'bar' );
penalis_assert( false !== strpos( $like_only_html, 'data-reaction="like"' ), 'like_only mode still renders the Like button.' );
penalis_assert( false === strpos( $like_only_html, 'data-reaction="dislike"' ), 'like_only mode omits the Dislike button.' );
$dislike_blocked = Penalis_Reactions::apply_reaction( 991, 'dislike', '' );
penalis_assert( is_wp_error( $dislike_blocked ) && 'penalis_invalid_reaction' === $dislike_blocked->get_error_code(), 'like_only mode rejects dislike requests server-side.' );

update_option( Penalis_Config::OPTION_ARTICLE_DISPLAY, array( 'reactions_mode' => 'off' ), false );
penalis_same( '', Penalis_Reactions::render_controls( $react_post, 'bar' ), 'off mode suppresses reaction controls completely.' );
$off_blocked = Penalis_Reactions::apply_reaction( 991, 'like', '' );
penalis_assert( is_wp_error( $off_blocked ) && 'penalis_reactions_disabled' === $off_blocked->get_error_code(), 'off mode rejects all reaction requests server-side.' );

// 5. Phase 3: Account policy clamping & enforcement.
$cfg_account = Penalis_Config::sanitize_account(
	array(
		'min_password'          => 3,
		'max_bio'               => 5000,
		'avatar_max_mb'         => 50,
		'avatar_output_size'    => 999,
		'avatar_output_quality' => 10,
		'avatar_min_edge'       => 10,
	)
);
penalis_same( 8, $cfg_account['min_password'], 'min_password hard-clamps at lower bound 8.' );
penalis_same( 1000, $cfg_account['max_bio'], 'max_bio hard-clamps at upper bound 1000.' );
penalis_same( 10, $cfg_account['avatar_max_mb'], 'avatar_max_mb hard-clamps at upper bound 10.' );
penalis_same( 512, $cfg_account['avatar_output_size'], 'avatar_output_size hard-clamps at upper bound 512.' );
penalis_same( 60, $cfg_account['avatar_output_quality'], 'avatar_output_quality hard-clamps at lower bound 60.' );
penalis_same( 50, $cfg_account['avatar_min_edge'], 'avatar_min_edge hard-clamps at lower bound 50.' );

update_option( Penalis_Config::OPTION_ACCOUNT, array( 'min_password' => 10, 'max_bio' => 120 ), false );
penalis_same( '', Penalis_Policy::validate_password( '1234567890' ), 'Configured min_password=10 accepts a 10-char password.' );
penalis_assert( '' !== Penalis_Policy::validate_password( '123456789' ), 'Configured min_password=10 rejects a 9-char password.' );
$bio_err = Penalis_Policy::validate_profile( array( 'description' => str_repeat( 'a', 121 ) ) );
penalis_assert( isset( $bio_err['description'] ), 'Configured max_bio=120 enforces the 120-char bio limit.' );

// 6. Phase 3: Security (Turnstile per-form, OTP, CDN override) clamping & runtime wiring.
$cfg_security = Penalis_Config::sanitize_security(
	array(
		'turnstile_forms'  => array( 'login' => true, 'register' => false ),
		'otp_ttl_minutes'  => 1,
		'otp_resend_wait'  => 5,
		'otp_max_attempts' => 99,
		'otp_max_issues'   => 12,
		'otp_daily_issues' => 5,
		'asset_base_url'   => 'https://cdn.penalis.com/build/',
	)
);
penalis_same( true, $cfg_security['turnstile_forms']['login'], 'Turnstile per-form preserves enabled login form.' );
penalis_same( false, $cfg_security['turnstile_forms']['register'], 'Turnstile per-form allows disabling register form.' );
penalis_same( 3, $cfg_security['otp_ttl_minutes'], 'otp_ttl_minutes hard-clamps at lower bound 3.' );
penalis_same( 30, $cfg_security['otp_resend_wait'], 'otp_resend_wait hard-clamps at lower bound 30.' );
penalis_same( 10, $cfg_security['otp_max_attempts'], 'otp_max_attempts hard-clamps at upper bound 10.' );
penalis_same( 12, $cfg_security['otp_daily_issues'], 'otp_daily_issues never drops below otp_max_issues.' );
penalis_same( 'https://cdn.penalis.com/build', $cfg_security['asset_base_url'], 'asset_base_url trims trailing slash.' );

update_option( Penalis_Config::OPTION_SECURITY, $cfg_security, false );
$otp_resp = Penalis_Otp::response( 'test-id' );
penalis_same( 180, $otp_resp['expires_in'], 'OTP response reflects configured otp_ttl_minutes (3 min = 180s).' );
penalis_same( 30, $otp_resp['resend_after'], 'OTP response reflects configured otp_resend_wait.' );
penalis_same( 10, $otp_resp['max_attempts'], 'OTP response reflects configured otp_max_attempts.' );
penalis_same( 'https://cdn.penalis.com/build/', Penalis_Dashboard::asset_base_url(), 'Configured asset_base_url overrides dashboard asset base URL.' );

// 7. Phase 3: Performance (cache TTLs, log retention, analytics) clamping & defaults.
$cfg_perf = Penalis_Config::sanitize_performance(
	array(
		'authors_cache_ttl'     => 99999,
		'authors_per_page'      => 5,
		'quotes_cache_ttl'      => 0,
		'email_log_limit'       => 5,
		'notification_limit'    => 999,
		'review_history_limit'  => 999,
		'account_log_limit'     => 5,
		'analytics_weeks'       => 50,
		'analytics_top_authors' => 1,
	)
);
penalis_same( 3600, $cfg_perf['authors_cache_ttl'], 'authors_cache_ttl clamps at 3600.' );
penalis_same( 12, $cfg_perf['authors_per_page'], 'authors_per_page clamps at 12.' );
penalis_same( 0, $cfg_perf['quotes_cache_ttl'], 'quotes_cache_ttl allows 0 to disable caching.' );
penalis_same( 20, $cfg_perf['email_log_limit'], 'email_log_limit clamps at 20.' );
penalis_same( 200, $cfg_perf['notification_limit'], 'notification_limit clamps at 200.' );
penalis_same( 200, $cfg_perf['review_history_limit'], 'review_history_limit clamps at 200.' );
penalis_same( 10, $cfg_perf['account_log_limit'], 'account_log_limit clamps at 10.' );
penalis_same( 26, $cfg_perf['analytics_weeks'], 'analytics_weeks clamps at 26.' );
penalis_same( 3, $cfg_perf['analytics_top_authors'], 'analytics_top_authors clamps at 3.' );
penalis_assert( count( Penalis_Config::all_option_names() ) === 14, 'Penalis_Config exports all 7 option groups plus 7 audit keys for uninstall.' );
penalis_reset();

/* -------------------------------------------------------------------------
 * Report
 * ---------------------------------------------------------------------- */

$assertions = (int) $GLOBALS['penalis_assertions'];
$failures   = (int) $GLOBALS['penalis_failures'];

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} of {$assertions} assertions failed.\n" );
	exit( 1 );
}

echo "OK: {$assertions} assertions passed.\n";
exit( 0 );
