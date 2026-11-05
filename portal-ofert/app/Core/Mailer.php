<?php

declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * E-maile z szablonów views/emails/*.php.
 *   Mailer::queue('jan@firma.pl', 'verify-email', ['url' => …]);   // przez kolejkę (zalecane)
 * Sterownik w config: mail.driver = smtp (produkcja) | log (lokalnie/testy — treść trafia do logu i Mailer::$sent).
 */
final class Mailer
{
    /** Wiadomości „wysłane” sterownikiem log — do testów. */
    public static array $sent = [];

    public static function queue(string $to, string $template, array $data = []): int
    {
        return Queue::push('mail', ['to' => $to, 'template' => $template, 'data' => $data]);
    }

    /** Renderuje i wysyła od razu (wołane przez worker dla zadania „mail”). */
    public static function send(string $to, string $template, array $data = []): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Niepoprawny adres odbiorcy: {$to}");
        }
        $vars = $data + ['portalName' => (string) Settings::get('portal.name', __('app.default_name'))];
        $html = View::render('emails/' . $template, $vars, 'emails/layout');
        $subject = trim((string) (self::extractSubject($html) ?? Settings::get('portal.name', '')));
        $html = (string) preg_replace('/<!--subject:.*?-->/s', '', $html);
        $text = self::toText($html);

        if (Config::get('mail.driver', 'smtp') === 'log') {
            self::$sent[] = compact('to', 'subject', 'html', 'text', 'template', 'data');
            Logger::info('E-mail (sterownik log)', ['to' => $to, 'subject' => $subject, 'text' => mb_substr($text, 0, 2000)]);
            return;
        }

        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) Config::get('mail.host');
        $m->Port = (int) Config::get('mail.port', 465);
        $m->SMTPAuth = true;
        $m->Username = (string) Config::get('mail.user');
        $m->Password = (string) Config::get('mail.pass');
        $m->SMTPSecure = Config::get('mail.encryption', 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $m->Timeout = 15;
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->setFrom((string) Config::get('mail.from_email'), (string) Config::get('mail.from_name', $vars['portalName']));
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send(); // wyjątek = zadanie wraca do kolejki z opóźnieniem
    }

    /** Temat ustawiany w szablonie komentarzem <!--subject:Treść tematu--> */
    private static function extractSubject(string $html): ?string
    {
        return preg_match('/<!--subject:(.*?)-->/s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }

    private static function toText(string $html): string
    {
        $text = (string) preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $text = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/tr)\s*/?>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
