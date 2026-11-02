<?php
/**
 * Setup class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Fluent collector of search and replace data.
 *
 * Usage: pick a side with forSearch() or forReplace(), then ask for a data
 * object with MetaData() or URL(), for example
 * `$setup->forSearch()->MetaData()->addData($meta)`. Each side holds at most
 * one MetaData and one Url object, created lazily and stored in the property
 * named after the side and the type (`searchMetaData`, `replaceURLData`,
 * ...), through `$this->{$this->new_action . 'MetaData'}`.
 *
 * Lifecycle: not a singleton. getInstance() deliberately returns a new
 * instance on every call so data from an earlier request is never reused.
 * The class fires and listens to no hooks. Replacer::replace() does not use
 * this API.
 */
class Setup
{

    /**
     * Url data object of the search side.
     *
     * @var Url|null
     */
    protected $searchURLData;

    /**
     * Url data object of the replace side.
     *
     * @var Url|null
     */
    protected $replaceURLData;

    /**
     * MetaData data object of the search side.
     *
     * @var MetaData|null
     */
    protected $searchMetaData;

    /**
     * MetaData data object of the replace side.
     *
     * @var MetaData|null
     */
    protected $replaceMetaData;

    /**
     * Data type of the pending request. Only reset by endRequest(); not
     * otherwise used.
     *
     * @var string|null
     */
    protected $new_datatype;

    /**
     * Side selected by forSearch() / forReplace(): `search` or `replace`.
     *
     * @var string|null
     */
    protected $new_action;

    /**
     * Unused; kept for the disabled singleton in getInstance().
     *
     * @var Setup|null
     */
    protected static $instance;

    /**
     * Returns a new Setup instance.
     *
     * Despite the name this is not a singleton: a shared instance would carry
     * the data of a previous request into the next one.
     *
     * @return Setup
     */
    public static function getInstance()
    {
        /* if (is_null(self::$instance))
         {
             self::$instance = new Setup();
         }

         return self::$instance; */

         // If using singleton here each request after uses the old data from previous request. Since the whole new module is not fully formed
         return new Setup(); 
    }


    /**
     * Returns the MetaData object of the selected side, creating it once.
     *
     * forSearch() or forReplace() must be called first; without a side the
     * property name resolves to `MetaData`, which is not declared.
     *
     * @return MetaData
     */
    public function MetaData()
    {
        if (is_null($this->{$this->new_action . 'MetaData'}))
        {
             $this->{$this->new_action . 'MetaData'} = new MetaData($this->new_action); 
        }

       //  $this->new_datatype = 'url';   
        return $this->{$this->new_action . 'MetaData'}; 
    }

    /**
     * Returns the Url object of the selected side, creating it once.
     *
     * forSearch() or forReplace() must be called first.
     *
     * @return Url
     */
    public function URL()
    {
        
        if (is_null($this->{$this->new_action . 'URLData'}))
        {
             $this->{$this->new_action . 'URLData'} = new Url($this->new_action); 
        }

       //  $this->new_datatype = 'url';   
        return $this->{$this->new_action . 'URLData'}; 
    }

    /**
     * Selects the replace side for the next MetaData() / URL() call.
     *
     * @return Setup This instance, for chaining.
     */
    public function forReplace()
    {
        $this->new_action = 'replace';
        return $this;
    }

    /**
     * Selects the search side for the next MetaData() / URL() call.
     *
     * @return Setup This instance, for chaining.
     */
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

    /**
     * Clears the selected side and data type.
     *
     * Only called from the commented-out data() method, so currently unused.
     *
     * @return void
     */
    private function endRequest()
    {
         $this->new_datatype = null;
         $this->new_action = null;
    }

    // Function to check even sides / are we ready to go?
    /**
     * Placeholder for checking that both sides are balanced.
     *
     * The body is empty: it validates nothing and returns null.
     *
     * @return void
     */
    public function validateData()
    {

    }



}