<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Auth\OtpException;
use App\Auth\OtpService;
use App\Auth\Tokens;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Queue;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Integrations\GetResponse;
use App\Integrations\MfVatWhitelist;

/**
 * Rejestracja: formularz → kod na telefon (WhatsApp, zapasowo SMS) → konto + logowanie
 * → e-mail z linkiem potwierdzającym. Reguły (NIP, osoby prywatne, typy kont, SMS) z presetu.
 * Dane do czasu wpisania kodu są tylko w sesji — w bazie nie powstają „wiszące” konta.
 */
final class RegisterController
{
    private const SESSION = 'registration';
    private const TTL = 1800; // 30 min na wpisanie kodu

    public function show(Request $request): Response
    {
        return Response::view('auth/register', $this->formData([], []));
    }

    public function store(Request $request): Response
    {
        if (!RateLimiter::attempt('register:ip:' . $request->ip(), 10, 3600)) {
            return Response::view('errors/429', [], 429);
        }

        $requireNip = (bool) Settings::get('registration.require_nip', false);
        $types = $this->accountTypes();
        $rules = [
            'first_name' => 'required|maxlength:100',
            'last_name' => 'required|maxlength:100',
            'email' => 'required|email|maxlength:190',
            'phone' => 'required|phone_pl',
            'password' => 'required|minlength:10|maxlength:200',
            'password_confirmation' => 'required',
            'nip' => ($requireNip ? 'required|' : '') . 'nip',
            'company_name' => 'maxlength:255',
            'terms' => 'accepted',
            'marketing' => 'maxlength:5',
        ];
        if ($types !== []) {
            $rules['account_type'] = 'required|in:' . implode(',', array_column($types, 'key'));
        }
        $labels = [
            'first_name' => __('register.first_name'), 'last_name' => __('register.last_name'),
            'email' => __('register.email'), 'phone' => __('register.phone'), 'password' => __('register.password'),
            'password_confirmation' => __('register.password_confirmation'), 'nip' => __('register.nip'),
            'company_name' => __('register.company_name'), 'terms' => __('register.terms_label'),
            'account_type' => __('register.account_type'),
        ];
        $v = Validator::make($request->post, $rules, $labels);
        $errors = $v->errors();
        $data = $v->validated();

        if (!isset($errors['password']) && ($request->post['password'] ?? '') !== ($request->post['password_confirmation'] ?? null)) {
            $errors['password_confirmation'] = __('register.password_mismatch');
        }
        if (!isset($errors['email']) && DB::value('SELECT 1 FROM users WHERE email = ?', [mb_strtolower((string) $data['email'])])) {
            $errors['email'] = __('register.email_taken');
        }
        if (!isset($errors['phone']) && DB::value('SELECT 1 FROM users WHERE phone_e164 = ?', [$data['phone']])) {
            $errors['phone'] = __('register.phone_taken');
        }

        $company = null;
        if (!isset($errors['nip']) && !empty($data['nip'])) {
            $company = MfVatWhitelist::lookup((string) $data['nip']);
            $typed = trim((string) ($data['company_name'] ?? ''));
            if ($company === null && $typed === '') {
                $errors['company_name'] = __('register.company_name_required');
            }
            $company ??= ['nip' => $data['nip'], 'name' => $typed, 'source' => 'manual'];
            $company['source'] ??= 'mf_api';
        }

        if ($errors !== []) {
            return Response::html(\App\Core\View::render('auth/register', $this->formData($errors, $request->post)), 422);
        }

        $registration = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => mb_strtolower((string) $data['email']),
            'phone' => $data['phone'],
            'password_hash' => Auth::hashPassword((string) $request->post['password']),
            'account_type' => $data['account_type'] ?? null,
            'company' => $company,
            'marketing' => !empty($request->post['marketing']),
            'started' => time(),
        ];

        if (!Settings::get('registration.require_sms', true)) {
            return $this->complete($registration, $request, phoneVerified: false);
        }

