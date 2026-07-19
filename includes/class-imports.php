<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Imports {
    
    public function __construct() {
        add_action('wp_ajax_import_revsport_csv', array($this, 'handle_revsport_import'));
        add_action('wp_ajax_import_xero_payments', array($this, 'handle_xero_payments_import'));
    }
    
    public function render_import_page() {
        if (isset($_POST['action'])) {
            $this->handle_import_action();
        }
        
        ?>
        <div class="wrap">
            <h1>Import Data</h1>
            
            <div class="import-export-section">
                <h3>Revolutionise Sport Data Import</h3>
                <p>Import accreditation data from RevSport CSV export.</p>
                
                <form method="post" enctype="multipart/form-data">
                    <div class="file-upload-area">
                        <p><strong>Upload RevSport CSV File</strong></p>
                        <input type="file" name="revsport_csv" accept=".csv" required>
                        <p class="description">Expected columns: VA ID, First name, Last name, Date of birth, Gender identity, Mobile phone, Email address, Payment status, Payment date, VA Coach, VA Referee</p>
                        <p class="description"><strong>Enhanced:</strong> Now imports payment status and full accreditation details including expiry dates.</p>
                    </div>
                    
                    <p>
                        <input type="submit" class="button button-primary" value="Import RevSport Data">
                        <input type="hidden" name="action" value="import_revsport">
                        <?php wp_nonce_field('import_revsport', 'import_nonce'); ?>
                    </p>
                </form>
            </div>
            
            <div class="import-export-section">
                <h3>Xero Payment Data Import</h3>
                <p>Import payment data from Xero to update outstanding balances.</p>
                
                <form method="post" enctype="multipart/form-data">
                    <div class="file-upload-area">
                        <p><strong>Upload Xero Payment CSV File</strong></p>
                        <input type="file" name="xero_csv" accept=".csv" required>
                        <p class="description">Expected columns: ContactName, EmailAddress, InvoiceNumber, Status, InvoiceAmountDue (Xero export format)</p>
                    </div>
                    
                    <p>
                        <input type="submit" class="button button-primary" value="Import Xero Payments">
                        <input type="hidden" name="action" value="import_xero_payments">
                        <?php wp_nonce_field('import_xero_payments', 'import_nonce'); ?>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function handle_import_action() {
        if (!wp_verify_nonce($_POST['import_nonce'], $_POST['action'])) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        
        switch ($_POST['action']) {
            case 'import_revsport':
                $this->import_revsport_csv();
                break;
            case 'import_xero_payments':
                $this->import_xero_payments();
                break;
        }
    }
    
    private function import_revsport_csv() {
        if (!isset($_FILES['revsport_csv']) || $_FILES['revsport_csv']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>Failed to upload file.</p></div>';
            return;
        }
        
        $file_path = $_FILES['revsport_csv']['tmp_name'];
        $handle = fopen($file_path, 'r');
        
        if ($handle === FALSE) {
            echo '<div class="notice notice-error"><p>Failed to read CSV file.</p></div>';
            return;
        }
        
        global $wpdb;
        
        $header = fgetcsv($handle);
        $imported_count = 0;
        $updated_count = 0;
        $errors = array();
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) < 11) {
                continue;
            }
            
            $vvid = sanitize_text_field($data[0]);
            $first_name = sanitize_text_field($data[1]);
            $last_name = sanitize_text_field($data[2]);
            $email = sanitize_email($data[6]);
            $payment_status = sanitize_text_field($data[7]);
            $payment_date = sanitize_text_field($data[8]);
            $va_coach = sanitize_text_field($data[9]);
            $va_referee = sanitize_text_field($data[10]);
            
            if (empty($email) || !is_email($email)) {
                $errors[] = "Invalid email for {$first_name} {$last_name}";
                continue;
            }
            
            // Build comprehensive accreditation information
            $accreditations = array();
            if (!empty($payment_status)) {
                $accreditations[] = "Payment: " . $payment_status;
            }
            if (!empty($va_coach)) {
                $accreditations[] = "VA Coach: " . $va_coach;
            }
            if (!empty($va_referee)) {
                $accreditations[] = "VA Referee: " . $va_referee;
            }
            
            $accreditation_list = implode(', ', $accreditations);
            
            // Parse payment date for database storage
            $payment_date_formatted = null;
            if (!empty($payment_date)) {
                $parsed_date = strtotime($payment_date);
                if ($parsed_date !== false) {
                    $payment_date_formatted = date('Y-m-d', $parsed_date);
                }
            }
            
            $existing = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_accreditations WHERE email = %s
            ", $email));
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'team_accreditations',
                    array(
                        'vvid' => $vvid,
                        'accreditation_list' => $accreditation_list,
                        'payment_status' => $payment_status,
                        'payment_date' => $payment_date_formatted
                    ),
                    array('email' => $email),
                    array('%s', '%s', '%s', '%s'),
                    array('%s')
                );
                $updated_count++;
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'team_accreditations',
                    array(
                        'email' => $email,
                        'vvid' => $vvid,
                        'accreditation_list' => $accreditation_list,
                        'payment_status' => $payment_status,
                        'payment_date' => $payment_date_formatted
                    ),
                    array('%s', '%s', '%s', '%s', '%s')
                );
                $imported_count++;
            }
        }
        
        fclose($handle);
        
        echo '<div class="notice notice-success"><p>';
        echo "RevSport import completed: {$imported_count} new records, {$updated_count} updated records.";
        if (!empty($errors)) {
            echo '<br>Errors: ' . implode(', ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                echo ' and ' . (count($errors) - 5) . ' more...';
            }
        }
        echo '</p></div>';
    }
    
    private function import_xero_payments() {
        if (!isset($_FILES['xero_csv']) || $_FILES['xero_csv']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>Failed to upload file.</p></div>';
            return;
        }
        
        $file_path = $_FILES['xero_csv']['tmp_name'];
        $handle = fopen($file_path, 'r');
        
        if ($handle === FALSE) {
            echo '<div class="notice notice-error"><p>Failed to read CSV file.</p></div>';
            return;
        }
        
        global $wpdb;
        
        $header = fgetcsv($handle);
        $processed_count = 0;
        $updated_count = 0;
        $skipped_count = 0;
        $updated_invoices = array();
        
        // Find column indices
        $contact_name_index = array_search('ContactName', $header);
        $email_index = array_search('EmailAddress', $header);
        $invoice_number_index = array_search('InvoiceNumber', $header);
        $status_index = array_search('Status', $header);
        $amount_due_index = array_search('InvoiceAmountDue', $header);
        
        if ($contact_name_index === false || $email_index === false || $invoice_number_index === false || 
            $status_index === false || $amount_due_index === false) {
            echo '<div class="notice notice-error"><p>Required columns not found. Expected: ContactName, EmailAddress, InvoiceNumber, Status, InvoiceAmountDue</p></div>';
            fclose($handle);
            return;
        }
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) < max($contact_name_index, $email_index, $invoice_number_index, $status_index, $amount_due_index) + 1) {
                continue;
            }
            
            $contact_name = sanitize_text_field($data[$contact_name_index]);
            $email = sanitize_email($data[$email_index]);
            $invoice_number = sanitize_text_field($data[$invoice_number_index]);
            $status = sanitize_text_field($data[$status_index]);
            $amount_due = floatval($data[$amount_due_index]);
            
            $processed_count++;
            
            // Only process VVL invoice numbers - skip others silently
            if (!preg_match('/^VVL-\d{4}-\d+$/', $invoice_number)) {
                $skipped_count++;
                continue;
            }
            
            // Extract season and invoice ID from VVL format
            if (preg_match('/^VVL-(\d{4})-(\d+)$/', $invoice_number, $matches)) {
                $season = $matches[1];
                $invoice_id = intval($matches[2]) - 5023 + 1; // Reverse the calculation
                
                // Find matching invoice by ID, season, and email
                $invoice = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$wpdb->prefix}team_invoices 
                    WHERE id = %d AND season = %s AND email = %s
                ", $invoice_id, $season, $email));
                
                if ($invoice) {
                    // Calculate GST-free amount
                    $gst_free_amount = round($amount_due / 1.1, 2);
                    
                    // Only update if the amount is different
                    if (abs($invoice->outstanding_amount - $gst_free_amount) > 0.01) { // Allow for small rounding differences
                        $wpdb->update(
                            $wpdb->prefix . 'team_invoices',
                            array('outstanding_amount' => $gst_free_amount),
                            array('id' => $invoice->id),
                            array('%f'),
                            array('%d')
                        );
                        
                        $updated_invoices[] = array(
                            'contact_name' => $contact_name,
                            'email' => $email,
                            'invoice_number' => $invoice_number,
                            'old_amount' => $invoice->outstanding_amount,
                            'new_amount' => $gst_free_amount
                        );
                        
                        $updated_count++;
                    }
                }
            }
        }
        
        fclose($handle);
        
        // Secure deletion of uploaded file
        unlink($file_path);
        
        echo '<div class="notice notice-success"><p>';
        echo "Xero payment import completed: {$processed_count} rows processed, {$updated_count} invoices updated, {$skipped_count} non-system invoices skipped.";
        
        if (!empty($updated_invoices)) {
            echo '<br><strong>Updated invoices:</strong><br>';
            foreach (array_slice($updated_invoices, 0, 10) as $update) {
                echo "• {$update['invoice_number']} ({$update['contact_name']}) - Outstanding: \${$update['old_amount']} → \${$update['new_amount']}<br>";
            }
            if (count($updated_invoices) > 10) {
                echo "... and " . (count($updated_invoices) - 10) . " more";
            }
        }
        
        echo '</p></div>';
    }
    
    public function handle_revsport_import() {
        // AJAX handler for RevSport import
        $this->import_revsport_csv();
        wp_die();
    }
    
    public function handle_xero_payments_import() {
        // AJAX handler for Xero payments import
        $this->import_xero_payments();
        wp_die();
    }
}