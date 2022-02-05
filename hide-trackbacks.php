<?php /** @noinspection AutoloadingIssuesInspection */

/*
Plugin Name: Hide Trackbacks
Plugin URI: http://wp.me/p1vXha-4u
Description: Stops trackbacks and pingbacks from showing up as comments on your posts.
Version: 1.1.5
Author: Sander van Dragt
Author URI: https://vandragt.com
License: GPL2

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

class SVD_HideTrackbacks {
	/**
	 * Initialisation
	 */
	public function __construct() {
		add_filter( 'the_posts', [ &$this, 'update_post_comment_count' ] );
		add_filter( 'comments_array', [ &$this, 'strip_comments' ] );
		add_filter( 'get_comments_number', [ &$this, 'comment_count' ], 10, 0 );
	}

	/**
	 * Return the correct comment count within the loop
	 */
	public function comment_count() : int {
		$id = get_the_ID();

		return $this->_count_comments( $id );
	}

	/**
	 * Updates the count for comments and trackbacks
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	private function _count_comments( int $post_id ) : int {
		$comments = get_approved_comments( $post_id );
		$comments = $this->_strip_trackbacks( $comments );

		return count( $comments );
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
		return array_filter( $unfiltered_comments, [ &$this, 'is_strippable_comment' ] );
	}

	/**
	 * @param WP_Comment $comment The comment to test
	 *
	 * @return bool Is the comment a pingback or trackback
	 */
	public function is_strippable_comment( WP_Comment $comment ) : bool {
		return ! in_array( $comment->comment_type, [ 'trackback', 'pingback' ] );
	}

	/**
	 * @param array $comments_unfiltered Unfiltered comments
	 *
	 * @return array Filtered comments
	 */
	public function strip_comments( array $comments_unfiltered ) : array {
		//TODO test why global is used.
		global $comments;
		$comments = $this->_strip_trackbacks( $comments_unfiltered );

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
