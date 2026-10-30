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
require_once __DIR__ . '/../includes/class-roles.php';
require_once __DIR__ . '/../includes/class-account-status.php';
require_once __DIR__ . '/../includes/class-avatar.php';

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
	'user_login'      => 'ada.lovelace',
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
$errors = Penalis_Policy::validate_profile( array( 'user_url' => 'javascript:alert(1)' ) );
penalis_assert( isset( $errors['user_url'] ), 'Non-http website URL is rejected.' );

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
