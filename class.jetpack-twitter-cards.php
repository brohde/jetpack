<?php

/* Twitter Cards
 *
 * Hooks onto the Open Graph protocol and extends it by adding only the tags
 * we need for twitter cards.
 *
 * @see /wp-content/blog-plugins/open-graph.php
 * @see https://dev.twitter.com/docs/cards
 */
class Jetpack_Twitter_Cards {

	static function twitter_cards_tags( $og_tags ) {
		global $post;

		if( post_password_required() )
			return $og_tags;

		if ( apply_filters( 'jetpack_disable_twitter_cards', false ) )
			return $og_tags;

		/*
		 * These tags apply to any page (home, archives, etc)
		 */

		$site_tag = apply_filters( 'jetpack_twitter_cards_site_tag', self::site_tag() ); 
		$og_tags['twitter:site'] = self::sanitize_twitter_user ( $site_tag );

		if ( ! is_singular() || ! empty( $og_tags['twitter:card'] ) )
			return $og_tags;

		/*
		 * The following tags only apply to single pages.
		 */

		$card_type = 'summary';

		// Try to give priority to featured images
		if ( class_exists('Jetpack_PostImages') ) {
			$featured = Jetpack_PostImages::from_thumbnail( $post->ID, 240, 240 );
			if ( !empty( $featured ) && count( $featured ) > 0 ) {
				if ( (int) $featured[0]['src_width'] >= 280 && (int) $featured[0]['src_height'] >= 150 ) {
					$card_type = 'summary_large_image';
					$og_tags['twitter:image:src'] = add_query_arg( 'w', 640, $featured[0]['src'] );
				} else {
					$og_tags['twitter:image'] = add_query_arg( 'w', 240, $featured[0]['src'] );
				}
			}
		}

		// Only proceed with media analysis if a featured image has not superseded it already.
		if ( empty( $og_tags['twitter:image'] ) && empty( $og_tags['twitter:image:src'] ) ) {
			if ( ! class_exists( 'Jetpack_Media_Summary' ) && defined('IS_WPCOM') && IS_WPCOM )
				include WP_CONTENT_DIR . '/lib/class.wpcom-media-summary.php';

			// Test again, class should already be auto-loaded in Jetpack.
			// If not, skip extra media analysis and stick with a summary card
			if ( class_exists( 'Jetpack_Media_Summary' ) ) {
				$extract = Jetpack_Media_Summary::get( $post->ID );

				if ( 'gallery' == $extract['type'] ) {
					list( $og_tags, $card_type ) = self::twitter_cards_define_type_based_on_image_count( $og_tags, $extract );
				} else if ( 'video' == $extract['type'] ) {
					// Leave as summary, but with large pict of poster frame (we know those comply to Twitter's size requirements)
					$card_type = 'summary_large_image';
					$og_tags['twitter:image:src'] = add_query_arg( 'w', 640, $extract['image'] );
				} else {
					list( $og_tags, $card_type ) = self::twitter_cards_define_type_based_on_image_count( $og_tags, $extract );
				}
			}
		}

		$og_tags['twitter:card'] = $card_type;

		// If we have information on the author/creator, then include that as well
		if ( ! empty( $post ) && ! empty( $post->post_author ) ) {
			$handle = apply_filters( 'jetpack_sharing_twitter_via', '', $post->ID );
			if ( ! empty( $handle ) && 'wordpressdotcom' != $handle && 'jetpack' != $handle )
				$og_tags['twitter:creator'] = self::sanitize_twitter_user ( $handle );
		}

		// Make sure we have a description for Twitter, their validator isn't happy without some content (single space not valid).
		if ( ! isset( $og_tags['og:description'] ) || '' == trim( $og_tags['og:description'] ) ) { // empty( trim( $og_tags['og:description'] ) ) isn't valid php
			$has_creator = ( !empty($og_tags['twitter:creator']) && '@wordpressdotcom' != $og_tags['twitter:creator'] ) ? true : false;
			if ( 'photo' == $card_type )
				$og_tags['twitter:description'] = ( $has_creator ) ? sprintf( __('Photo post by %s.', 'jetpack'), $og_tags['twitter:creator'] ) : __('Photo post.', 'jetpack');
			else if ( !empty( $extract ) && 'video' == $extract['type'] ) // use $extract['type'] since $card_type is 'summary' for video posts
				$og_tags['twitter:description'] = ( $has_creator ) ? sprintf( __('Video post by %s.', 'jetpack'), $og_tags['twitter:creator'] ) : __('Video post.', 'jetpack');
			else if ( 'gallery' == $card_type )
				$og_tags['twitter:description'] = ( $has_creator ) ? sprintf( __('Gallery post by %s.', 'jetpack'), $og_tags['twitter:creator'] ) : __('Gallery post.', 'jetpack');
			else
				$og_tags['twitter:description'] = ( $has_creator ) ? sprintf( __('New post by %s.', 'jetpack'), $og_tags['twitter:creator'] ) : __('New post.', 'jetpack');
		}

		return $og_tags;
	}

