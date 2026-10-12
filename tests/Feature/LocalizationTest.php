<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_pages_use_indonesian_and_accessible_labels(): void
    {
        $this->assertSame('id', app()->getLocale());

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('lang="id"', false)
            ->assertSee('Masuk ke akun Anda')
            ->assertSee('Kata sandi')
            ->assertSee('Tampilkan atau sembunyikan kata sandi')
            ->assertDontSee('Log in to your account');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Buat akun')
            ->assertSee('Konfirmasi kata sandi');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Kirim tautan atur ulang kata sandi');
    }

    public function test_registration_validation_messages_use_indonesian_attribute_names(): void
    {
        $this->post(route('register.store'), [
            'name' => '',
            'email' => 'invalid',
            'password' => 'abc',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors([
            'name' => 'nama wajib diisi.',
            'email' => 'alamat email harus berupa alamat email yang valid.',
            'password' => 'kata sandi harus berisi minimal '.Password::default()->appliedRules()['min'].' karakter.',
        ]);

        $this->post(route('register.store'), [
            'name' => 'Peneliti',
            'email' => 'peneliti@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors([
            'password' => 'Konfirmasi kata sandi tidak cocok.',
        ]);
    }

    public function test_registration_rejects_duplicate_email_in_indonesian(): void
    {
        $user = User::factory()->create();

        $this->post(route('register.store'), [
            'name' => 'Peneliti',
            'email' => $user->email,
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertSessionHasErrors([
            'email' => 'alamat email sudah digunakan.',
        ]);
    }

    public function test_failed_authentication_uses_indonesian(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors([
            'email' => 'Email atau kata sandi tidak sesuai dengan data kami.',
        ]);
    }

    public function test_settings_titles_and_appearance_are_indonesian(): void
    {
        $this->actingAs(User::factory()->create());

        $profile = $this->get(route('profile.edit'))->assertOk();
        $this->assertMatchesRegularExpression('/<title>\s*Pengaturan profil/', $profile->getContent());

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Ubah kata sandi');

        $appearance = $this->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('Terang')
            ->assertSee('Gelap')
            ->assertSee('Sistem');
        $this->assertMatchesRegularExpression('/<title>\s*Pengaturan tampilan/', $appearance->getContent());
    }

    public function test_current_password_validation_is_indonesian(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.security')
            ->set('current_password', 'incorrect-password')
            ->set('password', 'secure-password')
            ->set('password_confirmation', 'secure-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password'])
            ->assertSee('Kata sandi saat ini salah.');
    }

    public function test_password_reset_notification_is_indonesian(): void
    {
        $message = (new ResetPassword('test-token'))->toMail(User::factory()->create());

        $this->assertInstanceOf(MailMessage::class, $message);
        $this->assertSame('Atur ulang kata sandi Anda', $message->subject);
        $this->assertSame('Atur ulang kata sandi', $message->actionText);
        $this->assertStringContainsString('Tautan atur ulang kata sandi ini berlaku selama', implode(' ', $message->outroLines));
        $rendered = $message->render();

        $this->assertStringContainsString('Halo!', $rendered);
        $this->assertStringContainsString('Seluruh hak dilindungi.', $rendered);
        $this->assertStringNotContainsString('Regards,', $rendered);
    }
}
