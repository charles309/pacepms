<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * PHPMailer wrapper with HTML templates. Sending failures never crash the
 * request — they are caught, logged, and the method returns false.
 */
final class EmailHelper
{
    private function __construct()
    {
    }

    /**
     * Send an HTML email. Returns true on success, false on failure.
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        if (!ValidationHelper::validateEmail($toEmail)) {
            Logger::warning("EmailHelper: invalid recipient '{$toEmail}'");
            return false;
        }

        if (!class_exists(PHPMailer::class)) {
            Logger::error('EmailHelper: PHPMailer not installed; email not sent to ' . $toEmail);
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = SMTP_USER !== '';
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            return $mail->send();
        } catch (PHPMailerException | \Throwable $e) {
            Logger::error('EmailHelper send failed to ' . $toEmail . ': ' . $e->getMessage());
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------------

    private static function wrap(string $title, string $bodyHtml): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$safeTitle}</title></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#222;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="background:#0d47a1;padding:20px 32px;color:#ffffff;font-size:20px;font-weight:bold;">
          Property Management System
        </td></tr>
        <tr><td style="padding:32px;">
          <h2 style="margin:0 0 16px;font-size:18px;color:#0d47a1;">{$safeTitle}</h2>
          {$bodyHtml}
        </td></tr>
        <tr><td style="padding:20px 32px;background:#fafafa;color:#888;font-size:12px;">
          This is an automated message from the PMS platform. Please do not reply directly to this email.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function welcome(string $toEmail, string $name, string $tempPassword, string $role): bool
    {
        $body = '<p>Hello ' . self::esc($name) . ',</p>'
            . '<p>Your <strong>' . self::esc($role) . '</strong> account on the PMS platform has been created.</p>'
            . '<p>Your temporary password is:</p>'
            . '<p style="font-size:18px;font-weight:bold;background:#eef;padding:12px;border-radius:6px;">'
            . self::esc($tempPassword) . '</p>'
            . '<p>Please log in and change it as soon as possible.</p>';
        return self::send($toEmail, $name, 'Welcome to PMS', self::wrap('Account Created', $body));
    }

    public static function rentReceipt(
        string $toEmail,
        string $name,
        float $amount,
        string $receipt,
        string $unitId,
        string $paidAt
    ): bool {
        $body = '<p>Dear ' . self::esc($name) . ',</p>'
            . '<p>We have received your rent payment. Details below:</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;">'
            . '<tr><td><strong>Unit</strong></td><td>' . self::esc($unitId) . '</td></tr>'
            . '<tr><td><strong>Amount</strong></td><td>KES ' . number_format($amount, 2) . '</td></tr>'
            . '<tr><td><strong>M-Pesa Receipt</strong></td><td>' . self::esc($receipt) . '</td></tr>'
            . '<tr><td><strong>Date</strong></td><td>' . self::esc($paidAt) . '</td></tr>'
            . '</table>'
            . '<p>Thank you for your payment.</p>';
        return self::send($toEmail, $name, 'Rent Payment Receipt', self::wrap('Payment Received', $body));
    }

    public static function complaintReceived(string $toEmail, string $name, string $subject): bool
    {
        $body = '<p>Hello ' . self::esc($name) . ',</p>'
            . '<p>A new complaint has been submitted by your tenant:</p>'
            . '<p style="background:#fff8e1;padding:12px;border-radius:6px;"><strong>'
            . self::esc($subject) . '</strong></p>'
            . '<p>Please log in to your dashboard to review and respond.</p>';
        return self::send($toEmail, $name, 'New Tenant Complaint', self::wrap('Complaint Received', $body));
    }

    public static function complaintReply(string $toEmail, string $name, string $subject, string $reply): bool
    {
        $body = '<p>Dear ' . self::esc($name) . ',</p>'
            . '<p>Your complaint "<strong>' . self::esc($subject) . '</strong>" has an update:</p>'
            . '<p style="background:#e8f5e9;padding:12px;border-radius:6px;">' . nl2br(self::esc($reply)) . '</p>';
        return self::send($toEmail, $name, 'Update on your complaint', self::wrap('Complaint Reply', $body));
    }

    public static function defaulterAlert(
        string $toEmail,
        string $name,
        string $defaulterName,
        string $nationalId,
        float $amountOwed
    ): bool {
        $body = '<p>Dear ' . self::esc($name) . ',</p>'
            . '<p style="color:#b71c1c;"><strong>DEFAULTER ALERT</strong></p>'
            . '<p>A tenant has been flagged as a defaulter on the PMS platform:</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;">'
            . '<tr><td><strong>Name</strong></td><td>' . self::esc($defaulterName) . '</td></tr>'
            . '<tr><td><strong>National ID</strong></td><td>' . self::esc($nationalId) . '</td></tr>'
            . '<tr><td><strong>Amount Owed</strong></td><td>KES ' . number_format($amountOwed, 2) . '</td></tr>'
            . '</table>'
            . '<p>Exercise caution before renting to this individual.</p>';
        return self::send($toEmail, $name, 'PMS Defaulter Alert', self::wrap('Defaulter Alert', $body));
    }

    public static function rentReminder(
        string $toEmail,
        string $name,
        float $amount,
        string $unitId,
        string $dueInfo
    ): bool {
        $body = '<p>Dear ' . self::esc($name) . ',</p>'
            . '<p>This is a friendly reminder that your rent of <strong>KES '
            . number_format($amount, 2) . '</strong> for unit ' . self::esc($unitId)
            . ' is due ' . self::esc($dueInfo) . '.</p>'
            . '<p>Kindly make your payment on time to avoid penalties.</p>';
        return self::send($toEmail, $name, 'Rent Reminder', self::wrap('Rent Reminder', $body));
    }

    public static function withdrawalApproved(
        string $toEmail,
        string $name,
        float $amountDisbursed,
        float $serviceFee
    ): bool {
        $body = '<p>Dear ' . self::esc($name) . ',</p>'
            . '<p>Your withdrawal request has been <strong>approved</strong>.</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;">'
            . '<tr><td><strong>Amount Disbursed</strong></td><td>KES ' . number_format($amountDisbursed, 2) . '</td></tr>'
            . '<tr><td><strong>Service Fee Retained</strong></td><td>KES ' . number_format($serviceFee, 2) . '</td></tr>'
            . '</table>'
            . '<p>The funds are being processed to your bank account.</p>';
        return self::send($toEmail, $name, 'Withdrawal Approved', self::wrap('Withdrawal Approved', $body));
    }

    /**
     * Generic admin -> tenant message email.
     */
    public static function genericMessage(string $toEmail, string $name, string $subject, string $body): bool
    {
        $html = '<p>Dear ' . self::esc($name) . ',</p>'
            . '<div>' . nl2br(self::esc($body)) . '</div>';
        return self::send($toEmail, $name, $subject, self::wrap($subject, $html));
    }
}
