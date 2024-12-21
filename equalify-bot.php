<?php
/**
 * Plugin Name: Alt Text Bot by Equalify
 * Plugin URI: https://equalify.com
 * Description: Ensures all images in posts have appropriate alt text and provides feedback to authors via comments.
 * Version: 1.2
 * Author: Equalify
 * Author URI: https://equalify.com
 */

// Hook into the 'wp_insert_post' action to process the post after it's published
add_action('publish_post', 'equalify_alt_text_bot_check_images');

/**
 * Main function to check images for missing or empty alt text.
 *
 * @param int $post_id The ID of the post being published.
 */
function equalify_alt_text_bot_check_images($post_id) {
    $equalify_user_id = equalify_get_or_create_user();

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    // Initialize issue arrays
    $missing_alt = [];
    $empty_alt = [];
    $aria_issues = [];

    // Parse the post content
    preg_match_all('/<img[^>]+>|<svg[^>]*>.*?<\/svg>|<picture[^>]*>.*?<\/picture>/is', $post->post_content, $matches);

    foreach ($matches[0] as $media_tag) {
        if (preg_match('/^<img/i', $media_tag)) {
            // Handle <img> tags
            if (!preg_match('/\balt=["\'].*?["\']/i', $media_tag)) {
                $missing_alt[] = $media_tag;
            } else {
                preg_match('/alt=["\'](.*?)["\']/i', $media_tag, $alt_match);
                $alt_text = $alt_match[1];
                if (trim($alt_text) === '') {
                    $empty_alt[] = $media_tag;
                }
            }
        } elseif (preg_match('/<svg/i', $media_tag)) {
            // Handle <svg> tags
            if (!preg_match('/aria-label=["\'].*?["\']|role=["\']img["\']/i', $media_tag)) {
                $aria_issues[] = $media_tag;
            }
        } elseif (preg_match('/<picture/i', $media_tag)) {
            // Handle <picture> tags
            if (!preg_match('/<img[^>]+alt=["\'].*?["\']/i', $media_tag)) {
                $missing_alt[] = $media_tag;
            }
        }
    }

    // Check for existing comments from Equalify
    $existing_comments = get_comments([
        'post_id' => $post_id,
        'author_email' => 'support@equalify.com',
    ]);

    // If no issues remain, delete the comment
    if (empty($missing_alt) && empty($empty_alt) && empty($aria_issues)) {
        foreach ($existing_comments as $comment) {
            wp_delete_comment($comment->comment_ID, true);
        }
        return;
    }

    // Generate the comment content
    $comment_content = equalify_generate_comment_content($post->post_author, $missing_alt, $empty_alt, $aria_issues);

    // Update or add the comment
    if (!empty($existing_comments)) {
        foreach ($existing_comments as $comment) {
            wp_update_comment([
                'comment_ID' => $comment->comment_ID,
                'comment_content' => $comment_content,
            ]);
        }
    } else {
        wp_insert_comment([
            'comment_post_ID' => $post_id,
            'comment_author' => 'Equalify',
            'comment_author_email' => 'support@equalify.com',
            'comment_author_url' => 'https://equalify.com',
            'comment_content' => $comment_content,
            'comment_type' => '',
            'comment_parent' => 0,
            'user_id' => $equalify_user_id,
            'comment_approved' => 1,
        ]);
    }
}

/**
 * Create or retrieve the Equalify user.
 *
 * @return int User ID of the Equalify bot.
 */
function equalify_get_or_create_user() {
    $user = get_user_by('login', 'Equalify');

    if ($user) {
        return $user->ID;
    }

    return wp_insert_user([
        'user_login' => 'Equalify',
        'user_pass' => wp_generate_password(),
        'user_email' => 'support@equalify.com',
        'role' => 'author',
        'display_name' => 'Equalify',
    ]);
}

/**
 * Generate a comment based on the identified issues.
 *
 * @param int $author_id The ID of the post author.
 * @param array $missing_alt List of tags missing alt attributes.
 * @param array $empty_alt List of tags with empty alt attributes.
 * @param array $aria_issues List of tags with missing ARIA attributes.
 *
 * @return string The comment content.
 */
function equalify_generate_comment_content($author_id, $missing_alt, $empty_alt, $aria_issues) {
    $author = get_userdata($author_id);
    $author_name = $author ? $author->display_name : 'Author';

    $intro_message = get_user_meta($author_id, 'alt_text_bot_intro_sent', true)
        ? ''
        : equalify_get_random_intro_message($author_name);

    if (!$intro_message) {
        update_user_meta($author_id, 'alt_text_bot_intro_sent', true);
    }

    $details = '<ul>';
    if (!empty($missing_alt)) {
        $details .= '<li>' . equalify_get_random_missing_alt_message() . '</li>';
    }
    if (!empty($empty_alt)) {
        $details .= '<li>' . equalify_get_random_empty_alt_message() . '</li>';
    }
    if (!empty($aria_issues)) {
        $details .= '<li>' . equalify_get_random_aria_message() . '</li>';
    }
    $details .= '</ul>';

    $closing_message = equalify_get_random_closing_message();

    return "<p>{$intro_message}</p>{$details}<p>{$closing_message}</p>";
}

/**
 * Get a random introductory message.
 */
function equalify_get_random_intro_message($author_name) {
    $messages = [
        "Hi $author_name, here's a friendly note on improving accessibility!",
        "$author_name, just a quick accessibility tip for you!",
        "Hello $author_name! Let’s take a moment to check accessibility in your post.",
    ];
    return $messages[array_rand($messages)];
}

/**
 * Get a random missing alt message.
 */
function equalify_get_random_missing_alt_message() {
    $messages = [
        "Some images are missing alt text. Descriptive alt text helps screen reader users understand your images.",
        "There are images in your post without alt text. Adding alt text makes your content more accessible.",
        "Alt text is missing for some images. Consider adding it to enhance accessibility for all readers.",
    ];
    return $messages[array_rand($messages)];
}

/**
 * Get a random empty alt message.
 */
function equalify_get_random_empty_alt_message() {
    $messages = [
        "Some images have empty alt text. If they're decorative, that's fine; otherwise, add a description.",
        "You have images with empty alt text. Ensure they're truly decorative or consider describing them.",
        "Empty alt text is detected in some images. If they aren't decorative, add descriptions for accessibility.",
    ];
    return $messages[array_rand($messages)];
}

/**
 * Get a random ARIA issue message.
 */
function equalify_get_random_aria_message() {
    $messages = [
        "Some media elements are missing ARIA roles or labels. Adding them improves accessibility.",
        "Ensure media elements like SVGs and images have appropriate ARIA attributes (e.g., aria-label or role='img').",
        "ARIA attributes are missing in some media. Consider adding descriptive labels for better accessibility.",
    ];
    return $messages[array_rand($messages)];
}

/**
 * Get a random closing message.
 */
function equalify_get_random_closing_message() {
    $messages = [
        "Thanks for working on making the web a better place for everyone!",
        "Appreciate your effort to make content accessible for all readers!",
        "Thanks for your dedication to improving web accessibility!",
    ];
    return $messages[array_rand($messages)];
}