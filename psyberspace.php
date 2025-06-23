<?php
/*
Plugin Name: Psyberspace Lead Capture
Description: Capture emails via REST API and subscribe them to a Mailchimp list and send confirmation email with PDF.
Version: 1.1
Author: Mufaqar
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_action('rest_api_init', function () {
    register_rest_route('psyberspace/v1', '/subscribe', [
        'methods' => 'POST',
        'callback' => 'psyberspace_handle_email_subscription',
        'permission_callback' => '__return_true',
    ]);
});

function psyberspace_handle_email_subscription($request) {
    $email = sanitize_email($request->get_param('email'));

    if (!is_email($email)) {
        return new WP_REST_Response(['message' => 'Invalid email'], 400);
    }

    $result = psyberspace_add_to_mailchimp($email);

    if ($result === true) {
        // Send confirmation email with PDF
        psyberspace_send_pdf_email($email);
        return new WP_REST_Response(['message' => 'Email added to Mailchimp and confirmation sent'], 200);
    } else {
        return new WP_REST_Response(['message' => 'Mailchimp error: ' . $result], 500);
    }
}

function psyberspace_add_to_mailchimp($email) {
    $api_key = '831d41c8ac0b3a81e3584e8b38b2fead-us22';
    $list_id = '9ba9f6b7b3';
    $data_center = substr($api_key, strpos($api_key, '-') + 1);
    $subscriber_hash = md5(strtolower($email));
    $url = "https://{$data_center}.api.mailchimp.com/3.0/lists/{$list_id}/members/{$subscriber_hash}";

    $body = json_encode([
        'email_address' => $email,
        'status_if_new' => 'pending'  // will send double opt-in email
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
    if ($code == 200) {
        return true;
    } else {
        $body = json_decode(wp_remote_retrieve_body($response));
        return $body->detail ?? 'Unknown error';
    }
}

function psyberspace_send_pdf_email($to_email) {
    $subject = 'Thanks for subscribing – Here’s your PDF';
    $message = 'Hi there,<br><br>Thank you for subscribing! Please find your PDF attached.<br><br>Best regards,<br>Psyberspace';
    $headers = ['Content-Type: text/html; charset=UTF-8'];


    $pdf_path = 'https://stage.psyberspaceconsult.com/wp-content/uploads/2025/06/file-sample_150kB.pdf'; // Full path
    $attachments = file_exists($pdf_path) ? [$pdf_path] : [];

    wp_mail($to_email, $subject, $message, $headers, $attachments);
}

add_action('wpcf7_mail_sent', 'cf7_call_psyberspace_api');

function cf7_call_psyberspace_api($contact_form) {
    // Only trigger for specific form (optional)
    $form_id = $contact_form->id();
    if ($form_id != "9a246bd") { // Replace 456 with your actual CF7 form ID
        return;
    }

    // Get form submission data
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) {
        return;
    }

    $data = $submission->get_posted_data();

    // Replace 'your-email' with the actual name of your email field
    $email = isset($data['your-email']) ? sanitize_email($data['your-email']) : '';

    if (!is_email($email)) {
        return;
    }

    // Call your custom REST API
    $response = wp_remote_post(home_url('/wp-json/psyberspace/v1/subscribe'), [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['email' => $email]),
        'timeout' => 15,
    ]);

    // Optional: Log error
    if (is_wp_error($response)) {
        error_log('Contact Form 7 API error: ' . $response->get_error_message());
    }
}
