<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AvatarApiTest extends TestCase
{
    use RefreshDatabase;

    private function image(int $width = 1): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, 1, 8, 6, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0", 1 + $width * 4)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('profile.png', $png);
    }

    #[TestWith([null])]
    #[TestWith(['advertiser'])]
    #[TestWith(['publisher'])]
    #[TestWith(['admin'])]
    public function test_active_users_can_upload_only_their_own_profile_picture(?string $role): void
    {
        Storage::fake('public');
        $user = User::factory()->unverified()->create(['role' => $role]);
        $other = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)->post('/api/auth/avatar', [
            'avatar' => $this->image(),
            'user_id' => $other->id, 'avatar_path' => 'untrusted.png',
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);
        $response->assertJsonPath('data.avatar_url', Storage::disk('public')->url($path))
            ->assertJsonPath('data.has_uploaded_avatar', true)->assertJsonMissingPath('data.avatar_path');
        $this->assertNull($other->fresh()->avatar_path);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.avatar_url', Storage::disk('public')->url($path));
    }

    public function test_replacing_and_removing_uploads_cleans_up_owned_files_and_restores_google_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_url' => 'https://example.test/google.jpg']);
        $this->actingAs($user, 'sanctum');
        $this->post('/api/auth/avatar', ['avatar' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $first = $user->fresh()->avatar_path;
        $this->post('/api/auth/avatar', ['avatar' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $second = $user->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->deleteJson('/api/auth/avatar')->assertOk()
            ->assertJsonPath('data.avatar_url', 'https://example.test/google.jpg')
            ->assertJsonPath('data.has_uploaded_avatar', false);
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($user->fresh()->avatar_path);
        $this->deleteJson('/api/auth/avatar')->assertOk();
    }

    #[TestWith(['text'])]
    #[TestWith(['svg'])]
    #[TestWith(['large'])]
    #[TestWith(['dimensions'])]
    public function test_invalid_uploads_leave_the_existing_picture_unchanged(string $kind): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/current.png', 'existing');
        $user = User::factory()->create();
        $user->forceFill(['avatar_path' => 'avatars/current.png'])->save();
        $file = match ($kind) {
            'text' => UploadedFile::fake()->create('fake.png', 10, 'text/plain'),
            'svg' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'large' => $this->image()->size(2049),
            'dimensions' => $this->image(4097),
        };
        $this->actingAs($user, 'sanctum')->post('/api/auth/avatar', ['avatar' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->assertSame('avatars/current.png', $user->fresh()->avatar_path);
        $this->assertSame(['avatars/current.png'], Storage::disk('public')->allFiles('avatars'));
    }

    #[TestWith(['POST'])]
    #[TestWith(['DELETE'])]
    public function test_guests_cannot_change_profile_pictures(string $method): void
    {
        $this->json($method, '/api/auth/avatar')->assertUnauthorized();
    }

    #[TestWith(['POST'])]
    #[TestWith(['DELETE'])]
    public function test_suspended_users_cannot_change_profile_pictures(string $method): void
    {
        $user = User::factory()->create(['status' => 'suspended']);
        $this->actingAs($user, 'sanctum')->json($method, '/api/auth/avatar')->assertForbidden();
    }

    public function test_missing_upload_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/auth/avatar')
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');
    }
}
