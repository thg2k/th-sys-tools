<?php

declare(strict_types = 1);

/**
 * ...
 */
class Inotify {
  /**
   * Internal Inotify resource handle
   *
   * @var resource
   */
  private $_inotify;

  /**
   * Default constructor
   */
  public function __construct() {
    $this->_inotify = inotify_init();
  }

  /**
   * Destructor
   */
  public function __destruct() {
    fclose($this->_inotify);
  }

  /**
   * ...
   *
   * @param string $pathname ...
   * @param int $mask ...
   * @return array ...
   */
  public function addWatch(string $pathname, int $mask) {
    return inotify_add_watch($this->_inotify, $pathname, $mask);
  }

  /**
   * ...
   *
   * @param int $watch_descriptor ...
   * @return bool ...
   */
  public function removeWatch(int $watch_descriptor) {
    return inotify_rm_watch($watch_descriptor);
  }

  /**
   * ...
   *
   * @return array ...
   */
  public function poll() {
    return inotify_read($this->_inotify);
  }
}

/**
 * ...
 */
class TailException extends Exception {
}

/**
 * ...
 */
class Tail {
  /**
   * ...
   *
   * @var resource
   */
  private $_fd;

  /**
   * ...
   *
   * @var Inotify
   */
  private $_inotify;

  /**
   * ...
   *
   * @param string $pathname ...
   */
  public function __construct(string $pathname) {
    $this->_fd = @fopen($pathname, "r");
    if ($this->_fd === false)
      throw new TailException("Cannot open file \"$pathname\" for reading");

    /* position yourself at the end of the file */
    // fseek($this->_fd, -1024, SEEK_END);
    fseek($this->_fd, 0, SEEK_END);

    /* discard a possibly partial line */
    // fgets($this->_fd);

    /* create a new Inotify instance */
    $this->_inotify = new Inotify();

    /* set up the watch on this same file */
    $this->_inotify->addWatch($pathname,
        IN_MODIFY | IN_ATTRIB | IN_DELETE_SELF | IN_MOVE_SELF);
  }

  /**
   * ...
   *
   * @param int $amount ...
   */
  public function rollbackBytes($amount) {
    fseek($this->_fd, -$amount, SEEK_END);

    /* discard a possibly partial line */
    fgets($this->_fd);
  }

  /**
   * ...
   *
   * @return string ...
   */
  public function read() {
    do {
// dbg("--------------------");
// dbg("BEFORE");
// var_dump(ftell($this->_fd), feof($this->_fd));
      $buffer = fgets($this->_fd);
// dbg("AFTER");
// var_dump($buffer, ftell($this->_fd), feof($this->_fd));
// dbg("--------------------");
      if ($buffer !== false) {
        $buffer = rtrim($buffer, "\r\n");
        return $buffer;
      }

      /* no data to read at the moment, set up the inotify */
      // dbg("going to inotify");
      $fd_pos = ftell($this->_fd);
      $event = $this->_inotify->poll();
      $event = true;
      fseek($this->_fd, $fd_pos, SEEK_SET);
    }
    while ($event);
  }
}

/**
 * ...
 */
class WTools {
  /**
   * ...
   *
   * @param string $line ...
   * @param int $chars ...
   * @return string ...
   */
  public static function truncConsoleLine(string $line, int $chars): string {
    if ($chars <= 0)
      return $line;
    $visible_chars = 0;
    $in_escape_sequence = false;
    $i = 0;
    // dbg("starting with #line=" . strlen($line) . " max=" . $chars);
    while (($i < strlen($line)) && ($visible_chars < $chars)) {
      $chr = $line[$i++];
      // dbg("processing #chr=" . ord($chr) . " [visible=" . $visible_chars . "]");

      if ($in_escape_sequence) {
        if ($chr == "m")
          $in_escape_sequence = false;
        continue;
      }

      if ($chr == "\e") {
        $in_escape_sequence = true;
        continue;
      }

      $visible_chars++;
    }

    if ($visible_chars == $chars)
      return substr($line, 0, $i) . "\e[m";

    return $line;
  }
}
