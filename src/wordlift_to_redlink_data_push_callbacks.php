<?php

/**
 * Save the post to the triple store. Also saves the entities locally and on the triple store.
 * @param int $post_id The post id being saved.
 */
function wordlift_save_post_and_related_entities($post_id) {

    // ignore autosaves
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
        return;

    // read the user id and dataset name from the options.
    $user_id    = wordlift_configuration_user_id();
    $dataset_id = wordlift_configuration_dataset_id();

    // get the current post.
    $post = get_post($post_id); 

    // save the author and get the author URI.
    $author_uri = wordlift_save_author( $post->post_author );

    // set the post URI in the triple store.
    $post_uri   = "http://data.redlink.io/$user_id/$dataset_id/post/$post->ID";
    $date_published = get_the_time('c', $post);
    $date_modified  = get_post_modified_time( 'c', true, $post );
    $user_comments_count = $post->comment_count;

    // create the SPARQL query.
    $sparql  = "<$post_uri> rdfs:label '" . wordlift_esc_sparql($post->post_title) . "' . \n";
    $sparql .= "<$post_uri> a <http://schema.org/BlogPosting> . \n";
    $sparql .= "<$post_uri> schema:url <" . wordlift_esc_sparql(get_permalink($post->ID)) . "> . \n";
    $sparql .= "<$post_uri> schema:datePublished '" . wordlift_esc_sparql($date_published) . "' . \n";
    $sparql .= "<$post_uri> schema:dateModified '" . wordlift_esc_sparql($date_modified) . "' . \n";
    $sparql .= "<$post_uri> schema:author <$author_uri> . \n";
    $sparql .= "<$post_uri> schema:interactionCount 'UserComments:$user_comments_count' . \n";

    // get all the images attached to the post.
    $images = get_children( array (
        'post_parent'    => $post_id,
        'post_type'      => 'attachment',
        'post_mime_type' => 'image'
    ));

    // if images are found, add them to the triple store.
    if ( ! empty($images) ) {
        foreach ( $images as $attachment_id => $attachment ) {
            $image_attrs = wp_get_attachment_image_src( $attachment_id, 'full' );

            $sparql .= "<$post_uri> schema:image <$image_attrs[0]> . \n";
        }
    }

    
    // this array will hold all the entities found in this post.
    $entity_post_ids = array();
    $entities = isset($_POST['entities']) ? $_POST['entities'] : array(); 

    write_log("Going to loop on related entity/ies ...");
        
    // Loops on founded span tags
    foreach ($entities as $entity) {
        write_log("Within the loop on related entity/ies ...");
        
         write_log('ok2');
         write_log($entity["id"]);
        // If itemid attribute is set, then the node is a textAnnotation
    	// if ($entity['id']) {

            
            $entity_label = $entity['label'];
            $entity_id    = $entity['id'];
            $entity_type  = $entity['type'];
            $entity_description  = $entity['description'];

            // create or update the entity in WordPress and get the entity URI.
            $entity_posts = wordlift_save_entity_post($entity_id, $entity_label, $entity_type, $entity_description);

            write_log('[ entity_posts :: ' . count($entity_posts) . ' ]');

            foreach ($entity_posts as $entity_post) {
                if (!in_array($entity_post->ID, $entity_post_ids)) {
                    // add the entity post id to the array.
                    array_push($entity_post_ids, $entity_post->ID);
                    // get the entity URI and create a reference.
                    $entity_uri = get_post_meta($entity_post->ID, 'entity_url', true);
                    // create the sparql query.
                    $sparql     .= "<$post_uri>   dcterms:references <$entity_uri> . \n";
                }
            }
    	// }
    }

    // remove the reference to this post from related entities.
    // get the list of related entities.
    $existing_related_entities_ids = get_post_meta( $post_id, 'wordlift_related_entities', true );
    write_log("existing_related_entities_ids [ post_id :: $post_id ][ count :: " . count( $existing_related_entities_ids ) . " ][ is_array :: " . is_array( $existing_related_entities_ids ) . " ]\n");

    // for each entity, remove the reference to the post.
    if ( is_array( $existing_related_entities_ids ) ) {
        foreach ( $existing_related_entities_ids as $id ) {

            $related_posts_ids = get_post_meta( $id, 'wordlift_related_posts', true );
            $related_posts_ids = array_diff( $related_posts_ids, array( $post_id ) );
            delete_post_meta( $id, 'wordlift_related_posts' );
            add_post_meta( $id, 'wordlift_related_posts', $related_posts_ids, true );
            write_log("add_post_meta( $id, 'wordlift_related_posts', " . join( ', ', $related_posts_ids ) . ", true )\n");
        }
    }

    // reset the relationships.
    delete_post_meta( $post_id, 'wordlift_related_entities' );
    add_post_meta( $post_id, 'wordlift_related_entities', $entity_post_ids, true );
    write_log("add_post_meta( $post_id, 'wordlift_related_entities', " . join( ', ', $entity_post_ids ) . ", true )\n");

    // add the relationships to the post from the entities side.
    // for each entity, remove the reference to the post.
    if ( is_array( $entity_post_ids ) ) {
        foreach ( $entity_post_ids as $id ) {
            $related_posts_ids = get_post_meta( $id, 'wordlift_related_posts', true );
            if ( !is_array( $related_posts_ids ) ) {
                $related_posts_ids = array();
            }
            array_push( $related_posts_ids, $post_id );
            delete_post_meta( $id, 'wordlift_related_posts' );
            add_post_meta( $id, 'wordlift_related_posts', $related_posts_ids, true );
            write_log("add_post_meta( $id, 'wordlift_related_posts', " . join( ', ', $related_posts_ids ) . ", true )\n");
        }
    }

    // create the query:
    //  - remove existing references to entities.
    //  - set the new post information (including references).
    $query = wordlift_get_ns_prefixes() . <<<EOF
            DELETE { <{$post_uri}> dcterms:references ?o . }
            WHERE  { <{$post_uri}> dcterms:references ?o . };
            DELETE { <{$post_uri}> schema:url ?o . }
            WHERE  { <{$post_uri}> schema:url ?o . };
            DELETE { <{$post_uri}> schema:datePublished ?o . }
            WHERE  { <{$post_uri}> schema:datePublished ?o . };
            DELETE { <{$post_uri}> schema:dateModified ?o . }
            WHERE  { <{$post_uri}> schema:dateModified ?o . };
            DELETE { <{$post_uri}> a ?o . }
            WHERE  { <{$post_uri}> a ?o . };
            DELETE { <{$post_uri}> rdfs:label ?o . }
            WHERE  { <{$post_uri}> rdfs:label ?o . };
            DELETE { <{$post_uri}> schema:image ?o . }
            WHERE  { <{$post_uri}> schema:image ?o . };
            DELETE { <{$post_uri}> schema:interactionCount ?o . }
            WHERE  { <{$post_uri}> schema:interactionCount ?o . };
            INSERT DATA { $sparql }
EOF;

    // execute the query.
    wordlift_push_data_triple_store($query);
}

