#!/usr/bin/php
<?php

include __DIR__ . DIRECTORY_SEPARATOR . "wtools.inc.php";

/**
 * ...
 */
class TestTail {
  public function __construct() {
    $this->_testcases = array(
      '127.0.0.1 - - [11/Nov/2017:21:43:43 +0100] "POST /g\\0et\\"cfg.php HTTP/1.1" 200 1 "\\"xx\\"" "AG\\"ENT"',
      '127.0.0.1 - - [11/Nov/2017:21:43:43 +0100] "POST /normal.php HTTP/1.1" 200 1 "-" "AGENT"'
    );
  }
  public function read() {
    $retval = array_shift($this->_testcases);
    if ($retval === null)
      return false;
    return $retval;
  }
}

/**
 * ...
 *
 * @param string $message ...
 */
function dbg($message) {
  // print "[d] " . $message . "\n";
}

/* ------------------------------------------------------------------------ */

$opt_lines = 5;
$opt_test = false;
$opt_cols = null;
$argidx = 1;

/* read command line switch "-n LINES" */
if (isset($argv[$argidx]) && ($argv[$argidx] == "-n")) {
  $opt_lines = (int) (isset($argv[$argidx + 1]) ? $argv[$argidx + 1] : null);
  $argidx += 2;
}

/* read command line switch "-c COLS" */
if (isset($argv[$argidx]) && ($argv[$argidx] == "-c")) {
  $opt_cols = (int) (isset($argv[$argidx + 1]) ? $argv[$argidx + 1] : null);
  $argidx += 2;
}

/* read command line switch "-test" */
if (isset($argv[$argidx]) && ($argv[$argidx] == "-test")) {
  $opt_test = true;
  $argidx++;
}

/* ------------------------------------------------------------------------ */

/* auto-determine the number of usable columns */
if ($opt_cols === null) {
  dbg("trying to auto-determine total number of columns");
  $opt_cols = (int) exec("tput cols");
  dbg("detected screen columns: " . $opt_cols);
}

/* execute tests now if requested */
if ($opt_test) {
  $tail = new TestTail($file);
}
else {
  /* read first command argument (file name) */
  $file = (isset($argv[$argidx]) ? $argv[$argidx] : "");
  if ($file == "")
    $file = getenv("HOME") . "/logs/access_log";
  // if ($file == "")
    // fputs(STDERR, "Usage: " . $argv[0] . " [-n <lines>] <file>\n");
    // exit(1);
  // }

  $tail = new Tail($file);
  $tail->rollbackBytes(32768);
}

/* support function to format colored HTTP status (width: 3 chars) */
$fmt_http_status = function($status) {
  $status = (int) $status;

  if ($status == 200)
    $prefix = "\x1b[32m"; // normal green
  elseif ((200 <= $status) && ($status <= 299))
    $prefix = "\x1b[32;1m"; // bold green
  elseif ((300 <= $status) && ($status <= 399))
    $prefix = "\x1b[33m"; // normal yellow
  else
    $prefix = "\x1b[91m"; // bold red?

  return $prefix . $status . "\x1b[m";
};

/*
 *   "  - "
 *   " <1k"   < 1.0
 *   "  5k"
 *   " 10k"
 *   "999k"   < 999.5
 *   "1.0M"
 *   "9.9M"   < 9.95
 *   "100M"
 *   "999M"
 */
$fmt_sent_size = function(int $size) {
  if (!$size)
    return "  - ";

  /* convert to kibibytes */
  $size /= 1024.0;

  /* less than one kiB we show it as "less than" */
  // if ($size < 1)
    // return " <1k";

  /* up to 1.0M we show it as kilobytes without decimals, to ease up visual
   * comparison of the sizes, as visually "1.5k" is larger than "15k", it is
   * harder to spot more relevant transfers */
  if ($size < 999.5)
    return sprintf("%3.0fk", $size);

  /* time to convert to mebibytes */
  $size /= 1024.0;

  /* this time we also show decimals up to 10M */
  if ($size < 9.95)
    return sprintf("\e[1m%3.1fM\e[m", $size);

  return sprintf("\e[1m%3.0fM\e[m", $size);
};

$fmt_http_request = function($req) {
  // if (preg_match('/^"
  // return "{{ " . $req . " }}";
  // GET, POST, PUT, DELETE, OPTIONS
  if (preg_match('~^"([A-Z]{1,7}) (.+) HTTP/(1\.[01])"$~', $req, $regp))
    return "{" . $regp[3] . "} " . "\x1b[1m" . $regp[1] . " " . $regp[2] . "\x1b[m";
  else
    return "\x1b[31m" . $req . "\x1b[m";
};

$trunc_console_line = function($line, $max_chars) {
  $i = 0;
  $visible_chars = 0;
  $in_escape_sequence = false;
  // dbg("starting with #line=" . strlen($line) . " max=" . $max_chars);
  while (($i < strlen($line)) && ($visible_chars < $max_chars)) {
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

  if ($visible_chars == $max_chars)
    return substr($line, 0, $i) . "\e[m";

  return $line;
};

$current_date = null;
while (($str = $tail->read()) !== false) {

  // 81.202.254.26 WonderSeller TLSv1.2 [17/May/2018:00:58:13 +0200] "GET /sell/articles/cards HTTP/1.1" 200 98356 "-" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.139 Safari/537.36"
  if (!preg_match('{^' .
        '(?P<ip>[^ ]+) ' .                                  // 81.202.254.26
        '(?P<user>[^ ]+) ' .                                // WonderSeller
        '(?P<proto>[^ ]+) ' .                               // TLSv1.2
        '\[(?P<date>\d+/\w+/\d{4}):(?P<time>[0-9:]+) \+\d+\] ' .  // [17/May/2018:
        '(?<req>"(?:\\\\.|[^"])*") ' .
        '(?<http>\d+) (?<size>\d+|-) ' .
        '(?<referer>"(?:\\\\.|[^"])*")? ' .
        '(?<agent>"(?:\\\\.|[^"])*")$}', $str, $regp))
    goto err;

  // filter out unwanted static data
//    if (preg_match('{GET /dyndata|GET /d10m|GET /images|GET /admin}', $regp[4]))
//      continue;

// var_dump($regp);

  if ($regp['date'] !== $current_date) {
    print "\n\e[1m=== " . $regp['date'] . " ===\e[m\n";
    $current_date = $regp['date'];
  }

  // - ip
  // - user
  // - proto
  // - time
  // - req
  // - http
  // - size
  // - referer
  // - agent

  // format ipv6+nick on the same slot
  $slot_1 = sprintf("%-20s %s", $regp['ip'], $regp['user']);

  // print formatted line
  //                1    2     3    4  5     6   7  8
  $line = sprintf("%8s %-42s %-7s %s %s %-30s%s%s",
      /* 1 */ $regp['time'],
      /* 2 */ $slot_1,
      /* 3 */ ($regp['proto'] != "-" ? $regp['proto'] : "PLAIN"),
      /* 4 */ $fmt_http_status($regp['http']),
      /* 5 */ $fmt_sent_size((int) $regp['size']),
      /* 6 */ $fmt_http_request($regp['req']),
      /* 7 */ ($regp['referer'] != '"-"' ? "  \e[36m" . $regp['referer'] . "\e[m" : "  No referer"),
      /* 8 */ ($regp['agent'] != '"-"' ? "  \e[35m" . $regp['agent'] . "\e[m" : "  No user agent"));

  print WTools::truncConsoleLine($line, $opt_cols) . "\n";
  continue;

 err:
  print "FAILED $str\n";
}
