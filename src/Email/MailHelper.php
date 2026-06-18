<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Email;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Wraps Email::send() with retry, throttling and logging.
 *
 * Shared SMTP relays (e.g. 123-reg) drop connections without warning when they
 * rate-limit a sender or time out an idle connection, surfacing as a
 * TransportException ("Connection ... has been closed unexpectedly"). Symfony
 * Mailer discards the dead connection on failure, so a retry opens a fresh one
 * and normally succeeds.
 *
 * Never throws: returns true when the message was accepted by the SMTP server,
 * false otherwise. Failures are logged together with $context.
 */
class MailHelper
{
    /** Total delivery attempts per message. */
    private const MAX_ATTEMPTS = 3;

    /**
     * Minimum gap between sends in microseconds, so recipient loops stay under
     * the relay's per-connection message limits. Tunable for bulk blasts via
     * setSendGap().
     */
    private static int $sendGapUsec = 250000;

    /** @var float|null When the last attempt started, for throttling. */
    private static ?float $lastAttemptAt = null;

    public static function setSendGap(int $microseconds): void
    {
        self::$sendGapUsec = max(0, $microseconds);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function send(Email $email, array $context = [], bool $plain = false): bool
    {
        $logger = Injector::inst()->get(LoggerInterface::class);

        $to = implode(', ', array_map(
            static fn ($address) => $address->getAddress(),
            $email->getTo()
        ));
        $context += ['To' => $to, 'Subject' => $email->getSubject()];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            self::throttle();

            try {
                $plain ? $email->sendPlain() : $email->send();
                return true;
            } catch (TransportExceptionInterface $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    $logger->error(
                        'Email to ' . $to . ' failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $e->getMessage(),
                        $context + ['ExceptionClass' => $e::class]
                    );
                    return false;
                }

                $logger->warning(
                    'Email send attempt ' . $attempt . ' to ' . $to . ' failed, retrying: ' . $e->getMessage(),
                    $context
                );
                // Give the server a moment before reconnecting.
                sleep($attempt);
            } catch (\Throwable $e) {
                // Not a transport problem (bad address, missing template, ...)
                // so a retry cannot help.
                $logger->error(
                    'Email to ' . $to . ' failed: ' . $e->getMessage(),
                    $context + ['ExceptionClass' => $e::class]
                );
                return false;
            }
        }

        return false;
    }

    private static function throttle(): void
    {
        if (self::$lastAttemptAt !== null) {
            $elapsedUsec = (microtime(true) - self::$lastAttemptAt) * 1000000;
            if ($elapsedUsec < self::$sendGapUsec) {
                usleep((int) (self::$sendGapUsec - $elapsedUsec));
            }
        }

        self::$lastAttemptAt = microtime(true);
    }
}
