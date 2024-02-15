<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


class Image extends \EnableMediaReplace\FileSystem\Model\File\FileModel
{

   protected $image_id;
   protected $post; // attachment post.

   protected $sourceFileUntranslated;


   public function __construct($image_id)
   {
       $this->image_id = $image_id;
       $this->post = get_post($image_id);

       $filepath = $this->getSource();
       parent::__construct($filepath);
   }

   /* Image gets data from all FileModel as from this class as from POST object */
   public function __get($name)
   {
      $value = null;

      if (isset($this->$name))
      {
         $value = $this->$name;
      }
      elseif (isset($this->post->$name))
      {
          $value = $this->post->$name;
      }

      return $value;
   }


   public function hasImagePermission()
   {
      if (! is_object($this->post))
      {
        return false;
      }

     $post_id = $this->post->ID;
     $post_type = $this->post->post_type;
     $author_id = $this->post->post_author;


     if ('attachment' !== $post_type)
     {
       return false;
     }

     if (is_null($post_id) || intval($post_id) >! 0)
     {
        return false;
     }

      $env = EMR()->env();
      $general_cap = $env->getPermission('general');
      $user_cap = $env->getPermission('user');


       if ($general_cap === false && $user_cap === false) {
           if (current_user_can('edit_post', $post_id)  === true) {
                           return true;
           }
       } elseif (current_user_can($general_cap)) {
           return true;
       } elseif (current_user_can($user_cap) && $author_id == get_current_user_id()) {
           return true;
       }

       return false;
   }

   public function isBackgroundRemovable()
   {
       if (false === wp_attachment_is_image($this->post))
         return false;


       if (false === emr()->useFeature('background'))
       {
         return false;
       }

       $extensions = array('jpg', 'png','jpeg');

       $mime = get_post_mime_type($this->post);
       foreach($extensions as $extension)
       {
           if (strpos($mime, $extension) !== false )
             return true;
       }

       return false;

   }

   protected function getSource()
   {
       $source_file = false;
       $fs = EMR()->filesystem();

       // The main image as registered in attached_file metadata.  This can be regular or -scaled.
       $source_file_main = trim(get_attached_file($this->image_id, apply_filters( 'emr_unfiltered_get_attached_file', true )));

       // If available it -needs- to use the main image when replacing since treating a -scaled images as main will create a resursion in the filename when not replacing that one . Ie image-scaled-scaled.jpg or image-scaled-100x100.jpg .
       if (function_exists('wp_get_original_image_path')) // WP 5.3+
       {
           $source_file = wp_get_original_image_path($this->image_id, apply_filters( 'emr_unfiltered_get_attached_file', true ));
           // For offload et al to change path if wrong. Somehow this happens?
           $source_file = apply_filters('emr/replace/original_image_path', $source_file, $this->image_id);
      }

      if (false === $source_file) // If not scaled, use the main one.
      {
         $source_file = $source_file_main;
      }


       $sourceFileObj = $fs->getFile($source_file);
       $isVirtual = false;
       if ($sourceFileObj->is_virtual())
       {
           $isVirtual = true;

           /***
           *** Either here the table should check scaled - non-scaled ** or ** the original_path should be updated.
           ***

           */
           $this->sourceFileUntranslated = $fs->getFile($source_file);
           $sourcePath = apply_filters('emr/file/virtual/translate', $sourceFileObj->getFullPath(), $sourceFileObj, $this->post_id);


           if (false !== $sourcePath && $sourceFileObj->getFullPath() !== $sourcePath)
           {
              $sourceFileObj = $fs->getFile($sourcePath);
              $source_file = $sourcePath;
           }

       }

       /* It happens that the SourceFile returns relative / incomplete when something messes up get_upload_dir with an error something.
          This case should be detected here and create a non-relative path anyhow..
       */
       if (
         false === $isVirtual &&
         false === $sourceFileObj->exists() &&
         $source_file && 0 !== strpos( $source_file, '/' )
         && ! preg_match( '|^.:\\\|', $source_file ) )
       {
         $file = get_post_meta( $this->post_id, '_wp_attached_file', true );
         $uploads = wp_get_upload_dir();
         $source_file = $uploads['basedir'] . "/$source_file";
       }

       Log::addDebug('SetupSource SourceFile Path ' . $source_file);
       //$this->sourceFile = $fs->getFile($source_file);
       return $source_file;
   }

} // class
