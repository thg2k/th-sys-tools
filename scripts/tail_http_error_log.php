#!/usr/bin/php
<?php

define("THSYSTOOLS_SHARED_PATH", "@@THSYSTOOLS_SHARED_PATH@@");

if (substr(THSYSTOOLS_SHARED_PATH, 0, 1) != "@")
  require THSYSTOOLS_SHARED_PATH . DIRECTORY_SEPARATOR . "wtools.inc.php";
else
  require __DIR__ . DIRECTORY_SEPARATOR . "wtools.inc.php";

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
    $file = getenv("HOME") . "/logs/error_log";
  // if ($file == "") {
    // fputs(STDERR, "Usage: " . $argv[0] . " [-n <lines>] <file>\n");
    // exit(1);
  // }

  $tail = new Tail($file);
  $tail->rollbackBytes(10485760);
}

$Months = array(
   1 => 'Jan',
   2 => 'Feb',
   3 => 'Mar',
   4 => 'Apr',
   5 => 'May',
   6 => 'Jun',
   7 => 'Jul',
   8 => 'Aug',
   9 => 'Sep',
  10 => 'Oct',
  11 => 'Nov',
  12 => 'Dec');

// info
// notice
// warn
// error

$Filter = array(
  array('ssl',        'info',  'AH01964: '), // AH01964: Connection to child 22 established (server decktutor.com:443)
  array('ssl',        'info',  'AH01998: '), // AH01998: Connection closed to child 20 with abortive shutdown (server decktutor.com:443)
  array('ssl',        'info',  'AH01991: '), // AH01991: SSL input filter read failed.
  array('ssl',        'info',  'AH01993: '), // AH01993: SSL output filter write failed.
  array('reqtimeout', 'info',  '^AH01382:'), // AH01382: Request header read timeout
  array('php7',       'error', '^script \'(.*)\' not found or unable to stat'),
  array('core',       'info',  '^AH00128:'), // File does not exist
);
$FilterExclude = false;
$FilterExclude = true;

$current_date = null;
while (($str = $tail->read()) !== false) {
  // 81.202.254.26 WonderSeller TLSv1.2 [17/May/2018:00:58:13 +0200] "GET /sell/articles/cards HTTP/1.1" 200 98356 "-" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.139 Safari/537.36"

  // [Sat May 19 17:06:19.120115 2018] [core:info] [pid 20290] [client 66.249.66.159:58972] AH00128: File does not exist: /home/web/decktutor/htdocs/ads.txt
  // [Sat May 19 17:10:13.895016 2018] [ssl:info] [pid 20410] [client 2a01:4f8:13b:184e::2:38470] AH01964: Connection to child 19 established (server decktutor.com:443)
  // [Sun May 27 03:13:45.850986 2018] [ssl:info] [pid 12158] SSL Library Error: error:1408F081:SSL routines:SSL3_GET_RECORD:block cipher pad is wrong

  if (!preg_match('{^' .
        '\[(?P<datetime>[A-Za-z0-9:\. ]+)\] ' .   // [Sat May 19 17:10:13.895016 2018]
        '\[(?P<channel>\w+):(?P<level>\w+)\] ' .  // [ssl:info]
        '\[pid (?P<pid>\d+)\] ' .                // [pid 20410]
        '(?P<prefix>[^\[]*)' .                       // (104)Connection reset by peer:
        '(?:\[client (?P<ip>[0-9a-f:\.]+)\] )?' .     // [client 66.249.66.159:58972]
        '(?P<message>.*)$}', $str, $regp)) {
    $err = preg_last_error();
    print "PREG ERROR=" . $err . "\n";
    goto err;
  }

// var_dump($regp);

  /* further parsing of the date time */
  if (!preg_match('/^' .
      '\w{3} ' . // week day
      '(\w{3}) ' . // month short name
      '0?(\d+) ' . // month day
      '(\d{2}:\d{2}:\d{2})\.\d+ ' . // time
      '(\d{4})$/', $regp['datetime'], $regxp))
    goto err;
  $_date_month_name = $regxp[1];
  $_date_day = (int) $regxp[2];
  $_time = $regxp[3];
  $_date_year = $regxp[4];
  $_date_month = array_keys($Months, $_date_month_name);
  if ($_date_month === false)
    goto err;
  $_date = sprintf("%02d/%s/%4d", $_date_day, $_date_month_name, $_date_year);

  /* further parsing of the ip address */
  if ($regp['ip'] != "") {
    if (!preg_match('/^([0-9a-f:.]+)\:(\d+)$/', $regp['ip'], $regxp))
      goto err;
    $_ip_addr = $regxp[1];
    $_ip_port = $regxp[2];
  }
  else {
    $_ip_addr = null;
    $_ip_port = null;
  }

  if ($_date !== $current_date) {
    print "\n\e[1m=== " . $_date . " ===\e[m\n";
    $current_date = $_date;
  }

  $_prefix = trim($regp['prefix']);
  $_message = trim($regp['message']);
  if ($_prefix != "")
    $_message = $_prefix . " " . $_message;

  /* check if we filter out this message */
  $filtered = false;
  foreach ($Filter as $xfilter) {
    if ((($xfilter[0] === null) || ($regp['channel'] == $xfilter[0])) &&
        (($xfilter[1] === null) || ($regp['level'] == $xfilter[1])) &&
        // (($xfilter[2] === null) || !strncmp($_message, $xfilter[2], strlen($xfilter[2]))))
        (($xfilter[2] === null) || preg_match('/' . $xfilter[2] . '/', $_message)))
      $filtered = true;
  }

  if ($filtered === $FilterExclude)
    continue;

  // print formatted line
  $line = sprintf("%8s %-40s %-10s %-8s %s",
      /* 1 */ $_time,
      /* 2 */ $_ip_addr,
      /* 3 */ $regp['channel'],   // reqtimeout
      /* 4 */ $regp['level'],
      /* 5 */ $_message);

  print WTools::truncConsoleLine($line, $opt_cols) . "\n";
  continue;

 err:
  print "FAILED $str\n";
}
