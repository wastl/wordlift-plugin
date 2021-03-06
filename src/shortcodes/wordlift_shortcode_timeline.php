<?php

/**
 * This file provides methods for the shortcode *wl_timeline*.
 */

/**
 * Retrieve timeline events.
 *
 * @uses wl_get_referenced_entity_ids to retrieve the entities referenced by the specified post.
 *
 * @param int $post_id The post ID.
 * @return array An array of event posts.
 */
function wl_shortcode_timeline_get_events( $post_id = null ) {
	
	// Build list of event-entities.
	$entity_ids = null;
	if( is_null( $post_id ) ) {
		// TODO: Global timeline. Here we search for events that are from today on.
		return array();
	} else {
		// Post-specific timeline. Search for event-entities in the post itself.
		$entity_ids = wl_get_referenced_entity_ids( $post_id );
	}

    wl_write_log( "wl_shortcode_timeline_get_events [ entity IDs :: " . join( ', ', $entity_ids ) . " ]" );

    // Get all the entities that have a meta key with date start and end information.
    return get_posts( array(
        'post__in'        => $entity_ids,
        'post_type'       => WL_ENTITY_TYPE_NAME,
        'posts_per_page'  => -1,
        'meta_query'      => array(
            'relation'    => 'AND',
            array(
                'key'     => WL_CUSTOM_FIELD_CAL_DATE_START,
                'value'   => null,
                'compare' => '!=',
            ),
            array(
                'key'     => WL_CUSTOM_FIELD_CAL_DATE_END,
                'value'   => null,
                'compare' => '!=',
            )
        )
    ) );
}

/**
 * Convert timeline events to JSON.
 *
 * @used-by wl_shortcode_timeline_ajax
 */
function wl_shortcode_timeline_to_json( $posts ) {
	
	// If there are no events, return empty JSON
	if( empty( $posts ) || is_null( $posts ) )
		return json_encode('');
	
	// Model data from:
	// https://github.com/NUKnightLab/TimelineJS/blob/master/examples/example_json.json

    $timeline = array();
	$timeline['type'] = 'default';

    // Prepare for the starting slide data. The starting slide will be the one where *now* is between *start/end* dates.
    $start_at_slide = 0;
    $event_index = -1;
    $now = date_create();

    $timeline['date'] = array_map( function ( $post ) use ( &$timeline, &$event_index, &$start_at_slide, &$now ){

        $start_date = date_create_from_format( 'Y-m-d', get_post_meta( $post->ID, WL_CUSTOM_FIELD_CAL_DATE_START, true ) );
        $end_date = date_create_from_format( 'Y-m-d', get_post_meta( $post->ID, WL_CUSTOM_FIELD_CAL_DATE_END, true ) );

        // Set the starting slide.
        $event_index++;
        if ( 0 === $start_at_slide && $now >= $start_date && $now <= $end_date ) {
            $start_at_slide = $event_index;
        }

        $date['startDate'] = date_format( $start_date, 'Y,m,d' );
        $date['endDate']   = date_format( $end_date, 'Y,m,d' );
        $date['headline']  = '<a href="' . get_permalink( $post->ID ) . '">' . $post->post_title . '</a>';
        $date['text']      = $post->post_content;

        // Load thumbnail
        if ( '' !== ( $thumbnail_id = get_post_thumbnail_id( $post->ID ) ) &&
            false !== ( $attachment = wp_get_attachment_image_src( $thumbnail_id ) ) ) {

            $date['asset'] = array(
                'media' => $attachment[0]
            );

            // Add debug data.
            if ( WP_DEBUG ) {
                $date['debug'] = array(
                    'post' => $post,
                    'thumbnailId' => $thumbnail_id,
                    'attachment' => $attachment
                );
            }
        }

        return $date;

    }, $posts );


    // The *timeline* library expects the data to be encapsulated in a *timeline* element, e.g.:
    //  {timeline: ...}
	return json_encode( array(
        'timeline' => $timeline,
        'startAtSlide' => $start_at_slide
    ) );
}

/**
 * Retrieve timeline events and output them in JSON.
 *
 * @uses wl_shortcode_timeline_get_events to retrieve the list of events referenced by the specified Post ID.
 * @uses wl_shortcode_timeline_to_json to convert the result to JSON.
 */
function wl_shortcode_timeline_ajax()
{
	// Get the ID of the post who requested the timeline.
    $post_id = ( isset( $_REQUEST['post_id'] ) ? $_REQUEST['post_id'] : null );

    ob_clean();
    header( "Content-Type: application/json" );

    $result = wl_shortcode_timeline_get_events( $post_id );
    $result = wl_shortcode_timeline_to_json( $result );

    echo $result;

    wp_die();
}
add_action( 'wp_ajax_wl_timeline', 'wl_shortcode_timeline_ajax' );
add_action( 'wp_ajax_nopriv_wl_timeline', 'wl_shortcode_timeline_ajax' );


/**
 * Sets-up the widget. This is called by WordPress when the shortcode is inserted in the body.
 * 
 * @param array $atts An array of parameters set by the editor to customize the shortcode behaviour.
 * @return string
 */
function wl_shortcode_timeline( $atts ) {

    //extract attributes and set default values
    $timeline_atts = shortcode_atts( array(
        'width'      => '100%',
        'height'     => '600px',
        'main_color' => '#ddd'
    ), $atts );
	
	// Add timeline library.
	wp_enqueue_script(
        'timelinejs-storyjs-embed',
        plugins_url( 'bower_components/TimelineJS.build/build/js/storyjs-embed.js', __FILE__ )
    );
	wp_enqueue_script(
        'timelinejs',
        plugins_url( 'bower_components/TimelineJS.build/build/js/timeline-min.js', __FILE__ )
    );

	// Add wordlift-ui script.
	wp_enqueue_script( 'wordlift-ui', plugins_url( 'js/wordlift.ui.js', __FILE__ ), array( 'jquery' ) );
	wp_localize_script( 'wordlift-ui', 'wl_timeline_params', array(
        'ajax_url' => admin_url('admin-ajax.php'),	// Global param
        'action'   => 'wl_timeline'					// Global param
    ) );
	 
	$post_id = get_the_ID();

    // Escaping atts.
    $esc_class      = esc_attr( 'wl-timeline' );
    $esc_id         = esc_attr( 'wl-timeline-' . $post_id );
	$esc_width      = esc_attr( $timeline_atts['width'] );
	$esc_height     = esc_attr( $timeline_atts['height'] );
    $esc_post_id 	= esc_attr( $post_id );

    // TODO: check this, are they parameters or constants?
    $esc_depth		= esc_attr( 2 ); //$timeline_atts['depth']);
    $esc_main_color = esc_attr( '#aaa' ); //$timeline_atts['main_color']);
    
	// Building template.
    // TODO: in the HTML code there are static CSS rules. Move them to the CSS file.
    return <<<EOF
<div class="$esc_class" id="$esc_id" data-post-id="$esc_post_id" data-depth="$esc_depth"
    data-main-color="$esc_main_color"
	style="width:$esc_width;
        height:$esc_height;
        background-color:$esc_main_color;
        margin-top:10px;
        margin-bottom:10px">
</div>
EOF;
}

/**
 * Registers the *wl-timeline* shortcode.
 */
function wl_shortcode_timeline_register() {
    add_shortcode( 'wl-timeline', 'wl_shortcode_timeline' );
}
add_action( 'init', 'wl_shortcode_timeline_register' );
