<?php

/**
 * @file
 *   Pages to be displayed in CP.
 */

/**
 * Callback; results for autocomplete functionality.
 */
function sac_search_form_autocomplete($parameter = NULL) {
  $cid = 'search:key:' . $parameter;
  $cache = cache_get($cid, 'cp_cache_search');
  $matches = array();
  if (isset($cache->data)) {
    $matches = $cache->data;
  }
  else {
    // Data not cached.
    // TODO: Create admin interface to manage this name.
    $server = search_api_server_load('connect');
    $solr_class = new SearchApiSolrService($server);
    $connection = $solr_class->getSolrConnection();
    $result = $connection->makeServletRequest('suggest', array('suggest.q' => $parameter));

    // save the query to matches
    foreach ($result->suggest->mySuggester->$parameter->suggestions as $row) {
      $clean = strip_tags($row->term);
      $matches[$clean] = $row->term;
    }
    if (!empty($matches)) {
      cache_set($cid, $matches, 'sac_cache_search', REQUEST_TIME + 60*60*24*7);
    }
  }

  // Return the result to the form in json
  drupal_json_output($matches);
}
