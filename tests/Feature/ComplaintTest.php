<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private function parent(): User
    {
        return User::factory()->create(['role' => 'orang_tua']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function child(User $parent): Student
    {
        $student = Student::create(['nis' => 'S-'.fake()->unique()->numerify('####'), 'name' => 'Ahmad Pratama']);
        $parent->children()->attach($student->id, ['relationship' => 'ayah']);

        return $student;
    }

    /** Deep-link (`data.url`) notifikasi terakhir yang diterima satu user. */
    private function latestNotificationUrl(User $user): ?string
    {
        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->latest('created_at')
            ->first();

        return $row ? (json_decode($row->data, true)['url'] ?? null) : null;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'akademik',
            'subject' => 'Nilai rapor belum keluar',
            'body' => 'Mohon dibantu cek nilai rapor semester lalu.',
        ], $overrides);
    }

    public function test_orang_tua_bisa_mengajukan_pengaduan_dan_admin_dapat_notifikasi(): void
    {
        $parent = $this->parent();
        $admin = $this->admin();
        $student = $this->child($parent);

        $response = $this->actingAs($parent, 'sanctum')
            ->postJson('/api/ortu/complaints', $this->payload(['student_id' => $student->id]));

        $response->assertCreated();

        $complaint = Complaint::first();
        $this->assertSame('open', $complaint->status);
        $this->assertSame('normal', $complaint->priority);
        $this->assertSame($parent->id, $complaint->submitted_by);
        // Nomor tiket dibuat otomatis dan tahunnya ikut tahun berjalan.
        $this->assertSame('PGD-'.now()->format('Y').'-'.str_pad((string) $complaint->id, 5, '0', STR_PAD_LEFT), $complaint->ticket_no);
        // SLA default (normal) = 3 hari.
        $this->assertTrue($complaint->due_at->isSameDay(now()->addDays(3)));
        $this->assertFalse($complaint->is_overdue);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id, 'notifiable_type' => User::class]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaint.created', 'entity_id' => $complaint->id]);
    }

    public function test_orang_tua_tidak_bisa_menautkan_anak_yang_bukan_miliknya(): void
    {
        $parent = $this->parent();
        $otherParent = $this->parent();
        $student = $this->child($otherParent);

        $this->actingAs($parent, 'sanctum')
            ->postJson('/api/ortu/complaints', $this->payload(['student_id' => $student->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_admin_melihat_daftar_pengaduan_beserta_hitungan_per_status(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();

        $this->actingAs($parent, 'sanctum')->postJson('/api/ortu/complaints', $this->payload())->assertCreated();
        $this->actingAs($parent, 'sanctum')
            ->postJson('/api/ortu/complaints', $this->payload(['subject' => 'Kantin terlalu ramai']))
            ->assertCreated();

        Complaint::latest('id')->first()->update(['status' => 'in_progress']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/complaints')
            ->assertOk()
            ->assertJsonPath('counts.open', 1)
            ->assertJsonPath('counts.in_progress', 1)
            ->assertJsonPath('counts.resolved', 0)
            ->assertJsonPath('counts.overdue', 0)
            ->assertJsonCount(2, 'data');
    }

    public function test_tiket_telat_dihitung_overdue_dan_bisa_difilter(): void
    {
        $admin = $this->admin();
        $complaint = Complaint::create([
            'submitted_by' => $this->parent()->id,
            'category' => 'sarana',
            'subject' => 'AC kelas rusak',
            'body' => 'Sudah seminggu panas.',
            'status' => 'open',
            'priority' => 'high',
            'due_at' => now()->subDay(),
        ]);

        $this->assertTrue($complaint->fresh()->is_overdue);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/complaints?overdue=1')
            ->assertOk()
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonCount(1, 'data');

        // Tiket yang sudah selesai tidak pernah dianggap telat.
        $complaint->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->assertFalse($complaint->fresh()->is_overdue);
    }

    public function test_admin_ubah_status_mengirim_notifikasi_dan_mencatat_audit(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();
        $complaint = Complaint::create([
            'submitted_by' => $parent->id,
            'category' => 'keuangan',
            'subject' => 'Tagihan ganda',
            'body' => 'SPP bulan ini tertagih dua kali.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/complaints/{$complaint->id}", ['status' => 'resolved'])
            ->assertStatus(422); // menutup tiket wajib pakai resolution_note

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/complaints/{$complaint->id}", [
                'status' => 'resolved',
                'resolution_note' => 'Invoice ganda sudah dibatalkan.',
            ])
            ->assertOk();

        $complaint->refresh();
        $this->assertSame('resolved', $complaint->status);
        $this->assertNotNull($complaint->resolved_at);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $parent->id]);
        // Notifikasi perubahan status harus mengarah ke tiket di sisi Orang Tua.
        $this->assertSame("/ortu/complaints/{$complaint->id}", $this->latestNotificationUrl($parent));
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaint.updated', 'entity_id' => $complaint->id]);
    }

    public function test_ubah_priority_menghitung_ulang_target_sla(): void
    {
        $admin = $this->admin();
        $complaint = Complaint::create([
            'submitted_by' => $this->parent()->id,
            'category' => 'sarana',
            'subject' => 'Atap bocor',
            'body' => 'Bocor saat hujan.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/complaints/{$complaint->id}", ['priority' => 'high'])
            ->assertOk();

        $this->assertTrue($complaint->fresh()->due_at->isSameDay(now()->addDay()));
    }

    public function test_penanggung_jawab_tidak_boleh_orang_tua(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();
        $complaint = Complaint::create([
            'submitted_by' => $parent->id,
            'category' => 'lainnya',
            'subject' => 'Uji assign',
            'body' => 'Uji.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/complaints/{$complaint->id}", ['assigned_to' => $parent->id])
            ->assertStatus(422);
    }

    public function test_orang_tua_lain_tidak_bisa_membuka_tiket(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();
        $intruder = $this->parent();
        $complaint = Complaint::create([
            'submitted_by' => $parent->id,
            'category' => 'lainnya',
            'subject' => 'Privat',
            'body' => 'Hanya untuk TU.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/complaints/{$complaint->id}")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/replies", ['body' => 'Numpang lewat'])
            ->assertForbidden();

        $this->assertDatabaseCount('complaint_replies', 0);
    }

    public function test_balasan_dua_arah_tersimpan_dan_menotifikasi_pihak_lain(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();
        $complaint = Complaint::create([
            'submitted_by' => $parent->id,
            'category' => 'akademik',
            'subject' => 'Jadwal ekstrakurikuler',
            'body' => 'Mohon info jadwal terbaru.',
            'status' => 'in_progress',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
            'assigned_to' => $admin->id,
        ]);

        $this->actingAs($parent, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/replies", ['body' => 'Tambahan: untuk kelas 3A.'])
            ->assertCreated();

        // Balasan orang tua → notifikasi ke penanggung jawab.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
        $this->assertSame("/admin/complaints/{$complaint->id}", $this->latestNotificationUrl($admin));

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/replies", ['body' => 'Jadwal sudah dikirim via pengumuman.'])
            ->assertCreated();

        $this->assertDatabaseCount('complaint_replies', 2);
        // Balasan Tata Usaha → notifikasi ke orang tua, mengarah ke UI Ortu.
        $this->assertSame("/ortu/complaints/{$complaint->id}", $this->latestNotificationUrl($parent));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/complaints/{$complaint->id}")
            ->assertOk()
            ->assertJsonCount(2, 'complaint.replies');
    }

    public function test_hanya_admin_yang_bisa_menghapus_tiket(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();
        $complaint = Complaint::create([
            'submitted_by' => $parent->id,
            'category' => 'lainnya',
            'subject' => 'Salah kirim',
            'body' => 'Bukan untuk TU.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
        ]);

        $this->actingAs($parent, 'sanctum')
            ->deleteJson("/api/admin/complaints/{$complaint->id}")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/complaints/{$complaint->id}")
            ->assertOk();

        $this->assertDatabaseCount('complaints', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaint.deleted']);
    }

    public function test_menghapus_tiket_ikut_menghapus_balasannya(): void
    {
        $admin = $this->admin();
        $parent = $this->parent();
        $complaint = Complaint::create([
            'submitted_by' => $parent->id,
            'category' => 'lainnya',
            'subject' => 'Hapus thread',
            'body' => 'Uji cascade.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDays(3),
        ]);
        $complaint->replies()->create(['user_id' => $admin->id, 'body' => 'Diterima.']);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/complaints/{$complaint->id}")->assertOk();

        $this->assertDatabaseCount('complaint_replies', 0);
    }

    public function test_daftar_calon_penanggung_jawab_mencakup_admin_guru_dan_staff(): void
    {
        $admin = $this->admin();
        User::factory()->create(['role' => 'guru']);
        User::factory()->create(['role' => 'staff']);
        User::factory()->create(['role' => 'orang_tua']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/complaints/assignees')
            ->assertOk();

        $roles = collect($response->json('assignees'))->pluck('role')->sort()->values()->all();

        // Orang tua tidak boleh jadi penanggung jawab tiketnya sendiri.
        $this->assertSame(['admin', 'guru', 'staff'], $roles);
    }
}
