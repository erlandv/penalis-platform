<?php
/**
 * Revision limits for Penalis drafts.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the revision history of drafts bounded.
 *
 * The dashboard autosaves a draft every 20-60 seconds, and WordPress stores a
 * full copy of the content as a revision on every content change. Left alone,
 * one long writing session produces hundreds of stored copies of the same
 * article, and — because the limit is shared with deliberate saves — the
 * versions a writer created on purpose are evicted along with them.
 *
 * Published articles are deliberately left alone: they are not rewritten by
 * autosave, so the site-wide setting is the right answer there.
 */
final class Penalis_Revisions {

	/**
	 * Revisions kept for a draft.
	 *
	 * The number follows from the autosave cadence rather than from a round
	 * figure: one write every 20-60 seconds means one to two revisions per
	 * minute in practice, so twenty revisions cover roughly 10-20 minutes of
	 * writing — the horizon in which "I deleted a paragraph a moment ago" is
	 * still recoverable. It also caps an 8 KB article at about 160 KB of
	 * revision storage, which keeps a thousand edited drafts in the low
	 * hundreds of megabytes rather than growing without limit.
	 *
	 * Sites that want a different depth can filter `penalis_draft_revisions`;
	 * returning `0` disables the plugin's cap entirely.
	 */
	public const DRAFT_LIMIT = 20;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'limit_draft_revisions' ), 10, 2 );
	}

	/**
	 * Cap the revision count for draft posts.
	 *
	 * A stricter limit already configured on the site wins, including `0`
	 * (revisions disabled), so this never overrides an administrator's choice —
	 * it only tightens an unlimited or very loose one.
	 *
	 * @param int          $num  Revisions to keep; `-1` means unlimited.
	 * @param WP_Post|null $post Post being saved.
	 * @return int
	 */
	public static function limit_draft_revisions( $num, $post = null ) {
		$num = (int) $num;

		if ( ! is_object( $post ) || ! isset( $post->post_type, $post->post_status ) ) {
			return $num;
		}

		if ( 'post' !== $post->post_type || 'draft' !== $post->post_status ) {
			return $num;
		}

		$default = class_exists( 'Penalis_Config' ) ? (int) Penalis_Config::editorial()['draft_revisions'] : self::DRAFT_LIMIT;
		$limit   = (int) apply_filters( 'penalis_draft_revisions', $default );

		// A filter that opts out must not accidentally disable revisions.
		if ( $limit < 1 ) {
			return $num;
		}

		if ( $num >= 0 && $num <= $limit ) {
			return $num;
		}

		return $limit;
	}
}
