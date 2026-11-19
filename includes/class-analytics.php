<?php
/**
 * Editorial trend metrics for the Penalis overview screen.
 *
 * Everything here reads the editorial metadata that the review workflow already
 * writes (`_penalis_submitted_at`, `_penalis_reviewed_at`, the editorial state),
 * so there is no second source of truth to keep in sync and no extra table.
 *
 * Two limits of that data are worth knowing before trusting a number:
 *
 * - `_penalis_submitted_at` is overwritten on every (re)submission. "Submissions
 *   per week" therefore counts articles whose *latest* submission landed in that
 *   week, not every submit event in history.
 * - `_penalis_reviewed_at` is the last reviewer action. "Time to publish" is the
 *   turnaround from the last submission to the approval that published it, which
 *   is the number a reviewer can act on.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates submission and review metadata into trend numbers.
 */
final class Penalis_Analytics {

	/** Weeks shown in the overview chart. */
	const DEFAULT_WEEKS = 8;

	/** How many authors the "top author" list shows. */
	const TOP_AUTHOR_LIMIT = 3;

	/**
	 * Unix timestamps marking each week boundary, oldest first.
	 *
	 * Boundaries are computed in the site timezone with `modify( '+1 week' )`
	 * rather than by adding 604800 seconds, so a daylight-saving shift moves the
	 * boundary with local midnight instead of sliding the week.
	 *
	 * @param int $weeks Number of weeks.
	 * @param int $now   Reference timestamp; defaults to the current time.
	 * @return int[] Length `$weeks + 1`.
	 */
	public static function week_boundaries( $weeks = self::DEFAULT_WEEKS, $now = 0 ) {
		$weeks = max( 1, (int) $weeks );
		$now   = $now > 0 ? (int) $now : time();

		$timezone = Penalis_Datetime::site_timezone();
		$current  = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone );

		// Monday is the first day of the week used by the editorial calendar.
		$start = $current->modify( 'monday this week' )->setTime( 0, 0, 0 );
		$start = $start->modify( '-' . ( $weeks - 1 ) . ' weeks' );

		$boundaries = array();

		for ( $index = 0; $index <= $weeks; $index++ ) {
			$boundaries[] = $start->getTimestamp();
			$start        = $start->modify( '+1 week' );
		}