/**
 * Save the specified author to the triple store.
 * @param $author_id
 * @return The author URI.
 */
function wordlift_save_author( $author_id ) {

    // read the user id and dataset name from the options.
    $user_id    = wordlift_configuration_user_id();
    $dataset_id = wordlift_configuration_dataset_id();
    $author_uri = "http://data.redlink.io/$user_id/$dataset_id/author/$author_id";

    $name        = wordlift_esc_sparql( get_the_author_meta( 'display_name', $author_id ) );
    $email       = wordlift_esc_sparql( get_the_author_meta( 'email', $author_id ) );
    $given_name  = wordlift_esc_sparql( get_the_author_meta( 'first_name', $author_id ) );
    $family_name = wordlift_esc_sparql( get_the_author_meta( 'last_name', $author_id ) );
    $description = wordlift_esc_sparql( get_the_author_meta( 'description', $author_id ) );
    $url         = wordlift_esc_sparql( get_author_posts_url( 'user_url' ) );

    $sparql = "<$author_uri> a <http://schema.org/Person> . ";
    if ( !empty( $name ) ) {
        $sparql .= "<$author_uri> schema:name '$name' . ";
    }
    if ( !empty( $given_name ) ) {
        $sparql .= "<$author_uri> schema:givenName '$given_name' . ";
    }
    if ( !empty( $family_name ) ) {
        $sparql .= "<$author_uri> schema:familyName '$family_name' . ";
    }
    if ( !empty( $email ) ) {
        $sparql .= "<$author_uri> schema:email '$email' . ";
    }
    if ( !empty( $description ) ) {
        $sparql .= "<$author_uri> schema:description '$description' . ";
    }
    if ( !empty( $url ) ) {
        $sparql .= "<$author_uri> schema:url <$url> . ";
    }

    $query = wordlift_get_ns_prefixes() . <<<EOF
            DELETE { <$author_uri> a ?o . }
            WHERE  { <$author_uri> a ?o . };
            DELETE { <$author_uri> schema:name ?o . }
            WHERE  { <$author_uri> schema:name ?o . };
            DELETE { <$author_uri> schema:givenName  ?o . }
            WHERE  { <$author_uri> schema:givenName  ?o . };
            DELETE { <$author_uri> schema:familyName ?o . }
            WHERE  { <$author_uri> schema:familyName ?o . };
            DELETE { <$author_uri> schema:email ?o . }
            WHERE  { <$author_uri> schema:email ?o . };
            DELETE { <$author_uri> schema:description ?o . }
            WHERE  { <$author_uri> schema:description ?o . };
            DELETE { <$author_uri> schema:url ?o . }
            WHERE  { <$author_uri> schema:url ?o . };
            INSERT DATA { $sparql }
EOF;

    // execute the query.
    wordlift_push_data_triple_store($query);

    return $author_uri;
}

