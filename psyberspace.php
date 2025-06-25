<?php
/*
Plugin Name: Psyberspace Lead Capture
Description: Capture emails via REST API and subscribe them to a Mailchimp list and send confirmation email with PDF.
Version: 1.1.2
Author: Mufaqar
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Register REST route
add_action('rest_api_init', function () {
    register_rest_route('psyberspace/v1', '/subscribe', [
        'methods' => 'POST',
        'callback' => 'psyberspace_handle_email_subscription',
        'permission_callback' => '__return_true',
    ]);
});

// Main API handler
function psyberspace_handle_email_subscription($request) {
    $email = sanitize_email($request->get_param('email'));

    if (!is_email($email)) {
        return new WP_REST_Response(['message' => 'Invalid email'], 400);
    }

    $result = psyberspace_add_to_mailchimp($email);

    if ($result === true) {
        psyberspace_send_pdf_email($email);
        return new WP_REST_Response(['message' => 'Success! Please check your email for the PDF.'], 200);
    } elseif ($result === 'already_subscribed') {
        psyberspace_send_pdf_email($email);
        return new WP_REST_Response(['message' => 'You are already subscribed. We resent the PDF to your email.'], 200);
    } else {
        return new WP_REST_Response(['message' => 'Mailchimp error: ' . $result], 500);
    }
}

// Subscribe to Mailchimp list
function psyberspace_add_to_mailchimp($email) {
    $api_key = 'eb8274a9b38761cd09226246fad19dc9-us22';
    $list_id = '9ba9f6b7b3';
    $data_center = substr($api_key, strpos($api_key, '-') + 1);
    $subscriber_hash = md5(strtolower($email));
    $url = "https://{$data_center}.api.mailchimp.com/3.0/lists/{$list_id}/members/{$subscriber_hash}";

    $body = json_encode([
        'email_address' => $email,
        'status_if_new' => 'subscribed',
        'status' => 'subscribed'
    ]);

    $response = wp_remote_request($url, [
        'method'  => 'PUT',
        'headers' => [
            'Authorization' => 'apikey ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => $body,
    ]);

    if (is_wp_error($response)) {
        return $response->get_error_message();
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response));

    if ($code === 200) {
        return true;
    }

    if (isset($body->title) && $body->title === 'Member Exists') {
        return 'already_subscribed';
    }

    return $body->detail ?? 'Unknown error';
}

// Send email with PDF link
function psyberspace_send_pdf_email($to_email) {
    $subject = 'Thanks for subscribing – Here’s your PDF';

    $pod = pods('psyberspace_settings');
    $pdf_url = $pod->field('pdf_link');

    if (empty($pdf_url)) {
        error_log('PDF link not set in Pods settings.');
        return;
    }

    $pdf_url = 'http://demo.mufaqar.com/wp-content/uploads/2025/06/file-sample_150kB.pdf';

    $message = 'Hi there,<br><br>';
    $message .= 'Thank you for subscribing! You can download your PDF using the link below:<br><br>';
    $message .= '<a href="' . esc_url($pdf_url) . '" target="_blank">Download PDF</a><br><br>';
    $message .= 'Best regards,<br>Psyberspace';

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    wp_mail($to_email, $subject, $message, $headers);
}


add_action('wpcf7_mail_sent', 'cf7_call_psyberspace_api');

function cf7_call_psyberspace_api($contact_form) {
    $form_id = $contact_form->id();
    

    // Fetch dynamic ID from Pods options
    $target_form_id = pods('psyberspace_settings')->field('cf7_form_id');

    if ((int)$form_id !== (int)$target_form_id) {
        error_log("Form ID {$form_id} does not match target ID {$target_form_id}. Skipping API call.");
        return;
    }

    $submission = WPCF7_Submission::get_instance();
    if (!$submission) {
        error_log("No submission instance found.");
        return;
    }

    $data = $submission->get_posted_data();
    $email = isset($data['your-email']) ? sanitize_email($data['your-email']) : '';

    error_log("Retrieved email: $email");

    if (!is_email($email)) {
        error_log("Invalid email address.");
        return;
    }

    $response = wp_remote_post(home_url('/wp-json/psyberspace/v1/subscribe'), [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['email' => $email]),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        error_log('Contact Form 7 API error: ' . $response->get_error_message());
    } else {
        error_log('Contact Form 7 API call successful. Response: ' . wp_remote_retrieve_body($response));
    }
}
