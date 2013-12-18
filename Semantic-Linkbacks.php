<?php
/*
 Plugin Name: Semantic-Linkbacks
 Plugin URI: https://github.com/acegiak/Semantic-Linkbacks
 Description: Semantic Linkbacks for webmentions, trackbacks and pingbacks
 Author: pfefferle & acegiak
 Author URI: http://notizblog.org/
 Version: 2.0.0-dev
*/

require_once "mf-linkbacks.php";

/**
 *
 */
class SemanticLinkbacksPlugin {
  /**
   * constructor
   */
  public function __construct() {
    add_action('init', array( $this, 'init' ));
  }

  /**
   * Initialize the plugin, registering WordPess hooks.
   */
  public function init() {
    // hook into linkback functions to add more semantics
    add_action('pingback_post', array( $this, 'linkback_fix' ));
    add_action('trackback_post', array( $this, 'linkback_fix' ));
    add_action('webmention_post', array( $this, 'linkback_fix' ));
  }

  /**
   *
   *
   * @param int $comment_ID the comment id
   */
  public function linkback_fix($comment_ID) {
    // check if it is a valid comment
    $commentdata = get_comment($comment_ID, ARRAY_A);

    if (!$commentdata) {
      return false;
    }

    // check if post exists
    $post = get_post($commentdata['comment_post_ID'], ARRAY_A);

    if (!$post) {
      return false;
    }

    // get remote html
    $target = get_permalink( $post['ID'] );
    $response = wp_remote_get( $commentdata['comment_author_url'] );

    if ( is_wp_error( $response ) ) {
      return false;
    }

    $source_html = wp_remote_retrieve_body( $response );

    //
    $commentdata = apply_filters("semantic_linkbacks_commentdata", array(), $commentdata, $target, $source_html);

    if (!empty($commentdata)) {
      wp_update_comment($commentdata);
    }
  }
}

new SemanticLinkbacksPlugin;