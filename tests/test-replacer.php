<?php

use EnableMediaReplace\emrFile as File;
use EnableMediaReplace\Replacer as Replacer;

use EnableMediaReplace\Externals\Elementor as Elementor;

class ReplacerTest extends WP_UnitTestCase
{

  private static $replacer;
  private static $method;
  private $search = 'testfile.jpg';
  private $replace = 'replacedfile.jpg';

  public static function setUpBeforeClass()
  {
    $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
    $replacerFunc = $replaceRefl->getMethod('replaceContent');
    $replacerFunc->setAccessible(true);

    self::$replacer = new Replacer(0);
    self::$method = $replacerFunc;

  }

  public function testReplaceString()
  {
    $content = $this->search;
    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

    $this->assertEquals($this->replace, $result);

    $content = 'random' . $this->search . 'random';
    $correct = 'random' . $this->replace . 'random';

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

    $this->assertEquals($correct, $result);
  }

  public function testReplaceWpError()
  {
     $content = new \WP_Error(100, 'Error');

     $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

     // WP Error should just return error.
     $this->assertEquals(serialize($content), $result);

  }

  public function testReplaceObject()
  {
    $content = new \stdClass;
    $content->first = 'random';
    $content->image = $this->search;
    $content->last = 'other';

    $result_serialized = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);
    $result = unserialize($result_serialized);

