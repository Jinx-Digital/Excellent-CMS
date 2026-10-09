<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Plain text mails of the CMS (password reset, new e-mail address) through MAILER_DSN
 * (smtp://user:pass@host:587, sendmail://default ...). Without a DSN the mails are written to a
 * log file (runtime/logs/mail.log) - enough for local development, and the tests read the links
 * from there.
 */
final class Mailer
{
  public function __construct(
    private string $dsn,
    private string $from,
    private string $fromName,
    private string $logFile,
  ) {
  }

  /**
   * @throws MailException if the mail could not be handed to the mail server
   */
  public function send(string $to, string $subject, string $text): void
  {
    if ('' === trim($this->dsn)) {
      $this->log($to, $subject, $text);
      return;
    }
    $email = (new Email())
      ->from(new Address($this->from, $this->fromName))
      ->to($to)
      ->subject($subject)
      ->text($text);
    try {
      Transport::fromDsn($this->dsn)->send($email);
    } catch (TransportExceptionInterface $e) {
      throw new MailException('The mail could not be sent: '.$e->getMessage(), 0, $e);
    }
  }

  private function log(string $to, string $subject, string $text): void
  {
    if (!is_dir(dirname($this->logFile))) {
      mkdir(dirname($this->logFile), 0775, true);
    }
    file_put_contents($this->logFile, sprintf("[%s] To: %s\nSubject: %s\n\n%s\n\n", date('Y-m-d H:i:s'), $to, $subject, $text), FILE_APPEND | LOCK_EX);
  }
}
