#!/usr/bin/php
<?php declare(ticks=1);
/**
 * tail_http_access_log
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
  fprintf($fd, "Usage: %s [-c COLS] [-S|-B SIZE|-n LINES] [-W all] [-f] <file> [filters...]\n", $progname);
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

$opt_help = false;
$opt_debug = false;
$opt_cols = null;
$opt_rollback_type = null;
$opt_rollback_amount = 5;
$opt_follow = false;
$opt_filter = array();
$opt_lookup = "none";
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
  if (($opt_rollback_type !== null) && ($opt_rollback_type != "-S"))
    err("Command line options '-S' and '$opt_rollback_type' are incompatible");
  /** @var string */
  $opt_rollback_type = "-S";
  array_splice($local_args, $k, 1);
}

/* read command line switch "-B SIZE" */
while (($k = array_search("-B", $local_args)) !== false) {
  $_arg = (string) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  if (!preg_match('/^(\d+)(M|k)?$/', $_arg, $regp))
    err("Invalid syntax \"$_arg\" for filter, must be a bytes unit");
  if (($opt_rollback_type !== null) && ($opt_rollback_type != "-B"))
    err("Command line options '-B' and '$opt_rollback_type' are incompatible");
  $opt_rollback_type = "-B";
  $opt_rollback_amount = (int) $regp[1];
  if (!empty($regp[2])) {
    $opt_rollback_amount *= ($regp[2] == "M" ? 1024 * 1024 :
        ($regp[2] == "k" ? 1024 : 1));
  }
  array_splice($local_args, $k, 2);
}

