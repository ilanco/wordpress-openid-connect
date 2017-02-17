<?php

/**
 * Plugin Name: Wordpress OpenID Connect
 * Plugin Slug: wordpress-openid-connect
 * Plugin URI: https://github.com/ilanco/wordpress-openid-connect
 * Description: This plugin allows user authentication and registration using an openid connect server
 * Version: 1.0.2
 * Author: Ilan Cohen
 * Author Email: ilanco@gmail.com
 * Author URI: https://github.com/ilanco
 * License: MIT
 */

namespace IC;

use OpenIDConnectClient;
use OpenIDConnectClientException;

define('WP_OPENID_CONNECT_BASEPATH', dirname(__FILE__));
define('WP_OPENID_CONNECT_SLUG', 'wordpress-openid-connect');

class WP_OpenIDConnectClient extends OpenIDConnectClient
{
    /**
     * @return string
     */
    public function getRedirectURL() {
        return get_site_url() . '/' . WP_OPENID_CONNECT_SLUG;
    }
}

class WordpressOpenIDConnect
{
    /**
     * @type    string
     */
    private $_option_name = '';

    /**
     * The absolute path to this plugin's root directory.
     *
     * @type    string
     */
    protected $basepath = '';

    /**
     *
     * @type    array
     */
    protected $options = [];

    /**
     * Initializes the plugin by setting localization, filters, and administration functions.
     */
    function __construct()
    {
        $basepath = WP_OPENID_CONNECT_BASEPATH;

        if ($basepath == '') {
            // strip the trailing /includes from the current directory
            $basepath = substr(dirname(__FILE__), 0, -9);
        }

        $this->basepath = $basepath . '/';
        $this->_option_name = $this->get_class_name(get_class($this)) . '_options';

        $option = get_option($this->_option_name);

        if (isset($option) && $option) {
            $this->options = array_merge($this->options, $option);
        } else {
            $this->save_options($this->options);
        }

        /**
         * Register settings options
         */
        add_action('admin_init', [$this, 'register_plugin_settings_api_init']);
        add_action('admin_menu', [$this, 'register_plugin_admin_add_page']);

        /**
         * Custom Login page
         */
        add_action('login_message', [$this, 'login_headertitle']);

        /**
         * wp_logout action hook
         */
        add_action('wp_logout', [$this, 'logout']);

        /**
         * Add a plugin settings link on the plugins page
         */
        $plugin = plugin_basename(__FILE__);
        add_filter("plugin_action_links_$plugin", function ($links) {
            $settings_link = '<a href="options-general.php?page=' . WP_OPENID_CONNECT_SLUG . '">Settings</a>';
            array_unshift($links, $settings_link);
            return $links;
        });

        /**
         * Build a new endpoint
         * process requests with WP_OPENID_CONNECT_SLUG
         */
        $self = $this;
        add_action('parse_request', function ($wp) use ($self) {
            if ($wp->request === WP_OPENID_CONNECT_SLUG) {
                $self->authenticate();
            }
        });
    }

    private function get_class_name($classname)
    {
        if ($pos = strrpos($classname, '\\')) {
            return substr($classname, $pos + 1);
        }

        return $classname;
    }

    /**
     * Recursively strips slashes from a variable
     *
     * @param    mixed    an array or string to be stripped
     * @return    mixed    a "safe" version of the input variable
     */
    private function stripslashes_deep($value)
    {
        $value = is_array($value) ?
            array_map([$this, 'stripslashes_deep'], $value) :
            stripslashes($value);

        return $value;
    }

    /**
     * Save options to the database
     */
    protected function save_options()
    {
        update_option($this->_option_name, $this->options);
    }

    /**
     * @return string
     */
    public function get_option_name()
    {
        return $this->_option_name;
    }

    /**
     * Get an option
     *
     * @param    string    key
     * @return    string     value
     */
    public function get_option($key)
    {
        if (array_key_exists($key, $this->options)) {
            $value = $this->options[$key];

            if (is_array($value)) {
                return $this->stripslashes_deep($value);
            } elseif (is_string($value)) {
                return stripslashes($value);
            }
        }
        return null;
    }

    /**
     * Update an option
     *
     * @param    string    key
     * @param    mixed     value
     */
    protected function update_option($key, $value)
    {
        $this->options[$key] = $value;
        $this->save_options();
    }

    /**
     * Field settings helper for WP core function.
     * Takes care of namespacing
     *
     * @param $field_name
     * @param $page
     * @param $section
     * @param string $type
     */
    public function add_settings_field($field_name, $field_label, $page, $section, $type = 'text')
    {
        $self = $this;

        add_settings_field($field_name, $field_label, function () use ($self, $field_name) {
            echo "<input id='{$field_name}' name='{$self->get_option_name()}[{$field_name}]' size='40' type='text'
                value='{$self->get_option($field_name)}' />";
        }, $page, $section);
    }

    /**
     * Show a view
     *
     * @param    string    the name of the view
     * @param    array    (optional) variables to pass to the view
     * @param    boolean    echo the view? (default: true)
     */
    public function load_view($view, $data = null, $echo = true)
    {
        $view = $view . '.php';
        $viewfile = $this->basepath . get_class($this) . '/views/' . $view;

        if (!file_exists($viewfile)) {
            $viewfile = $this->basepath . 'views/' . $view;

            if (!file_exists($viewfile)) {
                echo 'couldn\'t load view';
            }
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                ${$key} = $value;
            }
        }

