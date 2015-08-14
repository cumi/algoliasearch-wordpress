<?php

class AlgoliaPlugin
{
    private $algolia_registry;
    private $algolia_helper;
    private $indexer;
    private $template_helper;
    private $query_replacer;

    public function __construct()
    {
        $this->algolia_registry = \Algolia\Core\Registry::getInstance();

        if ($this->algolia_registry->validCredential)
        {
            $this->algolia_helper   = new \Algolia\Core\AlgoliaHelper(
                $this->algolia_registry->app_id,
                $this->algolia_registry->search_key,
                $this->algolia_registry->admin_key
            );
        }

        $this->query_replacer = new \Algolia\Core\QueryReplacer();

        $this->template_helper = new \Algolia\Core\TemplateHelper();

        $this->indexer = new \Algolia\Core\Indexer();

        add_action('admin_menu',                                array($this, 'add_admin_menu'));

        add_action('admin_post_update_account_info',            array($this, 'admin_post_update_account_info'));
        add_action('admin_post_reset_config_to_default',        array($this, 'admin_post_reset_config_to_default'));
        add_action('admin_post_export_config',                  array($this, 'admin_post_export_config'));

        add_action('pre_get_posts',                             array($this, 'pre_get_posts'));
        add_filter('the_posts',                                 array($this, 'get_search_result_posts'));

        add_action('admin_post_reindex',                        array($this, 'admin_post_reindex'));

        add_action('admin_enqueue_scripts',                     array($this, 'admin_scripts'));
        add_action('wp_enqueue_scripts',                        array($this, 'scripts'));

        add_action('wp_footer',                                 array($this, 'wp_footer'));
    }

    public function add_admin_menu()
    {
        $icon_url = plugin_dir_url(__FILE__) . 'admin/imgs/icon.png';
        add_menu_page('Algolia Settings', 'Algolia Search', 'manage_options', 'algolia-settings', array($this, 'admin_view'), $icon_url);
    }

    public function admin_view()
    {
        include __DIR__ . '/admin/views/admin_menu.php';
    }

    public function wp_footer()
    {
        include __DIR__ . '/templates/' . $this->algolia_registry->template_dir . '/templates.php';
    }

    private function buildSettings()
    {
        $settings_name = [
            'autocompleteTypes', 'additionalAttributes', 'instantTypes', 'facets', 'app_id', 'search_key',
            'index_prefix', 'search_input_selector', 'number_by_page', 'instant_jquery_selector', 'sorts'
        ];

        $settings = array();

        foreach ($settings_name as $name)
            $settings[$name] = $this->algolia_registry->{$name};

        $algoliaSettings = array_merge($settings, array(
            'template'                  => $this->template_helper->get_current_template(),
            'is_search_page'            => isset($_GET['instant'])
        ));

        return $algoliaSettings;
    }

    public function scripts()
    {
        if (is_admin())
            return;

        wp_enqueue_style('algolia_bundle', plugin_dir_url(__FILE__) . 'templates/' . $this->algolia_registry->template_dir . '/bundle.css');
        wp_enqueue_style('algolia_styles', plugin_dir_url(__FILE__) . 'templates/' . $this->algolia_registry->template_dir . '/styles.css');



        wp_register_script('lib/bundle.min.js', plugin_dir_url(__FILE__) . 'lib/bundle.min.js', array());
        wp_localize_script('lib/bundle.min.js', 'algoliaSettings', $this->buildSettings());

        wp_register_script('template.js',  plugin_dir_url(__FILE__) . 'templates/' . $this->algolia_registry->template_dir . '/template.js', array('lib/bundle.min.js'), array());

        wp_enqueue_script('template.js');

    }

