<?php
if (!defined('ABSPATH')) exit;

/*
|--------------------------------------------------------------------------
| FIELD VISIBILITY ENGINE
|--------------------------------------------------------------------------
| Controls who can VIEW and EDIT individual fields.
| Visibility values:
| - public   → anyone
| - members  → logged-in users
| - private  → owner only
*/

if (!function_exists('bcc_get_field_visibility')) {

function bcc_get_field_visibility($post_id, $field) {

    // Global cache so bcc_clear_visibility_cache() can actually reach it.
    global $bcc_vis_cache;

    $cache_key = $post_id . '_' . $field;

    if (isset($bcc_vis_cache[$cache_key])) {
        return $bcc_vis_cache[$cache_key];
    }

    $vis = get_post_meta($post_id, '_bcc_vis_' . $field, true) ?: 'public';

    $bcc_vis_cache[$cache_key] = $vis;

    return $vis;
}

}

/* ======================================================
   CAN CURRENT USER VIEW FIELD
====================================================== */

if (!function_exists('bcc_user_can_view_field')) {

function bcc_user_can_view_field($post_id, $field) {

    $visibility = bcc_get_field_visibility($post_id, $field);

    // Public → everyone
    if ($visibility === 'public') {
        return true;
    }

    // Members → logged in only
    if ($visibility === 'members') {
        return is_user_logged_in();
    }

    // Private → owner only
    if ($visibility === 'private') {
        return bcc_user_is_owner($post_id);
    }

    // Fallback: deny unrecognized visibility values
    return false;
}

}

/* ======================================================
   CAN CURRENT USER EDIT FIELD
====================================================== */

if (!function_exists('bcc_user_can_edit_field')) {

function bcc_user_can_edit_field($post_id, $field) {

    // First check if user can edit the post at all
    if (!bcc_user_can_edit_post($post_id)) {
        return false;
    }

    // Then check field-specific visibility for edit permissions
    $visibility = bcc_get_field_visibility($post_id, $field);

    // If field is private, only owner can edit (already checked via bcc_user_can_edit_post)
    // If field is members/public, owner can edit (already checked)
    // So we don't need additional checks here, but we're keeping the function
    // for future expansion (e.g., role-based edit permissions)

    return true;
}

}

/* ======================================================
   SET FIELD VISIBILITY (with validation)
====================================================== */

if (!function_exists('bcc_set_field_visibility')) {

function bcc_set_field_visibility($post_id, $field, $visibility) {

    // Validate post exists
    if (!get_post($post_id)) {
        return false;
    }

    // Authorization check
    if (function_exists('bcc_user_can_edit_post')) {
        if (!bcc_user_can_edit_post($post_id)) {
            return false;
        }
    } elseif (!current_user_can('edit_post', $post_id)) {
        return false;
    }

    // Validate and sanitize field name
    if (empty($field)) {
        return false;
    }
    $field = sanitize_key($field);

    if (!in_array($visibility, ['public', 'members', 'private'], true)) {
        return false;
    }

    $result = update_post_meta($post_id, '_bcc_vis_' . $field, $visibility);
    
    // Clear cache if we're using caching
    if (function_exists('bcc_clear_visibility_cache')) {
        bcc_clear_visibility_cache($post_id, $field);
    }

    return (bool) $result;
}

}

/* ======================================================
   CLEAR VISIBILITY CACHE (optional helper)
====================================================== */

if (!function_exists('bcc_clear_visibility_cache')) {

function bcc_clear_visibility_cache($post_id = null, $field = null) {
    global $bcc_vis_cache;

    if (!is_array($bcc_vis_cache)) {
        return true;
    }

    if ($post_id && $field) {
        unset($bcc_vis_cache[$post_id . '_' . $field]);
    } elseif ($post_id) {
        $prefix = $post_id . '_';
        foreach (array_keys($bcc_vis_cache) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset($bcc_vis_cache[$key]);
            }
        }
    } else {
        $bcc_vis_cache = [];
    }

    return true;
}

}

