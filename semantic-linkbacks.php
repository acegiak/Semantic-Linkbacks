<?php
/*
 Plugin Name: Semantic-Linkbacks
 Plugin URI: https://github.com/acegiak/Semantic-Linkbacks
 Description: Semantic Linkbacks for webmentions, trackbacks and pingbacks
 Author: pfefferle & acegiak
 Author URI: http://notizblog.org/
 Version: 2.0.0-dev
*/

require_once "semantic-linkbacks-microformats-handler.php";

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
    // return if comment_ID is empty
    if (!$comment_ID) {
      return $comment_ID;
    }

    // check if it is a valid comment
    $commentdata = get_comment($comment_ID, ARRAY_A);

    // check if there is any comment-data
    if (!$commentdata) {
      return $comment_ID;
    }

    // source
    $source = $commentdata['comment_author_url'];

    // check if there is already a matching comment
    if ( $comments = get_comments( array('meta_key' => 'semantic_linkbacks_source', 'meta_value' => $source) ) ) {
      $comment = $comments[0];

      if ($comment_ID != $comment->comment_ID) {
        wp_delete_comment($commentdata['comment_ID'], true);

        $commentdata['comment_ID'] = $comment->comment_ID;
        $commentdata['comment_approved'] = $comment->comment_approved;
      } else {
        $commentdata['comment_ID'] = $comment_ID;
      }
    }

    // check if post exists
    $post = get_post($commentdata['comment_post_ID'], ARRAY_A);

    if (!$post) {
      return $comment_ID;
    }

    // get remote html
    $target = get_permalink( $post['ID'] );
    $response = wp_remote_get( $source );

    // handle errors
    if ( is_wp_error( $response ) ) {
      return $comment_ID;
    }

    // get HTML code of source url
    $html = wp_remote_retrieve_body( $response );

    // adds a hook to enable some other semantic handlers for example schema.org
    $commentdata = apply_filters("semantic_linkbacks_commentdata", $commentdata, $target, $html);

    if (empty($commentdata)) {
      return $comment_ID;
    }

    // check if there is a parent comment
    if ( !isset($commentdata['comment_parent']) && $query = parse_url($target, PHP_URL_QUERY) ) {
      parse_str($query);
      if (isset($replytocom) && get_comment($replytocom)) {
        $commentdata['comment_parent'] = $replytocom;
      }
    }

    $commentdata['comment_type'] = '';

    // update comment
    wp_update_comment($commentdata);

    // add source url as comment-meta
    update_comment_meta( $commentdata["comment_ID"], "semantic_linkbacks_source", $source );

    return $comment_ID;
  }
}

new SemanticLinkbacksPlugin;