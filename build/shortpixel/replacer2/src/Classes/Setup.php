<?php 

namespace EnableMediaReplace\Replacer\Classes; 

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class Setup
{

    protected $searchURLData; 
    protected $replaceURLData;

    protected $searchMetaData; 
    protected $replaceMetaData; 

    protected $new_datatype; 
    protected $new_action; 

    protected static $instance; 
    
    public static function getInstance()
    {
         if (is_null(self::$instance))
         {
             self::$instance = new Setup();
         }

         return self::$instance;
    }


    public function MetaData()
    {
        if (is_null($this->{$this->new_action . 'MetaData'}))
        {
             $this->{$this->new_action . 'MetaData'} = new MetaData($this->new_action); 
        }

       //  $this->new_datatype = 'url';   
        return $this->{$this->new_action . 'MetaData'}; 
    }

    public function URL()
    {
        
        if (is_null($this->{$this->new_action . 'URLData'}))
        {
             $this->{$this->new_action . 'URLData'} = new Url($this->new_action); 
        }

       //  $this->new_datatype = 'url';   
        return $this->{$this->new_action . 'URLData'}; 
    }

    public function forReplace()
    {
        $this->new_action = 'replace'; 
        return $this; 
    }

    public function forSearch()
    {
        $this->new_action = 'search'; 
        return $this; 
    }

    
    /*public function data($data)
    {
        if ('metadata' == $this->new_datatype)
        {
             
        }

        if ('search' === $this->new_action)
        {

        }
        elseif ('replace' === $this->new_action)
        {
             
        }

        $this->endRequest();
    } */

    private function endRequest()
    {
         $this->new_datatype = null;
         $this->new_action = null;
    }

    // Function to check even sides / are we ready to go? 
    public function validateData()
    {

    }



}