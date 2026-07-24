<?php
/**
 * AB Payment Router — OpenCart Language (English)
 */
// Heading
$_['heading_title'] = 'AB Payment Router';

// Text
$_['text_extension']  = 'Extensions';
$_['text_success']    = 'Success: AB Payment Router settings saved.';
$_['text_edit']       = 'Edit AB Payment Router';
$_['text_enabled']    = 'Enabled';
$_['text_disabled']   = 'Disabled';

// Entry
$_['entry_controller_url']  = 'Controller URL';
$_['entry_secret']          = 'Shared Secret';
$_['entry_gateway']         = 'Payment Gateway';
$_['entry_product_id']      = 'Fallback Product ID';
$_['entry_status']          = 'Status';
$_['entry_sort_order']      = 'Sort Order';

// Help
$_['help_controller_url'] = 'PaymentRouter central controller URL (without trailing slash).';
$_['help_secret']         = 'Must match APP_SECRET on the controller. Used for JWT verification and webhook signing.';
$_['help_gateway']        = 'Real payment gateway to redirect customers to (PayPal or Stripe).';
$_['help_product_id']     = 'Product ID to use when creating the order (for display purposes on the B-site).';

// Error
$_['error_permission']     = 'Warning: You do not have permission to modify AB Payment Router.';
$_['error_controller_url'] = 'Controller URL is required.';
$_['error_invalid_token']  = 'Invalid or expired payment token. Please try again.';
$_['error_order_create']   = 'Failed to create order. Please contact support.';
$_['error_payment_failed'] = 'Payment was not completed. Please try again.';

// Placeholder
$_['placeholder_controller_url'] = 'https://payment-controller.example.com';