/**
 * Save the specified entity to WordPress.
 * @param string $uri   The entity URI (local or remote).
 * @param string $label The entity label.
 * @param string $type  The entity type.
 * @param string $description  The entity description.
 * @return array        An array of posts.
 */
function wordlift_save_entity_post($uri, $label, $type, $description) {

    write_log("wordlift_add_or_update_related_entity_post($uri, $label, $type)");

    // get the entity posts.
    $entity_posts = wordlift_get_entity_posts_by_uri($uri);

    if ( 0 < count( $entity_posts ) ) {
        write_log("wordlift_add_or_update_related_entity_post: found " . count($entity_posts) . " entity/ies");
        // if there are entities, return the local URI of the first one.
        // TODO: handle more entities.
        return $entity_posts;
    }

    // there are no entities, create a new one.
    $params = array(
        'post_status'  => 'draft',
        'post_type'    => 'entity',
        'post_title'   => $label,
        'post_content' => $description,
        'post_excerpt' => ''
    );

    // get a local URI for the entity.
    // TODO: check that an entity with the provided URL doesn't exist yet.
    $local_uri = wordlift_get_custom_dataset_entity_uri($uri);

    if(!empty($type)) {
        $fragments = explode('/', $type);
        $taxo_type = end($fragments);
        $params['tax_input'] = array( 'entity_type' => array( $taxo_type ) );
    }

    // create or update the post.
//    remove_action('save_post', 'wordlift_save_post');
    $post_id = wp_insert_post($params, false);
//    add_action('save_post', 'wordlift_save_post');

    // TODO: handle errors.
    if (false === $post_id) {
        // inform an error occurred.
        return array();
    }

    write_log("update_post_meta( $post_id, 'entity_url', $local_uri )");

    update_post_meta( $post_id, 'entity_url', $local_uri );
    // set the same_as uri as the original URI, if it differs from the local uri.
    if ($local_uri !== $uri) {
        update_post_meta( $post_id, 'entity_same_as', $uri );
    }
    // save the entity in the triple store.
    wordlift_save_entity_to_triple_store($post_id);

    // finally return the entity post.
    return array(get_post($post_id));
}

