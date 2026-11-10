<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Session;
use App\Services\ArrayTransport;
use Tests\TestCase;

final class AuthFlowTest extends TestCase
{
    private function register(string $email = 'karim@example.com', string $pw = 'Str0ngPassw0rd', string $ip = '203.0.113.9'): \App\Core\Response
    {
        return $this->post('/register', ['name' => 'Karim Hossain', 'email' => $email, 'password' => $pw, 'password_confirm' => $pw, 'mobile' => '+8801712345678'], [], $ip);
    }

    private function linkFrom(string $path): string
    {
        foreach (array_reverse(ArrayTransport::$sent) as $m) {
            if (preg_match('#/' . preg_quote($path, '#') . '/([a-f0-9]{64})#', $m['html'], $x)) {
                return $x[1];
            }
        }
        $this->fail("no $path link in any sent mail");
    }

    public function testRegistrationVerificationLoginLogout(): void
    {
        $res = $this->register();
        $this->assertSame(302, $res->status);
        $u = DB::one('SELECT * FROM users WHERE email = ?', ['karim@example.com']);
        $this->assertNotNull($u);
        $this->assertNull($u['email_verified_at']);
        $this->assertNotSame('Str0ngPassw0rd', $u['password_hash']);
        $this->assertTrue(password_verify('Str0ngPassw0rd', $u['password_hash']));
        $this->assertStringStartsWith('$2y$', $u['password_hash'], 'PASSWORD_DEFAULT (bcrypt/argon) hash');
        $this->assertCount(1, ArrayTransport::$sent);
        $this->assertSame('karim@example.com', ArrayTransport::$sent[0]['to']);

        // cannot log in before verifying
        $this->post('/login', ['email' => 'karim@example.com', 'password' => 'Str0ngPassw0rd']);
        $this->assertNull(Session::get('uid'));

        $token = $this->linkFrom('verify-email');
        $this->assertNotSame($token, DB::val('SELECT token_hash FROM email_verification_tokens'), 'only a hash of the token is stored');
        $this->assertSame(hash('sha256', $token), DB::val('SELECT token_hash FROM email_verification_tokens'));
        $this->assertStringContainsString('/login', (string) $this->get('/verify-email/' . $token)->headers['Location']);
        $this->assertNotNull(DB::val('SELECT email_verified_at FROM users WHERE id = ?', [$u['id']]));

        // single use
        DB::exec('UPDATE users SET email_verified_at = NULL');
        $this->get('/verify-email/' . $token);
        $this->assertNull(DB::val('SELECT email_verified_at FROM users'), 'a used token cannot verify again');
        DB::exec('UPDATE users SET email_verified_at = ?', [DB::now()]);

        $r = $this->post('/login', ['email' => 'KARIM@example.com', 'password' => 'Str0ngPassw0rd']);
        $this->assertSame('/dashboard', $r->headers['Location']);
        $this->assertSame((int) $u['id'], Session::get('uid'));
        $this->assertSame(200, $this->get('/dashboard')->status);

        $this->post('/logout');
        $this->assertNull(Session::get('uid'));
        $this->assertSame('/login', $this->get('/dashboard')->headers['Location']);
    }

    public function testExpiredVerificationTokenFails(): void
    {
        $this->register();
        $token = $this->linkFrom('verify-email');
        DB::exec('UPDATE email_verification_tokens SET expires_at = ?', [gmdate('Y-m-d H:i:s', time() - 10)]);
        $this->get('/verify-email/' . $token);
        $this->assertNull(DB::val('SELECT email_verified_at FROM users'));
        $this->get('/verify-email/not-a-token');
        $this->get('/verify-email/' . str_repeat('a', 64));
        $this->assertNull(DB::val('SELECT email_verified_at FROM users'));
    }

    public function testRegistrationValidation(): void
    {
        foreach ([['abc', 'auth.err_password_short'], ['', 'auth.err_password_short']] as [$pw, $err]) {
            $this->register('x' . md5($pw) . '@example.com', $pw);
            $this->assertSame($err, Session::get('_errors')['password'] ?? null, $pw);
        }
        $this->post('/register', ['name' => '', 'email' => 'bad', 'password' => 'Str0ngPassw0rd', 'password_confirm' => 'different']);
        $e = Session::get('_errors');
        $this->assertArrayHasKey('name', $e);
        $this->assertArrayHasKey('email', $e);
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM users'));
    }

    public function testDuplicateEmailGivesIdenticalResponseAndNoSecondAccount(): void
    {
        $a = $this->register('same@example.com');
        $b = $this->register('same@example.com');
        $this->assertSame($a->status, $b->status);
        $this->assertSame($a->headers['Location'], $b->headers['Location']);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM users'));
    }

