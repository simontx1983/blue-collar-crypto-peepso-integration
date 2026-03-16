<?php
if (!defined('ABSPATH')) exit;

class BCC_Options_Helper {
    
    /**
     * Converts options string to key-value map
     * Format: "key1:value1,key2:value2"
     */
    public static function parse_options_string(string $options_str): array {
        $map = [];
        
        if (empty($options_str)) {
            return $map;
        }
        
        foreach (explode(',', $options_str) as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) === 2) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        return $map;
    }
}