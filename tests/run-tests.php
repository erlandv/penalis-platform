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
require_once __DIR__ . '/../includes/class-avatar.php';
require_once __DIR__ . '/../includes/class-dashboard.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-activator.php';
require_once __DIR__ . '/../includes/class-email-template.php';
require_once __DIR__ . '/../includes/class-emails.php';
require_once __DIR__ . '/../includes/class-author-history.php';
require_once __DIR__ . '/../includes/class-username-change.php';
require_once __DIR__ . '/../includes/class-password-reset.php';

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
	unset(
		$GLOBALS['penalis_test_sessions_destroyed'],
		$GLOBALS['penalis_test_auth_cookie_cleared'],
		$GLOBALS['penalis_test_duplicate_login'],
		$GLOBALS['penalis_test_lock_available'],
		$GLOBALS['penalis_test_update_fails'],
		$GLOBALS['penalis_test_verified_user'],
		$GLOBALS['penalis_test_updated_login']
	);
}

/* -------------------------------------------------------------------------
 * Policy: statuses and transitions
 * ---------------------------------------------------------------------- */

$statuses = Penalis_Policy::statuses();
penalis_same( 5, count( $statuses ), 'There are five account statuses.' );
penalis_assert( Penalis_Policy::is_valid_status( 'active' ), 'active is valid.' );
penalis_assert( ! Penalis_Policy::is_valid_status( 'administrator' ), 'administrator is not an account status.' );
penalis_assert( ! Penalis_Policy::is_valid_status( null ), 'null is not an account status.' );

penalis_assert(
	Penalis_Policy::can_transition( 'pending_verification', 'pending_approval' ),
	'Verification advances to pending approval.'
);
penalis_assert(
	Penalis_Policy::can_transition( 'pending_approval', 'active' ),
	'Pending approval advances to active.'
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
	! Penalis_Policy::can_transition( 'pending_verification', 'active' ),
	'Unverified accounts cannot jump to active.'
);
penalis_assert(
	! Penalis_Policy::can_transition( 'rejected', 'active' ),
	'Rejected accounts cannot be approved directly.'
);
penalis_assert(
	! Penalis_Policy::can_transition( 'active', 'active' ),
	'Same-state transitions are rejected.'
);

/* -------------------------------------------------------------------------
 * Policy: authorization decisions
 * ---------------------------------------------------------------------- */

penalis_assert( Penalis_Policy::can_login( 'pending_verification', false ), 'Unverified accounts may sign in to view status.' );
penalis_assert( Penalis_Policy::can_login( 'pending_approval', false ), 'Pending accounts may sign in to view status.' );
penalis_assert( Penalis_Policy::can_login( 'active', true ), 'Active accounts with access may sign in.' );
penalis_assert( ! Penalis_Policy::can_login( 'active', false ), 'Active accounts without access may not sign in.' );
penalis_assert( ! Penalis_Policy::can_login( 'suspended', true ), 'Suspended accounts may never sign in.' );
penalis_assert( ! Penalis_Policy::can_login( 'rejected', true ), 'Rejected accounts may never sign in.' );
penalis_assert( Penalis_Policy::can_login( '', true ), 'Legacy account with explicit access may sign in.' );
penalis_assert( ! Penalis_Policy::can_login( '', false ), 'Legacy account without access may not sign in.' );

penalis_assert( Penalis_Policy::can_write( 'active', true ), 'Active writers may write.' );
penalis_assert( ! Penalis_Policy::can_write( 'pending_approval', true ), 'Pending writers may not write.' );
penalis_assert( ! Penalis_Policy::can_write( 'suspended', true ), 'Suspended writers may not write.' );
penalis_assert( ! Penalis_Policy::can_write( 'active', false ), 'Writers without access may not write.' );

