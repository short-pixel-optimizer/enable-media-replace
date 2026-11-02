<?php
/**
 * Runtime autoloader for the modules bundled in a plugin's build directory.
 *
 * @package EnableMediaReplace\Build
 */
namespace EnableMediaReplace\Build;

/**
 * Minimal Composer-like autoloader for the bundled modules.
 *
 * Build::createPackageLoader() copies this file into `build/shortpixel/`
 * next to a generated `composer.json` and `autoload.php`. The plugin's main
 * file requires `autoload.php`, or constructs this class itself, and calls
 * load() with the build directory. load() decodes the composer.json found
 * there, registers one spl_autoload callback per PSR-4 and PSR-0 prefix and
 * includes any `autoload.files` entries. The PSR-4 prefixes in that file
 * carry no trailing backslash (see Build::createPackageLoader()), so the
 * callbacks match on the bare prefix.
 *
 * Lifecycle: `new` per use, normally once per plugin per request. Keeps the
 * build directory and the decoded composer.json. Uses WordPress's
 * trailingslashit(), so it must run after WordPress has loaded. Fires and
 * listens to no hooks.
 *
 * Bundling: this file is not copied as part of a module tree but on its own
 * by Build::createPackageLoader(), which also rewrites its namespace to the
 * consuming plugin's.
 */
class PackageLoader
{
  /**
   * Build directory passed to load(); base for every path in composer.json.
   *
   * @var string|null
   */
  public $dir;

  /**
   * Decoded composer.json, or false until read.
   *
   * @var array|false
   */
  public $composerFile  = false;

  /**
   * Does nothing; configuration happens in load().
   */
  public function __construct()
  {

  }

  /**
   * Reads and decodes a composer.json from an explicit path, replacing the
   * one that getComposerFile() would read from $dir.
   *
   * @param string $filePath Absolute path of the composer.json.
   * @return void
   */
  public function setComposerFile($filePath)
  {
    $this->composerFile = json_decode(file_get_contents($filePath),1);
  }

  /**
   * Returns the decoded composer.json, reading `$dir/composer.json` on first
   * use.
   *
   * @param string|false $filePath Unused.
   * @return array Decoded composer.json; null when the file is missing or
   *               invalid, in which case load() registers nothing.
   */
  public function getComposerFile($filePath = false )
  {
    if (! $this->composerFile)
      $this->composerFile = json_decode(file_get_contents($this->dir."/composer.json"), 1);

      return $this->composerFile;
  }

    /**
     * Registers the autoloaders described by the composer.json in $dir.
     *
     * Handles `autoload.psr-4`, `autoload.psr-0` and `autoload.files` in that
     * order. Each call registers new callbacks; calling it twice for the same
     * directory registers them twice.
     *
     * @param string $dir     Absolute path of the build directory.
     * @param bool   $prepend Whether the PSR-4 callbacks go to the front of
     *                        the autoload queue.
     * @return void
     */
    public function load($dir, $prepend = false)
    {
        $this->dir = $dir;
        $composer = $this->getComposerFile();


        if(isset($composer["autoload"]["psr-4"])){
            $this->loadPSR4($composer['autoload']['psr-4'], $prepend);
        }
        if(isset($composer["autoload"]["psr-0"])){
            $this->loadPSR0($composer['autoload']['psr-0']);
        }
        if(isset($composer["autoload"]["files"])){
            $this->loadFiles($composer["autoload"]["files"]);
        }
    }

    /**
     * Includes each file of an `autoload.files` list that exists under $dir.
     *
     * @param array $files Paths relative to $dir.
     * @return void
     */
    public function loadFiles($files){
        foreach($files as $file){
            $fullpath = $this->dir."/".$file;
            if(file_exists($fullpath)){
                include_once($fullpath);
            }
        }
    }

    /**
     * Registers PSR-4 autoloaders.
     *
     * @param array<string,string|array> $namespaces Prefix => path(s).
     * @param bool                       $prepend    Passed to
     *                                               spl_autoload_register().
     * @return void
     */
    public function loadPSR4($namespaces, $prepend)
    {
        $this->loadPSR($namespaces, true, $prepend);
    }

    /**
     * Registers PSR-0 autoloaders (always appended).
     *
     * @param array<string,string|array> $namespaces Prefix => path(s).
     * @return void
     */
    public function loadPSR0($namespaces)
    {
        $this->loadPSR($namespaces, false);
    }

    /**
     * Registers one spl_autoload callback per namespace prefix.
     *
     * A callback handles a class whose name starts with its prefix. For
     * PSR-4 the prefix is removed from the class name; for PSR-0 the full
     * class name is kept. The remainder is turned into a path by replacing
     * backslashes with slashes and appending `.php`, and the first matching
     * file under `$dir/<classpath>/` is included. Classes whose file is not
     * found are silently left to the next autoloader.
     *
     * @param array<string,string|array> $namespaces Prefix => path or list
     *                                               of paths relative to
     *                                               $dir.
     * @param bool                       $psr4       True for PSR-4, false
     *                                               for PSR-0.
     * @param bool                       $prepend    Passed to
     *                                               spl_autoload_register().
     * @return void
     *
     * @todo The prefix is removed with str_replace(), which removes every
     *       occurrence of the prefix text in the class name, not only the
     *       leading one, and the preg_match() has no namespace-separator
     *       boundary, so prefix `Foo\Bar` also claims `Foo\BarBaz\X`.
     *       Suggested fix: compare with strpos() === 0 on the prefix plus a
     *       backslash and strip it with substr().
     */
    public function loadPSR($namespaces, $psr4, $prepend = false)
    {
        $dir = $this->dir;
        // Foreach namespace specified in the composer, load the given classes
        foreach ($namespaces as $namespace => $classpaths) {
            if (!is_array($classpaths)) {
                $classpaths = array($classpaths);
            }
            spl_autoload_register(function ($classname) use ($namespace, $classpaths, $dir, $psr4) {
                // Check if the namespace matches the class we are looking for
                if (preg_match("#^".preg_quote($namespace)."#", $classname)) {
                    // Remove the namespace from the file path since it's psr4
                    if ($psr4) {
                        $classname = str_replace($namespace, "", $classname);
                    }

                    //  $filename = preg_replace("#\\\\#", "", $classname).".php";
                    // This is fix for nested classes which were losing a /
                    $filename = ltrim($classname .'.php', '\\');
                    $filename = str_replace('\\','/', $filename);

                    foreach ($classpaths as $classpath) {
                      $fullpath = trailingslashit($dir) . trailingslashit($classpath) .$filename;
                        if (file_exists($fullpath)) {
                            include_once $fullpath;
                        }
                    }
                }
            }, true, $prepend);
        }
    }
}
