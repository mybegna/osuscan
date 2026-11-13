<?php
/**
 * Helper Functions for OSU One Card System
 */

/**
 * Format timestamp to Ethiopia timezone with 12-hour format
 * This function handles timezone conversion reliably across different server configurations
 * 
 * @param string $timestamp Database timestamp
 * @param string $format Date format (default: 'M d, Y h:i A')
 * @return string Formatted date string
 */
function formatEthiopiaTime($timestamp, $format = 'M d, Y h:i A') {
    if (empty($timestamp)) {
        return 'N/A';
    }
    
    try {
        // Create DateTime object from the timestamp
        $date = new DateTime($timestamp);
        
        // Set timezone to Ethiopia
        $date->setTimezone(new DateTimeZone('Africa/Addis_Ababa'));
        
        // Return formatted date
        return $date->format($format);
    } catch (Exception $e) {
        // Fallback to basic formatting if DateTime fails
        return date($format, strtotime($timestamp));
    }
}

/**
 * Get current Ethiopia time
 * 
 * @param string $format Date format (default: 'Y-m-d H:i:s')
 * @return string Current time in Ethiopia timezone
 */
function getCurrentEthiopiaTime($format = 'Y-m-d H:i:s') {
    $date = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    return $date->format($format);
}
?>