        try {
            $otp = OtpService::send($registration['phone'], 'register', $request->ip());
        } catch (OtpException $e) {
            $errors['phone'] = $e->getMessage();
            return Response::html(\App\Core\View::render('auth/register', $this->formData($errors, $request->post)), 422);
        }
        $registration['otp'] = $otp;
        Session::set(self::SESSION, $registration);
        return Response::redirect(url('/rejestracja/weryfikacja'));
    }

    public function verifyForm(Request $request): Response
    {
        $reg = $this->pending();
        if ($reg === null) {
            return Response::redirect(url('/rejestracja'));
        }
        return Response::view('auth/verify-phone', $this->verifyData($reg, []));
    }

    public function verify(Request $request): Response
    {
        $reg = $this->pending();
        if ($reg === null) {
            Session::flash('notice', __('otp.session_expired'));
            return Response::redirect(url('/rejestracja'));
        }

        $result = OtpService::verify((int) $reg['otp']['id'], $reg['phone'], 'register', $request->str('code'));
        if ($result !== 'ok') {
            Audit::log('register.otp_' . $result, null, null, ['phone' => OtpService::mask($reg['phone'])], $request);
            return Response::html(\App\Core\View::render('auth/verify-phone', $this->verifyData($reg, ['code' => __('otp.' . $result)])), 422);
        }
        return $this->complete($reg, $request, phoneVerified: true);
    }

    /** Nowy kod pierwszym kanałem (np. po wygaśnięciu albo 5 błędnych próbach). */
    public function resend(Request $request): Response
    {
        $reg = $this->pending();
        if ($reg === null) {
            return Response::redirect(url('/rejestracja'));
        }
        try {
            $reg['otp'] = OtpService::send($reg['phone'], 'register', $request->ip());
            Session::set(self::SESSION, $reg);
            Session::flash('success', __('otp.resent', ['phone' => $reg['otp']['phone_masked']]));
        } catch (OtpException $e) {
            Session::flash('error', $e->getMessage());
        }
        return Response::redirect(url('/rejestracja/weryfikacja'));
    }

    /** „Nie dostałem — wyślij SMS-em” (ten sam kod). */
    public function smsFallback(Request $request): Response
    {
        $reg = $this->pending();
        if ($reg === null) {
            return Response::redirect(url('/rejestracja'));
        }
        if (OtpService::fallbackToSms((int) $reg['otp']['id'])) {
            Session::flash('success', __('otp.sms_sent', ['phone' => $reg['otp']['phone_masked']]));
        } else {
            Session::flash('error', __('otp.sms_unavailable'));
        }
        return Response::redirect(url('/rejestracja/weryfikacja'));
    }

    /** JSON dla ekranu kodu: czy WhatsApp doręczył, czy poszedł SMS zapasowy. */
    public function status(Request $request): Response
    {
        $reg = $this->pending();
        $s = $reg ? OtpService::status((int) $reg['otp']['id']) : null;
        if ($s === null) {
            return Response::json(['error' => 'expired'], 404);
        }
        return Response::json([
            'channel' => $s['channel'],
            'delivery' => $s['delivery_status'],
            'fallback_sent' => (bool) $s['fallback_sent'],
            'fallback_in' => (int) $s['fallback_in'],
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function complete(array $reg, Request $request, bool $phoneVerified): Response
    {
        // Ktoś mógł w międzyczasie zarejestrować ten sam e-mail/telefon.
        if (DB::value('SELECT 1 FROM users WHERE email = ? OR phone_e164 = ?', [$reg['email'], $reg['phone']])) {
            Session::forget(self::SESSION);
            Session::flash('error', __('register.email_taken'));
            return Response::redirect(url('/logowanie'));
        }

        $userId = DB::transaction(function () use ($reg, $request, $phoneVerified): int {
            $companyId = null;
            if (!empty($reg['company']['nip'])) {
                $c = $reg['company'];
                $companyId = DB::value('SELECT id FROM companies WHERE nip = ?', [$c['nip']]);
                if ($companyId === null) {
                    $companyId = DB::insert('companies', [
                        'nip' => $c['nip'],
                        'name' => mb_substr((string) $c['name'], 0, 255),
                        'regon' => $c['regon'] ?? null,
                        'krs' => $c['krs'] ?? null,
                        'address' => $c['address'] ?? null,
                        'city' => $c['city'] ?? null,
                        'postal_code' => $c['postal_code'] ?? null,
                        'vat_status' => $c['vat_status'] ?? null,
                        'source' => $c['source'] === 'mf_api' ? 'mf_api' : 'manual',
                        'verified_at' => $c['source'] === 'mf_api' ? gmdate('Y-m-d H:i:s') : null,
                    ]);
                }
            }
            $userId = DB::insert('users', [
                'company_id' => $companyId,
                'email' => $reg['email'],
                'password_hash' => $reg['password_hash'],
                'first_name' => $reg['first_name'],
                'last_name' => $reg['last_name'],
                'phone_e164' => $reg['phone'],
                'account_type' => $reg['account_type'],
                'phone_verified_at' => $phoneVerified ? gmdate('Y-m-d H:i:s') : null,
            ]);

            $ip = inet_pton($request->ip()) ?: inet_pton('0.0.0.0');
            $termsVersion = DB::value("SELECT version FROM pages WHERE slug IN ('regulamin', 'zasady', 'zasady-wspolpracy') ORDER BY slug = 'regulamin' DESC LIMIT 1");
            foreach (['terms' => true, 'marketing' => (bool) $reg['marketing']] as $type => $granted) {
                DB::insert('consents', [
                    'user_id' => $userId, 'email' => $reg['email'], 'type' => $type,
                    'page_version' => $type === 'terms' && $termsVersion !== null ? (int) $termsVersion : null,
                    'granted' => (int) $granted, 'ip' => $ip, 'user_agent' => $request->userAgent() ?: null,
                ]);
            }
            return $userId;
        });

        Session::forget(self::SESSION);
        Audit::log('user.register', 'user', $userId, ['account_type' => $reg['account_type'], 'otp_channel' => $reg['otp']['channel'] ?? null], $request, $userId);
        Auth::login($userId, $request);

        self::sendVerificationEmail($userId, $reg['email'], $reg['first_name']);
        if ($reg['marketing'] && GetResponse::isConfigured()) {
            Queue::push('getresponse.upsert', [
                'email' => $reg['email'], 'name' => $reg['first_name'] . ' ' . $reg['last_name'],
                'phone' => $reg['phone'], 'tags' => array_filter([$reg['account_type']]),
            ]);
        }
        foreach (DB::fetchAll("SELECT email FROM users WHERE role = 'admin' AND status = 'active'") as $admin) {
            Mailer::queue($admin['email'], 'admin-new-user', [
                'name' => $reg['first_name'] . ' ' . $reg['last_name'], 'email' => $reg['email'],
                'company' => $reg['company']['name'] ?? null, 'account_type' => $reg['account_type'],
            ]);
        }

        Session::flash('success', __('register.welcome', ['name' => $reg['first_name']]));
        return Response::redirect(url('/konto'));
    }

    public static function sendVerificationEmail(int $userId, string $email, string $firstName): void
    {
        Tokens::revokeAll($userId, 'email_verify');
        $t = Tokens::create($userId, 'email_verify', 7 * 86400);
        Mailer::queue($email, 'verify-email', [
            'name' => $firstName,
            'url' => rtrim((string) config('app.url'), '/') . url('/potwierdz-email/' . $t['selector'] . '/' . $t['validator']),
        ]);
    }

    private function pending(): ?array
    {
        $reg = Session::get(self::SESSION);
        if (!is_array($reg) || !isset($reg['otp']['id']) || time() - (int) $reg['started'] > self::TTL) {
            return null;
        }
        return $reg;
    }

    private function accountTypes(): array
    {
        return array_values(array_filter((array) Settings::get('account_types', []), 'is_array'));
    }

    private function formData(array $errors, array $old): array
    {
        unset($old['password'], $old['password_confirmation'], $old['_csrf']);
        return [
            'title' => __('register.title'),
            'errors' => $errors,
            'old' => $old,
            'accountTypes' => $this->accountTypes(),
            'requireNip' => (bool) Settings::get('registration.require_nip', false),
            'allowPrivate' => (bool) Settings::get('registration.allow_private_person', true),
            'phoneHint' => $this->phoneHint(),
            'noindex' => true,
        ];
    }

    /** Podpowiedź pod polem telefonu zgodna z faktyczną kolejnością kanałów. */
    private function phoneHint(): string
    {
        if (!Settings::get('registration.require_sms', true)) {
            return 'none';
        }
        $first = OtpService::channels()[0] ?? null;
        return $first !== null && $first->name() === 'whatsapp' ? 'whatsapp' : 'sms';
    }

    private function verifyData(array $reg, array $errors): array
    {
        $status = OtpService::status((int) $reg['otp']['id']);
        $smsAvailable = false;
        foreach (OtpService::channels() as $ch) {
            $smsAvailable = $smsAvailable || $ch->name() === 'sms';
        }
        return [
            'title' => __('otp.title'),
            'errors' => $errors,
            'otp' => $reg['otp'],
            'status' => $status,
            'canSms' => $smsAvailable && ($status['channel'] ?? '') === 'whatsapp' && !($status['fallback_sent'] ?? false),
            'noindex' => true,
        ];
    }
}
