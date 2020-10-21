<?php
//use org\bovigo\vfs\vfsStream;
//use EnableMediaReplace\emrFile as File;
//use EnableMediaReplace\Replacer as Replacer;

use EnableMediaReplace\Externals\WpBakery as WpBakery;


class WpBakeryTest extends WP_UnitTestCase
{

  public  function setUp()
  {
     do_action('vc_plugins_loaded');
  }

  public function testElementorAddSlashes()
  {
    $search_array = array('/dir/subdir/image.jpg', '/dir/subdir/image-1000x500.jpg');
    $replace_array = array('/dir/subdir/new.jpg', '/dir/subdir/new-1000x500.jpg');
    $url = '/subdir/image.jpg';

    $e = WpBakery::getInstance();

    $array = $e->addUrlEncoded(array(), $url, $search_array, $replace_array);

    $this->assertIsArray($array);
    $this->assertArrayHasKey('wpbakery', $array);

    $el = $array['wpbakery'];
    $result = array('%2Fdir%2Fsubdir%2Fimage.jpg', '%2Fdir%2Fsubdir%2Fimage-1000x500.jpg');

    $this->assertEquals($result, $el['search_urls']); // Not needed, since replacer does json_decode, then replaces.

    // here first slash should not return, image should be converted to appriorate amount of slashes.
    $this->assertEquals('%2Fsubdir%2Fimage.jpg', $el['base_url']);
  }

  public function testElementorFilterHook()
  {
       //if (! defined())
       $search_array = array('/dir/subdir/image.jpg', '/dir/subdir/image-1000x500.jpg');
       $replace_array = array('dir/subdir/new.jpg', 'dir/subdir/new-1000x500.jpg');
       $base_url = 'subdir/image.jpg';

       $e = new WpBakery(); // use non-static method, because filters are destroyed between tests

       $array['other_integration'] = array();
       $result = apply_filters('emr/replacer/custom_replace_query', $array, $base_url, $search_array, $replace_array);

       $this->assertArrayHasKey('other_integration', $result);
       $this->assertArrayHasKey('wpbakery', $result, print_r($result, true) );

  }



} // elemntor class
