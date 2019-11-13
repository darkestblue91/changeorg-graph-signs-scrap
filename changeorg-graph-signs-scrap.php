<?php
/**
 *
 * @link              http://www.josuediaz.io
 * @since             1.0.0
 * @package           Change_Org_Signs_Scrapper
 *
 * Plugin Name: Change.org GraphQL Signs Scrapper
 * Plugin URI: http://www.josuediaz.io
 * Description: Scrap number of signs from your Change.org petitions and save them in your database, exposing a shortcode to show them on your page just giving the petition ID of the petition.
 * Version: 1.0
 * Author: Josue Diaz
 * Author URI: http://www.josuediaz.io
 * Text Domain: changeorg-graph-signs-scrap
*/

//Prevent direct execution
if (!defined('ABSPATH')) {
  exit;
}

require('mini-graphql-client.php');
define('PLUGIN_VERSION', '1.0.0');
define('PLUGIN_DB_VERSION', '1.0.0');
define("DEFAULT_PETITION", "13710418");
define("SHORTCODE_TAG", "csrap_petition_signs");

//Generate table name
function cscrap_generate_table_name() {
  global $wpdb;

  return $wpdb->prefix . "cscrap_petitions";
}

//Install the plugin creating database table
function cscrap_activate() {
  global $wpdb;

  $table_name = cscrap_generate_table_name();
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

  //Activate cronjob
  cscrap_activate_cronjob();

  add_option("cscrap_version", PLUGIN_VERSION);
  add_option("cscrap_db_version", PLUGIN_DB_VERSION);
}

register_activation_hook(__FILE__, 'cscrap_activate');

//Uninstall plugin
function cscrap_deactivate() {
  global $wpdb;
  $table_name = cscrap_generate_table_name();

  //Delete petitions table
  $wpdb->query("DROP TABLE IF EXISTS $table_name;");

  //Deactivate cronjob
  cscrap_deactivate_cronjob();

  //Delete option values
  delete_option("cscrap_version");
  delete_option("cscrap_db_version");
}

register_deactivation_hook(__FILE__, 'cscrap_deactivate');

//Scrap a petition sign count
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

  $result = graphql_query(
    'https://www.change.org/api-proxy/graphql',
    $query,
    ['id' => $petitionId]
  );

  return $result['data'];
}

//Scrap a new petition
function cscrap_scrap_new_petition($petitionId) {
  global $wpdb;
  $table_name = cscrap_generate_table_name();
  
  //Fetch petition data from Change.org GraphQL server
  $petitionData = csscrap_petition_scrap($petitionId);

  //If petition exists
  if($petitionData['petitionById']) {
    $signsCount = $petitionData['petitionById']['signatureCount']['total'];

    //Add petition to database
    $wpdb->insert(
      $table_name,
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

//Scrap petitions with cronjob
function cscrap_scrap_petitions() {
  global $wpdb;
  $table_name = cscrap_generate_table_name();

  //Get petitions
  $petitions = $wpdb->get_results("SELECT * FROM " . $table_name);

  //Iterate all petitions
  foreach($petitions as $petition) {
    //Fetch petition data from Change.org GraphQL server
    $petitionData = csscrap_petition_scrap($petition->petitionId);

    if($petitionData['petitionById']) {
      //Get petition sign count
      $signsCount = $petitionData['petitionById']['signatureCount']['total'];

      //Save new values to database
      $wpdb->update($table_name, array(
        'fetched' => current_time('mysql'),
        'signatureCount' => $signsCount
      ), array('id' => $petition->id));
    }
  }
}

add_action('cscrap_scrap_petitions_signs', 'cscrap_scrap_petitions');

//Activate cronjob
function cscrap_activate_cronjob() {
  // if (!wp_next_scheduled('cscrap_scrap_petitions_signs'))
  wp_schedule_event(time(), 'hourly', 'cscrap_scrap_petitions_signs');
}

//Deactivate cronjob
function cscrap_deactivate_cronjob() {
  wp_clear_scheduled_hook('cscrap_scrap_petitions_signs');
}

//This shortcode shows on screen the number of signs of a petition
function cscrap_generate_petition_signs($atts) {
  global $wpdb;

  $table_name = cscrap_generate_table_name();

  //Manage shortcode parameters
  $atts = shortcode_atts(array(
    'petitionId' => DEFAULT_PETITION,
  ),
  $atts,
  SHORTCODE_TAG);

  //Get petition details
  $petition = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM " . $table_name .  " WHERE petitionId = %s",
    esc_attr($atts['petitionId']))
  );

  //If petition exists
  if($petition) {
    return "<span>" . $petition->signatureCount . "</span>";
  } else {
    $signatureCount = cscrap_scrap_new_petition($atts['petitionId']);
    return "<span>" . $signatureCount . "</span>";
  }
}

add_shortcode(SHORTCODE_TAG, 'cscrap_generate_petition_signs');