<?php 

namespace EnableMediaReplace\Helper;


class EnvHelper 
{
    protected static $instance; 


    public $is_multisite = false; 
    public $is_mainsite = false; 
    public $is_network_admin = false; 
    public $is_ajaxcall = false; 
    public $is_jsoncall = false; 
    public $is_croncall = false; 


    public function __construct()
    {
        $this->setWP();
    }


    public static function getInstance()
    {
        if (is_null(self::$instance))
            self::$instance = new EnvHelper();
    
        return self::$instance;
    }


    protected function setWP()
    {
        $this->is_multisite = (function_exists("is_multisite") && is_multisite()) ? true : false;
        $this->is_mainsite = (function_exists('is_main_site') && true === is_main_site()) ? true : false;
        $this->is_network_admin = (function_exists('is_network_admin') && true === is_network_admin()) ? true : false; 

        $this->is_ajaxcall = wp_doing_ajax();
        $this->is_jsoncall = wp_is_json_request();
        $this->is_croncall = wp_doing_cron();
    }


    


} // class 