<?php
declare(strict_types=1);

/**
 * CSV Import Helper Functions
 * 
 * Standardized CSV handling with BOM stripping.
 * 
 * @package SLOCloud\Helpers
 */

/**
 * Open a CSV file for reading
 * 
 * Automatically strips UTF-8 BOM (EF BB BF) if present at file start.
 * 
 * @param string $filePath Path to CSV file (absolute or relative)
 * @return resource|false File handle on success, false on failure
 */
function csv_open(string $filePath)
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return false;
    }
    
    // Check for and skip UTF-8 BOM (3 bytes: EF BB BF)
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    return $handle;
}

/**
 * Read a CSV row
 * 
 * Wrapper around fgetcsv with automatic trimming.
 * 
 * @param resource $handle Open file handle from csv_open()
 * @param int $length Maximum line length (0 for unlimited)
 * @param string $separator Field delimiter (default: comma)
 * @param string $enclosure Field enclosure character (default: double quote)
 * @param string $escape Escape character (default: backslash)
 * @param bool $trim Whether to trim whitespace from fields (default: true)
 * @return array|false Array of fields on success, false on EOF or error
 */
function csv_read(
    $handle,
    int $length = 0,
    string $separator = ',',
    string $enclosure = '"',
    string $escape = '\\',
    bool $trim = true
) {
    $row = fgetcsv($handle, $length, $separator, $enclosure, $escape);
    
    if ($row === false) {
        return false;
    }
    
    if ($trim) {
        $row = array_map('trim', $row);
    }
    
    return $row;
}
