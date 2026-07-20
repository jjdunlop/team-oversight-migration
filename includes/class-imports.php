<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Imports {
    
    public function __construct() {
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
    
}