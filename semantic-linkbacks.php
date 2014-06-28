<?php
/*
 Plugin Name: Semantic-Linkbacks
 Plugin URI: https://github.com/pfefferle/wordpress-semantic-linkbacks
 Description: Semantic Linkbacks for webmentions, trackbacks and pingbacks
 Author: pfefferle & acegiak
 Author URI: http://notizblog.org/
 Version: 3.0.2
*/

if (!class_exists("SemanticLinkbacksPlugin")) :

// check if php version is >= 5.3
// version is required by the mf2 parser
function semantic_linkbacks_activation() {
  if ( version_compare( phpversion(), 5.3, '<' ) ) {
    die( "The minimum PHP version required for this plugin is 5.3" );
  }
}
register_activation_hook( __FILE__, 'semantic_linkbacks_activation' );

// run plugin only if php version is >= 5.3
if ( version_compare( phpversion(), 5.3, '>=' ) ) {
  require_once "semantic-linkbacks-microformats-handler.php";
  add_action('init', array( 'SemanticLinkbacksPlugin', 'init' ));
}

/**
 * semantic linkbacks class
 *
 * @author Matthias Pfefferle
 */
class SemanticLinkbacksPlugin {
  /**
   * Initialize the plugin, registering WordPess hooks.
   */
  public static function init() {
    // hook into linkback functions to add more semantics
    add_action('pingback_post', array( 'SemanticLinkbacksPlugin', 'linkback_fix' ));
    add_action('trackback_post', array( 'SemanticLinkbacksPlugin', 'linkback_fix' ));
    add_action('webmention_post', array( 'SemanticLinkbacksPlugin', 'linkback_fix' ));

    add_filter('get_avatar', array( 'SemanticLinkbacksPlugin', 'get_avatar'), 11, 5);
    add_filter('comment_text', array( 'SemanticLinkbacksPlugin', 'comment_text_add_cite'), 11, 3);
    add_filter('comment_text', array( 'SemanticLinkbacksPlugin', 'comment_text_excerpt'), 12, 3);
    add_filter('get_comment_link', array( 'SemanticLinkbacksPlugin', 'get_comment_link' ), 99, 3);
    add_filter('get_comment_author_url', array( 'SemanticLinkbacksPlugin', 'get_comment_author_url' ), 99, 3);
    add_filter('get_avatar_comment_types', array( 'SemanticLinkbacksPlugin', 'get_avatar_comment_types' ));
  }

