<?php

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
  public function addWatch($pathname, $mask) {
    return inotify_add_watch($this->_inotify, $pathname, $mask);
  }

  /**
   * ...
   *
   * @param int $watch_descriptor ...
   * @return bool ...
   */
  public function removeWatch($watch_descriptor) {
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
  public function __construct($pathname) {
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
  public static function truncConsoleLine($line, $chars) {
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

  public static function parseAccessLog_th_lf_2($str)
  {
    // $str = '10.101.1.4 - - [13/Nov/2020:14:59:21 +0000] "GET /codice-civile/libro-quinto/titolo-ii/capo-i/sezione-i/art2087.html HTTP/1.1" 200 55203 "https://www.google.com/" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.193 Safari/537.36" 14122';
    // $str = '10.101.1.4 - - [13/Nov/2020:14:59:21 +0000] "GET /codice-civile/libro-quinto/titolo-ii/capo-i/sezione-i/art2087.html HTTP/1.1" 200 55203 "https://www.google.com/" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.193 Safari/537.36"';
    // 81.202.254.26 WonderSeller TLSv1.2 [17/May/2018:00:58:13 +0200]
    //     "GET /sell/articles/cards HTTP/1.1"
    //     200 98356 "-"
    //     "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.139 Safari/537.36"
    // 10.101.1.4    -            -       [13/Nov/2020:14:34:48 +0000]
    //     "GET /codice-penale/libro-secondo/titolo-ii/capo-i/art323bis.html HTTP/1.1"
    //     200 26452 "-"
    //     "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36"
    //     360527
    if (!preg_match('{^' .
        '(?P<ip>[^ ]+) ' .                    // ip:      81.202.254.26
        '(?P<user>[^ ]+) ' .                  // user:    WonderSeller
        '(?P<proto>[^ ]+) ' .                 // proto:   TLSv1.2
        '\[(?P<date>\d+/\w+/\d{4}):' .        // date:    17/May/2018
          '(?P<time>[0-9:]+) \+\d+\] ' .      // time:    00:58:13 +0200
        '(?P<req>"(?:\\\\.|[^"])*") ' .       // req:     GET /blabla HTTP/1.1
        '(?P<status>\d+) ' .                  // status:  200
        '(?<size>\d+|-) ' .                   // size:    168682
        '(?P<referer>"(?:\\\\.|[^"])*")? ' .  // referer: -
        '(?P<agent>"(?:\\\\.|[^"])*")' .      // agent:   Mozilla/5.0 (...)
        '(?: (?P<duration>\d+))?$}',
        $str, $regp))
      return false;

    $rec = array();
    $rec['ip'] = $regp['ip'];
    $rec['user'] = $regp['user'];
    $rec['proto'] = ($regp['proto'] != "-" ? $regp['proto'] : "");
    $rec['date'] = $regp['date'];
    $rec['time'] = $regp['time'];
    $rec['req'] = $regp['req'];
    $rec['status'] = (int) $regp['status'];
    $rec['size'] = (int) $regp['size'];
    $rec['referer'] = (empty($regp['referer']) ? null :
        ($regp['referer'] != '"-"' ? substr($regp['referer'], 1, -1) : ""));
    $rec['agent'] = (empty($regp['agent']) ? null :
        ($regp['agent'] != '"-"' ? substr($regp['agent'], 1, -1) : ""));
    $rec['duration'] = (isset($regp['duration']) ?
        (int) round($regp['duration'] / 1000) : null);

    return $rec;
  }
}