    public function testRegistrationAbuseIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->register("bot$i@example.com", 'Str0ngPassw0rd', '198.51.100.7');
        }
        $this->register('bot11@example.com', 'Str0ngPassw0rd', '198.51.100.7');
        $this->assertSame('auth.err_rate', Session::get('_errors')['_'] ?? null);
        $this->assertSame(10, (int) DB::val('SELECT COUNT(*) FROM users'));
    }

    public function testLoginRateLimitAndGenericMessages(): void
    {
        $this->createUser('victim@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'victim@example.com', 'password' => 'wrong' . $i]);
            $flash = Session::pull('_flash')[0]['msg'] ?? '';
            $this->assertSame('Incorrect email or password.', $flash);
        }
        // locked: even the correct password is refused now
        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'Str0ngPassw0rd']);
        $this->assertNull(Session::get('uid'));
        $this->assertStringContainsString('Too many attempts', Session::pull('_flash')[0]['msg']);
        // unknown email -> same generic message as a wrong password
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever12345']);
        $this->assertSame('Incorrect email or password.', Session::pull('_flash')[0]['msg']);
    }

    public function testSuspendedAccountCannotLoginAndExistingSessionIsKilled(): void
    {
        $id = $this->createUser('susp@example.com');
        $this->post('/login', ['email' => 'susp@example.com', 'password' => 'Str0ngPassw0rd']);
        $this->assertSame($id, Session::get('uid'));
        DB::exec("UPDATE users SET status = 'suspended' WHERE id = ?", [$id]);
        $this->assertSame('/login', $this->get('/dashboard')->headers['Location'], 'suspension takes effect immediately');
        $this->post('/login', ['email' => 'susp@example.com', 'password' => 'Str0ngPassw0rd']);
        $this->assertNull(Session::get('uid'));
    }

    public function testPasswordResetFlow(): void
    {
        $id = $this->createUser('forgot@example.com', 'OldPassw0rd123');
        $this->post('/forgot-password', ['email' => 'forgot@example.com']);
        $this->assertCount(1, ArrayTransport::$sent);
        $token = $this->linkFrom('reset-password');
        $this->assertSame(hash('sha256', $token), DB::val('SELECT token_hash FROM password_reset_tokens'));
        $this->assertSame(200, $this->get('/reset-password/' . $token)->status);

        $this->post('/reset-password/' . $token, ['password' => 'abc', 'password_confirm' => 'abc']);
        $this->assertSame('OK-still-old', password_verify('OldPassw0rd123', DB::val('SELECT password_hash FROM users')) ? 'OK-still-old' : 'changed');

        $this->post('/reset-password/' . $token, ['password' => 'BrandNewPassw0rd', 'password_confirm' => 'BrandNewPassw0rd']);
        $this->assertTrue(password_verify('BrandNewPassw0rd', DB::val('SELECT password_hash FROM users WHERE id = ' . $id)));

        // single use
        $this->post('/reset-password/' . $token, ['password' => 'AnotherPassw0rd9', 'password_confirm' => 'AnotherPassw0rd9']);
        $this->assertTrue(password_verify('BrandNewPassw0rd', DB::val('SELECT password_hash FROM users WHERE id = ' . $id)));
        $this->assertSame('/forgot-password', $this->get('/reset-password/' . $token)->headers['Location']);

        $this->post('/login', ['email' => 'forgot@example.com', 'password' => 'OldPassw0rd123']);
        $this->assertNull(Session::get('uid'));
        $this->post('/login', ['email' => 'forgot@example.com', 'password' => 'BrandNewPassw0rd']);
        $this->assertSame($id, Session::get('uid'));
    }

    public function testResetTokenExpiresAndUnknownEmailLooksIdentical(): void
    {
        $this->createUser('forgot@example.com');
        $a = $this->post('/forgot-password', ['email' => 'forgot@example.com']);
        $token = $this->linkFrom('reset-password');
        DB::exec('UPDATE password_reset_tokens SET expires_at = ?', [gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->assertSame('/forgot-password', $this->get('/reset-password/' . $token)->headers['Location']);
        $this->post('/reset-password/' . $token, ['password' => 'BrandNewPassw0rd', 'password_confirm' => 'BrandNewPassw0rd']);
        $this->assertFalse(password_verify('BrandNewPassw0rd', DB::val('SELECT password_hash FROM users')));

        ArrayTransport::reset();
        $b = $this->post('/forgot-password', ['email' => 'ghost@example.com']);
        $this->assertSame($a->headers['Location'], $b->headers['Location']);
        $this->assertCount(0, ArrayTransport::$sent, 'no mail for unknown addresses, and the response does not reveal that');
    }

    public function testChangePasswordRequiresCurrentPassword(): void
    {
        $id = $this->createUser();
        $this->loginAs($id);
        $this->post('/profile/password', ['current_password' => 'nope', 'new_password' => 'BrandNewPassw0rd', 'new_password_confirm' => 'BrandNewPassw0rd']);
        $this->assertTrue(password_verify('Str0ngPassw0rd', DB::val('SELECT password_hash FROM users')));
        $this->post('/profile/password', ['current_password' => 'Str0ngPassw0rd', 'new_password' => 'BrandNewPassw0rd', 'new_password_confirm' => 'BrandNewPassw0rd']);
        $this->assertTrue(password_verify('BrandNewPassw0rd', DB::val('SELECT password_hash FROM users')));
    }

    public function testAccountDeletionRequestDeactivatesAndLogsOut(): void
    {
        $id = $this->createUser();
        $this->loginAs($id);
        $this->post('/profile/delete', ['current_password' => 'wrong']);
        $this->assertSame('active', DB::val('SELECT status FROM users'));
        $this->post('/profile/delete', ['current_password' => 'Str0ngPassw0rd']);
        $this->assertSame('deletion_requested', DB::val('SELECT status FROM users'));
        $this->assertNull(Session::get('uid'));
        $this->assertNull(Auth::user());
    }

    public function testResendVerificationOnlyForUnverifiedAndRateLimited(): void
    {
        $this->createUser('unv@example.com', 'Str0ngPassw0rd', false);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/resend-verification', ['email' => 'unv@example.com']);
        }
        $this->assertLessThanOrEqual(3, count(ArrayTransport::$sent));
        $this->createUser('ver@example.com');
        ArrayTransport::reset();
        $this->post('/resend-verification', ['email' => 'ver@example.com']);
        $this->assertCount(0, ArrayTransport::$sent);
    }
}
