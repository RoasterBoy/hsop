<?php

/**
 * Abstract Async Request Class
 * 
 * Based on the WordPress core async request class
 * Adapted for the Cemetery Records plugin
 */

abstract class Cemetery_Records_Async_Request {

    /**
     * Prefix
     *
     * @var string
     */
    protected $prefix = 'wp';

    /**
     * Action
     *
     * @var string
     */
    protected $action = 'async_request';

    /**
     * Identifier
     *
     * @var mixed
     */
    protected $identifier;

    /**
     * Data
     *
     * @var array
     */
    protected $data = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->identifier = $this->prefix . '_' . $this->action;

        add_action('wp_ajax_' . $this->identifier, array($this, 'maybe_handle'));
        add_action('wp_ajax_nopriv_' . $this->identifier, array($this, 'maybe_handle'));
    }

    /**
     * Set data
     *
     * @param array $data Data.
     *
     * @return $this
     */
    public function data($data) {
        $this->data = $data;

        return $this;
    }

    /**
     * Dispatch
     *
     * @return array|WP_Error
     */
    public function dispatch() {
        $url  = add_query_arg($this->get_query_args(), $this->get_query_url());
        $args = $this->get_post_args();

        return wp_remote_post(esc_url_raw($url), $args);
    }

    /**
     * Get query args
     *
     * @return array
     */
    protected function get_query_args() {
        if (property_exists($this, 'query_args')) {
            return $this->query_args;
        }

        $args = array(
            'action' => $this->identifier,
            'nonce'  => wp_create_nonce($this->identifier),
        );

        return array_merge($args, $this->data);
    }

    /**
     * Get query URL
     *
     * @return string
     */
    protected function get_query_url() {
        if (property_exists($this, 'query_url')) {
            return $this->query_url;
        }

        return admin_url('admin-ajax.php');
    }

    /**
     * Get post args
     *
     * @return array
     */
    protected function get_post_args() {
        if (property_exists($this, 'post_args')) {
            return $this->post_args;
        }

        return array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'body'      => $this->data,
            'cookies'   => $_COOKIE,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        );
    }

    /**
     * Maybe handle
     *
     * Check for correct nonce and pass to handler.
     */
    public function maybe_handle() {
        // Don't lock up other requests while processing
        session_write_close();

        check_ajax_referer($this->identifier, 'nonce');

        $this->handle();

        wp_die();
    }

    /**
     * Handle
     *
     * Override this method to perform any actions required
     * during the async request.
     */
    abstract protected function handle();
} 