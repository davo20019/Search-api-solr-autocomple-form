<?php

/**
 * Class to oveeride auto suggestions
 */

class SacSearchService extends SearchApiSolrService {
  // Change how autocompletion works on your server.
  public function getAutocompleteSuggestions(SearchApiQueryInterface $query, SearchApiAutocompleteSearch $search, $incomplete_key, $user_input) {
    $suggestions = array();
    // Reset request handler
    $this->request_handler = NULL;
    // Turn inputs to lower case, otherwise we get case sensivity problems.
    $incomp = drupal_strtolower($incomplete_key);

    $index = $query->getIndex();
    $fields = $this->getFieldNames($index);
    $complete = $query->getOriginalKeys();

    // Extract keys
    $keys = $query->getKeys();
    if (is_array($keys)) {
      $keys_array = array();
      while ($keys) {
        reset($keys);
        if (!element_child(key($keys))) {
          array_shift($keys);
          continue;
        }
        $key = array_shift($keys);
        if (is_array($key)) {
          $keys = array_merge($keys, $key);
        }
        else {
          $keys_array[$key] = $key;
        }
      }
      $keys = $this->flattenKeys($query->getKeys());
    }
    else {
      $keys_array = drupal_map_assoc(preg_split('/[-\s():{}\[\]\\\\"]+/', $keys, -1, PREG_SPLIT_NO_EMPTY));
    }
    if (!$keys) {
      $keys = NULL;
    }

    // Set searched fields
    $options = $query->getOptions();
    $search_fields = $query->getFields();
    $qf = array();
    foreach ($search_fields as $f) {
      $qf[] = $fields[$f];
    }

    // Extract filters
    $fq = $this->createFilterQueries($query->getFilter(), $fields, $index->options['fields']);
    $index_id = $this->getIndexId($index->machine_name);
    $fq[] = 'index_id:' . call_user_func(array($this->connection_class, 'phrase'), $index_id);
    if (!empty($this->options['site_hash'])) {
      // We don't need to escape the site hash, as that consists only of
      // alphanumeric characters.
      $fq[] = 'hash:' . search_api_solr_site_hash();
    }

    // Autocomplete magic
    $facet_fields = array();
    foreach ($search_fields as $f) {
      $facet_fields[] = $fields[$f];
    }

    $limit = $query->getOption('limit', 10);

//    $params = array(
//      'fl' => 'item_id,tm_mail',
//      'qf' => $qf,
//      'fq' => $fq,
//      'rows' => 10,
//      'facet' => 'true',
//      'facet.field' => $facet_fields,
//      'facet.prefix' => $incomp,
//      'facet.limit' => $limit * 5,
//      'facet.mincount' => 1,
//      'spellcheck' => (!isset($this->options['autocorrect_spell']) || $this->options['autocorrect_spell']) ? 'true' : 'false',
//      'spellcheck.count' => 50,
//      'spellcheck.collate' => TRUE,
//      'facet.sort' => 'index',
//      'terms' => TRUE,
//      'terms.fl' => 'tm_profile_doctor:field_first_name',
//    );

    // Original
    $params = array(
      'qf' => $qf,
      'fq' => $fq,
      'rows' => 0,
      'facet' => 'true',
      'facet.field' => $facet_fields,
      'facet.prefix' => $incomp,
      'facet.limit' => $limit * 5,
      'facet.mincount' => 1,
      'spellcheck' => (!isset($this->options['autocorrect_spell']) || $this->options['autocorrect_spell']) ? 'true' : 'false',
      'spellcheck.count' => 1,
    );



    // Retrieve http method from server options.
    $http_method = !empty($this->options['http_method']) ? $this->options['http_method'] : 'AUTO';

    $call_args = array(
      'query'       => &$keys,
      'params'      => &$params,
      'http_method' => &$http_method,
    );
    if ($this->request_handler) {
      $this->setRequestHandler($this->request_handler, $call_args);
    }
    $second_pass = !isset($this->options['autocorrect_suggest_words']) || $this->options['autocorrect_suggest_words'];
    for ($i = 0; $i < ($second_pass ? 2 : 1); ++$i) {
      try {
        // Send search request
        $this->connect();
        drupal_alter('search_api_solr_query', $call_args, $query);
        $this->preQuery($call_args, $query);
        $response = $this->solr->search($keys, $params, $http_method);
        $test = drupal_json_decode($response->data);
        if (!empty($response->spellcheck->suggestions)) {
          $replace = array();
          foreach ($response->spellcheck->suggestions as $word => $data) {
            $replace[$word] = $data->suggestion[0];
          }
          $corrected = str_ireplace(array_keys($replace), array_values($replace), $user_input);
          if ($corrected != $user_input) {
            array_unshift($suggestions, array(
              'prefix' => t('Did you mean') . ':',
              'user_input' => $corrected,
            ));
          }
        }

        $matches = array();
        if (isset($response->facet_counts->facet_fields)) {
          foreach ($response->facet_counts->facet_fields as $terms) {
            foreach ($terms as $term => $count) {
              if (isset($matches[$term])) {
                // If we just add the result counts, we can easily get over the
                // total number of results if terms appear in multiple fields.
                // Therefore, we just take the highest value from any field.
                $matches[$term] = max($matches[$term], $count);
              }
              else {
                $matches[$term] = $count;
              }
            }
          }

          if ($matches) {
            // Eliminate suggestions that are too short or already in the query.
            foreach ($matches as $term => $count) {
              if (strlen($term) < 3 || isset($keys_array[$term])) {
                unset($matches[$term]);
              }
            }

            // Don't suggest terms that are too frequent (by default in more
            // than 90% of results).
            $result_count = $response->response->numFound;
            $max_occurrences = $result_count * variable_get('search_api_solr_autocomplete_max_occurrences', 0.9);
            if (($max_occurrences >= 1 || $i > 0) && $max_occurrences < $result_count) {
              foreach ($matches as $match => $count) {
                if ($count > $max_occurrences) {
                  unset($matches[$match]);
                }
              }
            }

            // The $count in this array is actually a score. We want the
            // highest ones first.
            arsort($matches);

            // Shorten the array to the right ones.
            $additional_matches = array_slice($matches, $limit - count($suggestions), NULL, TRUE);
            $matches = array_slice($matches, 0, $limit, TRUE);

            // Build suggestions using returned facets
            $incomp_length = strlen($incomp);
            foreach ($matches as $term => $count) {
              if (drupal_strtolower(substr($term, 0, $incomp_length)) == $incomp) {
                $suggestions[] = array(
                  'suggestion_suffix' => substr($term, $incomp_length),
                  'term' => $term,
                  'results' => $count,
                );
              }
              else {
                $suggestions[] = array(
                  'suggestion_suffix' => ' ' . $term,
                  'term' => $term,
                  'results' => $count,
                );
              }
            }
          }
        }
      }
      catch (SearchApiException $e) {
        watchdog_exception('search_api_solr', $e, "%type during autocomplete Solr query: !message in %function (line %line of %file).", array(), WATCHDOG_WARNING);
      }

      if (count($suggestions) >= $limit) {
        break;
      }
      // Change parameters for second query.
      unset($params['facet.prefix']);
      $keys = trim ($keys . ' ' . $incomplete_key);
    }

    return $suggestions;
  }
}
