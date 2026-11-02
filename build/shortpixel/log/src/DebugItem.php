<?php
/**
 * Data model of one log entry.
 *
 * @package EnableMediaReplace\ShortPixelLogger
 */
namespace EnableMediaReplace\ShortPixelLogger;


/**
 * One log entry: level, message, dumped data and the calling frame.
 *
 * Created by ShortPixelLogger::addLog() with `new` for every logged message
 * and kept in the logger's item list; immutable after construction. The
 * constructor normalises the message and data into printable strings so
 * that ShortPixelLogger::write() only has to join them. The LEVEL_*
 * constants define the severity scale used by the logger's threshold: lower
 * is more severe. Fires and listens to no hooks.
 */
class DebugItem
{
    /**
     * microtime() at construction.
     *
     * @var float
     */
    protected $time;

    /**
     * Severity, one of the LEVEL_* constants.
     *
     * @var int
     */
    protected $level;

    /**
     * Message text. A non-scalar message is moved to $data and replaced by
     * `[Data]`.
     *
     * @var string
     */
    protected $message;

    /**
     * Printable dumps of the extra variables, one entry per output line.
     *
     * @var string[]
     */
    protected $data = array();

    /**
     * Calling frame as `['line' => int|string, 'file' => string,
     * 'function' => string]` (file is the basename), or false when the
     * backtrace is too short.
     *
     * @var array|false
     */
    protected $caller = false; // array when filled

    /**
     * Unused.
     *
     * @var mixed
     */
    protected $model;

    /** Most severe level; always written when the logger is active. */
    const LEVEL_ERROR = 1;
    /** Default threshold of the logger. */
    const LEVEL_WARN = 2;
    /** Level selected by `SHORTPIXEL_DEBUG` set to `true`. */
    const LEVEL_INFO = 3;
    /** Least severe level, used by addDebug(), addTrace() and addMemory(). */
    const LEVEL_DEBUG = 4;

    /**
     * Builds the entry and renders the data to strings.
     *
     * An array or object passed as message is treated as a variable dump:
     * it is appended to the data and the message becomes `[Data]`. The data
     * is then rendered depending on getDataType(): a list of scalars is
     * dumped with one print_r() call; a collection of arrays/objects gets a
     * header line with the type and count, followed by one line per
     * array/object item with its index and type. A non-array data value is
     * dumped as is; an empty array (the default) adds nothing.
     *
     * @param mixed $message Log message, or a variable to dump.
     * @param array $args    `level` (int, one of LEVEL_*) and `data` (mixed,
     *                       usually an array of variables).
     * @return void
     *
     * @todo In the collection case only array and object items are dumped;
     *       scalar items that follow a first array/object item are dropped
     *       silently. Suggested fix: print scalar items with print_r() as
     *       well.
     */
    public function __construct($message, $args)
    {
        $this->level = $args['level'];
        $data = $args['data'];

        $this->message = $message;
        $this->time = microtime(true);

        $this->setCaller();

        // Add message to data if it seems to be some debug variable.
        if (is_object($this->message) || is_array($this->message))
        {
          $data[] = $this->message;
          $this->message = __('[Data]', 'enable-media-replace');
        }
        if (is_array($data) && count($data) > 0)
        {
          $dataType = $this->getDataType($data);
          if ($dataType == 1)  // singular
          {
              $this->data[] = print_r($data, true);
          }
          if ($dataType == 2) //array or object.
          {
						$count = false;
						if (gettype($data) == 'array')
							 $count = count($data);
						elseif(gettype($data) == 'object')
							 $count = count(get_object_vars($data));

						$firstLine = 	 ucfirst(gettype($data)) . ':';
						if ($count !== false)
							$firstLine .= ' (' . $count . ')';

						$this->data[] = $firstLine;

            foreach($data as $index => $item)
            {
              if (is_object($item) || is_array($item))
              {
                $this->data[] = print_r($index, true) . ' ( ' . ucfirst(gettype($item)) . ') => ' . print_r($item, true);
              }
            }
          }
        } // if
        elseif (! is_array($data)) // this leaves out empty default arrays
        {
           $this->data[] = print_r($data, true);
        }
    }

    /**
     * Returns the raw entry fields.
     *
     * @return array `time` (float), `level` (int), `message` (string),
     *               `data` (string[]) and `caller` (array|false).
     */
    public function getData()
    {
      return array('time' => $this->time, 'level' => $this->level, 'message' => $this->message, 'data' => $this->data, 'caller' => $this->caller);
    }

    /**
     * Guesses whether the data is a plain list of scalars or a collection
     * of variables.
     *
     * Only the first element is inspected: an integer, boolean or string
     * means the whole array is treated as one value; anything else (array,
     * object, null, float) means a collection of separate variables.
     *
     * @param array $data Non-empty data array.
     * @return int 1 for a scalar list, 2 for a collection.
     */
    protected function getDataType($data)
    {
        $single_type = array('integer', 'boolean', 'string');
        if (in_array(gettype(reset($data)), $single_type))
        {
          return 1;
        }
        else
        {
          return 2;
        }
    }

    /**
     * Returns the entry fields prepared for ShortPixelLogger::formatLine().
     *
     * Same as getData() with `level` replaced by its three-letter label
     * (`ERR`, `WRN`, `INF`, `DBG`) and `color`/`color_end` ANSI escape
     * sequences added so the line is coloured when the file is tailed in a
     * terminal.
     *
     * @return array Fields keyed by the placeholders of the logger's
     *               format string.
     */
    public function getForFormat()
    {
      $data = $this->getData();
      switch($this->level)
      {
          case self::LEVEL_ERROR:
            $level = 'ERR';
            $color = "\033[31m";
          break;
          case self::LEVEL_WARN:
            $level = 'WRN';
            $color = "\033[33m";
          break;
          case self::LEVEL_INFO:
            $level = 'INF';
            $color = "\033[37m";
          break;
          case self::LEVEL_DEBUG:
            $level = 'DBG';
            $color = "\033[37m";
          break;

      }
      $color_end = "\033[0m";

      $data['color'] = $color;
      $data['color_end'] = $color_end;
      $data['level'] = $level;

      return $data;

      //return array('time' => $this->time, 'level' => $level, 'message' => $this->message, 'data' => $this->data, 'color' => $color, 'color_end' => $color_end, 'caller' =>  $this->caller);

    }

    /**
     * Records the frame that called the logger into $caller.
     *
     * Takes the fifth backtrace frame, which is the caller when the path is
     * `Log::add*() -> addLog() -> new DebugItem() -> setCaller()`. For the
     * static methods that go through one more call (addWarning(),
     * addTemp(), addMemory(), logHook()) the frame recorded is that method
     * itself. Leaves $caller false when the trace is shorter.
     *
     * @return void
     *
     * @todo The fixed frame index misreports the caller for the alias
     *       methods named above. Suggested fix: walk the trace and take the
     *       first frame whose class is outside this namespace.
     */
    protected function setCaller()
    {
        if(PHP_VERSION_ID < 50400) {
          $debug=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        } else {
          $debug=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,5);
        }

        $i = 4;
        if (isset($debug[$i]))
        {
          $info = $debug[$i];
          $line = isset($info['line']) ? $info['line'] : 'Line unknown';
          $file = isset($info['file']) ? basename($info['file']) : 'File not set';

          $this->caller = array('line' => $line, 'file' => $file, 'function' => $info['function']);
        }


    }


}
