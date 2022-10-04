#!/usr/bin/php
<?php declare(ticks=1);
/**
 * tail_http_error_log
 *
 * ...
 */

define("THSYSTOOLS_SHARED_PATH", "@@THSYSTOOLS_SHARED_PATH@@");

if (substr(THSYSTOOLS_SHARED_PATH, 0, 1) != "@")
  require THSYSTOOLS_SHARED_PATH . DIRECTORY_SEPARATOR . "wtools.inc.php";
else
  require __DIR__ . DIRECTORY_SEPARATOR . "wtools.inc.php";

/**
 * Prints an error message and terminates the execution
 *
 * @param string $message Error message
 * @return never
 */
function err($message)
{
  fprintf(STDERR, "Error: %s\n", $message);
  exit(1);
}

/**
 * Prints a debugging message to the standard error stream
 *
 * @param string $message Debug message
 * @return void
 */
function dbg($message)
{
  global $opt_debug;
  if ($opt_debug)
    fprintf(STDERR, "[d] %s\n", $message);
}

/**
 * Prints the command usage information on the given stream
 *
 * @param resource $fd Stream for output
 * @param string $progname Command line name
 * @return void
 */
function print_usage($fd, $progname)
{
  fprintf($fd, "Usage: %s [-c COLS] [-S|-B SIZE] [-f] <file> [filters...]\n", $progname);
}

/**
 * System function to get the current terminal's columns available
 *
 * @return int Number of columns available
 */
function sys_get_term_cols()
{
  $cols = (int) exec("tput cols 2>/dev/null");
  if (!$cols)
    return 80;

  return $cols;
}

/* ------------------------------------------------------------------------ */

$opt_help = false;
$opt_debug = false;
$opt_cols = null;
$opt_rollback_bytes = 32768;
$opt_rollback_lines = 5;
$opt_follow = false;
$opt_filter = array();
$files = array();

$local_args = $argv;
$progname = array_shift($local_args);

