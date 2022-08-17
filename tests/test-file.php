<?php
use org\bovigo\vfs\vfsStream;
use EnableMediaReplace\emrFile as File;

class FileTest extends  WP_UnitTestCase
{
  protected $fs;
  protected $root;

  public function setUp()
  {

    $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
  }

  private function getTestFiles()
  {
    //$content = file_get_contents(realimage) // to implement to make it more reliable.
    $ar = array(
        'images' => array('image1.jpg' => '1234', 'image2.jpg' => '1234', 'image3.png' => '1345'),
    );
    return $ar;
  }

  public function testFileBasic()
  {
      $filename = $this->root->url() . '/images/image1.jpg';
      $filedir = $this->root->url()  . '/images/';
      $file = new File($filename);

      $this->assertTrue($file->exists());
      $this->assertEquals($file->getFileDir(), $filedir);
      //$this->assertEquals($file->getFileMime(), 'image/jpg'); // can't work since images don't really exist.
      $this->assertEquals($file->getFileDir(), $filename);
      $this->assertEquals($file->getExtension(), 'jpg');

      $filename2 = $this->root->url() . '/not-existing.png';
      $file2  = new File($filename2);

      $this->assertFalse($file2->exists());
      $this->assertEquals($file2->getFullPath(), $filename2);
    //  $this->assertFalse($file2->getFileMime()); function will return mime on non-existing files.

  }

  public function testFileDirectory()
  {
      $filename = $this->root->url() . '/newdir/image.png';

      $file = new File($filename);

      $this->assertDirectoryNotExists($file->getFullPath());

      $return = $file->checkAndCreateFolder();

      $this->assertTrue($return, $file->getFileDir() );
      $this->assertDirectoryExists($file->getFileDir());

  }

//  public function

}