penalis_assert( Penalis_Policy::can_reset_password( 'pending_verification', false ), 'Unverified accounts may reset their password.' );
penalis_assert( Penalis_Policy::can_reset_password( 'pending_approval', false ), 'Pending accounts may reset their password.' );
penalis_assert( Penalis_Policy::can_reset_password( 'active', true ), 'Active accounts with access may reset their password.' );
penalis_assert( ! Penalis_Policy::can_reset_password( 'active', false ), 'Active accounts without access may not reset their password.' );
penalis_assert( ! Penalis_Policy::can_reset_password( 'suspended', true ), 'Suspended accounts may not reset their password.' );
penalis_assert( ! Penalis_Policy::can_reset_password( 'rejected', true ), 'Rejected accounts may not reset their password.' );
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
penalis_assert( Penalis_Policy::is_token_format_valid( $token ), 'Generated token format is valid.' );
penalis_assert( strlen( $token ) >= 40 && strlen( $token ) <= 64, 'Generated token length is in range.' );
penalis_assert( $token !== Penalis_Policy::generate_token(), 'Tokens are unique.' );
penalis_assert( ! Penalis_Policy::is_token_format_valid( 'short' ), 'Short token is rejected.' );
penalis_assert( ! Penalis_Policy::is_token_format_valid( str_repeat( '!', 50 ) ), 'Invalid characters are rejected.' );

$hash = Penalis_Policy::hash_token( $token );
penalis_same( 64, strlen( $hash ), 'Token hash is SHA-256 hex.' );
penalis_assert( $hash !== $token, 'Raw token is not stored as the hash.' );

penalis_assert( Penalis_Policy::is_token_expired( 100, 101 ), 'Past expiry is expired.' );
penalis_assert( ! Penalis_Policy::is_token_expired( 101, 100 ), 'Future expiry is valid.' );
penalis_assert( Penalis_Policy::can_resend_verification( 0, 100 ), 'First issue may resend.' );
penalis_assert( ! Penalis_Policy::can_resend_verification( 100, 200 ), 'Resend within the window is throttled.' );
penalis_assert( Penalis_Policy::can_resend_verification( 100, 100 + Penalis_Policy::TOKEN_RESEND_WAIT ), 'Resend after the window is allowed.' );

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

$token = Penalis_Account_Status::issue_verification_token( 1 );
penalis_assert( is_string( $token ), 'Verification token is issued.' );
penalis_same( 1, Penalis_Account_Status::find_user_by_token( $token ), 'Token lookup finds the account.' );
penalis_same( 0, Penalis_Account_Status::find_user_by_token( 'not-a-token' ), 'Invalid token lookup returns zero.' );

$result = Penalis_Account_Status::transition( 1, 'active', 'skip_verification', 99 );
penalis_assert( is_wp_error( $result ), 'Unverified account cannot skip to active.' );

$result = Penalis_Account_Status::transition( 1, 'pending_approval', 'email_verified', 0 );
penalis_assert( true === $result, 'Verification transitions to pending approval.' );
penalis_same( 'pending_approval', Penalis_Account_Status::get_status( 1 ), 'Status is pending approval.' );
penalis_same( '', (string) get_user_meta( 1, Penalis_Account_Status::META_TOKEN_HASH, true ), 'Token is cleared after verification.' );
penalis_assert( ! Penalis_Account_Status::can_write( 1 ), 'Pending approval account cannot write.' );

$result = Penalis_Account_Status::transition( 1, 'active', 'admin_approved', 2 );
penalis_assert( true === $result, 'Pending approval transitions to active.' );
penalis_same( 'active', Penalis_Account_Status::get_status( 1 ), 'Status is active.' );
penalis_assert( Penalis_Account_Status::is_access_enabled( 1 ), 'Approved account has access.' );
penalis_assert( Penalis_Account_Status::can_write( 1 ), 'Approved account can write.' );
penalis_assert( in_array( 'penalis_writer', $GLOBALS['penalis_test_users'][1]->roles, true ), 'Approved account receives the writer role.' );
penalis_same( 2, (int) get_user_meta( 1, Penalis_Account_Status::META_APPROVED_BY, true ), 'Approver is recorded.' );

$result = Penalis_Account_Status::transition( 1, 'suspended', 'admin_suspended', 2 );
penalis_assert( true === $result, 'Active account can be suspended.' );
penalis_assert( ! Penalis_Account_Status::is_access_enabled( 1 ), 'Suspended account loses access.' );
penalis_assert( ! Penalis_Account_Status::can_write( 1 ), 'Suspended account cannot write.' );
penalis_assert( ! Penalis_Account_Status::can_login( 1 ), 'Suspended account cannot sign in.' );
penalis_assert( in_array( 'subscriber', $GLOBALS['penalis_test_users'][1]->roles, true ), 'Suspended account loses the writer role.' );

