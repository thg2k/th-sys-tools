#!/usr/bin/php
<?php
/**
 * ...
 */

declare(strict_types=1);

/**
 * ...
 */
class DuplicateRemoverException extends Exception {
}

/**
 * ...
 */
class DuplicateRemover {
  /**
   * ...
   */
  const METADATA_VER = 'v1';

  /**
   * ...
   *
   * @var array
   */
  private $_paths;

  /**
   * Resolved real path
   *
   * @var array
   */
  private $_real_paths;

  /**
   * ...
   *
   * Each entry has the following record:
   *  - size       Size in bytes (int)
   *  - mtime      Modification timestamp (int)
   *  - md5        MD5 of the content (string)
   *
   * @var array
   */
  private $_index;

  /**
   * ...
   *
   * @var array
   */
  private $_cache_sizes;

  /**
   * ...
   *
   * @var array
   */
  private $_cache_md5s;

  /**
   * ...
   *
   * @var int
   */
  private $_duplicates = 0;

  /**
   * ...
   *
   * @param array $path ...
   * @param bool $nocache ...
   */
  public function __construct(array $paths, $nocache = false) {
    /* check that the structural data is correct */
    foreach ($paths as $path) {
      if (!is_string($path) || ($path == ""))
        throw new DuplicateRemoverException(
            "Invalid paths format, must be all non-empty strings");
    }

    /* check for access */
    $this->_paths = array();
    $this->_real_paths = array();
    foreach ($paths as $path) {
      if (!file_exists($path))
        throw new DuplicateRemoverException(
            "Invalid path \"$path\": File not found");
      if (!is_dir($path))
        throw new DuplicateRemoverException(
            "Invalid path \"$path\": Not a directory");

      $real_path = $path;

      $this->_paths[] = $path;
      $this->_real_paths[] = realpath($path);

      $this->_dbg("Resolved real path: " . $real_path);
    }

    if ($nocache)
      return;

    for ($i = 0; $i < count($this->_paths); $i++) {
      if (!$this->_load_metadata_cache($i))
        $this->_dbg("Metadata cache not available " .
                    "for path [$i] \"" . $this->_paths[$i] . "\"");
    }
  }

  /**
   * ...
   *
   * @param string $message ...
   */
  private function _dbg($message) {
    print "[d] $message\n";
  }

  /**
   * ...
   *
   * @param int $path_idx ...
   * @return string ...
   */
  private function _get_cache_file(int $path_idx) {
    $home = getenv("HOME");
    if ($home == "")
      return false;

    $cache_root = $home . DIRECTORY_SEPARATOR . ".cache";
    if (file_exists($cache_root) && !is_dir($cache_root))
      return false;
    if (!file_exists($cache_root) && !mkdir($cache_root))
      return false;

    $cache_local = $cache_root . DIRECTORY_SEPARATOR . "duplicate_remover";
    if (file_exists($cache_local) && !is_dir($cache_local))
      return false;
    if (!file_exists($cache_local) && !mkdir($cache_local))
      return false;

    /* metadata index */
    $cache_file = $cache_local . DIRECTORY_SEPARATOR . md5($this->_real_paths[$path_idx]);

    return $cache_file;
  }

  /**
   * ...
   *
   * @param string $path_idx ...
   * @return bool ...
   */
  private function _save_metadata_cache(int $path_idx): bool {
    $cachefile = $this->_get_cache_file($path_idx);
    if ($cachefile === false)
      return false;

    $this->_dbg("Saving metadata cache to " . $cachefile);
    $datarec = array(
      'metadata_ver' => self::METADATA_VER,
      'path' => $this->_real_paths[$path_idx],
      'timestamp' => time(),
      'index' => $this->_index[$path_idx]);
    file_put_contents($cachefile, serialize($datarec));

    return true;
  }

  /**
   * ...
   *
   * @param int $path_idx ...
   */
  private function _load_metadata_cache($path_idx): bool {
    $cachefile = $this->_get_cache_file($path_idx);
    if ($cachefile === false) {
      $this->_dbg("Metadata cache: Couldn't determine cache file");
      return false;
    }

    if (!file_exists($cachefile)) {
      $this->_dbg("Metadata cache: Cache file not found");
      return false;
    }

    $this->_dbg("Loading metadata cache from " . $cachefile);

    $datarec = unserialize(file_get_contents($cachefile));

    if ($datarec['metadata_ver'] != self::METADATA_VER) {
      $this->_dbg("Metadata cache: Invalid or corrupted data");
      return false;
    }

    $this->_index[$path_idx] = $datarec['index'];

    return true;
  }

