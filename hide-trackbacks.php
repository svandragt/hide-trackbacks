<?php /** @noinspection AutoloadingIssuesInspection */

/*
Plugin Name: Hide Trackbacks
Plugin URI: http://wp.me/p1vXha-4u
Description: Prevents trackbacks and pingbacks from showing up as comments on your posts.
Version: 1.1.8
Author: Sander van Dragt
Author URI: https://vandragt.com
Requires at least: 5.8
Requires PHP: 7.4
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

	Copyright 2014-2022  Sander van Dragt  (email : sander@vandragt.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

	Derived from original code by:
	Honey Singh
	http://www.honeytechblog.com/how-to-remove-tracbacks-and-pings-from-wordpress-posts/

 */

// No direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVD_HideTrackbacks {
	/**
	 * Per-request cache of filtered comment counts, keyed by post ID.
	 *
	 * @var int[]
	 */
	private $counts = [];

	/**
	 * Initialisation
	 */
	public function __construct() {
		add_filter( 'the_posts', [ $this, 'update_post_comment_count' ] );
		add_filter( 'comments_array', [ $this, 'strip_comments' ] );
		add_filter( 'get_comments_number', [ $this, 'comment_count' ], 10, 2 );
	}

	/**
	 * Return the correct comment count for a post
	 *
	 * The get_comments_number filter passes the post ID, which is authoritative.
	 * get_the_ID() is only a fallback for the in-loop case, and returns false
	 * outside of it - hence the guard, without which counts render as zero.
	 *
	 * @param mixed $count   Comment count as calculated by WordPress
	 * @param mixed $post_id Post the count belongs to
	 *
	 * @return int
	 */
	public function comment_count( $count = 0, $post_id = 0 ) : int {
		$post_id = (int) ( $post_id ?: get_the_ID() );
		if ( $post_id < 1 ) {
			return (int) $count;
		}

		return $this->_count_comments( $post_id );
	}

	/**
	 * Updates the count for comments and trackbacks
	 *
	 * Memoised: this filter fires once per template call, and update_post_comment_count()
	 * calls it for every post in every query, so an uncached lookup here means an extra
	 * comment query per post per page.
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	private function _count_comments( int $post_id ) : int {
		if ( isset( $this->counts[ $post_id ] ) ) {
			return $this->counts[ $post_id ];
		}

		$comments = get_approved_comments( $post_id );
		$comments = $this->_strip_trackbacks( $comments );

		$this->counts[ $post_id ] = count( $comments );

		return $this->counts[ $post_id ];
	}

	/**
	 * Strips out trackbacks/pingbacks
	 * Helper for filtering out the trackbacks / pingbacks leaving comments only from list of comments
	 *
	 * @param array $unfiltered_comments Unfiltered comments
	 *
	 * @return array Filtered comments
	 */
	private function _strip_trackbacks( array $unfiltered_comments ) : array {
		return array_filter( $unfiltered_comments, [ $this, 'is_strippable_comment' ] );
	}

	/**
	 * Despite the name, this returns true for comments that are KEPT - it is the
	 * array_filter() callback used by _strip_trackbacks(). The name is retained
	 * because the method is public and may be unhooked by other code.
	 *
	 * Deliberately untyped: comments_array is a public filter, so the array may
	 * contain whatever other plugins put there. A WP_Comment type declaration here
	 * would turn that into a fatal TypeError on the front end. Anything we cannot
	 * identify is left visible rather than hidden.
	 *
	 * @param mixed $comment The comment to test
	 *
	 * @return bool True when the comment is neither a pingback nor a trackback
	 */
	public function is_strippable_comment( $comment ) : bool {
		if ( is_object( $comment ) ) {
			$type = $comment->comment_type ?? '';
		} elseif ( is_array( $comment ) ) {
			$type = $comment['comment_type'] ?? '';
		} else {
			$type = '';
		}

		return ! in_array( $type, [ 'trackback', 'pingback' ], true );
	}

	/**
	 * @param mixed $comments_unfiltered Unfiltered comments
	 *
	 * @return array Filtered comments
	 */
	public function strip_comments( $comments_unfiltered ) : array {
		//TODO test why global is used.
		global $comments;
		// array_values() because array_filter() preserves keys, and templates that
		// index the comment list numerically choke on the resulting gaps.
		$comments = array_values( $this->_strip_trackbacks( (array) $comments_unfiltered ) );

		return $comments;
	}

	/**
	 * Updates the comment number for posts with trackbacks
	 *
	 * @param mixed $maybe_posts Array of WP_Post objects
	 *
	 * @return array Updated array of WP_Post objects with the correct comment count
	 */
	public function update_post_comment_count( $maybe_posts ) : array {
		$posts = (array) $maybe_posts;
		foreach ( $posts as $p ) {
			if ( ! $p instanceof WP_Post ) {
				continue;
			}
			if ( $p->comment_count < 1 ) {
				continue;
			}
			$p->comment_count = $this->_count_comments( $p->ID );
		}

		return $posts;
	}
}

function svd_hide_trackbacks() {
	global $svd_hide_trackbacks;
	$svd_hide_trackbacks = new SVD_HideTrackbacks();
}

// Load the plugin
add_action( 'plugins_loaded', 'svd_hide_trackbacks' );