$result = Penalis_Account_Status::transition( 1, 'active', 'admin_reactivated', 2 );
penalis_assert( true === $result, 'Suspended account can be reactivated.' );
penalis_assert( Penalis_Account_Status::can_write( 1 ), 'Reactivated account can write.' );

$result = Penalis_Account_Status::transition( 1, 'pending_verification', 'invalid', 2 );
penalis_assert( is_wp_error( $result ), 'Active account cannot return to unverified.' );

$log = Penalis_Account_Status::get_log( 1 );
penalis_assert( count( $log ) > 0, 'Status changes are audited.' );
penalis_assert( count( $log ) <= Penalis_Account_Status::LOG_LIMIT, 'Audit log is bounded.' );

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
penalis_assert( Penalis_Policy::can_convert_legacy( 'pending_approval' ), 'Legacy accounts may skip to pending approval when an admin vouches.' );
penalis_assert( ! Penalis_Policy::can_convert_legacy( 'active' ), 'Legacy accounts can never be converted straight to active.' );
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
$result = Penalis_Account_Status::convert_legacy( 5, 'pending_approval', 'admin_vouched', 2, true );
penalis_assert( true === $result, 'A legacy account can be converted to pending approval.' );
penalis_same( 'pending_approval', Penalis_Account_Status::get_status( 5 ), 'Converted account is pending approval.' );
penalis_assert( ! Penalis_Account_Status::is_access_enabled( 5 ), 'Converted account has no access before approval.' );
penalis_assert( ! Penalis_Account_Status::can_write( 5 ), 'Converted account cannot write before approval.' );
penalis_assert( Penalis_Account_Status::is_managed_account( 5 ), 'Converted account is now plugin-managed.' );
penalis_assert( ! Penalis_Account_Status::is_legacy_account( 5 ), 'Converted account is no longer legacy.' );
penalis_same( '1', (string) get_user_meta( 5, Penalis_Account_Status::META_VERIFIED, true ), 'Vouched conversion marks the email verified.' );
penalis_assert( Penalis_Account_Status::needs_password( 5 ), 'A vouched legacy account still needs a password.' );

Penalis_Account_Status::clear_needs_password( 5 );
penalis_assert( ! Penalis_Account_Status::needs_password( 5 ), 'Setting a password clears the flag.' );

$result = Penalis_Account_Status::convert_legacy( 5, 'pending_approval', 'again', 2 );
penalis_assert( is_wp_error( $result ), 'A converted account cannot be converted twice.' );

$result = Penalis_Account_Status::transition( 5, 'active', 'admin_approved', 2 );
penalis_assert( true === $result, 'A converted legacy account can be approved.' );
penalis_assert( Penalis_Account_Status::can_write( 5 ), 'Approved legacy account can write.' );
penalis_assert( in_array( 'penalis_writer', $GLOBALS['penalis_test_users'][5]->roles, true ), 'Approved legacy account receives the writer role.' );

// Regression: self-service activation sets a password before converting, so
// the account must not be flagged as still needing one.
penalis_reset();
$GLOBALS['penalis_test_users'][10] = new WP_User( 10, array( 'contributor' ) );
$result = Penalis_Account_Status::convert_legacy( 10, 'pending_approval', 'legacy_self_activated', 0, false );
penalis_assert( true === $result, 'Self-service activation converts to pending approval.' );
penalis_assert( ! Penalis_Account_Status::needs_password( 10 ), 'Self-service activation does not flag the account as needing a password.' );
penalis_same( '1', (string) get_user_meta( 10, Penalis_Account_Status::META_VERIFIED, true ), 'Self-service activation marks the email verified.' );

// A stale flag from an earlier state is cleared by a self-service conversion.
penalis_reset();
$GLOBALS['penalis_test_users'][11] = new WP_User( 11, array( 'contributor' ) );
update_user_meta( 11, Penalis_Account_Status::META_NEEDS_PASSWORD, '1' );
$result = Penalis_Account_Status::convert_legacy( 11, 'pending_approval', 'legacy_self_activated', 0, false );
penalis_assert( true === $result, 'Conversion succeeds with a stale flag present.' );
penalis_assert( ! Penalis_Account_Status::needs_password( 11 ), 'Self-service conversion clears a stale needs-password flag.' );

