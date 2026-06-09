<?php
/**
 * Mail Helper - sends emails via SMTP using PHPMailer
 * 
 * Uses the MAIL_* constants defined in config.php
 */

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email using SMTP settings from config.php
 *
 * @param string $toEmail   Recipient email address
 * @param string $toName    Recipient display name
 * @param string $subject   Email subject line
 * @param string $body      Plain-text email body
 * @return bool             True on success, false on failure
 */
function send_mail($toEmail, $toName, $subject, $body) {
    return _send($toEmail, $toName, $subject, $body, false);
}

/**
 * Send an HTML email using SMTP settings from config.php
 *
 * @param string $toEmail   Recipient email address
 * @param string $toName    Recipient display name
 * @param string $subject   Email subject line
 * @param string $htmlBody  HTML email body
 * @return bool             True on success, false on failure
 */
function send_html_mail($toEmail, $toName, $subject, $htmlBody) {
    return _send($toEmail, $toName, $subject, $htmlBody, true);
}

/**
 * Send a welcome email to a newly registered user.
 *
 * @param string $toEmail    Recipient email address
 * @param string $firstName  User's first name
 * @return bool              True on success, false on failure
 */
function send_welcome_email($toEmail, $firstName) {
    $templatePath = __DIR__ . '/template/welcome.html';
    if (!file_exists($templatePath)) {
        error_log('Welcome email template not found: ' . $templatePath);
        return false;
    }
    $html = file_get_contents($templatePath);
    $html = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName), $html);
    $html = str_replace('{{APP_URL}}', APP_URL, $html);
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = (MAIL_ENCRYPTION === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $firstName);
        $mail->addBCC(MAIN_ADMIN);

        $mail->isHTML(true);
        $mail->Subject = 'Welcome to CYOA Maker!';
        $mail->Body    = $html;

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('PHPMailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Internal: send an email via SMTP.
 */
function _send($toEmail, $toName, $subject, $body, $isHTML) {
    $mail = new PHPMailer(true);

    try {
        // SMTP settings from config.php constants
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = (MAIL_ENCRYPTION === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        // Sender & recipient
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML($isHTML);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging (visible in PHP error log)
        error_log('PHPMailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}
?>
