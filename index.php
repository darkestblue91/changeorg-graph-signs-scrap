<?php
/**
 * Plugin Name: Change.org GraphQL Signs Scrapper
 * Plugin URI: http://www.josuediaz.io
 * Description: Scrap number of signs from a Change.org campaign.
 * Version: 1.0
 * Author: Josue Diaz
 * Author URI: http://www.josuediaz.io
 */

global $wpdb;
require('mini-graphql-client.php');

define("TABLE_NAME", $wpdb->prefix . "cscrap_petitions");
define("DEFAULT_PETITION", "13710418");
define("SHORTCODE_TAG", "csrap_petition_signs");

function cscrap_installation() {
  global $wpdb;

  $table_name = $wpdb->prefix . "cscrap_petitions"; 
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table_name (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    petitionId varchar(32) NOT NULL,
    signatureCount int DEFAULT 0 NOT NULL,
    fetched datetime DEFAULT NOW() NOT NULL,
    PRIMARY KEY  (id)
  ) $charset_collate;";
  
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
  
  add_option("cscrap_db_version", "1.0");
}

register_activation_hook(__FILE__, 'cscrap_installation');

function csscrap_petition_scrap($petitionId) {
  $query = <<<'GRAPHQL'
  query Petition ($id: ID!) {
    petitionById(id: $id) {
      id
      signatureCount {
        displayed
        total
      }
    }
  }
  GRAPHQL;

  $result = graphql_query('https://www.change.org/api-proxy/graphql', $query, ['id' => $petitionId]);
  return $result['data'];
}

function cscrap_scrap_new_petition($petitionId) {
  global $wpdb;

  //Fetch petition data from Change.org GraphQL server
  $petitionData = csscrap_petition_scrap($petitionId);

  //If petition exists
  if($petitionData['petitionById']) {
    $signsCount = $petitionData['petitionById']['signatureCount']['total'];
    echo $signsCount;

    //Add petition to database
    $wpdb->insert(
      TABLE_NAME,
      array(
        'petitionId' => $petitionId,
        'signatureCount' => $signsCount,
        'fetched' => current_time('mysql'),
      )
    );

    return $signsCount;
  } else {
    return 'Petition does not exist in Change.org';
  }
}

// function scrap_campaign_signs($petitionId, $isNew = true) {
//   //Fetch petition data from Change.org GraphQL server
//   $petitionData = graphql_query('https://www.change.org/api-proxy/graphql', $query, ['id' => $petition->$id]);

//   if($isNew) {

//   } else {
//     //Save new values to database
//     $wpdb->update($table_name, array('id'=>$petition->$id, 'fetched'=>current_time('mysql'), 'signatureCount'=>$petitionData->signatureCount->total), array('id'=>$petition->$id));
//   }
// }

// function cscrap_scrap_campaign_signs() {
//   global $wpdb;

//   //Get al; registered petitions of the shortcode
//   $petitions = $wpdb->get_results( "SELECT * FROM {$table_name}", OBJECT);

//   //If there are petitions
//   if (!empty($petitions)) {
//     //Iterate all petitions
//     foreach($petitions as $petition) {
//       //If now date > than lastfeched date + 6 hours
//       if(strtotime('+2 hours', strtotime($petition->fetched)) < current_time('mysql')) {
//         //Fetch petition data from Change.org GraphQL server
//         $petitionData = graphql_query('https://www.change.org/api-proxy/graphql', $query, ['id' => $petition->$id]);

//         //Save new values to database
//         $wpdb->update($table_name, array('id'=>$petition->$id, 'fetched'=>current_time('mysql'), 'signatureCount'=>$petitionData->signatureCount->total), array('id'=>$petition->$id));
//       }   
//     }
//   }
// }

//add_action('cscrap_scrap_campaign_signs', 'cscrap_scrap_campaign_signs');
 
//This shortcode shows on screen the number of signs of a petition
function cscrap_generate_petition_signs($atts) {
  global $wpdb;

  //Manage shortcode parameters
  $atts = shortcode_atts(array(
    'petitionId' => DEFAULT_PETITION,
  ),
  $atts,
  SHORTCODE_TAG);

  //Get petition details
  $petition = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TABLE_NAME .  " WHERE petitionId = %s", esc_attr($atts['petitionId'])));

  //If petition exists
  if($petition) {
    return "<span>" . $petition->signatureCount . "</span>";
  } else {
    $signatureCount = cscrap_scrap_new_petition($atts['petitionId']);
    return "<span>" . $signatureCount . "</span>";
  }
}

add_shortcode(SHORTCODE_TAG, 'cscrap_generate_petition_signs');