    $this->assertEquals($this->replace, $result->image);
    $this->assertEquals($content->first, $result->first);
    $this->assertEquals($content->last, $result->last);
  }

  public function testReplaceArray()
  {
    $content = array('first'=> 'random', 'content' => $this->search, 'last' => 'other');

    $result_serialized = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);
    $result = unserialize($result_serialized);

    $this->assertEquals($this->replace, $result['content']);
    $this->assertEquals($content['first'], $result['first']);
    $this->assertEquals($content['last'], $result['last']);
  }

  public function testMixedReplace()
  {
    $content = new \stdClass;
    $content->first = 'randomstring';
    $content->second = array('array', 'barray', 0, 12);
    $content->third = array('string '. $this->search . ' string');

    $correct = array('string '. $this->replace . ' string');

    $result_serialized = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);
    $result = unserialize($result_serialized);

    $this->assertEquals($content->first, $result->first);
    $this->assertIsString($result->first);

    $this->assertEquals($content->second, $result->second);
    $this->assertIsArray($result->second);

    $this->assertEquals($content->third, $result->third);
    $this->assertIsArray($result->third);
    $this->assertEquals($correct, $result->third);

  }

  public function testSerialized()
  {
    $content = serialize(array('img' => '<img class="alignnone size-large wp-image-1358" src="' . $this->search . '" alt="" width="640" height="427" />'));
    $expected = array('img' => '<img class="alignnone size-large wp-image-1358" src="' . $this->replace. '" alt="" width="640" height="427" />');

    $expected_ser =  serialize(array('img' => '<img class="alignnone size-large wp-image-1358" src="' . $this->replace . '" alt="" width="640" height="427" />'));

    $result_serialized = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);
    $result = unserialize($result_serialized);

    $this->assertEquals($expected_ser, $result_serialized);
    $this->assertEquals($expected, $result);
  }

  public function testJson()
  {
    global $wpdb;

    $content = '[["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2020/01/' . $this->search . '\" alt=\"\" width=\"640\" height=\"426\" class=\"alignnone size-large wp-image-1448\" />","","","",""],["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/'. $this->search . '\" alt=\"\" width=\"640\" height=\"853\" class=\"alignnone size-large wp-image-621\" />","","","",""],["","","","",""],["","","","",""],["","","","",""]]';

    $expected = '[["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2020/01/' . $this->replace . '\" alt=\"\" width=\"640\" height=\"426\" class=\"alignnone size-large wp-image-1448\" />","","","",""],["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/' . $this->replace . '\" alt=\"\" width=\"640\" height=\"853\" class=\"alignnone size-large wp-image-621\" />","","","",""],["","","","",""],["","","","",""],["","","","",""]]';

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);
  //  $result = json_decode($result_serialized);

    $this->assertEquals($expected, $result);

    $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
    $replacerFunc = $replaceRefl->getMethod('isJSON');
    $replacerFunc->setAccessible(true);

    $bool = $replacerFunc->invoke(self::$replacer, $content);
    $this->assertTrue($bool);

    //TablesPress
    $content = '[["Column A","Info Doc"],["Column B","<a href=\"' . $this->search . '\">PDF</a>\n<a href=\"' . $this->search .  '\">PDF</a>"]]';

    $expected = '[["Column A","Info Doc"],["Column B","<a href=\"' . $this->replace . '\">PDF</a>\n<a href=\"' . $this->replace .  '\">PDF</a>"]]';

    $result_serialized = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);
    $result = $result_serialized;

    $this->assertEquals($expected, $result);

    $bool = $replacerFunc->invoke(self::$replacer, $content);
    $this->assertTrue($bool);

    $post_id = $this->factory->post->create(array('name' => 'test1', 'status' => 'publish', 'post_content' => $result));
    //wp_update_post(array('ID' => $post_id, 'post_content' => $result ));
    $sql = 'UPDATE ' . $wpdb->posts . ' SET post_content = %s WHERE ID = %d';
    $sql = $wpdb->prepare($sql, $result, $post_id);
    $q = $wpdb->query($sql);
    $post = get_post($post_id); // somehow get_post still fucks with the content

    $sql = 'SELECT * FROM '  . $wpdb->posts . ' where ID = ' . $post_id;
    $sqlresult = $wpdb->get_results($sql);

    $this->assertEquals($result, $sqlresult[0]->post_content);


  }

  public function testMetaDataReplace()
  {
      $term_id = $this->factory->term->create(array('name' => 'test'));
      add_term_meta($term_id, 'test', $this->search);

      $post_id = $this->factory->post->create(array('name' => 'test1', 'status' => 'publish'));
      add_post_meta($post_id, 'test', $this->search);

      global $wpdb;

      $search_urls = array($this->search);
      $replace_urls = array($this->replace);

      $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
      $replacerFunc = $replaceRefl->getMethod('handleMetaData');
      $replacerFunc->setAccessible(true);

      // Test without replacing ( empty hook )
      add_filter('emr/metadata_tables', array($this, 'filterNoResults'));
      $result = $replacerFunc->invoke(self::$replacer, $this->search, $search_urls, $replace_urls);
      remove_filter('emr/metadata_tables', array($this, 'filterNoResults'));

      $this->assertEquals(0, $result);

      // Test both term and post at once.
      add_filter('emr/metadata_tables', array($this, 'filterPostAndTerm'));
      $result = $replacerFunc->invoke(self::$replacer, $this->search, $search_urls, $replace_urls);
      remove_filter('emr/metadata_tables', array($this, 'filterPostAndTerm'));

      $this->assertEquals(2, $result);

      $termtest = get_term_meta($term_id, 'test', true);
      $posttest = get_post_meta($post_id, 'test', true);

      $this->assertEquals($this->replace, $termtest);
      $this->assertEquals($this->replace, $posttest);

  }

  /** Seem in the wild - Amazon S3 Cache*/
  public function testArrayWithURLasKey()
  {
    //echo strlen('//s3-eu-central-1.amazonaws.com/shortpixel-bas/2019/12/' . $this->search);
    //echo strlen('//s3-eu-central-1.amazonaws.com/shortpixel-bas/2019/12/' . $this->replace);

    $content = unserialize('a:6:{s:74:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/WUydTfaP3t4.jpg";i:1347;s:83:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/WUydTfaP3t4-1024x683.jpg";i:1347;s:67:"//s3-eu-central-1.amazonaws.com/shortpixel-bas/2019/12/' . $this->search . '";i:1347;s:79:"//s3-eu-central-1.amazonaws.com/shortpixel-bas/2019/12/WUydTfaP3t4-1024x683.jpg";i:1347;s:73:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/ea18dobBfA.jpg";a:1:{s:9:"timestamp";i:1579010684;}s:83:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/ea18dobBfA-1024x1024.jpg";a:1:{s:9:"timestamp";i:1579010684;}} ');

    $expected = unserialize('a:6:{s:74:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/WUydTfaP3t4.jpg";i:1347;s:83:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/WUydTfaP3t4-1024x683.jpg";i:1347;s:71:"//s3-eu-central-1.amazonaws.com/shortpixel-bas/2019/12/' . $this->replace . '";i:1347;s:79:"//s3-eu-central-1.amazonaws.com/shortpixel-bas/2019/12/WUydTfaP3t4-1024x683.jpg";i:1347;s:73:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/ea18dobBfA.jpg";a:1:{s:9:"timestamp";i:1579010684;}s:83:"//shortpixel.weblogmechanic.com/wp-content/uploads/2019/12/ea18dobBfA-1024x1024.jpg";a:1:{s:9:"timestamp";i:1579010684;}} ');

    $post_id = $this->factory->post->create(array('name' => 'test1', 'status' => 'publish'));
    add_post_meta($post_id, 'test_array', $content);

    $search_urls = array($this->search);
    $replace_urls = array($this->replace);

    $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
    $replacerFunc = $replaceRefl->getMethod('handleMetaData');
    $replacerFunc->setAccessible(true);

    $count = $replacerFunc->invoke(self::$replacer, $this->search, $search_urls, $replace_urls);

    $this->assertEquals(1, $count);

//var_dump($post_id);
//var_dump(get_post_meta($post_id));
    $postmeta = get_post_meta($post_id, 'test_array', true);
//var_dump($postmeta);
    $this->assertEquals($expected, $postmeta);


  }

  public function filterNoResults()
  { return array();  }

  public function filterPostAndTerm()
  {
     return array('post', 'term');
  }

  public function testProblematicExamples()
  {

      $content = ' <!-- wp:uagb/table-of-contents {"block_id":"0739b4c9","classMigrate":true,"headerLinks":"[{\u0022tag\u0022:3,\u0022text\u0022:\u0022Pores\u0022,\u0022link\u0022:\u0022pores\u0022,\u0022content\u0022:\u0022Pores\u0022,\u0022level\u0022:0},{\u0022tag\u0022:2,\u0022text\u0022:\u0022Pores 4\u0022,\u0022link\u0022:\u0022pores-4\u0022,\u0022content\u0022:\u0022Pores 4\u0022,\u0022level\u0022:0}]"} -->
<div class="wp-block-uagb-table-of-contents uagb-toc__align-left uagb-toc__columns-undefined uagb-block-0739b4c9" data-scroll="true" data-offset="30" data-delay="800"><div class="uagb-toc__wrap"><div class="uagb-toc__title-wrap"><div class="uagb-toc__title">Table Of Contents</div></div><div class="uagb-toc__list-wrap"><ul class="uagb-toc__list"><li><a href="#pores">Pores</a></li></ul></div></div></div>
<!-- /wp:uagb/table-of-contents -->';

      $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

      $this->assertEquals($content, $result);

  }






}
