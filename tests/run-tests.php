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
require_once __DIR__ . '/../includes/class-policy.php';
require_once __DIR__ . '/../includes/class-editorial-policy.php';
require_once __DIR__ . '/../includes/class-roles.php';
require_once __DIR__ . '/../includes/class-account-status.php';
require_once __DIR__ . '/../includes/class-editorial-workflow.php';
require_once __DIR__ . '/../includes/class-editorial-notifications.php';
require_once __DIR__ . '/../includes/class-admin.php';
require_once __DIR__ . '/../includes/class-editorial-admin.php';
require_once __DIR__ . '/../includes/class-access-control.php';
require_once __DIR__ . '/../includes/class-rest-api.php';
require_once __DIR__ . '/../includes/class-avatar.php';
require_once __DIR__ . '/../includes/class-dashboard.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-activator.php';
require_once __DIR__ . '/../includes/class-email-template.php';
require_once __DIR__ . '/../includes/class-emails.php';
require_once __DIR__ . '/../includes/class-author-history.php';
require_once __DIR__ . '/../includes/class-username-change.php';
require_once __DIR__ . '/../includes/class-password-reset.php';
require_once __DIR__ . '/../includes/class-registration.php';
require_once __DIR__ . '/../includes/class-legacy-activation.php';
require_once __DIR__ . '/../includes/class-otp.php';
require_once __DIR__ . '/../includes/class-email-change.php';

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
	$GLOBALS['penalis_test_post_updates']    = array();
	$GLOBALS['penalis_test_password_notifications'] = array();
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
		$GLOBALS['penalis_test_mail_fails']
	);
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
$errors = Penalis_Policy::validate_profile( array( 'description' => str_repeat( 'a', 501 ) ) );
penalis_assert( isset( $errors['description'] ), 'Overlong bio is rejected.' );

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
 * Avatar identifier resolution
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][12] = new WP_User( 12, array( 'author' ) );

penalis_same( 12, Penalis_Avatar::resolve_user_id( 12 ), 'Numeric identifiers resolve.' );
penalis_same( 12, Penalis_Avatar::resolve_user_id( '12' ), 'Numeric strings resolve.' );
penalis_same( 12, Penalis_Avatar::resolve_user_id( new WP_User( 12 ) ), 'WP_User objects resolve.' );
penalis_same( 12, Penalis_Avatar::resolve_user_id( 'user12@example.com' ), 'Email addresses resolve.' );
penalis_same( 0, Penalis_Avatar::resolve_user_id( 'not-an-email' ), 'Unknown strings resolve to zero.' );
penalis_same( 0, Penalis_Avatar::resolve_user_id( 'nobody@example.com' ), 'Unknown emails resolve to zero.' );

$penalis_post            = new WP_Post();
$penalis_post->post_author = 12;
penalis_same( 12, Penalis_Avatar::resolve_user_id( $penalis_post ), 'Post authors resolve.' );

$penalis_comment           = new WP_Comment();
$penalis_comment->user_id  = 12;
penalis_same( 12, Penalis_Avatar::resolve_user_id( $penalis_comment ), 'Comment authors resolve.' );

penalis_same( true, Penalis_Avatar::is_provider(), 'The built-in provider is active without One User Avatar.' );

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
	false !== strpos( $penalis_module_tag, 'src="https://example.com/dashboard.js"' ),
	'The module tag keeps the enqueued source.'
);
penalis_assert(
	false !== strpos( $penalis_module_tag, 'id="penalis-dashboard-js"' ),
	'The module tag keeps the handle id so dependent localisations still resolve.'
);

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

$GLOBALS['penalis_test_current_user'] = 20;
penalis_assert( true === Penalis_Rest_Api::require_logged_in(), 'An administrator may reach the dashboard API.' );
$admin_account = Penalis_Rest_Api::get_account()->get_data();
penalis_assert( true === $admin_account['isReviewer'], 'The account payload marks an administrator as a reviewer.' );
penalis_same( 'Administrator', $admin_account['statusLabel'], 'An administrator keeps the Administrator label.' );

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
penalis_assert( ! penalis_cap_denied( 'delete_post', 10, 101 ), 'A writer may still delete their own published article.' );
penalis_assert( penalis_cap_denied( 'delete_post', 10, 103 ), 'A writer may not delete another writer\'s article.' );
penalis_assert( ! penalis_cap_denied( 'edit_post', 10, 310 ), 'A writer may edit their own attachment.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 311 ), 'A writer may not edit another writer\'s attachment.' );
penalis_assert( penalis_cap_denied( 'edit_post', 10, 400 ), 'A writer may not reach a page through a meta capability.' );

$GLOBALS['penalis_test_users'][20] = new WP_User( 20, array( 'administrator' ) );
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'editor' ) );
penalis_assert( ! penalis_cap_denied( 'edit_post', 20, 101 ), 'An administrator may edit a published article.' );
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

$draft_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_DRAFT, 1, 10 );
penalis_same( array( 'draft' ), $draft_args['post_status'], 'The draft filter queries draft posts.' );
penalis_same( 'OR', $draft_args['meta_query']['relation'], 'The draft filter matches both legacy drafts without meta and explicit draft state.' );

$changes_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, 1, 10 );
penalis_same( array( 'draft' ), $changes_args['post_status'], 'Requested changes use the draft post status.' );
penalis_same( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, $changes_args['meta_query'][0]['value'], 'Requested changes filter by editorial state meta.' );

$rejected_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_REJECTED, 1, 10 );
penalis_same( array( 'draft' ), $rejected_args['post_status'], 'Rejected submissions use the draft post status.' );
penalis_same( Penalis_Editorial_Policy::STATE_REJECTED, $rejected_args['meta_query'][0]['value'], 'Rejected submissions filter by editorial state meta.' );

$pending_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_PENDING_REVIEW, 1, 10 );
penalis_same( array( 'pending' ), $pending_args['post_status'], 'Pending review queries pending posts.' );

$published_args = Penalis_Rest_Api::editorial_query_args( 10, Penalis_Editorial_Policy::STATE_PUBLISHED, 1, 10 );
penalis_same( array( 'publish' ), $published_args['post_status'], 'Published queries publish posts.' );

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
$admin_review = Penalis_Rest_Api::get_article_review( new WP_REST_Request( 'GET', '/penalis/v1/articles/500/review', array( 'id' => 500 ) ) );
penalis_assert( $admin_review instanceof WP_REST_Response, 'An administrator may read a pending submission review.' );
penalis_assert( false === $admin_review->get_data()['canSubmit'], 'A non-owner administrator does not get writer submit flags.' );

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