  /**
   * nicer semantic linkbacks
   *
   * @param int $comment_ID the comment id
   */
  public static function linkback_fix($comment_ID) {
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
    $source = esc_url_raw($commentdata['comment_author_url']);

    // check if there is already a matching comment
    if ( $comments = get_comments( array('meta_key' => 'semantic_linkbacks_source', 'meta_value' => htmlentities($source)) ) ) {
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

    // generate target
    $target = get_permalink( $post['ID'] );

    // add replytocom if present
    if (isset($commentdata['comment_parent']) && !empty($commentdata['comment_parent'])) {
      $target = add_query_arg(array("replytocom" => $commentdata['comment_parent']), $target);
    }

    // get remote html
    $response = wp_remote_get( esc_url_raw(html_entity_decode($source)), array('timeout' => 100) );

    // handle errors
    if ( is_wp_error( $response ) ) {
      return $comment_ID;
    }

    // get HTML code of source url
    $html = wp_remote_retrieve_body( $response );

    // add source url as comment-meta
    update_comment_meta( $commentdata["comment_ID"], "semantic_linkbacks_source", esc_url_raw($commentdata["comment_author_url"]), true );

    // adds a hook to enable some other semantic handlers for example schema.org
    $commentdata = apply_filters("semantic_linkbacks_commentdata", $commentdata, $target, $html);

    // check if comment-data is empty
    if (empty($commentdata)) {
      return $comment_ID;
    }

    // remove "webmention" comment-type if $type is "reply"
    if (isset($commentdata['_type']) && in_array($commentdata['_type'], apply_filters("semantic_linkbacks_comment_types", array("reply")))) {
      global $wpdb;

      $wpdb->update( $wpdb->comments, array( 'comment_type' => '' ), array( 'comment_ID' => $commentdata["comment_ID"] ) );
    }

    // save custom comment properties as comment-metas
    foreach ($commentdata as $key => $value) {
      if (strpos($key, "_") === 0) {
        update_comment_meta( $commentdata["comment_ID"], "semantic_linkbacks$key", $value, true );
        unset($commentdata[$key]);
      }
    }

    // disable flood control
    remove_filter('check_comment_flood', 'check_comment_flood_db', 10, 3);

    // update comment
    wp_update_comment($commentdata);

    // re-add flood control
    add_filter('check_comment_flood', 'check_comment_flood_db', 10, 3);

    return $comment_ID;
  }

  /**
   * returns an array of comment type excerpts to their translated and pretty display versions
   *
   * @return array The array of translated post format excerpts.
   */
  public static function get_comment_type_excerpts() {
    $strings = array(
      'mention'       => _x( '%1$s mentioned this %2$s on <a href="%3$s">%4$s</a>',   'semantic_linkbacks' ), // Special case. any value that evals to false will be considered standard

      'reply'         => _x( '%1$s replied to this %2$s on <a href="%3$s">%4$s</a>',  'semantic_linkbacks' ),
      'repost'        => _x( '%1$s reposted this %2$s on <a href="%3$s">%4$s</a>',    'semantic_linkbacks' ),
      'like'          => _x( '%1$s liked this %2$s on <a href="%3$s">%4$s</a>',       'semantic_linkbacks' ),
      'favorite'      => _x( '%1$s favorited this %2$s on <a href="%3$s">%4$s</a>',   'semantic_linkbacks' ),
      'rsvp:yes'      => _x( '%1$s is <strong>attending</strong>',                    'semantic_linkbacks' ),
      'rsvp:no'       => _x( '%1$s is <strong>not attending</strong>',                'semantic_linkbacks' ),
      'rsvp:maybe'    => _x( 'Maybe %1$s will be <strong>attending</strong>',         'semantic_linkbacks' ),
      'rsvp:invited'  => _x( '%1$s is <strong>invited</strong>',                      'semantic_linkbacks' ),
      'rsvp:tracking' => _x( '%1$s <strong>tracks</strong> this event',               'semantic_linkbacks' )
    );
    return $strings;
  }

  /**
  * returns an array of comment type slugs to their translated and pretty display versions
  *
  * @return array The array of translated comment type names.
  */
  public static function get_comment_type_strings() {
    $strings = array(
      'mention'       => _x( 'Mention',   'semantic_linkbacks' ), // Special case. any value that evals to false will be considered standard

      'reply'         => _x( 'Reply',     'semantic_linkbacks' ),
      'repost'        => _x( 'Repost',    'semantic_linkbacks' ),
      'like'          => _x( 'Like',      'semantic_linkbacks' ),
      'favorite'      => _x( 'Favorite',  'semantic_linkbacks' ),
      'rsvp:yes'      => _x( 'RSVP',      'semantic_linkbacks' ),
      'rsvp:no'       => _x( 'RSVP',      'semantic_linkbacks' ),
      'rsvp:invited'  => _x( 'RSVP',      'semantic_linkbacks' ),
      'rsvp:maybe'    => _x( 'RSVP',      'semantic_linkbacks' ),
      'rsvp:tracking' => _x( 'RSVP',      'semantic_linkbacks' )
    );
    return $strings;
  }

  /**
   * add cite to "reply"s
   *
   * @param string $text the comment text
   * @param WP_Comment $comment the comment object
   * @param array $args a list of arguments
   * @return string the filtered comment text
   */
  public static function comment_text_add_cite($text, $comment = null, $args = array()) {
    // only change text for pinbacks/trackbacks/webmentions
    if (!$comment) {
      return $text;
    }

    // thanks to @snarfed for the idea
    if ($comment->comment_type == "" && $canonical = get_comment_meta($comment->comment_ID, "semantic_linkbacks_canonical", true)) {
      $host = parse_url($canonical, PHP_URL_HOST);

      // strip leading www, if any
      $host = preg_replace("/^www\./", "", $host);
      // note that WordPress's sanitization strips the class="u-url". sigh. :/ also,
      // <cite> is one of the few elements that make it through the sanitization and
      // is still uncommon enough that we can use it for styling.
      $text .= '<p><small>&mdash;&nbsp;<cite><a class="u-url" href="' . $canonical . '">via ' . $host .
                      '</a></cite></small></p>';
    }

    return apply_filters("semantic_linkbacks_cite", $text);
  }

  /**
   * generate excerpt for all types except "reply"
   *
   * @param string $text the comment text
   * @param WP_Comment $comment the comment object
   * @param array $args a list of arguments
   * @return string the filtered comment text
   */
  public static function comment_text_excerpt($text, $comment = null, $args = array()) {
    // only change text for pinbacks/trackbacks/webmentions
    if (!$comment || $comment->comment_type == "" || !get_comment_meta($comment->comment_ID, "semantic_linkbacks_canonical", true)) {
      return $text;
    }

    // check comment type
    $comment_type = get_comment_meta($comment->comment_ID, "semantic_linkbacks_type", true);

    if (!$comment_type || !in_array($comment_type, array_keys(self::get_comment_type_strings()))) {
      $comment_type = "mention";
    }

    $post_format = get_post_format($comment->comment_post_ID);

    // replace "standard" with "Article"
    if (!$post_format || $post_format == "standard") {
      $post_format = "Article";
    } else {
      $post_formatstrings = get_post_format_strings();
      // get the "nice" name
      $post_format = $post_formatstrings[$post_format];
    }

    // generate the verb, for example "mentioned" or "liked"
    $comment_type_excerpts = self::get_comment_type_excerpts();

    // get URL canonical url...
    $url = get_comment_meta($comment->comment_ID, "semantic_linkbacks_canonical", true);
    // ...or fall back to source
    if (!$url) {
      $url = get_comment_meta($comment->comment_ID, "semantic_linkbacks_source", true);
    }

    // parse host
    $host = parse_url($url, PHP_URL_HOST);
    // strip leading www, if any
    $host = preg_replace("/^www\./", "", $host);

    // generate output
    $text = sprintf($comment_type_excerpts[$comment_type], get_comment_author_link($comment->comment_ID), $post_format, $url, $host);

    return apply_filters("semantic_linkbacks_excerpt", $text);
  }

  /**
   * replaces the default avatar with the webmention uf2 photo
   *
   * @param string $avatar the avatar-url
   * @param int|string|object $id_or_email A user ID, email address, or comment object
   * @param int $size Size of the avatar image
   * @param string $default URL to a default image to use if no avatar is available
   * @param string $alt Alternative text to use in image tag. Defaults to blank
   * @return string new avatar-url
   */
  public static function get_avatar($avatar, $id_or_email, $size, $default = '', $alt = '') {
    if (!is_object($id_or_email) || !isset($id_or_email->comment_type) || !get_comment_meta($id_or_email->comment_ID, 'semantic_linkbacks_avatar', true)) {
      return $avatar;
    }

    // check if comment has an avatar
    $sl_avatar = get_comment_meta($id_or_email->comment_ID, 'semantic_linkbacks_avatar', true);

    if (!$sl_avatar) {
      return $avatar;
    }

    if ( false === $alt )
      $safe_alt = '';
    else
      $safe_alt = esc_attr( $alt );

    $avatar = "<img alt='{$safe_alt}' src='{$sl_avatar}' class='avatar avatar-{$size} photo u-photo avatar-semantic-linkbacks' height='{$size}' width='{$size}' />";
    return $avatar;
  }

  /**
   * replace comment url with canonical url
   *
   * @param string $link the link url
   * @param obj $comment the comment object
   * @param array $args a list of arguments to generate the final link tag
   * @return string the linkback source or the original comment link
   */
  public static function get_comment_link($link, $comment, $args) {
    if (is_singular() && $canonical = get_comment_meta($comment->comment_ID, 'semantic_linkbacks_canonical', true) ) {
      return $canonical;
    }

    return $link;
  }

  /**
   * replace comment url with author url
   *
   * @param string $link the author url
   * @return string the replaced/parsed author url or the original comment link
   */
  public static function get_comment_author_url($link) {
    global $comment;

    if ( $author_url = get_comment_meta($comment->comment_ID, 'semantic_linkbacks_author_url', true) ) {
      return $author_url;
    }

    return $link;
  }

  /**
   * show avatars also on trackbacks and pingbacks
   *
   * @param array $types list of avatar enabled comment types
   *
   * @return array show avatars also on trackbacks and pingbacks
   */
  public static function get_avatar_comment_types($types) {
    $types[] = 'pingback';
    $types[] = 'trackback';
    $types[] = 'webmention';

    return $types;
  }
}

endif;