    public function admin_scripts($hook)
    {
        wp_enqueue_style('styles-admin', plugin_dir_url(__FILE__) . 'admin/styles/styles.css');
        wp_enqueue_style('algolia_bundle', plugin_dir_url(__FILE__) . 'templates/' . $this->algolia_registry->template_dir . '/bundle.css');

        // Only load these scripts on the Algolia admin page
        if ( 'toplevel_page_algolia-settings' != $hook ) {
            return;
        }

        global $batch_count;

        $algoliaAdminSettings = array(
            'taxonomies'    => array(),
            'types'         => array(),
            'batch_count'   => $batch_count,
            'site_url'      => site_url()
        );


        foreach ($this->algolia_registry->autocompleteTypes as $value)
            $algoliaAdminSettings["types"][$value['name']] = array('type' => $value['name'], 'count' => wp_count_posts($value['name'])->publish);


        foreach (get_taxonomies() as $tax)
            $algoliaAdminSettings['taxonomies'][$tax] = array('count' => wp_count_terms($tax, array('hide_empty' => false)));

        wp_register_script('lib/bundle.min.js', plugin_dir_url(__FILE__) . 'lib/bundle.min.js', array());
        wp_register_script('angular.min.js', plugin_dir_url(__FILE__) . 'admin/scripts/angular.min.js', array());
        wp_register_script('admin.js', plugin_dir_url(__FILE__) . 'admin/scripts/admin.js', array('lib/bundle.min.js', 'angular.min.js'));
        wp_localize_script('admin.js', 'algoliaAdminSettings', $algoliaAdminSettings);
        wp_enqueue_script('admin.js');
    }

    public function pre_get_posts($query)
    {
        return $this->query_replacer->search($query);
    }

    public function get_search_result_posts($posts)
    {
        $posts = $this->query_replacer->getOrderedPost($posts);

        return $posts;
    }

    public function admin_post_update_account_info()
    {
        if (isset($_POST['submit']) && $_POST['submit'] == 'Import configuration'
            && isset($_FILES['import']) && isset($_FILES['import']['tmp_name']) && is_file($_FILES['import']['tmp_name']))
        {
            $content = file_get_contents($_FILES['import']['tmp_name']);

            try
            {
                $this->algolia_registry->import(json_decode($content, true));
                wp_redirect('admin.php?page=algolia-settings#credentials');
                return;
            }
            catch(\Exception $e)
            {
                echo $e->getMessage();
                echo '<pre>';
                echo $e->getTraceAsString();
                die();
            }
        }

        $settings_name = [
            'autocompleteTypes', 'additionalAttributes', 'instantTypes', 'attributesToIndex',
            'customRankings', 'facets', 'app_id', 'search_key', 'admin_key', 'index_prefix', 'enable_truncating',
            'truncate_size', 'search_input_selector', 'template_dir', 'number_by_page', 'instant_jquery_selector',
            'sorts'
        ];

        foreach ($settings_name as $name)
        {
            if (isset($_POST['data']) && isset($_POST['data'][$name]))
            {
                $data = $_POST['data'][$name];

                if (is_array($data))
                    foreach ($data as $key => &$value)
                        if (is_array($value))
                            foreach ($value as $sub_key => &$sub_value)
                                $sub_value = \Algolia\Core\WordpressFetcher::try_cast($sub_value);

                $this->algolia_registry->{$name} = $data;

            }
            else
                $this->algolia_registry->resetAttribute($name);
        }


        $algolia_helper = new \Algolia\Core\AlgoliaHelper($this->algolia_registry->app_id, $this->algolia_registry->search_key, $this->algolia_registry->admin_key);
        $algolia_helper->checkRights();

        if ($this->algolia_registry->validCredential)
            $algolia_helper->handleIndexCreation();

        $this->algolia_registry->need_to_reindex    = true;

        wp_redirect('admin.php?page=algolia-settings#credentials');
    }

    public function admin_post_reset_config_to_default()
    {
        $this->algolia_registry->reset_config_to_default();

        $this->algolia_registry->need_to_reindex  = true;
    }

    public function admin_post_export_config()
    {
        header("Content-type: text/plain");
        header("Content-Disposition: attachment; filename=algolia-wordpress-config.txt");

        echo $this->algolia_registry->export();
    }

    public function admin_post_reindex()
    {
        global $batch_count;

        foreach ($_POST as $post)
        {
            $subaction = explode('__', $post);

            if (count($subaction) == 1 && $subaction[0] != "reindex")
            {
                if ($subaction[0] == 'handle_index_creation')
                    $this->algolia_helper->handleIndexCreation();
                if ($subaction[0] == 'index_taxonomies')
                    $this->indexer->indexTaxonomies();
                if ($subaction[0] == 'move_indexes')
                {
                    $this->indexer->moveTempIndexes();

                    $this->algolia_registry->need_to_reindex  = false;
                }
            }

            if (count($subaction) == 3)
            {
                $this->algolia_registry->last_update = time();
                if ($subaction[0] == 'type' && is_numeric($subaction[2]))
                    $this->indexer->indexPostsTypePart($subaction[1], $batch_count, $subaction[2]);
            }
        }
    }
}
