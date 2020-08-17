<?php
use EnableMediaReplace\emrFile as File;
use EnableMediaReplace\Replacer as Replacer;

// Functions
use EnableMediaReplace\emr_get_match_url as emr_get_match_url;

class FunctionsTest extends WP_UnitTestCase
{

  public function testMatchUrl()
  {
    $url1 = home_url() . '/wp-content/uploads/2019/02/W-K-Kellogg-Foundation-with-CCL.jpg';
    $match = EnableMediaReplace\emr_get_match_url($url1);

    $this->assertEquals('/wp-content/uploads/2019/02/W-K-Kellogg-Foundation-with-CCL', $match);

    $url2 = '/wp-content/uploads/2010/03/five-key-themes-for-high-achieving-women-leaders-center-for-creative-leadership-podcast-280x156.jpg';
    $match2 = EnableMediaReplace\emr_get_match_url($url2);

    $this->assertEquals('/wp-content/uploads/2010/03/five-key-themes-for-high-achieving-women-leaders-center-for-creative-leadership-podcast', $match2);

    //https://cdn.btyaly.com/wp-content/uploads/2019/07/Herbivore_Lapis_Facial_Oil_white_ceramic-1366x911.jpg
    //$url3 = ''
    //$match3 =
  }

  public function testMaybeRemoveQString()
  {
     $test1 = 'https://www.ccl.org/wp-content/uploads/2019/02/bg-image-1.png?id=36539';
     $result1 = EnableMediaReplace\emr_maybe_remove_query_string($test1);

     $this->assertNotEquals($result1, $test1);
     $this->assertEquals('https://www.ccl.org/wp-content/uploads/2019/02/bg-image-1.png', $result1);

     $test2 = 'https://www.ccl.org/wp-content/uploads/2019/02/W-K-Kellogg-Foundation-with-CCL.jpg';
     $result2 = EnableMediaReplace\emr_maybe_remove_query_string($test2);

     $this->assertEquals($test2, $result2);
  }

  public function testRemoveSizeFromFilename()
  {
     $test1 = '/wp-content/uploads/2010/03/five-key-themes-for-high-achieving-women-leaders-center-for-creative-leadership-podcast-280x156.jpg';
     $result1 = EnableMediaReplace\emr_remove_size_from_filename($test1);

     $this->assertNotEquals($test1, $result1);

     $test2 = 'https://www.ccl.org/wp-content/uploads/2019/02/bg-image-1.png';
     $result2 = EnableMediaReplace\emr_remove_size_from_filename($test2);
     $this->assertEquals($test2, $result2);

     $test3 = 'https://www.ccl.org/wp-content/uploads/2019/02/bg-image-1.png?id=36539';
     $result3 = EnableMediaReplace\emr_remove_size_from_filename($test3);
     $this->assertEquals($test3, $result3);
  }

  public function testRemoveScheme()
  {
     $test1 = 'http://www.ccl.org/wp-content/uploads/2019/02/bg-image-1.png';
     $result1 = EnableMediaReplace\emr_remove_scheme($test1);

     $this->assertEquals('//www.ccl.org/wp-content/uploads/2019/02/bg-image-1.png', $result1);

     $test2 = 'https://www.domain.com';
     $result2 = EnableMediaReplace\emr_remove_scheme($test2);

     $this->assertEquals('//www.domain.com', $result2);

     $test3 = 'http://www.domain.com';
     $result3 = EnableMediaReplace\emr_remove_scheme($test3);

     $this->assertEquals('//www.domain.com', $result3);
     $this->assertEquals($result2, $result3); // http(s) replacement equal.

     $test4 = '/wp-content/uploads/2010/03/five-key-themes';
     $result4 = EnableMediaReplace\emr_remove_scheme($test4);

      $this->assertEquals($test4, $result4);
  }


/*  public function testGetFileURLS()
  {
    $urls = EnableMediaReplace\emr_get_file_urls();

  } */


  // needs reflection Class
  // moved to it's own test.
  /*public function testReplaceContent()
  {
    $replacer = new Replacer(0);
    $class = new ReflectionClass('\EnableMediaReplace\Replacer');

    $method = $class->getMethod ('replaceContent');
    $method->setAccessible(true);

    $content = '2019/02/W-K-Kellogg-Foundation-with-CCL.jpg';
    $search = array('2019/02/W-K-Kellogg-Foundation-with-CCL.jpg');
    $replace = array('2019/02/W-K-Kellogg-Foundation-with-CCL-replaced.jpg');

    $output = $method->invoke($replacer, $content, $search, $replace);

    $this->assertEquals($content, $replace);

  } */



}
