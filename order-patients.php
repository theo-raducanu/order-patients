<?php
/*
Plugin Name: Order Patients
Plugin URI:   #
Description: Order patients within categories.
Version: 1.0
Author: Theodor Raducanu
Author URI: #
License: GPLv2
Copyright: Theodor Raducanu
Text Domain: order-patients
Domain Path: /languages
*/
function disable_plugin_updates( $value ) {
  if ( isset($value) && is_object($value) ) {
    if ( isset( $value->response['order-patients/order-patients.php'] ) ) {
      unset( $value->response['order-patients/order-patients.php'] );
    }
  }
  return $value;
}
add_filter( 'site_transient_update_plugins', 'disable_plugin_updates' );

if (!class_exists('OrderPatients')) {
    class OrderPatients
    {
        public $adminOptionsName = "conf_AdminSettings";
        public $orderedCategoriesOptionName = "conf_orderOrderedCategoriesOptions";

        public $conf_order_db_version = "1.0";
        public $conf_order_dbOptionVersionName = "conf_order_db_version";
        public $conf_order_tableName = "order_post_rel";

        public $custom_cat = 0;
        public $stop_join = false;


        /**
         * Constructor
         */
        public function __construct()
        {
            load_plugin_textdomain('order-patients', false, basename(dirname(__FILE__)) . '/languages');

            // hook for activation
            register_activation_hook(__FILE__, array(&$this, 'order_install'));
            //hook for new blog on multisite
            add_action('wpmu_new_blog', 'multisite_new_blog', 10, 6);
            // hook for desactivation
            register_deactivation_hook(__FILE__, array(&$this, 'order_uninstall'));

            // Link to the setting page
            $plugin = plugin_basename(__FILE__);
            add_filter("plugin_action_links_$plugin", array(&$this,'display_settings_link'));

            //hook for notices
            add_action('admin_notices', array(&$this, 'admin_dashboard_notice'));

            add_action('init', array(&$this, 'saveOptionPlugin'));
            add_action('admin_menu', array(&$this, 'add_setting_page'));
            add_action('admin_menu', array(&$this, 'add_order_pages'));

            add_action('wp_ajax_cat_ordered_changed', array(&$this, 'cat_orderedChangeTraiment'));
            add_action('wp_ajax_user_ordering', array(&$this, 'user_orderingTraiment'));

            add_action('save_post', array(&$this, 'savePost_callBack'));
            add_action('before_delete_post', array(&$this, 'deletePost_callBack'));
            add_action('trashed_post', array(&$this, 'deletePost_callBack'));

            add_action('deleteUnecessaryEntries', array(&$this, 'deleteUnecessaryEntries_callBack'));

            if ((defined('DOING_AJAX') && DOING_AJAX) || !is_admin()) {
                add_filter('posts_join', array(&$this, 'order_query_join'), 10, 2);
                add_filter('posts_where', array(&$this, 'order_query_where'), 10, 2);
                add_filter('posts_orderby', array(&$this, 'order_query_orderby'), 10, 2);
            }
        }
        public function admin_dashboard_notice()
        {
            $options = $this->getAdminOptions();
            if (empty($options)) {
                ?>
				<div class="updated re_order">
						<p><?php echo sprintf(__('Vous devez enregistrer <a href="%s">order-patients</a>', 'order-patients'), admin_url('options-general.php?page=order-patients.php')); ?></p>
				</div>
				<?php
            }
        }
        public function order_query_join($args, $wp_query)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . $this->conf_order_tableName;

            $queriedObj = $wp_query->get_queried_object();

            if (isset($queriedObj->slug) && isset($queriedObj->term_id)) {
                $category_id = $queriedObj->slug;
                $theID = $queriedObj->term_id;
            } else {
                return $args;
            }


            if (!$category_id) {
                $category_id = $this->custom_cat;
            }

            $userOrderOptionSetting = $this->getOrderedCategoriesOptions();
            if (!empty($userOrderOptionSetting[$theID]) && $userOrderOptionSetting[$theID] == "true" && $this->stop_join == false) {
                $args .= " INNER JOIN $table_name ON ".$wpdb->posts.".ID = ".$table_name.".post_id and incl = 1  ";
                //echo $args;
            }

            return $args;
        }
        public function order_query_where($args, $wp_query)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . $this->conf_order_tableName;

            $queriedObj = $wp_query->get_queried_object();

            if (isset($queriedObj->slug) && isset($queriedObj->term_id)) {
                $category_id = $queriedObj->slug;
                $theID = $queriedObj->term_id;
            } else {
                return $args;
            }


            if (!$category_id) {
                $category_id = $this->custom_cat;
            }

            $userOrderOptionSetting = $this->getOrderedCategoriesOptions();
            if (!empty($userOrderOptionSetting[$theID]) && $userOrderOptionSetting[$theID] == "true" && $this->stop_join == false) {
                //$args .= " INNER JOIN $table_name ON ".$wpdb->posts.".ID = ".$table_name.".post_id and incl = 1  ";
                $args .= " AND $table_name".".category_id = '".$theID."'";
                //echo $args;
            }

            return $args;
        }
        public function order_query_orderby($args, $wp_query)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . $this->conf_order_tableName;

            $queriedObj = $wp_query->get_queried_object();

            if (isset($queriedObj->slug) && isset($queriedObj->term_id)) {
                $category_id = $queriedObj->slug;
                $theID = $queriedObj->term_id;
            } else {
                return $args;
            }

            if (!$category_id) {
                $category_id = $this->custom_cat;
            }

            $userOrderOptionSetting = $this->getOrderedCategoriesOptions();
            if (!empty($userOrderOptionSetting[$theID]) && $userOrderOptionSetting[$theID] == "true" && $this->stop_join == false) {
                $args = $table_name.".id ASC";
            }
            return $args;
        }


        /**
         * When a post is deleted we remove all entries from the custom table
         * @param type $post_id
         */
        public function deletePost_callBack($post_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . $this->conf_order_tableName;
            $sql = $wpdb->prepare("DELETE FROM $table_name WHERE (post_id =%d)", $post_id);
            $wpdb->query($sql);
        }
        /**
         * When a new post is created several actions are required
         * We need to inspect all associated taxonomies
         * @param type $post_id
         */
        public function savePost_callBack($post_id)
        {
            $orderedSettingOptions = $this->getAdminOptions();
            if (empty($orderedSettingOptions)) {
                return;
            } //order settings not saved yet
            //verify post is not a revision
            if (!wp_is_post_revision($post_id)) {
                global $wpdb;

                $table_name = $wpdb->prefix . $this->conf_order_tableName;
                //let's get the options first

                // Type de post
                $post_type = get_post_type($post_id);
                $post_type = get_post_type_object($post_type);
                $taxonomies = get_object_taxonomies($post_type->name, 'objects');

                if (count($taxonomies) > 0 && array_key_exists($post_type->name, $orderedSettingOptions['categories_checked'])) {
                    $orderedSettingOptions = $orderedSettingOptions['categories_checked'][$post_type->name];
                    // for each CPT taxonomy, look at only the hierarchical ones
                    foreach ($taxonomies as $taxonomie) {
                        if ($taxonomie->hierarchical == 1 && is_array($orderedSettingOptions) && in_array($taxonomie->name, $orderedSettingOptions)) {
                            //echo "<li>".$taxonomie->name."</li>";
                            $terms = get_terms($taxonomie->name);

                            $terms_of_the_post = wp_get_post_terms($post_id, $taxonomie->name);
                            $term_ids_of_the_post = wp_list_pluck($terms_of_the_post, 'term_id');
                            if (count($terms) > 0) {
                                foreach ($terms as $term) {
                                    if (in_array($term->term_id, $term_ids_of_the_post)) {
                                        $trieEnCoursEnDb = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE category_id=%d", $term->term_id));
                                        if ($trieEnCoursEnDb != 0) {
                                            $nbligne = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE post_id=%d AND category_id=%d", $post_id, $term->term_id));
                                            if ($nbligne == 0) {
                                                $wpdb->insert(
                            $table_name,
                            array(
                                'category_id'    => $term->term_id,
                                'post_id'    => $post_id
                            )
                            );
                                            }
                                        }
                                    } else {
                                        $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE post_id=%d AND category_id=%d", $post_id, $term->term_id));
                                        $nbPostRestant =  $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE category_id=%d", $term->term_id));
                                        if ($nbPostRestant < 2) {
                                            $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE category_id=%d", $term->term_id));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        /**
         * Launched when the plugin is being activated
         * NOTE: Added multisite compatibility (wordpress.syllogic.in Dec 2015)
         */
        public function order_install($networkwide)
        {
            global $wpdb;
            if (function_exists('is_multisite') && is_multisite()) {
                // check if it is a network activation - if so, run the activation function for each blog id
                if ($networkwide) {
                    $old_blog = $wpdb->blogid;
                    // Get all blog ids
                    $blogids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
                    foreach ($blogids as $blog_id) {
                        switch_to_blog($blog_id);
                        $this->_order_install();
                    }
                    switch_to_blog($old_blog);
                    return;
                }
            }
            $this->_order_install();
        }
        private function _order_install()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . $this->conf_order_tableName;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
                $sqlCreateTable = "CREATE TABLE IF NOT EXISTS $table_name (
							 `id` int(11) NOT NULL AUTO_INCREMENT,
							 `category_id` int(11) NOT NULL,
							 `post_id` int(11) NOT NULL,
							 `incl` tinyint(1) NOT NULL DEFAULT '1',
							 `order_code` int(11) NOT NULL DEFAULT '1',
							 PRIMARY KEY (`id`)
							 ) ;";
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sqlCreateTable);
            }
            add_option($this->conf_order_dbOptionVersionName, $this->conf_order_db_version);
        }

        public function multisite_new_blog($blog_id, $user_id, $domain, $path, $site_id, $meta)
        {
            global $wpdb;

            if (is_plugin_active_for_network('order-patients/order-patients.php')) {
                $old_blog = $wpdb->blogid;
                switch_to_blog($blog_id);
                $this->_order_install();
                switch_to_blog($old_blog);
            }
        }

        /**
         * Launched when the plugin is being desactivated
         */
        public function order_uninstall($networkwide)
        {
            global $wpdb;
            if (function_exists('is_multisite') && is_multisite()) {
                // check if it is a network activation - if so, run the activation function for each blog id
                if ($networkwide) {
                    $old_blog = $wpdb->blogid;
                    // Get all blog ids
                    $blogids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
                    foreach ($blogids as $blog_id) {
                        switch_to_blog($blog_id);
                        $this->_order_deactivate();
                    }
                    switch_to_blog($old_blog);
                    return;
                }
            }
            $this->_order_deactivate();
        }
        private function _order_deactivate()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . $this->conf_order_tableName;

            $sqlDropTable = "DROP TABLE IF EXISTS $table_name";
            $wpdb->query($sqlDropTable);
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sqlDropTable);

            delete_option($this->conf_order_dbOptionVersionName);

            $sqlClearOption = "delete from  wp_options where option_name like 'conf_order%'";
            $wpdb->query($sqlClearOption);
            dbDelta($sqlClearOption);
        }

        public function getOrderCode( $procedure ) {
        	$procedures = [	"Wonder Breast Augmentation" => 1,
							"Wonder Breast Lift" => 2,
							"Wonder Breast Lift with Implant" => 3,
							"Wonder Breast Reduction" => 4,
							"Wonder Breast Revision" => 5,
							"Hourglass Butt Augmentation" => 6,
							"Hourglass Butt Implant" => 7,
							"Hourglass Hips" => 8,
							"Hourglass Lipo" => 9,
							"Hourglass Mommy Makeover" => 10,
							"Hourglass Tummy Tuck" => 11,
							"Hourglass Tummy Tuck Revision" => 12,
							"Brachioplasty" => 13,
							"Gynecomastia Correction" => 14
						];
			if ( isset( $procedures[$procedure] ) ) {
				return $procedures[$procedure];
			}
			return 0;
        }

        public function user_orderingTraiment()
        {
            if (!isset($_POST['deefuseNounceUserOrdering']) || !wp_verify_nonce($_POST['deefuseNounceUserOrdering'], 'nonce-UserOrderingChange')) {
                return;
            }

            $procedures = [ "Wonder Breast Augmentation" => 1,
                            "Wonder Breast Lift" => 2,
                            "Wonder Breast Lift with Implant" => 3,
                            "Wonder Breast Reduction" => 4,
                            "Wonder Breast Revision" => 5,
                            "Hourglass Butt Augmentation" => 6,
                            "Hourglass Butt Implant" => 7,
                            "Hourglass Hips" => 8,
                            "Hourglass Lipo" => 9,
                            "Hourglass Mommy Makeover" => 10,
                            "Hourglass Tummy Tuck" => 11,
                            "Hourglass Tummy Tuck Revision" => 12,
                            "Brachioplasty" => 13,
                            "Gynecomastia Correction" => 14
                        ];


            global $wpdb;
            $order = explode(",", $_POST['order']);
            /*var_dump($order);*/
            $category = $_POST['category'];

            $table_name = $wpdb->prefix . $this->conf_order_tableName;
            $total = $wpdb->get_var($wpdb->prepare("select count(*) as total from `$table_name` where category_id = %d", $category));

            // if category has not been sorted as yet
            if ($total == 0) {
                foreach ($order as $post_id) {
                    $value[] = "($category, $post_id)";
                }
                $sql = sprintf("insert into $table_name (category_id,post_id) values %s", implode(",", $value));
                $wpdb->query($sql);
            } else {
                $results = $wpdb->get_results($wpdb->prepare("select * from `$table_name` where category_id = %d order by id", $category));
                foreach ($results as $index => $result_row) {
                    $result_arr[$result_row->post_id] = $result_row;
                }
                $start = 0;
                foreach ($order as $post_id) {
                    $inc_row = $result_arr[$post_id];
                    $incl = 1; //$inc_row->incl; @toto
                    $row = $results[$start];
                    $currentProcedure = null;
                    if ( isset( $procedures[get_cat_name($category)] ) ) {
                        $currentProcedure = $procedures[get_cat_name($category)];
                    }
                    if ( $currentProcedure !== null ) {
                        $order_code = $currentProcedure * 10000 + $start + 1;
                    } else {
                        $order_code = (int)$category * 1000 + $start + 1;
                    }
                    
                    ++$start;
                    $id = $row->id;
                    update_post_meta( $post_id, 'order_code', $order_code );
                    $sql = $wpdb->prepare("update $table_name set order_code = %d, post_id = %d,incl = %d where id = %d", $order_code, $post_id, $incl, $id);
                    $wpdb->query($sql);
                }
            }



            die();
        }

        public function cat_orderedChangeTraiment()
        {
            if (!isset($_POST['deefuseNounceOrder']) || !wp_verify_nonce($_POST['deefuseNounceOrder'], 'nonce-CatOrderedChange')) {
                return;
            }

            $orderedSettingOptions = $this->getOrderedCategoriesOptions();
            $orderedSettingOptions[$_POST['current_cat']] = $_POST['valueForManualOrder'];
            update_option($this->orderedCategoriesOptionName, $orderedSettingOptions);

            die();
        }


        /**
         * Returns an array of admin options
         */
        public function getAdminOptions()
        {
            $adminOptions = array();
            $settingsOptions = get_option($this->adminOptionsName);
            if (!empty($settingsOptions)) {
                foreach ($settingsOptions as $key => $option) {
                    $adminOptions[$key] = $option;
                }
            }
            update_option($this->adminOptionsName, $adminOptions);
            return $adminOptions;
        }

        public function getOrderedCategoriesOptions()
        {
            $orderedOptions = array();
            $orderedSettingOptions = get_option($this->orderedCategoriesOptionName);
            if (!empty($orderedSettingOptions)) {
                foreach ($orderedSettingOptions as $key => $option) {
                    $orderedOptions[$key] = $option;
                }
            }
            update_option($this->orderedCategoriesOptionName, $orderedOptions);
            return $orderedOptions;
        }

        /**
         * Show admin pages for sorting posts
         * (as per settings options of plugin);
         */
        public function add_order_pages()
        {
            //On liste toutes les catÃ©gorie dont on veut avoir la main sur le trie
            $settingsOptions = $this->getAdminOptions();

            if (!isset($settingsOptions['categories_checked'])) {
                return;
            }
            //debug_msg($settingsOptions);

            foreach ($settingsOptions['categories_checked'] as $post_type=>$taxonomies) {
                /**
                *filter to allow other capabilities for managing orders.
                * @since 1.3.0
                **/
                $cabability = apply_filters('order_post_within_categories_capability', 'manage_categories', $post_type);
                if('manage_categories'!== $cabability){ //validate capability.
                    $roles = wp_roles();
                    $is_valid=false;
                    foreach($roles as $role){
                        if(in_array($capability, $role['capabilities'])){
                            $is_valid=true;
                            break;
                        }
                    }
                    if(!$is_valid) $cabability = 'manage_categories';
                }
                switch ($post_type) {
          case 'attachment':
            $the_page = add_submenu_page('upload.php', 'Re-order', 'order', $cabability, 're-orderPost-'.$post_type, array(&$this,'printOrderPage'));
            break;
          case 'post':
            $the_page = add_submenu_page('edit.php', 'Re-order', 'order', $cabability, 're-orderPost-'.$post_type, array(&$this,'printOrderPage'));
            break;
          default:
            $the_page =  add_submenu_page('edit.php?post_type='.$post_type, 'Re-order', 'order', $cabability, 're-orderPost-'.$post_type, array(&$this,'printOrderPage'));
            break;
          }
                add_action('admin_head-'. $the_page, array(&$this,'myplugin_admin_header'));
            }
        }
        public function myplugin_admin_header()
        {
            wp_enqueue_style("orderDeefuse", plugins_url('style.css', __FILE__));
            wp_enqueue_script('deefuseorderAjax', plugin_dir_url(__FILE__).'js/orderAjax.js', array('jquery'));
            wp_enqueue_script('jquery-ui-sortable', '/wp-includes/js/jquery/ui/jquery.ui.sortable.min.js', array('jquery-ui-core', 'jquery-ui-mouse'), '1.8.20', 1);
            wp_localize_script('deefuseorderAjax', 'deefuseorder_vars', array(
           'deefuseNounceCatorder' =>  wp_create_nonce('nonce-CatOrderedChange'),
           'deefuseNounceUserOrdering' =>  wp_create_nonce('nonce-UserOrderingChange')
       ));
        }
        public function deleteUnecessaryEntries_callBack()
        {
            $post_types = get_post_types(array( 'show_in_nav_menus' => true,'public'=>true, 'show_ui'=>true, 'hierarchical' => false ), 'object');
            $categories_checked = $this->getAdminOptions();
            $categories_checked = $categories_checked['categories_checked'];

            $taxoPostToDelete = array();
            if ($post_types) :
        foreach ($post_types as $post_type) {
            $taxonomies = get_object_taxonomies($post_type->name, 'objects');
            if (count($taxonomies) > 0) {
                foreach ($taxonomies as $taxonomie) {
                    if ($taxonomie->hierarchical == 1) {
                        if (isset($categories_checked[$post_type->name])) {
                            if (!in_array($taxonomie->name, $categories_checked[$post_type->name])) {
                                $taxoPostToDelete[] = $taxonomie->name;
                            }
                        } else {
                            $taxoPostToDelete[] = $taxonomie->name;
                        }
                    }
                }
            }
        }
            endif;

            $cat_to_delete_in_db = array();
            $listTerms = get_terms($taxoPostToDelete);
            foreach ($listTerms as $term) {
                $cat_to_delete_in_db[] = $term->term_id;
            }

            $nbCatToDelete = count($cat_to_delete_in_db);

            global $wpdb;
            $table_name = $wpdb->prefix . $this->conf_order_tableName;
            if ($nbCatToDelete > 0) {
                $sql = "DELETE FROM $table_name WHERE (";

                for ($d = 0; $d < $nbCatToDelete ; $d++) {
                    if ($d > 0) {
                        $sql .= "OR";
                    }
                    $sql .= sprintf(" (category_id = %d) ", $cat_to_delete_in_db[$d]);
                }

                $sql.= ")";
                $wpdb->query($sql);
            }

            $nbligne = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            if ($nbligne == 0) {
                $sql = "ALTER TABLE $table_name AUTO_INCREMENT =1";
                $wpdb->query($sql);
            }
        }
        public function saveOptionPlugin()
        {
            if (!empty($_POST) && isset($_POST['nounceUpdateOptionorder']) && wp_verify_nonce($_POST['nounceUpdateOptionorder'], 'updateOptionSettings')) {
                if (isset($_POST['selection'])) {
                    $categories_checked = $_POST['selection'];
                } else {
                    $categories_checked = array();
                }
                $settingsOptions['categories_checked'] = $categories_checked;
                update_option($this->adminOptionsName, $settingsOptions);
            }
        }


        public function printOrderPage()
        {	
     		//Add style
     		echo '<style>
     		span.title {
     			padding-left: 15px;
     			font-size: 16px;
     			min-width: 200px !important;
			    display: table-cell;
			    vertical-align: middle;
			}
     		ul.order-list li {
			    display: table;
			}
     		.patients-images {
     			width: 100%;
			    display: table-cell;
			    text-align: right;
     		}
     		.patients-images img {
     			width: 128px;
     		}
     		</style>';
            $page_name = $_GET['page'];
            $cpt_name = substr($page_name, 13, strlen($page_name));
            $post_type = get_post_types(array('name' => $cpt_name), 'objects');
            $post_type_detail  = $post_type[$cpt_name];
            unset($post_type, $page_name, $cpt_name);

            $settingsOptions = $this->getAdminOptions();

            if (!empty($_POST) && check_admin_referer('loadPostInCat', 'nounceLoadPostCat') && isset($_POST['nounceLoadPostCat']) && wp_verify_nonce($_POST['nounceLoadPostCat'], 'loadPostInCat')) {
                if (isset($_POST['cat_to_retrive']) && !empty($_POST['cat_to_retrive']) && $_POST['cat_to_retrive'] != null) {
                    $cat_to_retrieve_post = $_POST['cat_to_retrive'];
                    $taxonomySubmitted = $_POST['taxonomy'];

                    if ($cat_to_retrieve_post > 0) {
                        global $wpdb;
                        $table_name = $wpdb->prefix . $this->conf_order_tableName;
                        $sql = $wpdb->prepare("select * from $table_name where category_id = '%d' order by id", $cat_to_retrieve_post);
                        $order_result = $wpdb->get_results($sql);
                        $nbResult = count($order_result);
                        
                        for ($k =0 ;$k < $nbResult; ++$k) {
                            $order_result_incl[$order_result[$k]->post_id] = $order_result[$k]->incl;
                        }
                        $args = array(
                                    'tax_query' => array(
                                                    array('taxonomy' => $taxonomySubmitted, 'operator' => 'IN', 'field' => 'id', 'terms' => $cat_to_retrieve_post)
                                                ),
                                    'posts_per_page'            => -1,
                                    'post_type'       => $post_type_detail->name,
                                    'orderby'            => 'date',
                                    'post_status'     => 'publish',
                                    'order' => 'ASC'
                                );

                        $args = apply_filters('order_post_within_category_query_args', $args);
                        $this->stop_join = true;
                        $this->custom_cat = $cat_to_retrieve_post;
                        $query = new WP_Query($args);
                        $this->stop_join = false;
                        $this->custom_cat = 0;
                        $posts_array = $query->posts;

                        $order_code = get_post_meta($posts_array[0]->ID , 'order_code', true);
                        // var_dump($order_code);
                        if ( ! empty ( $order_code ) ) {
							$args = array(
                                'tax_query' => array(
                                                array('taxonomy' => $taxonomySubmitted, 'operator' => 'IN', 'field' => 'id', 'terms' => $cat_to_retrieve_post)
                                            ),
                                'posts_per_page'            => -1,
                                'post_type'       => $post_type_detail->name,
                                'meta_key'			=> 'order_code',
                                'orderby'            => 'meta_value_num',
                                'post_status'     => 'publish',
                                'order' => 'ASC'
                            );
						}
						$args = apply_filters('order_post_within_category_query_args', $args);
                        $this->stop_join = true;
                        $this->custom_cat = $cat_to_retrieve_post;
                        $query = new WP_Query($args);
                        $this->stop_join = false;
                        $this->custom_cat = 0;
                        $posts_array = $query->posts;

                        $temp_order = array();
                        for ($j = 0; $j < count($posts_array); ++$j) {
                            $temp_order[$posts_array[$j]->ID] = $posts_array[$j];
                        }
                    }
                }
            } ?>
	    <div class="wrap">
	    	<div class="icon32 icon32-posts-<?php echo $post_type_detail->name; ?>" id="icon-edit"><br></div>
		<h2><?php echo sprintf(__('Sort items "%s"', 'order-patients'), $post_type_detail->labels->menu_name); ?></h2>
		<p>
		    <?php echo sprintf(__('Select a category to sort the type items <b>%s</b>. ', 'order-patients'), $post_type_detail->labels->name); ?>
		</p>

		<form method="post" id="chooseTaxomieForm">
		<?php
			
            wp_nonce_field('loadPostInCat', 'nounceLoadPostCat');

			$customCats = [];
			if ( !is_singular($post_type_detail->name) ){
				global $wpdb;
				// set the target relationship here
				$post_type = $post_type_detail->name;
				$taxonomy = 'category';

				$terms_ids = $wpdb->get_col( $wpdb->prepare( "
				    SELECT
				        tt.term_id
				    FROM
				        {$wpdb->term_relationships} tr,
				        {$wpdb->term_taxonomy} tt,
				        {$wpdb->posts} p
				    WHERE 1=1
				        AND tr.object_id = p.id
				        AND p.post_type = '%s'
				        AND p.post_status = 'publish'
				        AND tr.term_taxonomy_id = tt.term_taxonomy_id
				        AND tt.taxonomy ='%s'
				    ", $post_type, $taxonomy ) );

				// here you are
				$terms = get_terms( $taxonomy, array(
				    'include' => $terms_ids,
				    'orderby' => 'name',
				    'order' => 'ASC'
				) );

				$catNumber = count($terms);
				$customCats = [];
				for($i=0;$i<$catNumber;$i++) {
					array_push($customCats, $terms[$i]->name);
				}
			}
			

            $listCategories = $settingsOptions['categories_checked'][$post_type_detail->name];
            $taxonomies= '';
            $taxonomy= '';
            $term_selected = '';

            if (count($listCategories) > 0) {
                echo '<select id="selectCatToRetrieve" name="cat_to_retrive">';
                echo '<option value="null" disabled="disabled" selected="selected">Select</option>';
                $catDisabled = false;
                foreach ($listCategories as $categorie) {
                    $taxonomies = get_taxonomies(array('name'=> $categorie), 'object');
                    $taxonomy = $taxonomies[$categorie];

                    // On liste maintenant les terms disponibles pour la taxonomie concernÃ©e
                    $list_terms = get_terms($taxonomy->name);

                    if (count($list_terms) > 0) {
                        echo '<optgroup id="'.$taxonomy->name.'" label="'.$taxonomy->labels->name.'">';
                        foreach ($list_terms as $term) {
                            $selected = '';
                            /*var_dump($term);*/
                            if (isset($cat_to_retrieve_post) && ($cat_to_retrieve_post == $term->term_id)) {
                                $selected = ' selected = "selected"';
                                $term_selected = $term->name;
                            }
                            $disabled = '';
                            if ($term->count < 2) {
                                $disabled = ' disabled = "disabled"';
                                $catDisabled = true;
                            }
                            if ( !is_singular($post_type_detail->name) ) {
                            	if (  in_array($term->name,$customCats) ) {
		                            echo '<option' . $selected . $disabled.' value="'.$term->term_id.'">' . $term->name . '</option>';
		                        }
                            } else {
                            	echo '<option' . $selected . $disabled.' value="'.$term->term_id.'">' . $term->name . '</option>';
                            }
                            
                        }
                        echo '</optgroup>';
                    }
                }
                echo '</select>';
                if ($catDisabled) {
                    echo '<br/><span class="description">' . __("Gray categories are not available for sorting because they do not have enough items at the moment.", "order-patients") .'</span>';
                }

                $valueTaxonomyField = (isset($taxonomySubmitted) ? $taxonomySubmitted : '');
                echo '<input type="hidden" id="taxonomyHiddenField" name="taxonomy" value="'.$valueTaxonomyField.'"/>';
            } ?>
		</form>
		<form id="form_result" method="post">
		<?php
            if (isset($posts_array)) {
                echo '<div id="result">';
                echo '<div id="sorter_box">';
                echo '<h3>' . __('Utiliser le tri manuel pour cette catégorie ?', 'order-patients') .'</h3>';
                echo '<div id="catOrderedRadioBox">';

                $checkedRadio1 = '';
                $checkedRadio2 = ' checked = "checked"';
                $orderedSettingOptions = $this->getOrderedCategoriesOptions();
                if (isset($orderedSettingOptions[$cat_to_retrieve_post]) && $orderedSettingOptions[$cat_to_retrieve_post] == 'true') {
                    $checkedRadio1 = $checkedRadio2;
                    $checkedRadio2 = '';
                }

                echo '<label for="yes"><input type="radio"'.$checkedRadio1.' class="option_order" id="yes" value="true" name="useForThisCat"/> <span>'.__('Oui', 'order-patients').'</span></label><br/>';
                echo '<label for="no"><input type="radio"'.$checkedRadio2.' class="option_order" id="no" value="false" name="useForThisCat"/> <span>'.__('Non', 'order-patients').'</span></label>';
                echo '<input type="hidden" name="termID" id="termIDCat" value="'.$cat_to_retrieve_post.'">';
                echo '<span class="spinner" id="spinnerAjaxRadio"></span>';
                echo '</div>';

                echo '<h3 class="floatLeft">' . sprintf(__('Listes des articles de type "%s", classé dans la catégorie "%s" :', 'order-patients'), $post_type_detail->labels->name, $term_selected) . '</h3>';
                echo '<span id="spinnerAjaxUserOrdering" class="spinner"></span><div class="clearBoth"></div>';
                echo '<ul id="sortable-list" class="order-list" rel ="'.$cat_to_retrieve_post.'">';

                for ($i = 0; $i < count($order_result); ++$i) {
                    $post_id = $order_result[$i]->post_id;
                    $post = $temp_order[$post_id];
                    unset($temp_order[$post_id]);
                    $od = $order_result_incl[$post->ID];

                    echo '<li id="'.$post->ID.'">';
                    echo '<span class="title">'.$post->post_title.' ('. get_post_meta($post->ID, 'order_code', TRUE) . ')</span>';
                    //Add images
                    $post_data = get_post_field('post_content', $post_id );
					$images = substr(explode(" ",(explode("images", $post_data)[1]))[0],2);
					if ( strpos ($post_data, "medias" ) ) {
						$images = substr(explode(" ",(explode("medias", $post_data)[1]))[0],2);
                    }
					$firstTwoImg = [] ;
					array_push($firstTwoImg , (int)explode(",",$images)[0] , (int)explode(",",$images)[1] ) ;
					echo '<div class="patients-images"><img src="'.wp_get_attachment_image_src($firstTwoImg[0],add_image_size( 'custom-size', 128, 150) )[0].'"><img src="'.wp_get_attachment_image_src($firstTwoImg[1],add_image_size( 'custom-size', 128, 150) )[0].'"></div>';

                    echo '</li>';
                }

                foreach ($temp_order as $temp_order_id => $temp_order_post) {
                    $post_id = $temp_order_id;
                    $post = $temp_order_post;

                    echo '<li id="'.$post->ID.'">';
                    echo '<span class="title">'.$post->post_title.' ('. get_post_meta($post->ID, 'order_code', TRUE) . ')</span>';
                    //Add images
                    $post_data = get_post_field('post_content', $post_id );
					$images = substr(explode(" ",(explode("images", $post_data)[1]))[0],2);
					$firstTwoImg = [] ;
					array_push($firstTwoImg , (int)explode(",",$images)[0] , (int)explode(",",$images)[1] ) ;
					echo '<div class="patients-images"><img src="'.wp_get_attachment_image_src($firstTwoImg[0],add_image_size( 'custom-size', 128, 150) )[0].'"><img src="'.wp_get_attachment_image_src($firstTwoImg[1],add_image_size( 'custom-size', 128, 150) )[0].'"></div>';


                    echo '</li>';
                }

                echo "</ul>";
                echo '</div>';
                echo '</div>';
            } ?>
		</form>
		<div id="debug">

		</div>
	    </div>
	    <?php
        }

        /**
         *
         */
        public function printAdminPage()
        {
            if (!empty($_POST) && check_admin_referer('updateOptionSettings', 'nounceUpdateOptionorder') && wp_verify_nonce($_POST['nounceUpdateOptionorder'], 'updateOptionSettings')) {
                do_action("deleteUnecessaryEntries"); ?>
		<div class="updated"><p><strong><?php _e("Options enregistrées.", "order-patients"); ?></strong> <?php _e("You can now find in the main menu for each type of article, a page to re-order your items within each category.", "order-patients"); ?></p></div>
		<?php
            }
            $settingsOptions = $this->getAdminOptions(); ?>
	    <div class="wrap">
		<div class="icon32" id="icon-options-general"><br/></div>
		<h2><?php _e('Trie des articles d\'une catégorie', 'order-patients'); ?></h2>
		<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
		    <?php wp_nonce_field('updateOptionSettings', 'nounceUpdateOptionorder'); ?>
		    <p><?php _e("Check the categories you want to manually sort the items. Once you have checked and validated this information, a new menu will appear in each type of post concerned.", "order-patients"); ?></p>
		    <h3><?php _e("Types of articles available:", "order-patients"); ?></h3>
		    <?php
      /**
      * improve the post selection, select post with taxobnomies only
      * @since 1.2.2
      */
      $args = array(
        'show_ui' => true,
        // '_builtin' => false
      );
            $post_types = get_post_types($args, 'object');
            if ($post_types) :

                foreach ($post_types as $post_type) {
                    $taxonomies = get_object_taxonomies($post_type->name, 'objects');
                    if (empty($taxonomies)) {
                        continue;
                    } //no taxonomies to order post in terms.
                    else {
                        $taxonomy_ui = false;
                        foreach ($taxonomies as $taxonomy) {
                            if ($taxonomy->show_ui) {
                                $taxonomy_ui = true;
                            }
                        }
                        if (!$taxonomy_ui) {
                            continue;
                        } //no taxonomies to oder post in terms.
                    }

                    echo "<strong>" . $post_type->labels->menu_name . "</strong>";

                    foreach ($taxonomies as $taxonomie) {
                        if (!$taxonomie->show_ui) {
                            continue;
                        }
                        if ($taxonomie->hierarchical == 1 || apply_filters('order_post_within_categories_and_tags', false)) {
                            $ischecked = '';
                            if (isset($settingsOptions['categories_checked'][$post_type->name])) {
                                if (in_array($taxonomie->name, $settingsOptions['categories_checked'][$post_type->name])) {
                                    $ischecked = ' checked = "checked"';
                                }
                            }
                            echo '<p>&nbsp;&nbsp;<label><input type="checkbox"'.$ischecked.' value="'.$taxonomie->name.'" name="selection['.$post_type->name.'][]"> '. $taxonomie->labels->name .'</label></p>';
                        }
                    }
                }
            echo '<p class="submit"><input id="submit" class="button button-primary" type="submit" value="'.__('Autoriser le tri pour les catégories cochées', 'order-patients').'" name="submit"/>';
            endif; ?>
		</form>
	    </div>
	    <?php
        }

        /**
         * Add an option age link for the administrator only
         */
        public function add_setting_page()
        {
            if (function_exists('add_options_page')) {
                add_options_page(__('Order patients', 'order-patients'), __('order Post', 'order-patients'), 'manage_options', basename(__FILE__), array(&$this, 'printAdminPage'));
            }
        }

        /**
         * Dispplay a link to setting page inside the plugin description
         */
        public function display_settings_link($links)
        {
            $settings_link = '<a href="options-general.php?page=order-patients.php">' . __('Paramètres', 'order-patients') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }
    }


    /* Instantiate the plugin */
    $orderPatients_instance = new OrderPatients();
}
