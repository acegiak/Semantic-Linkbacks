<?php
require_once "Mf2/Parser.php";

use Mf2\Parser;

/**
 *
 */
class SemanticLinkbacksPlugin_MicroformatsHandler {
  // the parsed microformats objects
  private $entries = null;

  // the entry of interest
  private $entry = null;

  // the type of the linkback
  private $type = "mention";

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
    //
    add_filter('semantic_linkbacks_commentdata', array( $this, 'generate_commentdata' ), 1, 4);
  }

  /**
   * all supported url types
   *
   * @return array
   */
  public function get_class_mapper() {
    $class_mapper = array();

    /*
     * replies
     * @link http://indiewebcamp.com/replies
     */
    $class_mapper["in-reply-to"] = "reply";
    $class_mapper["reply"]       = "reply";
    $class_mapper["reply-of"]    = "reply";

    /*
     * likes
     * @link http://indiewebcamp.com/likes
     */
    $class_mapper["like"]        = "like";
    $class_mapper["like-of"]     = "like";

    /*
     * mentions
     * @link http://indiewebcamp.com/mentions
     */
    $class_mapper["mention"]     = "mention";

    return apply_filters("mf_linkbacks_class_mapper", $class_mapper);
  }

  /**
   * all supported url types
   *
   * @return array
   */
  public function get_rel_mapper() {
    $rel_mapper = array();

    return apply_filters("mf_linkbacks_rel_mapper", $rel_mapper);
  }

  /**
   *
   */
  public function generate_commentdata($commentdata, $original_commentdata, $target, $html) {
    global $wpdb;

    // parse source html
    $parser = new Parser( $html );
    $mf_array = $parser->parse(true);

    // get all "relevant" entries
    $entries = $this->get_entries($mf_array);

    // check if there are any entries
    if (empty($entries)) {
      return array();
    }

    // get the entry of interest
    $entry = $this->get_representative_entry($entries, $target);

    // check if there is a representative entry
    if (empty($entry)) {
      return array();
    }

    $properties = $entry['properties'];

    // try to find some content
    // @link http://indiewebcamp.com/comments-presentation
    if (isset($properties['summary'])) {
      $commentdata['comment_content'] = esc_sql($properties['summary'][0]);
    } elseif (isset($properties['content'])) {
      $commentdata['comment_content'] = esc_sql($properties['content'][0]['html']);
    } elseif (isset($properties['name'])) {
      $commentdata['comment_content'] = esc_sql($properties['name'][0]);
    }

    // set the right date
    if (isset($properties['published'])) {
      $time = strtotime($properties['published'][0]);
      $commentdata['comment_date'] = date("Y-m-d H:i:s", $time);
    } elseif (isset($properties['updated'])) {
      $time = strtotime($properties['updated'][0]);
      $commentdata['comment_date'] = date("Y-m-d H:i:s", $time);
    }

    $author = null;

    // check if h-card has an author
    if ( isset($properties['author']) && isset($properties['author'][0]['properties']) ) {
      $author = $properties['author'][0]['properties'];
    }

    // else get representative hcard
    if (!$author) {
      foreach ($mf_array["items"] as $mf) {
        if ( isset( $mf["type"] ) ) {
          if ( in_array( "h-card", $mf["type"] ) ) {
            // check domain
            if (isset($mf['properties']) && isset($mf['properties']['url'])) {
              foreach ($mf['properties']['url'] as $url) {
                if (parse_url($url, PHP_URL_HOST) == parse_url($source, PHP_URL_HOST)) {
                  $author = $mf['properties'];
                  break;
                }
              }
            }
          }
        }
      }
    }

    // if author is present use the informations for the comment
    if ($author) {
      if (isset($author['name'])) {
        $commentdata['comment_author'] = esc_sql($author['name'][0]);
      }

      if (isset($author['email'])) {
        $commentdata['comment_author_email'] = esc_sql($author['email'][0]);
      }

      if (isset($author['url'])) {
        $commentdata['comment_author_url'] = esc_sql($author['url'][0]);
      }
    }

    $commentdata['comment_post_ID'] = $original_commentdata["comment_post_ID"];
    $commentdata['comment_ID'] = $original_commentdata["comment_ID"];

    //if ( $comments = get_comments( array('meta_key' => 'semantic_linkback_source', 'meta_value' => $source) ) ) {
    //  $comment = $comments[0];
    //  $commentdata['comment_ID'] = $comment->comment_ID;

      //@todo delete pingback
    //}

    // check if there is a parent comment
    if ( $query = parse_url($target, PHP_URL_QUERY) ) {
      parse_str($query);
      if (isset($replytocom) && get_comment($replytocom)) {
        $commentdata['comment_parent'] = $replytocom;
      }
    }

    return $commentdata;
  }

  public function get_entries($mf_array) {
    $entries = array();

    // some basic checks
    if ( !is_array( $mf_array ) )
      return $entries;
    if ( !isset( $mf_array["items"] ) )
      return $entries;
    if ( count( $mf_array["items"] ) == 0 )
      return $entries;

    // get first item
    $first_item = $mf_array["items"][0];

    // check if it is an h-feed
    if ( isset($first_item['type']) && in_array( "h-feed", $first_item["type"]) && isset($first_item['children']) ) {
      $mf_array["items"] = $first_item['children'];
    }

    // iterate array
    foreach ($mf_array["items"] as $mf) {
      if ( isset( $mf["type"] ) && in_array( "h-entry", $mf["type"] ) ) {
        $entries[] = $mf;
      }
    }

    // return entries
    return $entries;
  }

  /**
   * helper to find the correct h-entry node
   *
   * @param array $mf_array the parsed microformats array
   * @param string $target the target url
   * @return array|false the h-entry node or false
   */
  public function get_representative_entry( $entries, $target ) {
    // iterate array
    foreach ($entries as $entry) {
      // check properties
      if ( isset( $entry['properties'] ) ) {
        // check properties if target urls was mentioned
        foreach ($entry['properties'] as $key => $values) {
          $classes = $this->get_class_mapper();

          // check u-* params
          if ( in_array( $key, array_keys($classes) ) ) {
            foreach ($values as $value) {
              if ($value == $target) {
                $this->type = $classes[$key];

                return $entry;
              }
            }
          }
        }

        // check properties if target urls was mentioned
        foreach ($entry['properties'] as $key => $values) {
          if ( $key == "content" && preg_match_all("|<a[^>]+?".preg_quote($target, "|")."[^>]*>([^>]+?)</a>|", $values[0]['html'], $context) ) {
            return $entry;
          }
        }
      }
    }

    return false;
  }
}

new SemanticLinkbacksPlugin_MicroformatsHandler();