/* read command line switch "-n LINES" */
while (($k = array_search("-n", $local_args)) !== false) {
  if (($opt_rollback_type !== null) && ($opt_rollback_type != "-n"))
    err("Command line options '-n' and '$opt_rollback_type' are incompatible");
  $opt_rollback_type = "-n";
  $opt_rollback_amount = (int) (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  array_splice($local_args, $k, 2);
}

/* read command line switch "-f" */
while (($k = array_search("-f", $local_args)) !== false) {
  $opt_follow = true;
  array_splice($local_args, $k, 1);
}

/* read command line switch "-W" */
while (($k = array_search("-W", $local_args)) !== false) {
  $opt_lookup = (isset($local_args[$k + 1]) ? $local_args[$k + 1] : null);
  array_splice($local_args, $k, 2);
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
dbg("..  opt_rollback_type = " . ($opt_rollback_type !== null ? $opt_rollback_type : "(lines)"));
dbg("..  opt_rollback_amount = " . $opt_rollback_amount);
dbg("..  opt_follow = " . ($opt_follow ? "true" : "false"));
dbg("..  opt_lookup = " . $opt_lookup);
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

/* pull the lookup groups if configured */
$netlookup = null;
if ($opt_lookup != "") {
  $netlookup = WTools::fetchNetworkLookupCached(explode(",", $opt_lookup));
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
      "%HOME/logs/access_log",
      "./access_log");

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
if ($opt_rollback_type == "-S") {
  $tail->rollback();
}
elseif ($opt_rollback_type == "-B") {
  $tail->rollbackBytes($opt_rollback_amount);
}
else {
  /* until we have the real lines-based rollback, estimate */
  $tail->rollbackBytes($opt_rollback_amount * 305);
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
 *   "  < "   < 0.5
 *   " <1k"   < 1.0
 *   "  5k"
 *   " 10k"
 *   "999k"   < 999.5
 *   "1.0M"
 *   "9.9M"   < 9.95
 *   "100M"
 *   "999M"
 */
$fmt_sent_size = function($size) {
  if (!$size)
    return "  - ";

  /* convert to kibibytes */
  $size /= 1024.0;

  /* less than half kiB we show it as "less than" */
  if ($size < 0.5)
    return "  < ";

  /* less than one kiB we show it as "less than" */
  // if ($size < 1)
    // return " <1k";

  /* up to 1.0M we show it as kilobytes without decimals, to ease up visual
   * comparison of the sizes, as visually "1.5k" is larger than "15k", it is
   * harder to spot more relevant transfers */
  if ($size < 999.5)
    // return sprintf("%3.0fk", $size);  // FIXME: see https://3v4l.org/8QtBc
    return sprintf("%3.0fk", round($size));

  /* time to convert to mebibytes */
  $size /= 1024.0;

  /* this time we also show decimals up to 10M */
  if ($size < 9.95)
    return sprintf("\x1b[1m%3.1fM\x1b[m", round($size, 1));

  return sprintf("\x1b[1m%3.0fM\x1b[m", round($size, 0));
};

/**
 * ...
 *
 * fmts:
 *   "   -  "
 *   "9999ms"
 *   "99.9s "
 *   "9999s "
 */
$fmt_duration = function($duration) {
  if (!$duration)
    return "  -   ";

  /* formatting space-fixed */
  if ($duration < 10000) {
    /* format: 9999ms */
    $fmt = sprintf("%4dms", $duration);
  }
  elseif ($duration < 99950) {
    /* format: 999.9s_ */
    $fmt = sprintf("%4.1fs ", $duration / 1000);
  }
  else {
    /* format: 9999s_ */
    $fmt = sprintf("%4ds ", (int) round($duration / 1000));
  }

  /* apply coloring */
  if ($duration >= 10000) {
    $fmt = "\x1b[31;1m" . $fmt . "\x1b[m";
  }
  elseif ($duration > 1499) {
    $fmt = "\x1b[31m" . $fmt . "\x1b[m";
  }
  elseif ($duration > 299) {
    $fmt = "\x1b[33m" . $fmt . "\x1b[m";
  }
  // elseif ($duration > 100) {
    // $fmt = "\x1b[1m" . $fmt . "\x1b[m";
  // }

  return $fmt;
};

$fmt_http_request = function($req) {
  // if (preg_match('/^"
  // return "{{ " . $req . " }}";
  // GET, POST, PUT, DELETE, OPTIONS
  if (preg_match('~^"([A-Z]{1,7}) (.+) HTTP/(1\.[01])"$~', $req, $regp)) {
    if (preg_match('/^([^?]+)(\?.*)$/', $regp[2], $regxp)) {
      return "{" . $regp[3] . "} " . "\x1b[1m" . $regp[1] . " " . $regxp[1] . "\x1b[m" . $regxp[2];
    }
    else
      return "{" . $regp[3] . "} " . "\x1b[1m" . $regp[1] . " " . $regp[2] . "\x1b[m";
  }
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

    if ($chr == "\x1b") {
      $in_escape_sequence = true;
      continue;
    }

    $visible_chars++;
  }

  if ($visible_chars == $max_chars)
    return substr($line, 0, $i) . "\x1b[m";

  return $line;
};

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


  if (!($regp = WTools::parseAccessLog_th_lf($str)))
    goto err;
  // var_dump($regp); exit();

  // filter out unwanted static data
//    if (preg_match('{GET /dyndata|GET /d10m|GET /images|GET /admin}', $regp[4]))
//      continue;

// var_dump($regp);

  if (count($opt_filter) > 0) {
    /* if filters are provided, each filter must match */
    $filter_match = true;
    foreach ($opt_filter as $xfilter) {
      /* each filter can have alternative match values, either one is fine */
      $_match = false;
      foreach ($xfilter[1] as $xfiltervalue) {
        switch ($xfilter[0]) {
        case 'url':
        case 'req':
          $_match = $_match || (strpos($regp['req'], $xfiltervalue) !== false);
          break;

        case 'status':
          $_match = $_match || ($regp['status'] == (int) $xfiltervalue);
          break;

        case 'ip':
          $_match = $_match || (strpos($regp['ip'], $xfiltervalue) !== false);
          break;

        case 'time':
          $_match = $_match ||
              !strncmp($regp['time'], $xfiltervalue, strlen($xfiltervalue));
          break;

        case 'minsize':
          $_match = $_match ||
              ($regp['size'] >= (int) $xfiltervalue);
          break;

        case 'maxsize':
          $_match = $_match ||
              ($regp['size'] <= (int) $xfiltervalue);
          break;

        case 'peer':
          $_match = $_match || (strpos($regp['peer'], $xfiltervalue) !== false);
          break;
        }
      }
      if (!$_match)
        $filter_match = false;
    }
    if (!$filter_match)
      continue;
  }

  if ($regp['date'] !== $current_date) {
    // print "\n\x1b[1m=== " . $regp['date'] . " ===\x1b[m\n";
    print "\n\x1b[1m=== " . $regp['date'] . " ===\x1b[m" .
        str_repeat(" ", 32) .
        " SSL   STAT SIZE  TIME  PROTOCOL / REQUEST / REFERER / USER AGENT\n";
    // print "  TIME       CLIENT                                 SSL   STAT SIZE  TIME  PROTOCOL / REQUEST / REFERER / USER AGENT\n";
    //    "00:28:12| 54.216.39.50                            |PLAIN  |403|  < |  -   |{1.0} GET /                        (no referer)  Mozilla/5.0 (compatible; NetcraftSurveyAgent/1.0; +info@netcraft.com)
    //    "00:28:12| 54.216.39.50                            |TLS1.2 |403|  < |  -   |{1.0} GET /                        (no referer)  Mozilla/5.0 (compatible; NetcraftSurveyAgent/1.0; +info@netcraft.com)
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
  // 255.255.255.255 MyNickNameIsVeryLong
  // 1111:2222:3333:4444:5555:6666:7777:8888
  // $slot_1 = sprintf("%-15s %s",
      // ;

  $net_name = ($netlookup ? $netlookup->lookup($regp['ip']) : null);

  $ctl_char = ($net_name ? "@" : ($regp['peer'] != "" ? ">" : ""));

  // print formatted line
  //                ts  p   ip   pt st sz tm    rq u r a
  $line = sprintf("%8s %s%-40s %-7s %s %s %s %-40s%s%s%s",
      /* ts */ $regp['time'],
      /* pp */ ($ctl_char ? "\x1b[33m" . $ctl_char . "\x1b[m" : " "),
      /* ip */ ($net_name ?: $regp['ip']),
      /* pt */ ($regp['proto'] != "" ? $regp['proto'] : "PLAIN"),
      /* st */ $fmt_http_status($regp['status']),
      /* sz */ $fmt_sent_size($regp['size']),
      /* tm */ $fmt_duration($regp['duration']),
      /* rq */ $fmt_http_request($regp['req']),
      /* uu */ ($regp['user'] != "" ? "  \x1b[31m" . $regp['user'] . "\x1b[m" : ""),
      /* rr */ ($regp['referer'] != "" ? "  \x1b[36m" . $regp['referer'] . "\x1b[m" : "  (no referer)"),
      /* aa */ ($regp['agent'] != "" ? "  \x1b[35m" . $regp['agent'] . "\x1b[m" : "  (no agent)"));

  print WTools::truncConsoleLine($line, $opt_cols) . "\n";
  continue;

 err:
  print "FAILED $str\n";
}
