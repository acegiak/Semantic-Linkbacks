<?php
/**
 * A compatibility function to slice the comment array
 *
 * @see https://github.com/pfefferle/wordpress-semantic-linkbacks/pull/73#discussion_r94093790
 *
 * @param array $commentdata the enriched comment array
 *
 * @return array the sliced comment array
 */
function semantic_linkbacks_slice_comments_array( $commentdata ) {
	$keys = array(
		'comment_post_ID',
		'comment_content',
		'comment_author',
		'comment_author_email',
		'comment_approved',
		'comment_karma',
		'comment_author_url',
		'comment_date',
		'comment_date_gmt',
		'comment_type',
		'comment_parent',
		'user_id',
		'comment_agent',
		'comment_author_IP',
	);

	$commentdata = wp_array_slice_assoc( $commentdata, $keys );

	return wp_unslash( $commentdata );
}
add_filter( 'wp_update_comment_data', 'semantic_linkbacks_slice_comments_array', 99, 1 );
