#!/usr/bin/php
<?php declare(ticks=1);
/**
 * tail_mail_log
 *
 * ...
 */

define("THSYSTOOLS_SHARED_PATH", "@@THSYSTOOLS_SHARED_PATH@@");

if (substr(THSYSTOOLS_SHARED_PATH, 0, 1) != "@") {
  require THSYSTOOLS_SHARED_PATH . DIRECTORY_SEPARATOR . "wtools.inc.php";
  require THSYSTOOLS_SHARED_PATH . DIRECTORY_SEPARATOR . "ztools.inc.php";
}
else {
  require __DIR__ . DIRECTORY_SEPARATOR . "wtools.inc.php";
  require __DIR__ . DIRECTORY_SEPARATOR . "ztools.inc.php";
}

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
 * System function to get the current terminal's columns available
 *
 * @return int Number of columns available
 */
function sys_get_term_cols()
{
  /* tput uses stderr to query the terminal, and it outputs there in case of
   * error, i found no way to suppress that behaviour, as '2>/dev/null' is not
   * an option */
  $cols = (int) exec("TERM=\${TERM:-linux} tput cols");
  if (!$cols)
    return 80;

  return $cols;
}

/* ------------------------------------------------------------------------ */

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

/* read command line switch: help (-h) */
while (($k = array_search("-h", $local_args)) !== false) {
  $opt_help = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch: debug (-d) */
while (($k = array_search("-d", $local_args)) !== false) {
  $opt_debug = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch: columns (-c COLS) */
while (($k = array_search("-c", $local_args)) !== false) {
  $opt_cols = (int) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  array_splice($local_args, $k, 2);
}

/* read command line switch: rollback to start (-S) */
while (($k = array_search("-S", $local_args)) !== false) {
  $opt_rollback_bytes = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch: rollback bytes (-B SIZE) */
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

/* read command line switch: rollback lines (-n LINES) */
while (($k = array_search("-n", $local_args)) !== false) {
  $opt_rollback_lines = (int) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  array_splice($local_args, $k, 2);
}

/* read command line switch: follow (-f) */
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
      "/var/log/maillog");

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

$pp = new PostfixMailLogParser();

$current_date = null;
while (($str = $tail->read()) !== false) {

  // FIXME: only for testing!
  if ((substr($str, 0, 1) == "#") || ($str == "")) {
    print $str . "\n";
    continue;
  }
  if (substr($str, 0, 1) == "=") {
    continue;
  }
  // print $str . "\n";

  if (!($syslog = $pp->parseLine($str)))
    goto err;

  if ($syslog['parsed']['content'] == "")
    continue;

  $ctrl_s = $syslog['parsed']['c_color'];
  $ctrl_e = "\x1b[m";

  $prefix = sprintf("%s {$ctrl_s}%-24s %-11s ",
      $syslog['time'],
      $syslog['parsed']['ipaddr'],
      $syslog['parsed']['msgid']);
  $prefix_len = strlen($prefix) - strlen($ctrl_s);

  $content = $syslog['process'] . ($syslog['pid'] !== null ?
      "(" . $syslog['pid'] . ")" : "") . ": " .
      $syslog['parsed']['content'];
  // $content_len = $this->_wrapping_cols - $prefix_len;

  // if ($this->_wrapping_style == "truncate") {
    // $content = substr($content, 0, $content_len);
  // }
  // elseif ($this->_wrapping_style == "wrap") {
    // $content = rtrim(chunk_split($content, $content_len, "\n"), "\n");
    // $content = str_replace("\n", "\n" . str_repeat(" ", $prefix_len), $content);
  // }

  // filter out unwanted static data
//    if (preg_match('{GET /dyndata|GET /d10m|GET /images|GET /admin}', $regp[4]))
//      continue;

  if ($syslog['date'] !== $current_date) {
    // print "\n\x1b[1m=== " . $regp['date'] . " ===\x1b[m\n";
    print "\n\x1b[1m=== " . $syslog['date'] . " ===\x1b[m\n";
    // print "  TIME       CLIENT                                 SSL   STAT SIZE  TIME  PROTOCOL / REQUEST / REFERER / USER AGENT\n";
    //    "00:28:12| 54.216.39.50                            |PLAIN  |403|  < |  -   |{1.0} GET /                        (no referer)  Mozilla/5.0 (compatible; NetcraftSurveyAgent/1.0; +info@netcraft.com)
    //    "00:28:12| 54.216.39.50                            |TLS1.2 |403|  < |  -   |{1.0} GET /                        (no referer)  Mozilla/5.0 (compatible; NetcraftSurveyAgent/1.0; +info@netcraft.com)
    $current_date = $syslog['date'];
  }

  $line = $prefix . $content . $ctrl_e;

  print WTools::truncConsoleLine($line, $opt_cols) . "\n";
  continue;

 err:
  print "FAILED $str\n";
}
