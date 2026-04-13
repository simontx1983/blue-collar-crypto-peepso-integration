<?php
if (!defined('ABSPATH')) exit;

/**
 * ======================================================
 * POST-LEVEL VIEW PERMISSION
 * ======================================================
 */

function bcc_user_can_view_post($post_id) {
    if (!$post_id) return false;
    
    $visibility = get_post_meta($post_id, '_bcc_visibility', true);
    if (!$visibility) $visibility = 'public';

    // Owner always sees
    if (bcc_user_is_owner($post_id)) {
        return true;
    }

    if ($visibility === 'public') {
        return true;
    }

    if ($visibility === 'members') {
        return is_user_logged_in();
    }

    // Private - only owner (already handled above)
    return false;
}

/**
 * ======================================================
 * POST-LEVEL EDIT PERMISSION
 * ======================================================
 */

function bcc_user_can_edit_post($post_id) {
    return bcc_user_is_owner($post_id);
}

/**
 * ======================================================
 * OWNER CHECK (centralized)
 * ======================================================
 */

function bcc_user_is_owner($post_id) {
    if (!is_user_logged_in() || !$post_id) return false;

    // Delegate to the centralised Permissions class (bcc-core) which
    // resolves ownership via PageOwnerResolver → PeepSo tables → WP post author.
    if (class_exists('\\BCC\\Core\\Permissions\\Permissions')) {
        return \BCC\Core\Permissions\Permissions::owns_page((int) $post_id, get_current_user_id());
    }

    // Fail closed when bcc-core is not active — post_author may be an
    // admin who created the page on behalf of the owner, not the owner
    // themselves. Granting access based on post_author is incorrect.
    return false;
}