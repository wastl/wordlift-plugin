<?php

/**
 * Get the URI for the specified user ID (or create a new URI if the user's URI is not set yet).
 * @param int $user_id The user ID.
 * @return string|null The user URI or null if the user is not found.
 */
function wl_get_user_uri( $user_id ) {

    // Get the user URI.
    $uri = get_user_meta( $user_id, 'wl_uri', true );

    wl_write_log( "wl_get_user_uri [ user id :: $user_id ][ uri :: $uri ]" );

    // Create the URI if the URI is not yet set.
    if ( empty( $uri ) ) {
        $uri = wl_build_user_uri( $user_id );
        wl_set_user_uri( $user_id, $uri );
    }

    return $uri;
}

/**
 * Set the URI for the specified user.
 * @param int $user_id The user ID.
 * @param string $uri  The URI.
 */
function wl_set_user_uri( $user_id, $uri ) {

    wl_write_log( "wl_set_user_uri [ user id :: $user_id ][ uri :: $uri ]" );

    delete_user_meta( $user_id, 'wl_uri' );
    add_user_meta( $user_id, 'wl_uri', $uri );
}

/**
 * Build an URI for the specified user ID.
 * @param int $user_id The user ID.
 * @return null|string Null if the user is not found, or the URI.
 */
function wl_build_user_uri( $user_id ) {

    // Get the user with the specified ID.
    $user = wl_get_user( $user_id );

    // If the user is not found return null.
    if ( false === $user ) {
        wl_write_log( "wl_build_user_uri : no user found [ user id :: $user_id ]" );
        return null;
    }

    // Build the ID using the First and Last Name.
    if ( ! ( empty( $user->first_name ) && empty( $user->last_name ) ) ) {
        $id  = wl_sanitize_uri_path( $user->first_name . ' ' . $user->last_name );
    } else {
        // If there's no First and Last Name use the user ID.
        $id = $user_id;
    }

//    $uri = sprintf(
//        'http://data.redlink.io/%s/%s/%s/%s',
//        wl_config_get_user_id(),
//        wl_config_get_dataset(),
//        'user',
//        $id
//    );

    // Create the URL (dataset base URI has a trailing slash).
    $uri = sprintf( '%s/%s/%s', wl_config_get_dataset_base_uri(), 'user', $id );

    // Check that the URI doesn't exist already. If it exists, add a numeric suffix.
    $base_uri = $uri;
    $counter  = 1;
    while ( null !== wl_get_user_by_uri( $uri ) ) {
        $uri = $base_uri . "_" . ( $counter++ );
    }

    wl_write_log( "wl_build_user_uri [ user id :: $user_id ][ uri :: $uri ]" );

    return $uri;
}

/**
 * Get a user by his/her ID.
 * @param int $user_id The user ID.
 * @return bool|WP_User WP_User object or false if no user is found.
 */
function wl_get_user( $user_id ) {

    wl_write_log( "wl_get_user [ user id :: $user_id ]" );

    return get_user_by( 'id', $user_id );
}

/**
 * Get a user by his/her URI.
 * @param string $uri The URI
 * @return null|array The user data or null if not found.
 */
function wl_get_user_by_uri( $uri ) {

    $users = get_users( array(
        'number'     => 1,
        'meta_key'   => 'wl_uri',
        'meta_value' => $uri
    ) );

    if ( 0 === count( $users ) ) {
        wl_write_log( "wl_get_user_by_uri [ uri :: $uri ][ count :: 0 ]");
        return null;
    }

    wl_write_log( "wl_get_user_by_uri [ uri :: $uri ][ user id :: " . $users[0]->ID . " ]");
    return $users[0];
}

/**
 * Delete a user from the remote triple store (hooked to the *delete_user* hook).
 * @param int $user_id The user ID.
 */
function wl_before_delete_user( $user_id ) {

    wl_write_log( "wl_before_delete_user [ user id :: $user_id ]" );

    $uri   = wl_get_user_uri( $user_id );

    $query = rl_sparql_prefixes();
    $query .= <<<EOF
            DELETE { <$uri> ?p ?o . } WHERE { <$uri> ?p ?o . };
            DELETE { ?s ?p <$uri> . } WHERE { ?s ?p <$uri> . };
EOF;

    rl_execute_sparql_update_query( $query );
}
add_action( 'delete_user', 'wl_before_delete_user' );

/**
 * Called when a user is updated.
 * @param int $user_id The user ID.
 * @return true if successful otherwise false.
 */
function wl_update_user_profile( $user_id ) {

    wl_write_log( "wl_update_user_profile [ user id :: $user_id ]" );

    // Get the site language setting.
    $language   = wl_config_get_site_language();

    // Get the user.
    $user       = get_userdata( $user_id );

    // Get the user URI.
    $uri        = wl_get_user_uri( $user_id );

    // Get the first/last name and the posts URL.
    $first_name = wordlift_esc_sparql( $user->first_name );
    $last_name  = wordlift_esc_sparql( $user->last_name );
    $posts_url  = wordlift_esc_sparql( get_author_posts_url( $user_id ) );

    $query = rl_sparql_prefixes();
    $query .= <<<EOF
        DELETE { <$uri> schema:givenName ?o } WHERE { <$uri> schema:givenName ?o };
        DELETE { <$uri> schema:familyName ?o } WHERE { <$uri> schema:familyName ?o };
        DELETE { <$uri> schema:url ?o } WHERE { <$uri> schema:url ?o };
        INSERT DATA {
            <$uri> schema:givenName '$first_name'@$language .
            <$uri> schema:familyName '$last_name'@$language .
            <$uri> schema:url <$posts_url> .
        }
EOF;

    // Execute the query.
    return rl_execute_sparql_update_query( $query );
}
add_action( 'profile_update', 'wl_update_user_profile', 10, 1 );

/**
 * Create a user on the remote triple store (hooked to the action *user_register*).
 * @param int $user_id The user ID.
 * @return true if successful otherwise false.
 */
function wl_register_user( $user_id ) {

    wl_write_log( "wl_register_user [ user id :: $user_id ]" );

    return wl_update_user_profile( $user_id );
}
add_action( 'user_register', 'wl_register_user', 10, 1 );