/* read command line switch "-h" */
while (($k = array_search("-h", $local_args)) !== false) {
  $opt_help = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch "-d" */
while (($k = array_search("-d", $local_args)) !== false) {
  $opt_debug = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch "-c COLS" */
while (($k = array_search("-c", $local_args)) !== false) {
  $opt_cols = (int) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  array_splice($local_args, $k, 2);
}

/* read command line switch "-S" */
while (($k = array_search("-S", $local_args)) !== false) {
  $opt_rollback_bytes = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch "-B SIZE" */
while (($k = array_search("-B", $local_args)) !== false) {
  $_arg = (string) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  if (!preg_match('/^(\d+)(M|k)?$/', $_arg, $regp))
    err("Invalid syntax \"$_arg\" for filter, must be a bytes unit");
  $opt_rollback_bytes = (int) $regp[1];
  if (!empty($regp[2])) {
    $opt_rollback_bytes *= ($regp[2] == "M" ? 1024 * 1024 :
        ($regp[2] == "k" ? 1024 : 1));
  }
  array_splice($local_args, $k, 2);
}

/* read command line switch "-n LINES" */
while (($k = array_search("-n", $local_args)) !== false) {
  $opt_rollback_lines = (int) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  array_splice($local_args, $k, 2);
}

/* read command line switch "-f" */
while (($k = array_search("-f", $local_args)) !== false) {
  $opt_follow = true;
  array_splice($local_args, $k, 1);
}

if ($opt_help) {
  print_usage(STDOUT, $progname);
  exit(0);
}

/* process remaining argument, depending on the type, bucket-sort them */
$_use_only_files = false;
foreach ($local_args as $_arg) {
  /* after '--', only files are encountered */
  if ($_use_only_files) {
    $files[] = $_arg;
  }
  elseif ($_arg == "--") {
    $_use_only_files = true;
  }
  elseif ($_arg == "-") {
    $files[] = "php://stdin";
  }
  elseif (substr($_arg, 0, 1) == "-") {
    err("Unknown option '$_arg'");
  }
  elseif (strpos($_arg, "=") !== false) {
    if (!preg_match('/^([a-z]+)=(.+)$/', $_arg, $regp))
      err("Invalid syntax \"$_arg\" for filter, must be key=value");
    $opt_filter[] = array($regp[1],
        preg_split('/,/', $regp[2], PREG_SPLIT_NO_EMPTY));
  }
  else {
    $files[] = $_arg;
  }
}

dbg("Runtime options:");
dbg("..  opt_cols = " . ($opt_cols !== null ? $opt_cols : "(auto)"));
dbg("..  opt_rollback_lines = " . $opt_rollback_lines);
dbg("..  opt_rollback_bytes = " . $opt_rollback_bytes);
dbg("..  opt_follow = " . ($opt_follow ? "true" : "false"));
dbg("..  opt_filter = " . json_encode($opt_filter));

/* ------------------------------------------------------------------------ */

/* auto-determine the number of usable columns */
if ($opt_cols === null) {
  dbg("Trying to auto-determine total number of columns");
  $opt_cols = sys_get_term_cols();
  dbg(".. detected screen columns: " . $opt_cols);

  if (function_exists('pcntl_signal')) {
    dbg("Setting up terminal resize signal handler (SIGWINCH)");
    pcntl_signal(SIGWINCH, function($signo, $siginfo) {
      global $opt_cols;
      dbg("Terminal resized, attempting to detect new number of columns");
      $opt_cols = sys_get_term_cols();
      dbg(".. detected screen columns: " . $opt_cols);
    });
  }
}

/* read first command argument (file name) */
$file = null;
if (count($files) > 0)
  $file = array_shift($files);
if ($file == "-") {
  $file = "php://stdin";
}
elseif ($file == "") {
  if ($file === null) {
    /* attempt to find the most obvious file */
    $CandidateFiles = array(
      "%HOME/logs/error_log",
      "./error_log");

    $repls = array(array("%HOME"), array(getenv("HOME")));
    foreach ($CandidateFiles as $candidate_file) {
      $candidate_file = str_replace($repls[0], $repls[1], $candidate_file);
      if (file_exists($candidate_file))
        $file = $candidate_file;
    }
  }

  if ($file == "") {
    print_usage(STDERR, $progname);
    exit(1);
  }
}

$tail = new Tail($file, $opt_follow);
if ($opt_rollback_bytes === true) {
  $tail->rollback();
}
else {
  $tail->rollbackBytes($opt_rollback_bytes);
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

function parse_error_log_line($str)
{
}

$current_date = null;
while (($str = $tail->read()) !== false) {
  // 81.202.254.26 WonderSeller TLSv1.2 [17/May/2018:00:58:13 +0200] "GET /sell/articles/cards HTTP/1.1" 200 98356 "-" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.139 Safari/537.36"

  // [Sat May 19 17:06:19.120115 2018] [core:info] [pid 20290] [client 66.249.66.159:58972] AH00128: File does not exist: /home/web/decktutor/htdocs/ads.txt
  // [Sat May 19 17:10:13.895016 2018] [ssl:info] [pid 20410] [client 2a01:4f8:13b:184e::2:38470] AH01964: Connection to child 19 established (server decktutor.com:443)
  // [Sun May 27 03:13:45.850986 2018] [ssl:info] [pid 12158] SSL Library Error: error:1408F081:SSL routines:SSL3_GET_RECORD:block cipher pad is wrong

  if (!preg_match('{^' .
        '\[(?P<datetime>[A-Za-z0-9:\. ]+)\] ' .   // [Sat May 19 17:10:13.895016 2018]
        '\[(?P<channel>\w+):(?P<level>\w+)\] ' .  // [ssl:info]
        '\[pid (?P<pid>\d+)(?::tid (?P<tid>\d+))?\] ' .  // [pid 20410] or [pid 30712:tid 30712]
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

  // if ($filtered === $FilterExclude)
    // continue;

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