	static function sanitize_twitter_user( $str ) {
		return '@' . preg_replace( '/^@/', '', $str );
	}

	static function twitter_cards_define_type_based_on_image_count( $og_tags, $extract ) {
		$card_type = 'summary';
		$img_count = $extract['count']['image'];

		if ( empty( $img_count ) ) {
			// No images, use Blavatar as a thumbnail for the summary type.
			if ( function_exists('blavatar_domain') ) {
				$blavatar_domain = blavatar_domain(site_url());
				if ( blavatar_exists( $blavatar_domain ) )
					$og_tags['twitter:image'] = blavatar_url( $blavatar_domain, 'img', 240);
			}
			// Not falling back on Gravatar, because there's no way to know if we end up with an auto-generated one.
		} else if  ( 1 == $img_count && ( 'image' == $extract['type'] || 'gallery' == $extract['type'] ) ) {
			// 1 image = photo
			// Test for $extract['type'] to limit to image and gallery, so we don't send a potential fallback image like a Gravatar as a photo post.
			$card_type = 'photo';
			$og_tags['twitter:image'] = add_query_arg( 'w', 1400, ( empty( $extract['images'] ) ) ? $extract['image'] : $extract['images'][0]['url'] );
		} else if ( $img_count <= 3 ) {
			// 2-3 images = summary with small thumbnail
			$og_tags['twitter:image'] = add_query_arg( 'w', 240, ( empty( $extract['images'] ) ) ? $extract['image'] : $extract['images'][0]['url'] );
		} else if ( $img_count >= 4 ) {
			// >= 4 images = gallery
			$card_type = 'gallery';
			$og_tags = self::witter_cards_gallery( $extract, $og_tags );
		}

		return array( $og_tags, $card_type );
	}

	static function twitter_cards_gallery( $extract, $og_tags ) {
		foreach( $extract['images'] as $key => $value ) {
			if ( $key > 3 )
				break; // Can only send a max of 4 picts (https://dev.twitter.com/docs/cards/types/gallery-card)
			$og_tags[ 'twitter:image' . $key ] = add_query_arg( 'w', 640, $value['url'] );
		}
		return $og_tags;
	}

	static function twitter_cards_output( $og_tag ) {
		return ( false !== strpos( $og_tag, 'twitter:' ) ) ? preg_replace( '/property="([^"]+)"/', 'name="\1"', $og_tag ) : $og_tag;
	}

	static function settings_init() {
		add_settings_section( 'jetpack-twitter-cards-settings', 'Twitter Cards', '__return_false', 'sharing' );
		add_settings_field( 'jetpack-twitter-cards-site-tag',  __( 'Twitter Site Tag', 'jetpack' ), array( __CLASS__, 'settings_field' ), 'sharing', 'jetpack-twitter-cards-settings', array(
			'label_for' => 'jetpack-twitter-cards-site-tag'
		) );
	}

	static function sharing_global_options() {
		do_settings_fields( 'sharing', 'jetpack-twitter-cards-settings' );
	}

	static function site_tag() { 
		$site_tag = get_option( 'jetpack-twitter-cards-site-tag' );
		if ( empty( $site_tag ) ) {
			$site_tag = ( defined( 'IS_WPCOM' ) && IS_WPCOM )? '@wordpressdotcom' : '@jetpack';
		}
		return $site_tag;
	} 
 
	static function settings_field() {
		wp_nonce_field( 'jetpack-twitter-cards-settings', 'jetpack_twitter_cards_nonce', false ); ?>
		<input type="text" id="jetpack-twitter-cards-site-tag" class="regular-text" name="jetpack-twitter-cards-site-tag" value="<?php echo esc_attr( get_option( 'jetpack-twitter-cards-site-tag' ) ); ?>" />
		<p class="description" style="width: auto;"><?php _e( 'The Twitter username of the owner of this site\'s domain.', 'jetpack-twitter-cards-site-tag' ); ?></p>
		<?php
	}

	static function settings_validate() {
		if ( wp_verify_nonce( $_POST['jetpack_twitter_cards_nonce'], 'jetpack-twitter-cards-settings' ) ) {
			update_option( 'jetpack-twitter-cards-site-tag', trim( ltrim( strip_tags( $_POST['jetpack-twitter-cards-site-tag'] ), '@' ) ) );
		}
	}

	static function init() {
		add_filter( 'jetpack_open_graph_tags', array( __CLASS__, 'twitter_cards_tags' ) );
		add_filter( 'jetpack_open_graph_output', array( __CLASS__, 'twitter_cards_output' ) );
		add_filter( 'jetpack_twitter_cards_site_tag', array( __CLASS__, 'site_tag' ), -99 );
		add_action( 'admin_init', array( __CLASS__, 'settings_init' ) );
		add_action( 'sharing_global_options', array( __CLASS__, 'sharing_global_options' ) );
		add_action( 'sharing_admin_update', array( __CLASS__, 'settings_validate' ) );
	}
}

Jetpack_Twitter_Cards::init();
