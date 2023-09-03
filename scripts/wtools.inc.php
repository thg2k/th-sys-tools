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
    return inotify_rm_watch($this->_inotify, $watch_descriptor);
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
   * @param bool $follow ...
   */
  public function __construct($pathname, $follow = true) {
    $this->_fd = @fopen($pathname, "r");
    if ($this->_fd === false)
      throw new TailException("Cannot open file \"$pathname\" for reading");

    /* position yourself at the end of the file */
    // fseek($this->_fd, -1024, SEEK_END);
    fseek($this->_fd, 0, SEEK_END);

    /* discard a possibly partial line */
    // fgets($this->_fd);

    /* create a new Inotify instance */
    if ($follow) {
      $this->_inotify = new Inotify();

      /* set up the watch on this same file */
      $this->_inotify->addWatch($pathname,
          IN_MODIFY | IN_ATTRIB | IN_DELETE_SELF | IN_MOVE_SELF);
    }
  }

  public function rollback() {
    fseek($this->_fd, 0, SEEK_SET);
  }

  /**
   * ...
   *
   * @param int $amount ...
   */
  public function rollbackBytes($amount) {
    /* attempt to rollback that amount plus one, this way we can chop off one
     * line in case it's partial and position to the first beginning on line
     * since the amount requested */
    $ret = fseek($this->_fd, -($amount + 1), SEEK_END);

    if ($ret < 0) {
      fseek($this->_fd, 0, SEEK_SET);
    }
    else {
      /* discard a possibly partial line */
      fgets($this->_fd);
    }
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

      if (!$this->_inotify) {
        return false;
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

      if ($chr == "\x1b") {
        $in_escape_sequence = true;
        continue;
      }

      $visible_chars++;
    }

    if ($visible_chars == $chars)
      return substr($line, 0, $i) . "\x1b[m";

    return $line;
  }

  /**
   * ...
   *
   * @param string $str ...
   * @return array{
   *    peer: string,
   *    ip: string,
   *    proto: string,
   *    date: string,
   *    time: string,
   *    req: string,
   *    status: int,
   *    size: int,
   *    referer: ?string,
   *    agent: ?string,
   *    duration: int} ...
   */
  public static function parseAccessLog_th_lf($str)
  {
    // basic?
    //     IP USER IDENT [REQ_TIME] "REQ" STATUS SIZE
    //
    // th_lf_1 format:
    //     IP USER PROTO [REQ_TIME] "REQ" STATUS SIZE "REFERER" "AGENT"
    //
    // th_lf_2 format:
    //     IP USER PROTO [REQ_TIME] ...
    //     "REQ" STATUS SIZE "REFERER" "AGENT" DURATION
    //
    // th_lf_3 format:
    //     PEER>IP USER PROTO [REQ_TIME] ...
    //     "REQ" STATUS SIZE "REFERER" "AGENT" DURATION
    //
    if (!preg_match('{^' .
        // peer available only in: th_lf_3
        '(?:(?P<peer>[0-9.a-f:]+)>)?' .       // peer:     172.71.26.115
        '(?P<ip>[0-9.a-f:]+) ' .              // ip:       81.202.254.26
        '(?P<user>[^ ]+) ' .                  // user:     WonderSeller
        '(?P<proto>[^ ]+) ' .                 // proto:    TLSv1.2
        '\[(?P<date>\d+/\w+/\d{4}):' .        // date:     17/May/2018
          '(?P<time>[0-9:]+) ' .              // time:     00:58:13
          '(?P<zone>\+\d+)\] ' .              // zone:     +0200
        '(?P<req>"(?:\\\\.|[^"])*") ' .       // req:      GET /blabla HTTP/1.1
        '(?P<status>\d+) ' .                  // status:   200
        '(?<size>\d+|-)' .                    // size:     168682
        // the following are only available in combined
        '(?: ' .
        '(?P<referer>"(?:\\\\.|[^"])*")? ' .  // referer:  -
        '(?P<agent>"(?:\\\\.|[^"])*")' .      // agent:    Mozilla/5.0 (...)
        // duration available only in: th_lf_2, th_lf_3
        '(?: (?P<duration>\d+))?' .           // duration: 242232 (usec)
        ')?$}',
        $str, $regp))
      return false;

    $stamp = \DateTime::createFromFormat("d/M/Y G:i:s P",
        $regp['date'] . " " . $regp['time'] . " " . $regp['zone']);

    $rec = array();
    $rec['stamp'] = $stamp->getTimestamp();
    $rec['peer'] = ($regp['peer'] != $regp['ip'] ? $regp['peer'] : "");
    $rec['ip'] = $regp['ip'];
    $rec['user'] = ($regp['user'] != "-" ? $regp['user'] : "");
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