  /**
   * ...
   *
   * @param string $subpath ...
   * @return bool ...
   */
  public function _index_internal(int $path_idx, string $subpath = null): bool {
    $this->_dbg("Processing subpath=$subpath [$path_idx]");

    $fullpath = $this->_paths[$path_idx] .
        ($subpath != "" ? DIRECTORY_SEPARATOR . $subpath : "");

    /* open target directory */
    $dh = opendir($fullpath);
    if ($dh === false) {
      $this->_dbg("Failed to open path: $fullpath");
      return false;
    }

    /* read all the files */
    $_local_files = array();
    while ($file = readdir($dh)) {
      if (($file == ".") || ($file == ".."))
        continue;
      $_local_files[] = $file;
    }
    closedir($dh);
    sort($_local_files);

    /* process all the sorted files */
    foreach ($_local_files as $file) {
      $subpathfile =
            ($subpath != "" ? $subpath . DIRECTORY_SEPARATOR : "") . $file;
      $fullpathfile = $fullpath . DIRECTORY_SEPARATOR . $file;

      print "== $fullpathfile\n";
      $stat = @lstat($fullpathfile);
      if ($stat === false)
        die("FAILED TO STAT $fullpathfile\n");
      $stat_m = $stat['mode'] & 0xf000;

      switch ($stat_m) {
      case 0x4000: // S_IFDIR
        /* git and subversion are known to keep duplicate copies, so
         * we can just ignore them */
        if (($file == ".svn") || ($file == ".git"))
          continue;

        $this->_index_internal($path_idx, $subpathfile);
        break;

      case 0x8000: // S_IFREG
        /* ignore empty files */
        if ($stat['size'] == 0)
          continue;

        // $this->_dbg(".. found " . $fullpathfile . " -> " . $stat['size']);
        $this->_index[$path_idx][$subpathfile] = array(
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'md5' => null);

        /* cache a pointer to this entry based on the size */
        $this->_cache_sizes[$stat['size']][] = $path_idx . ":" . $subpathfile;
        break;

      case 0xa000: // S_IFLNK
        $this->_dbg("Ignoring symlink: $file");
        break;

      default:
        die("UNKNOWN FILE MODE " . dechex($stat_m));
      }
    }

    return true;
  }

  private function _parse_vfile(string $vfile): array {
    $p = strpos($vfile, ":");
    return array((int) substr($vfile, 0, $p), substr($vfile, $p + 1));
  }

  /**
   * ...
   */
  public function scan(): void {
    /* save the previous index */
    $prev_index = $this->_index;

    /* rebuild the index */
    foreach (array_keys($this->_paths) as $path_idx) {
      $this->_dbg("Indexing directory...");
      $this->_index[$path_idx] = array();
      $this->_index_internal($path_idx);
      $this->_duplicates = 0;
    }

    /* compute the md5 for those who have potential duplicates */
    foreach ($this->_cache_sizes as $vfiles) {
      if (count($vfiles) <= 1)
        continue;

      foreach ($vfiles as $vfile) {
        list($path_idx, $file) = $this->_parse_vfile($vfile);
        if (isset($prev_index[$path_idx][$file]['md5']) &&
            ($prev_index[$path_idx][$file]['size'] == $this->_index[$path_idx][$file]['size']) &&
            ($prev_index[$path_idx][$file]['mtime'] == $this->_index[$path_idx][$file]['mtime'])) {
          $this->_dbg("using cached md5 for $file");
          $md5sum = $prev_index[$path_idx][$file]['md5'];
        }
        else {
          $this->_dbg("calculating md5 for $file...");
          $fullpathfile = $this->_paths[$path_idx] . DIRECTORY_SEPARATOR . $file;
          $md5sum = md5_file($fullpathfile);
        }

        $this->_index[$path_idx][$file]['md5'] = $md5sum;
        $this->_cache_md5s[$md5sum][] = $vfile;
        if (count($this->_cache_md5s[$md5sum]) == 2)
          $this->_duplicates++;
      }
    }

    /* save the metadata cache for future use */
    foreach (array_keys($this->_paths) as $path_idx) {
      $this->_save_metadata_cache($path_idx);
    }
  }

  /**
   * ...
   */
  public function interactivePrompt(): void {
    $cnt = 0;
    foreach ($this->_cache_md5s as $md5 => $vfiles) {
      if (count($vfiles) <= 1)
        continue;
      $cnt++;

      print "\n\n[$cnt/" . $this->_duplicates . "] Found " . count($vfiles) . " duplicates!\n";
      foreach ($vfiles as $idx => $vfile) {
// var_dump($vfile);
        list($path_idx, $file) = $this->_parse_vfile($vfile);
        print "  [" . ($idx + 1) . "]  " . $this->_paths[$path_idx] . " >> " . $file . "\n";
      }
      do {
        print "Enter which to KEEP [1-" . count($vfiles) . "X]: ";
        $choice = rtrim(fgets(STDIN), "\r\n");
        if (!preg_match('/^(\d*|X)$/', $choice))
          continue;

        /* convert to integer */
        $choice = ($choice == "X" ? -1 : intval($choice));
        if ($choice > count($vfiles))
          continue;

        if ($choice) {
          foreach ($vfiles as $idx => $vxfile) {
            list($path_idx, $xfile) = $this->_parse_vfile($vxfile);

            if ($idx == ($choice - 1))
              continue;
            $real_xfile = $this->_real_paths[$path_idx] . DIRECTORY_SEPARATOR . $xfile;
            print ".. deleting $real_xfile\n";
            unlink($real_xfile);
          }
        }
        else {
          print ".. skipping\n";
        }

        break;
      }
      while (true);
    }
  }
}


if ($argc < 2) {
  fprintf(STDERR, "Usage: %s [-nocache] <path> [path...]\n", $argv[0]);
  exit(1);
}

$opt_nocache = false;
$paths = array();

for ($i = 1; $i < $argc; $i++) {
  if ($argv[$i] == "-nocache") {
    $opt_nocache = true;
    continue;
  }
  $paths[] = $argv[$i];
}

$dups = new DuplicateRemover($paths, $opt_nocache);

$dups->scan();
$dups->interactivePrompt();