        ob_start();
        include $viewfile;
        $result = ob_get_contents();
        ob_end_clean();

        if ($echo) {
            echo $result;
        } else {
            return $result;
        }

        return null;
    }

    /**
     *
     */
    public function add_button_to_login()
    {
        $this->load_view('button', null, true);
    }

    /**
     *
     */
    public function login_headertitle($message)
    {
        $content = $this->load_view('button', null, false);

        return $message . $content;
    }

    /**
     *
     */
    public function logout()
    {
        $accessToken = get_user_meta(get_current_user_id(), 'access_token', true);

        $oidc = new WP_OpenIDConnectClient($this->get_option('openid_server_url')
            , $this->get_option('openid_client_id')
            , $this->get_option('openid_client_secret'));

        $oidc->signOut($accessToken, get_site_url());
    }

    /**
     *
     */
    public function authenticate()
    {
        if (!$this->get_option('openid_client_id')
            || !$this->get_option('openid_client_secret')
            || !$this->get_option('openid_server_url')
        ) {
            wp_die("Wordpress OpenID Connect plugin is not configured");
        }

        $oidc = new WP_OpenIDConnectClient($this->get_option('openid_server_url')
            , $this->get_option('openid_client_id')
            , $this->get_option('openid_client_secret'));

        // Setup a proxy if defined in wp-config.php
        if (defined('WP_PROXY_HOST')) {
            $proxy = WP_PROXY_HOST;

            if (defined('WP_PROXY_PORT')) {
                $proxy = rtrim($proxy, '/') . ':' . WP_PROXY_PORT . '/';
            }

            $oidc->setHttpProxy($proxy);
        }

        $oidc->addScope('openid');
        $oidc->addScope('email');
        $oidc->addScope('profile');

        try {
            $oidc->authenticate();
            self::login_oidc_user($oidc);

        } catch (Exception $e) {
            wp_die($e->getMessage());
        }

        return null;
    }

    /**
     * @param $oidc WP_OpenIDConnectClient
     *
     * @throws OpenIDConnectClientException
     */
    private function login_oidc_user($oidc)
    {
        /*
         * Only allow usernames that are not affected by sanitize_user(), and that are not
         * longer than 60 characters (which is the 'user_login' database field length).
         * Otherwise an account would be created but with a sanitized username, which might
         * clash with an already existing account.
         * See sanitize_user() in wp-includes/formatting.php.
         *
         */
        $username = $oidc->requestUserInfo('preferred_username');

        if ($username != substr(sanitize_user($username, TRUE), 0, 60)) {
            $error = sprintf(__('<p><strong>ERROR</strong><br /><br />
                We got back the following identifier from the login process:<pre>%s</pre>
                Unfortunately that is not suitable as a username.<br />
                Please contact the <a href="mailto:%s">blog administrator</a> and ask to reconfigure the
                Wordpress OpenID connect plugin!</p>'), $username, get_option('admin_email'));
            $errors['registerfail'] = $error;
            print($error);
            exit();
        }

        if (!function_exists('get_user_by')) {
            die("Could not load user data");
        }

        $user = get_user_by('email', $oidc->requestUserInfo('email'));
        $wp_uid = null;

        if ($user) {
            // user already exists
            $wp_uid = $user->ID;
        } else {
            throw new OpenIDConnectClientException('User is not allowed to login to this site.');
        }

        // save access token
        update_user_meta($wp_uid, 'access_token', $oidc->getAccessToken());

        $user = wp_set_current_user($wp_uid, $username);
        wp_set_auth_cookie($wp_uid);
        do_action('wp_login', $username);

        // Redirect the user
        wp_safe_redirect(admin_url());
        exit();
    }

    /**
     *
     */
    public function register_plugin_settings_api_init()
    {
        register_setting($this->get_option_name(), $this->get_option_name());

        add_settings_section('wordpress_openid_connect_client', 'Main Settings', function () {
            echo "<p>These settings are required for the plugin to work properly.</p>";
        }, WP_OPENID_CONNECT_SLUG);

        // Add a Server URL setting
        $this->add_settings_field('openid_server_url', 'OpenID Server URL', WP_OPENID_CONNECT_SLUG, 'wordpress_openid_connect_client');
        // Add a Client ID setting
        $this->add_settings_field('openid_client_id', 'OpenID Client ID', WP_OPENID_CONNECT_SLUG, 'wordpress_openid_connect_client');
        // Add a Client Secret setting
        $this->add_settings_field('openid_client_secret', 'OpenID Client Secret', WP_OPENID_CONNECT_SLUG, 'wordpress_openid_connect_client');
    }

    /**
     *
     */
    public function register_plugin_admin_add_page()
    {
        $self = $this;
        add_options_page('Wordpress OpenID Connect Login Page', 'Wordpress OpenID Connect', 'manage_options', WP_OPENID_CONNECT_SLUG, function () use ($self) {
            $self->load_view('settings', null);
        });
    }
}

// Init plugin
$plugin_name = new WordpressOpenIDConnect();
