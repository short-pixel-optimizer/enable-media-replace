<?php

use EnableMediaReplace\emrFile as File;
use EnableMediaReplace\Replacer as Replacer;

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
     $this->assertEquals($content, $result);

  }

  public function testReplaceObject()
  {
    $content = new \stdClass;
    $content->first = 'random';
    $content->image = $this->search;
    $content->last = 'other';

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

    $this->assertEquals($this->replace, $result->image);
    $this->assertEquals($content->first, $result->first);
    $this->assertEquals($content->last, $result->last);
  }

  public function testReplaceArray()
  {
    $content = array('first'=> 'random', 'content' => $this->search, 'last' => 'other');

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

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

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

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

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

    $this->assertEquals($expected, $result);
  }

  public function testJson()
  {
    $content = '[["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2020/01/' . $this->search . '\" alt=\"\" width=\"640\" height=\"426\" class=\"alignnone size-large wp-image-1448\" />","","","",""],["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/'. $this->search . '\" alt=\"\" width=\"640\" height=\"853\" class=\"alignnone size-large wp-image-621\" />","","","",""],["","","","",""],["","","","",""],["","","","",""]]';

    $expected = '[["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2020/01/' . $this->replace . '\" alt=\"\" width=\"640\" height=\"426\" class=\"alignnone size-large wp-image-1448\" />","","","",""],["<img src=\"http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/' . $this->replace . '\" alt=\"\" width=\"640\" height=\"853\" class=\"alignnone size-large wp-image-621\" />","","","",""],["","","","",""],["","","","",""],["","","","",""]]';

    $result = self::$method->invoke(self::$replacer, $content, $this->search, $this->replace);

    $this->assertEquals($expected, $result);

    $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
    $replacerFunc = $replaceRefl->getMethod('isJSON');
    $replacerFunc->setAccessible(true);

    
    $bool = $replacerFunc->invoke(self::$replacer, $content);
    $this->assertTrue($bool);


  }



}
