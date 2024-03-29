<?php

/**
 * @file
 * Contains \Drupal\composer_manager\ComposerManager.
 */

namespace Drupal\composer_manager;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Json;

class ComposerFile implements ComposerFileInterface {

  /**
   * @var string
   */
  protected $filepath;

  /**
   * @param string $filepath
   */
  public function __construct($filepath) {
    $this->filepath = $filepath;
  }

  /**
   * @return string
   */
  public function getFilepath() {
    return $this->filepath;
  }

  /**
   * Returns TRUE if the file exists and is a regular file.
   *
   * @return bool
   */
  public function exists() {
    return is_file($this->filepath);
  }

  /**
   * Returns TRUE if the file exists and is valid JSON.
   *
   * @return bool
   */
  public function isValidJson() {
    try {
      $this->read();
      return TRUE;
    } catch (\RuntimeException $e) {
      return FALSE;
    }
  }

  /**
   * Parses the contents of the Composer file into a PHP array.
   *
   * @return array
   *
   * @throws \RuntimeException
   */
  public function read() {
    if (!$this->exists()) {
      throw new \RuntimeException(t('File does not exist: @filepath', array('@filepath' => $this->filepath)));
    }
    if (!$filedata = @file_get_contents($this->filepath)) {
      throw new \RuntimeException(t('Error reading file: @filepath', array('@filepath' => $this->filepath)));
    }
    return Json::decode($filedata);
  }

  /**
   * Converts the data to a JSON string and writes the file.
   *
   * @param array $filedata
   *   The Composer filedata to encode.
   *
   * @return int
   *   This function returns the number of bytes that were written to the file.
   *
   * @throws \UnexpectedValueException
   * @throws \RuntimeException
   */
  public function write(array $filedata) {
    $options = JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    if (!$json = @json_encode($filedata, $options)) {
      throw new \UnexpectedValueException('Error encoding filedata');
    }
    if (!$bytes = @file_put_contents($this->filepath, $json)) {
      throw new \RuntimeException(String::format('Error writing file: @filepath', array('@filepath' => $this->filepath)));
    }
    return $bytes;
  }

  /**
   * Returns the file path.
   *
   * @return string
   */
  public function __toString() {
    return $this->filepath;
  }

}