// A legacy account converted through email verification starts unverified.
penalis_reset();
$GLOBALS['penalis_test_users'][7] = new WP_User( 7, array( 'contributor' ) );
$result = Penalis_Account_Status::convert_legacy( 7, 'pending_verification', 'legacy_self_activated', 0 );
penalis_assert( true === $result, 'A legacy account can start at email verification.' );
penalis_same( '0', (string) get_user_meta( 7, Penalis_Account_Status::META_VERIFIED, true ), 'Self-service conversion leaves the email unverified.' );
penalis_assert( ! Penalis_Account_Status::can_write( 7 ), 'Unverified converted account cannot write.' );
penalis_assert( ! Penalis_Account_Status::needs_password( 7 ), 'Email-verification conversion does not flag a missing password.' );

// Non-legacy accounts cannot be converted.
penalis_reset();
$GLOBALS['penalis_test_users'][8] = new WP_User( 8, array( 'subscriber' ) );
penalis_assert( ! Penalis_Account_Status::is_legacy_account( 8 ), 'A subscriber is not a legacy account.' );
$result = Penalis_Account_Status::convert_legacy( 8, 'pending_approval', 'nope', 2 );
penalis_assert( is_wp_error( $result ), 'A non-legacy account cannot be converted.' );

// Editors and administrators are never treated as legacy accounts.
penalis_reset();
$GLOBALS['penalis_test_users'][9] = new WP_User( 9, array( 'editor' ) );
penalis_assert( ! Penalis_Account_Status::is_legacy_account( 9 ), 'An editor is not a legacy account.' );

// Rejected accounts cannot log in.
penalis_reset();
$GLOBALS['penalis_test_users'][6] = new WP_User( 6, array( 'subscriber' ) );
Penalis_Account_Status::initialize_pending( 6 );
Penalis_Account_Status::transition( 6, 'rejected', 'admin_rejected', 2 );
penalis_assert( ! Penalis_Account_Status::can_login( 6 ), 'Rejected account cannot sign in.' );
penalis_assert( ! Penalis_Account_Status::can_write( 6 ), 'Rejected account cannot write.' );

// A rejected application can be reopened for review, but not approved directly.
penalis_assert( Penalis_Policy::can_transition( 'rejected', 'pending_approval' ), 'Rejected applications can be reopened.' );
penalis_assert( ! Penalis_Policy::can_transition( 'rejected', 'active' ), 'Rejected applications cannot be approved directly.' );
penalis_assert( true === Penalis_Account_Status::transition( 6, 'pending_approval', 'admin_reopened', 2 ), 'Reopening a rejected application succeeds.' );
penalis_assert( ! Penalis_Account_Status::can_write( 6 ), 'Reopened application still cannot write.' );

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
	Penalis_Policy::can_change_email( 'pending_approval', false ),
	'Pending-approval accounts may change their email address.'
);
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
	! Penalis_Policy::can_change_email( 'rejected', true ),
	'Rejected accounts may not change their email address.'
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
 * Email change token storage
 * ---------------------------------------------------------------------- */

penalis_reset();
$GLOBALS['penalis_test_users'][21] = new WP_User( 21, array( 'penalis_writer' ) );

$penalis_email_token = Penalis_Account_Status::issue_email_change_token( 21, 'baru@example.com' );
penalis_assert( is_string( $penalis_email_token ), 'An email change token is issued.' );
penalis_assert( Penalis_Policy::is_token_format_valid( $penalis_email_token ), 'The issued token has the plugin token shape.' );
penalis_same( 21, Penalis_Account_Status::find_user_by_email_change_token( $penalis_email_token ), 'The token resolves to its owner.' );
penalis_same( 0, Penalis_Account_Status::find_user_by_email_change_token( 'x' ), 'Malformed tokens resolve to nobody.' );

$penalis_pending = Penalis_Account_Status::get_email_change( 21 );
penalis_same( 'baru@example.com', $penalis_pending['email'], 'The pending address is stored.' );
penalis_assert( $penalis_pending['expires'] > time(), 'The pending change has a future expiry.' );

