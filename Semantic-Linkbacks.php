<?php
/*
 Plugin Name: Semantic-Linkbacks
 Plugin URI: https://github.com/acegiak/Semantic-Linkbacks
 Description: Semantic Linkbacks for webmentions and pingbacks
 Author: pfefferle & acegiak
 Author URI: http://notizblog.org/
 Version: 2.0.0-dev
*/
use Mf2;

/**
 * all supported url types
 *
 * @return array
 */
function webmention_get_supported_url_types() {
  return apply_filters("webmention_supported_url_types", array("in-reply-to", "like", "mention"));
}

/**
 * helper to find the correct h-entry node
 * 
 * @param array $mf_array the parsed microformats array
 * @param string $target the target url
 * @return array|false the h-entry node or false
 */
function webmention_hentry_walker( $mf_array, $target ) {
  // some basic checks
  if ( !is_array( $mf_array ) )
    return false;
  if ( !isset( $mf_array["items"] ) )
    return false;
  if ( count( $mf_array["items"] ) == 0 )
    return false;
  
  // iterate array
  foreach ($mf_array["items"] as $mf) {    
    if ( isset( $mf["type"] ) ) {
      // only h-entries are important
      if ( in_array( "h-entry", $mf["type"] ) ) {
        if ( isset( $mf['properties'] ) ) {
          // check properties if target urls was mentioned
          foreach ($mf['properties'] as $key => $values) {
            // check u-* params at first      
            if ( in_array( $key, webmention_get_supported_url_types() )) {
              foreach ($values as $value) {
                if ($value == $target) {
                  return $mf['properties'];
                }
              }
            // check content as fallback
            } elseif ( in_array( $key, array("content", "summary", "name")) && preg_match_all("|<a[^>]+?".preg_quote($target, "|")."[^>]*>([^>]+?)</a>|", $values[0], $context) ) {
              return $mf['properties'];
            }
          }
        }
      // if root is h-feed, than hop into the "children" array to find some
      // h-entries
      } elseif ( in_array( "h-feed", $mf["type"]) && isset($mf['children']) ) {
        $temp = array("items" => $mf['children']);
        return webmention_hentry_walker($temp, $target);
      }
    }
  }
  
  return false;
}

function webmention_to_comment( $html, $source, $target, $post, $commentdata = null ) {
  global $wpdb;
  
  // check commentdata
  if ( $commentdata == null ) {
    $comment_post_ID = (int) $post->ID;
    $commentdata = array('comment_post_ID' => $comment_post_ID, 'comment_author' => '', 'comment_author_url' => '', 'comment_author_email' => '', 'comment_content' => '', 'comment_type' => '', 'comment_ID' => '');
    
    if ( $comments = get_comments( array('meta_key' => 'webmention_source', 'meta_value' => $source) ) ) {
      $comment = $comments[0];
      $commentdata['comment_ID'] = $comment->comment_ID;
    }
  }
  
  // check if there is a parent comment
  if ( $query = parse_url($target, PHP_URL_QUERY) ) {
    parse_str($query);
    if (isset($replytocom) && get_comment($replytocom)) {
      $commentdata['comment_parent'] = $replytocom;
    }
  }
  
  // reset content type
  $commentdata['comment_type'] = '';
  
  // parse source html
  $result = Mf2\parse($html);
  
  // search for a matching h-entry
  $hentry = webmention_hentry_walker($result, $target);
  
  if (!$hentry) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(array("error"=> "no_link_found"));
    exit;
  }
  
  // try to find some content
  // @link http://indiewebcamp.com/comments-presentation
  if (isset($hentry['summary'])) {
    $commentdata['comment_content'] = $wpdb->escape($hentry['summary'][0]);
  } elseif (isset($hentry['content'])) {
    $commentdata['comment_content'] = $wpdb->escape($hentry['content'][0]);
  } elseif (isset($hentry['name'])) {
    $commentdata['comment_content'] = $wpdb->escape($hentry['name'][0]);
  } else {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(array("error"=> "no_content_found"));
    exit;
  }
  
  // set the right date
  if (isset($hentry['published'])) {
    $time = strtotime($hentry['published'][0]);
    $commentdata['comment_date'] = date("Y-m-d H:i:s", $time);
  } elseif (isset($hentry['updated'])) {
    $time = strtotime($hentry['updated'][0]);
    $commentdata['comment_date'] = date("Y-m-d H:i:s", $time);
  }

  $author = null;
  
  // check if h-card has an author
  if ( isset($hentry['author']) && isset($hentry['author'][0]['properties']) ) {
    $author = $hentry['author'][0]['properties'];
  }
  
  // else get representative hcard
  if (!$author) {
    foreach ($result["items"] as $mf) {    
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
      $commentdata['comment_author'] = $wpdb->escape($author['name'][0]);
    }
    
    if (isset($author['email'])) {
      $commentdata['comment_author_email'] = $wpdb->escape($author['email'][0]);
    }
    
    if (isset($author['url'])) {
      $commentdata['comment_author_url'] = $wpdb->escape($author['url'][0]);
    }
  }
  
  // check if it is a new comment or an update
  if ( $commentdata['comment_ID'] ) {
    wp_update_comment($commentdata);
    $comment_ID = $commentdata['comment_ID'];
  } else {
    $comment_ID = wp_insert_comment($commentdata);
  }
  
  // add source url as comment-meta
  add_comment_meta( $comment_ID, "webmention_source", $source, true );
  
  if (isset($author['photo'])) {
    // add photo url as comment-meta
    add_comment_meta( $comment_ID, "webmention_avatar", $author['photo'][0], true );
  }
}

function webmention_pingback_fix($comment_ID) {
  $commentdata = get_comment($comment_ID, ARRAY_A);
  
  if (!$commentdata) {
    return false;
  }
  
  $post = get_post($commentdata['comment_post_ID'], ARRAY_A);
  
  if (!$post) {
    return false;
  }
  
  $target = get_permalink($post['ID']);
  $response = wp_remote_get( $commentdata['comment_author_url'] );
  
  if ( is_wp_error( $response ) ) {
    return false;
  }

  $html = wp_remote_retrieve_body( $response );

  webmention_to_comment( $html, $commentdata['comment_author_url'], $target, $post, $commentdata );
}

add_action( 'pingback_post', 'linkback_fix', 90, 1 );
add_action( 'trackback_post', 'linkback_fix', 90, 1 ); 
add_action( 'webmention_post', 'linkback_fix', 90, 1 );



?>