/**
 * Create an URI on the custom dataset based on an existing URI.
 * @param $uri
 */
function wordlift_get_custom_dataset_entity_uri($uri) {

    // TODO: check for naming collision.

    // read the user id and dataset name from the options.
    $user_id    = wordlift_configuration_user_id();
    $dataset_id = wordlift_configuration_dataset_id();

    $fragments  = explode('/', $uri);
    $name       = end($fragments);

    // set the post URI in the triple store.
    return "http://data.redlink.io/$user_id/$dataset_id/resource/$name";
}

/**
 * Find entity posts by the entity URI. Entity as searched by their entity URI or same as.
 * @param string $uri The entity URI.
 * @return array mixed An array of posts.
 */
function wordlift_get_entity_posts_by_uri($uri) {

    $query = new WP_Query( array(
            'post_status' => 'any',
            'post_type'   => 'entity',
            'meta_query'  => array(
                'relation' => 'OR',
                array(
                    'key'     => 'entity_url',
                    'value'   => $uri,
                    'compare' => '='
                ),
                // TODO: the entity_same_as must be changed to an array.
                array(
                    'key'     => 'entity_same_as',
                    'value'   => $uri,
                    'compare' => '='
                )
            )
        )
    );

    return $query->get_posts();
}

/**
 * Execute the query against the triple store.
 * @param string $query A SPARQL query.
 * @return bool
 */
function wordlift_push_data_triple_store($query) {

    // get the configuration.
    $api_version = '1.0-ALPHA';
    $dataset_id  = wordlift_configuration_dataset_id();
    $app_key     = wordlift_configuration_application_key();

    // construct the API URL.
    $api_url = "https://api.redlink.io/$api_version/data/$dataset_id/sparql/update?key=$app_key";

    // post the request.
    $response = wp_remote_post($api_url, array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true, // switched to not blocking.
            'headers'     => array(
                'Content-type' => 'application/sparql-update; charset=utf-8',
            ),
            'body' => $query,
            'sslverify'   => false,
            'cookies'     => array()
        )
    );

    write_log("=============================================================\n");
    write_log("API URL: $api_url\n");
    write_log("Query:\n");
    write_log("$query\n");
    write_log("=============================================================\n");
    write_log(var_export($response, true));
    write_log("=============================================================\n");

    // TODO: handle errors.
    if ( is_wp_error( $response ) || 200 !== $response['response']['code'] ) {

        write_log("== ERROR        =============================================\n");
        write_log("=============================================================\n");

        return false;
    }

    return true;
}

/**
 * Receive events from post saves, and split them according to the post type.
 * @param int $post_id The post id.
 */
function wordlift_save_post($post_id) {

    // ignore revisions.
    if ( is_numeric(wp_is_post_revision( $post_id ))) {
        return;
    }

    // get the post.
    $post = get_post($post_id);

    // if it's an entity, raise the *wordlift_save_entity* event.
    if ('entity' === $post->post_type) {
        do_action('wordlift_save_entity', $post_id);
    }

    // raise the *wordlift_save_post* event.
    do_action('wordlift_save_post', $post_id);
}

/**
 * Save the entity represented by the specified post_id to the Redlink triple-store.
 * @param $post_id The entity post ID.
 */