// The raw token must never be stored; only its hash is.
penalis_assert(
	Penalis_Policy::hash_token( $penalis_email_token ) !== $penalis_email_token,
	'The stored value is a hash, not the raw token.'
);
penalis_same(
	Penalis_Policy::hash_token( $penalis_email_token ),
	(string) get_user_meta( 21, Penalis_Account_Status::META_EMAIL_CHANGE_HASH, true ),
	'Only the token hash is persisted.'
);

Penalis_Account_Status::clear_email_change( 21 );
penalis_same( '', Penalis_Account_Status::get_email_change( 21 )['email'], 'Clearing removes the pending address.' );
penalis_same( 0, Penalis_Account_Status::find_user_by_email_change_token( $penalis_email_token ), 'A cleared token no longer resolves.' );

// An email change token must not be usable as a registration token.
$penalis_registration_token = Penalis_Account_Status::issue_verification_token( 21 );
penalis_same( 0, Penalis_Account_Status::find_user_by_email_change_token( $penalis_registration_token ), 'Registration tokens cannot confirm an email change.' );
penalis_same( 21, Penalis_Account_Status::find_user_by_token( $penalis_registration_token ), 'Registration tokens still resolve through their own lookup.' );

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
penalis_assert( Penalis_Policy::can_change_username( Penalis_Policy::STATUS_PENDING_APPROVAL, false ), 'Pending approval accounts may rename.' );
penalis_assert( Penalis_Policy::can_change_username( Penalis_Policy::STATUS_ACTIVE, true ), 'Active accounts with access may rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_ACTIVE, false ), 'Active accounts without access may not rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_PENDING_VERIFICATION, true ), 'Unverified accounts may not rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_SUSPENDED, true ), 'Suspended accounts may not rename.' );
penalis_assert( ! Penalis_Policy::can_change_username( Penalis_Policy::STATUS_REJECTED, true ), 'Rejected accounts may not rename.' );

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
update_user_meta( 35, Penalis_Account_Status::META_STATUS, Penalis_Policy::STATUS_PENDING_APPROVAL );
$GLOBALS['wpdb'] = new Penalis_Test_WPDB();
$penalis_pending = Penalis_Username_Change::change( 35, 'penulis_pending', 'correct' );
penalis_assert( ! is_wp_error( $penalis_pending ), 'Pending approval accounts may change their login.' );

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
penalis_assert( Penalis_Emails::send_verification( 90, 'safe-test-token' ), 'Verification email is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], '<table' ), 'Transactional email uses the branded HTML layout.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'logo-penalis.png' ), 'Transactional email includes the Penalis logo.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'safe-test-token' ), 'Verification link is present in the rendered email.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], '>Konfirmasi Email</a>' ), 'Verification action has a descriptive button label.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'https://example.com/dashboard/?penalis_verify=safe-test-token' ), 'Verification action points to the full original URL.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'permintaan verifikasi alamat email' ), 'Verification email has verification-specific footer copy.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Jika kamu tidak mendaftar, abaikan email ini. Jangan bagikan isi email ini kepada siapapun.' ), 'Verification footer uses the approved request-denial and confidentiality copy.' );
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
penalis_assert( Penalis_Emails::send_email_change_confirmation( 92, 'email-token', 'new@example.com' ), 'Email change confirmation is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'Alamat baru hanya dipakai setelah kamu mengonfirmasi.' ), 'Email body explains that the new address is pending confirmation.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'mengubah email akun Penalis ke alamat ini' ), 'Email change confirmation has the approved contextual footer.' );

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
penalis_assert( Penalis_Emails::send_legacy_activation( 94, 'legacy-token' ), 'Legacy activation email is accepted.' );
penalis_assert( false !== strpos( $GLOBALS['penalis_test_mail'][0][2], 'permintaan aktivasi akun Penalis dari akun kontributor lama kamu' ), 'Legacy activation email uses the approved reason copy.' );

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
penalis_assert( Penalis_Editorial_Policy::can_review( true, true ), 'An administrator who can edit others may review.' );
penalis_assert( ! Penalis_Editorial_Policy::can_review( true, false ), 'An administrator without edit-others may not review.' );
penalis_assert( ! Penalis_Editorial_Policy::can_review( false, true ), 'A non-administrator may not review even with edit-others.' );
penalis_assert( ! Penalis_Editorial_Policy::can_review( false, false ), 'A writer may not review.' );

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