		return $boundaries;
	}

	/**
	 * Count timestamps per week bucket.
	 *
	 * @param int[] $timestamps Timestamps to place.
	 * @param int[] $boundaries Boundaries from `week_boundaries()`.
	 * @return int[] One count per bucket.
	 */
	public static function bucket_timestamps( array $timestamps, array $boundaries ) {
		$buckets = count( $boundaries ) - 1;

		if ( $buckets < 1 ) {
			return array();
		}

		$counts = array_fill( 0, $buckets, 0 );
		$end    = (int) $boundaries[ $buckets ];

		foreach ( $timestamps as $timestamp ) {
			$timestamp = (int) $timestamp;

			if ( $timestamp < (int) $boundaries[0] || $timestamp >= $end ) {
				continue;
			}

			for ( $index = $buckets - 1; $index >= 0; $index-- ) {
				if ( $timestamp >= (int) $boundaries[ $index ] ) {
					++$counts[ $index ];
					break;
				}
			}
		}

		return $counts;
	}

	/**
	 * Articles whose latest submission falls in each of the last weeks.
	 *
	 * @param int $weeks Number of weeks.
	 * @return array<int,array<string,int>> Each item has `start` and `count`.
	 */
	public static function weekly_submissions( $weeks = self::DEFAULT_WEEKS ) {
		$boundaries = self::week_boundaries( $weeks );
		$ids        = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'none',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded to the reporting window.
					array(
						'key'     => Penalis_Editorial_Policy::META_SUBMITTED_AT,
						'value'   => (int) $boundaries[0],
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$timestamps = array();

		foreach ( $ids as $id ) {
			$timestamps[] = (int) get_post_meta( (int) $id, Penalis_Editorial_Policy::META_SUBMITTED_AT, true );
		}

		$counts = self::bucket_timestamps( $timestamps, $boundaries );
		$series = array();

		foreach ( $counts as $index => $count ) {
			$series[] = array(
				'start' => (int) $boundaries[ $index ],
				'count' => (int) $count,
			);
		}

		return $series;
	}

	/**
	 * Articles that received a reviewer decision since a timestamp.
	 *
	 * @param int $since Earliest review timestamp.
	 * @return WP_Post[]
	 */
	public static function decisions_since( $since ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'none',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded to the reporting window.
					array(
						'key'     => Penalis_Editorial_Policy::META_REVIEWED_AT,
						'value'   => (int) $since,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Share of decided articles that were approved.
	 *
	 * Rejected is the only negative outcome counted: "changes requested" is not
	 * a decision yet, and the article is still in play.
	 *
	 * @param int $since Earliest review timestamp.
	 * @return array<string,int|float|null> `approved`, `rejected`, `rate`.
	 */
	public static function approval_rate( $since ) {
		$approved = 0;
		$rejected = 0;

		foreach ( self::decisions_since( $since ) as $post ) {
			$state = Penalis_Editorial_Workflow::get_state( $post );

			if ( Penalis_Editorial_Policy::STATE_PUBLISHED === $state ) {
				++$approved;
			} elseif ( Penalis_Editorial_Policy::STATE_REJECTED === $state ) {
				++$rejected;
			}
		}

		return array(
			'approved' => $approved,
			'rejected' => $rejected,
			'rate'     => self::rate( $approved, $rejected ),
		);
	}

	/**
	 * Median days between the last submission and the approval.
	 *
	 * Median rather than mean: one article that sat for two months must not
	 * define the typical turnaround.
	 *
	 * @param int $since Earliest review timestamp.
	 * @return array<string,int|float|null> `median_days` and `sample`.
	 */
	public static function time_to_publish( $since ) {
		$days = array();

		foreach ( self::decisions_since( $since ) as $post ) {
			if ( Penalis_Editorial_Policy::STATE_PUBLISHED !== Penalis_Editorial_Workflow::get_state( $post ) ) {
				continue;
			}

			$submitted = Penalis_Editorial_Workflow::get_submitted_at( $post );
			$reviewed  = Penalis_Editorial_Workflow::get_reviewed_at( $post );

			if ( $submitted <= 0 || $reviewed < $submitted ) {
				continue;
			}

			$days[] = ( $reviewed - $submitted ) / DAY_IN_SECONDS;
		}

		return array(
			'median_days' => self::median( $days ),
			'sample'      => count( $days ),
		);
	}

	/**
	 * Authors with the most published articles in the window.
	 *
	 * @param int $since Earliest review timestamp.
	 * @param int $limit How many authors to return.
	 * @return array<int,array<string,int|string>> `name` and `count`, most first.
	 */
	public static function top_authors( $since, $limit = self::TOP_AUTHOR_LIMIT ) {
		$counts = array();

		foreach ( self::decisions_since( $since ) as $post ) {
			if ( Penalis_Editorial_Policy::STATE_PUBLISHED !== Penalis_Editorial_Workflow::get_state( $post ) ) {
				continue;
			}

			$author_id = (int) $post->post_author;

			if ( $author_id <= 0 ) {
				continue;
			}

			$counts[ $author_id ] = isset( $counts[ $author_id ] ) ? $counts[ $author_id ] + 1 : 1;
		}

		arsort( $counts );

		$authors = array();

		foreach ( array_slice( $counts, 0, max( 1, (int) $limit ), true ) as $author_id => $count ) {
			$user      = get_userdata( (int) $author_id );
			$authors[] = array(
				'name'  => $user ? (string) $user->display_name : 'Penulis #' . (int) $author_id,
				'count' => (int) $count,
			);
		}

		return $authors;
	}

	/**
	 * Median of a list of numbers.
	 *
	 * @param float[] $values Numbers.
	 * @return float|null Null for an empty list.
	 */
	public static function median( array $values ) {
		$count = count( $values );

		if ( 0 === $count ) {
			return null;
		}

		sort( $values );

		$middle = intdiv( $count, 2 );

		if ( 0 === $count % 2 ) {
			return ( (float) $values[ $middle - 1 ] + (float) $values[ $middle ] ) / 2;
		}

		return (float) $values[ $middle ];
	}

	/**
	 * Split a duration in days into a number and the unit that fits it.
	 *
	 * A fast community approves articles in minutes, and "0,0 hari" is a worse
	 * answer than "12 menit". The unit is returned rather than baked into a
	 * sentence so the caller keeps control of the copy.
	 *
	 * @param float|null $days Duration in days.
	 * @return array<string,float|string>|null `value` and `unit`, or null.
	 */
	public static function duration( $days ) {
		if ( null === $days ) {
			return null;
		}

		$days = (float) $days;

		if ( $days < ( 1 / 24 ) ) {
			return array(
				'value' => (float) round( $days * DAY_IN_SECONDS / MINUTE_IN_SECONDS ),
				'unit'  => 'menit',
			);
		}

		if ( $days < 1 ) {
			return array(
				'value' => (float) round( $days * 24, 1 ),
				'unit'  => 'jam',
			);
		}

		return array(
			'value' => (float) round( $days, 1 ),
			'unit'  => 'hari',
		);
	}

	/**
	 * Approval percentage.
	 *
	 * @param int $approved Approved articles.
	 * @param int $rejected Rejected articles.
	 * @return float|null Null when nothing was decided, so the screen can print
	 *                    "—" instead of a misleading 0%.
	 */
	public static function rate( $approved, $rejected ) {
		$total = (int) $approved + (int) $rejected;

		if ( $total <= 0 ) {
			return null;
		}

		return round( ( (int) $approved / $total ) * 100, 1 );
	}
}