function wordlift_save_entity_to_triple_store( $post_id ) {

    write_log("wordlift_save_entity_to_triple_store( $post_id )");

    // get the post.
    $post    = get_post( $post_id );

    // get the title and content as label and description.
    $label   = $post->post_title;
    $descr   = $post->post_content;

    // get the entity URI.
    $uri     = get_post_meta( $post_id, 'entity_url', true );
    // TODO: raise an error if the URI is not set.
    if ( empty( $uri ) ) {
        write_log('The entity URI is missing.');
        return;
    }

    // create a new empty statement.
    $sparql  = '';

    // set the same as.
    $same_as = get_post_meta( $post_id, 'entity_same_as', true );
    foreach ( explode( "\r\n", $same_as ) as $s ) {
        if ( !empty($s) ) {
            $sparql  .= "<$uri> owl:sameAs <$s> . \n";
        }
    }

    // set the label
    $sparql  .= "<$uri> rdfs:label '" . wordlift_esc_sparql($label) . "' . \n";
    // set the URL
    $sparql  .= "<$uri> schema:url <" . get_permalink( $post_id ) . "> . \n";

    // set the description.
    if (!empty($descr)) {
        $sparql  .= "<$uri> schema:description '" . wordlift_esc_sparql($descr) . "' . \n";
    }

    $types   = wp_get_post_terms( $post->ID, 'entity_type' );
    // Support type are only schema.org ones: it could by null
    foreach ($types as $type) {
        $sparql .= "<$uri> a <http://schema.org/$type->name> . \n";
    }

    // get related entities.
    $related_entities_ids = get_post_meta( $post_id, 'wordlift_related_entities', true );
    foreach ( $related_entities_ids as $entity_id ) {
        $entity_uri = wordlift_esc_sparql( get_post_meta( $entity_id, 'entity_url', true ) );
        // create a two-way relationship.
        $sparql .= " <$uri> dct:relation <$entity_uri> . ";
        $sparql .= " <$entity_uri> dct:relation <$uri> . ";
    }

    $query = wordlift_get_ns_prefixes() . <<<EOF
    DELETE { <$uri> rdfs:label ?o }
    WHERE  { <$uri> rdfs:label ?o };
    DELETE { <$uri> owl:sameAs ?o . }
    WHERE  { <$uri> owl:sameAs ?o . };
    DELETE { <$uri> schema:description ?o . }
    WHERE  { <$uri> schema:description ?o . };
    DELETE { <$uri> schema:url ?o . }
    WHERE  { <$uri> schema:url ?o . };
    DELETE { <$uri> a ?o . }
    WHERE  { <$uri> a ?o . };
    INSERT DATA { $sparql }
EOF;

    wordlift_push_data_triple_store($query);
}

/**
 * Get a string representing the NS prefixes for a SPARQL query.
 * @return string The PREFIX lines.
 */
function wordlift_get_ns_prefixes() {

    return <<<EOF
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl: <http://www.w3.org/2002/07/owl#>
PREFIX schema: <http://schema.org/>
PREFIX dct: <http://purl.org/dc/terms/>

EOF;

}

/**
 * Escape a sparql literal.
 * @param string $string The string to escape.
 * @return string The escaped string.
 */
function wordlift_esc_sparql($string) {
    // see http://www.w3.org/TR/rdf-sparql-query/
    //    '\t'	U+0009 (tab)
    //    '\n'	U+000A (line feed)
    //    '\r'	U+000D (carriage return)
    //    '\b'	U+0008 (backspace)
    //    '\f'	U+000C (form feed)
    //    '\"'	U+0022 (quotation mark, double quote mark)
    //    "\'"	U+0027 (apostrophe-quote, single quote mark)
    //    '\\'	U+005C (backslash)

    $string = str_replace('\\', '\\\\', $string);
    $string = str_replace('\'', '\\\'', $string);
    $string = str_replace('"', '\\"', $string);
    $string = str_replace('\f', '\\f', $string);
    $string = str_replace('\b', '\\b', $string);
    $string = str_replace('\r', '\\r', $string);
    $string = str_replace('\n', '\\n', $string);
    $string = str_replace('\t', '\\t', $string);

    return $string;
}

// hook save events.
add_action('save_post', 'wordlift_save_post');
add_action('wordlift_save_post', 'wordlift_save_post_and_related_entities');
add_action('wordlift_save_entity', 'wordlift_save_entity_to_triple_store');
