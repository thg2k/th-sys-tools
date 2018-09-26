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
   * @var string
   */
  private $_path;

  /**
   * Resolved real path
   *
   * @var string
   */
  private $_real_path;

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
   * @param string $message ...
   */
  private function _dbg($message) {
    print "[d] $message\n";
  }

  /**
   * ...
   *
   * @return string ...
   */
  private function _get_cache_file() {
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
    $cache_file = $cache_local . DIRECTORY_SEPARATOR . md5($this->_real_path);

    return $cache_file;
  }

  /**
   * ...
   */
  private function _save_metadata_cache(): bool {
    $cachefile = $this->_get_cache_file();
    if ($cachefile === false)
      return false;

    $this->_dbg("Saving metadata cache to " . $cachefile);
    $datarec = array(
      'metadata_ver' => self::METADATA_VER,
      'timestamp' => time(),
      'index' => $this->_index);
    file_put_contents($cachefile, serialize($datarec));

    return true;
  }

  /**
   * ...
   */
  private function _load_metadata_cache(): bool {
    $cachefile = $this->_get_cache_file();
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

    $this->_index = $datarec['index'];

    return true;
  }

  /**
   * ...
   *
   * @param string $path ...
   */
  public function __construct(string $path) {
    if (!file_exists($path))
      throw new DuplicateRemoverException(
          "Invalid path \"$path\": File not found");
    if (!is_dir($path))
      throw new DuplicateRemoverException(
          "Invalid path \"$path\": Not a directory");

    $this->_path = $path;
    $this->_real_path = realpath($path);

    $this->_dbg("Resolved real path: " . $this->_real_path);

    if (!$this->_load_metadata_cache())
      $this->_dbg("Metadata cache not available");
  }

  /**
   * ...
   *
   * @param string $subpath ...
   * @return bool ...
   */
  public function _index_internal(string $subpath = null): bool {
    $this->_dbg("Processing subpath=$subpath");

    $fullpath = $this->_path .
        ($subpath != "" ? DIRECTORY_SEPARATOR . $subpath : "");

    $dh = opendir($fullpath);
    if ($dh === false) {
      $this->_dbg("Failed to open path: $fullpath");
      return false;
    }

    $_local_files = array();
    while ($file = readdir($dh)) {
      if (($file == ".") || ($file == ".."))
        continue;
      $_local_files[] = $file;
    }
    closedir($dh);
    sort($_local_files);

    foreach ($_local_files as $file) {
      $subpathfile =
            ($subpath != "" ? $subpath . DIRECTORY_SEPARATOR : "") . $file;
      $fullpathfile = $fullpath . DIRECTORY_SEPARATOR . $file;

      if (is_dir($fullpathfile)) {
        /* git and subversion are known to keep duplicate copies, so
         * we can just ignore them */
        if (($file == ".svn") || ($file == ".git"))
          continue;

        $this->_index_internal($subpathfile);
      }
      else {
        $stat = stat($fullpathfile);
        if (!$stat) {
          die("FAILED TO STAT $fullpathfile");
          continue;
        }

        if ($stat['size'] == 0)
          continue;

        // $this->_dbg(".. found " . $fullpathfile . " -> " . $stat['size']);
        $this->_index[$subpathfile] = array(
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'md5' => null);

        /* cache a pointer to this entry based on the size */
        $this->_cache_sizes[$stat['size']][] = $subpathfile;
      }
    }

    return true;
  }

  /**
   * ...
   */
  public function scan(): void {
    /* save the previous index */
    $prev_index = $this->_index;

    /* rebuild the index */
    $this->_dbg("Indexing directory...");
    $this->_index = array();
    $this->_index_internal();
    $this->_duplicates = 0;

    /* compute the md5 for those who have potential duplicates */
    foreach ($this->_cache_sizes as $files) {
      if (count($files) <= 1)
        continue;

      foreach ($files as $file) {
        if (isset($prev_index[$file]['md5']) &&
            ($prev_index[$file]['size'] == $this->_index[$file]['size']) &&
            ($prev_index[$file]['mtime'] == $this->_index[$file]['mtime'])) {
          $this->_dbg("using cached md5 for $file");
          $md5sum = $prev_index[$file]['md5'];
        }
        else {
          $this->_dbg("calculating md5 for $file...");
          $fullpathfile = $this->_path . DIRECTORY_SEPARATOR . $file;
          $md5sum = md5_file($fullpathfile);
        }

        $this->_index[$file]['md5'] = $md5sum;
        $this->_cache_md5s[$md5sum][] = $file;
        if (count($this->_cache_md5s[$md5sum]) == 2)
          $this->_duplicates++;
      }
    }

    /* save the metadata cache for future use */
    $this->_save_metadata_cache();
  }

  /**
   * ...
   */
  public function interactivePrompt(): void {
    $cnt = 0;
    foreach ($this->_cache_md5s as $md5 => $files) {
      if (count($files) <= 1)
        continue;
      $cnt++;

      print "\n\n[$cnt/" . $this->_duplicates . "] Found " . count($files) . " duplicates!\n";
      foreach ($files as $idx => $file) {
        print "  [" . ($idx + 1) . "]  $file\n";
      }
      do {
        print "Enter which to KEEP [1-" . count($files) . "X]: ";
        $choice = rtrim(fgets(STDIN), "\r\n");
        if (!preg_match('/^(\d*|X)$/', $choice))
          continue;

        /* convert to integer */
        $choice = ($choice == "X" ? -1 : intval($choice));
        if ($choice > count($files))
          continue;

        if ($choice) {
          foreach ($files as $idx => $xfile) {
            if ($idx == ($choice - 1))
              continue;
            $real_xfile = $this->_real_path . DIRECTORY_SEPARATOR . $xfile;
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
  fprintf(STDERR, "Usage: %s <path>\n", $argv[0]);
  exit(1);
}

$path = $argv[1];

$dups = new DuplicateRemover($path);

$dups->scan();
$dups->interactivePrompt();

