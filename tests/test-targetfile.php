<?php
use org\bovigo\vfs\vfsStream;
use EnableMediaReplace\emrFile as File;
use EnableMediaReplace\Replacer as Replacer;

class TargetFileTest extends WP_UnitTestCase
{
  public static function setUpBeforeClass()
  {
    //$mysqli = new mysqli("127.0.0.1", "shortpixel", "w76TZ#QUEJaf", "shortpixel_test");
    //$sql = file_get_contents('tests/test_posts.sql');
    //$result = $mysqli->multi_query($sql);
  }

  /** Testing if getTargetFile() performs like it should.
  * This test will -not- test the assumptions of existing files and the function of the filesystems
  * Thats for test-file.php to complete. */
  protected static function getMethod($name) {
    $class = new ReflectionClass('\EnableMediaReplace\Replacer');
    $method = $class->getMethod($name);
    $method->setAccessible(true);
    return $method;
  }


  public function setUp()
  {
    //\EnableMediaReplace\Replacer::MODE_REPLACE
    //\EnableMediaReplace\Replacer::MODE_SEARCHREPLACE
  }

  public function testUsualReplace()
  {
      // add a new image for testing.
      $post = $this->factory->post->create_and_get();
      $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/test-image.jpg', $post->ID );

      //$fileUrl = 'https://www.ccl.org/wp-content/uploads/2019/02/W-K-Kellogg-Foundation-with-CCL.jpg';
      //$this->replacer->sourceFile = new File($filen);
      $replacer = new Replacer($attachment_id);

      $method = self::getMethod('getTargetFile'); // $this->replacer->getTargetFile();
      $result = $method->invoke($replacer);

      $attached_file = get_attached_file($attachment_id);

      $this->assertEquals($attached_file, $result);
      $this->assertFileExists($attached_file);
      $this->assertFileExists($result);

      $method = self::getMethod('removeCurrent');
      $result = $method->invoke($replacer);

      $attached_new_file = get_attached_file($attachment_id);
      $this->assertFileNotExists($attached_new_file);
  }


  public function testReplaceRename()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/test-image.jpg', $post->ID );

    $attached_file = get_attached_file($attachment_id);

    $replacer = new Replacer($attachment_id);
    $replacer->setMode(Replacer::MODE_SEARCHREPLACE);

    $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
    $targetProp = $replaceRefl->getProperty('targetName');
    $targetProp->setAccessible(true);

    $targetProp->setValue($replacer, 'NewFile.jpg' );

    $method = self::getMethod('getTargetFile'); // $this->replacer->getTargetFile();
    $targetFile = $method->invoke($replacer);

    $sfile = new File($attached_file);
    $tfile = new File($targetFile);

    $this->assertNotEquals($targetFile, $attached_file);
    $this->assertFileNotExists($targetFile);
    $this->assertEquals($tfile->getFilePath(), $sfile->getFilePath() );
    $this->assertEquals($tfile->getFileMime(), $sfile->getFileMime());

    $method = self::getMethod('removeCurrent');
    $result = $method->invoke($replacer);

    $attached_new_file = get_attached_file($attachment_id);
    $this->assertFileNotExists($attached_new_file);
  }

/*  public function testReplaceWithoutFileName()
  {

  } */

/*  public function testReplaceFailedMetadata()
  {

    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/test-image.jpg', $post->ID );

    // wp_update_metadata // here update the metadata to remove month.
    $attached_file = get_attached_file($attachment_id);

  } */
/*

  public function testFileWithoutMonthPath()
  {
    $filen = 'var/www/shortpixel/wp-content/uploads/2019/07/garrett-sears-aBnX6nhU5KI-unsplash-1.jpg';
    $replaceRefl = new ReflectionClass('\EnableMediaReplace\Replacer');
    $sourceProp = $replaceRefl->getProperty('sourceFile');
    $sourceProp->setAccessible(true);
      $replaceRefl->setValue($replacer, new File($filen) );
  }*/



}
