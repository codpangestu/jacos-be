<?php

namespace Database\Seeders;

use App\Models\AcademicCalendarHoliday;
use App\Models\AcademicYear;
use App\Models\AuthorizedPickup;
use App\Models\Classroom;
use App\Models\FeeStructure;
use App\Models\GradeLevel;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PickupLog;
use App\Models\School;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentConsent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data dummy realistis lintas SEMUA modul (bukan cuma skeleton minimal seperti
 * DatabaseSeeder) — dipakai supaya sistem "terlihat sudah berjalan" saat demo/
 * review: banyak siswa/kelas/staff, riwayat absensi & jemput berminggu-minggu,
 * cuti dengan macam-macam status, invoice lintas bulan dengan status bervariasi.
 * Tidak ada notifikasi contoh di sini lagi — `NotificationService` sekarang
 * benar-benar dipanggil di titik aksi asli (absensi/jemput/cuti/invoice/dst),
 * jadi Notification Center terisi organik dari aksi yang dijalankan, bukan data fiktif.
 *
 * Jalankan setelah DatabaseSeeder: php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private array $invoiceSequence = [];

    private const MALE_FIRST = ['Ahmad', 'Muhammad', 'Fajar', 'Bayu', 'Dimas', 'Rizky', 'Arya', 'Farhan', 'Reza', 'Ilham', 'Fauzan', 'Yusuf', 'Rafi', 'Bagas', 'Aditya'];
    private const FEMALE_FIRST = ['Siti', 'Nur', 'Aisyah', 'Putri', 'Salsa', 'Dinda', 'Zahra', 'Amelia', 'Bunga', 'Citra', 'Dewi', 'Intan', 'Kirana', 'Larasati', 'Melati'];
    private const LAST = ['Pratama', 'Wijaya', 'Saputra', 'Santoso', 'Kusuma', 'Nugroho', 'Setiawan', 'Hidayat', 'Ramadhan', 'Firmansyah', 'Kurniawan', 'Wibowo', 'Susanto', 'Handoko', 'Gunawan'];
    private const FATHER_FIRST = ['Budi', 'Agus', 'Hendra', 'Joko', 'Slamet', 'Wahyu', 'Eko', 'Bambang', 'Dedi', 'Iwan', 'Rudi', 'Sigit', 'Hadi', 'Bayu', 'Fajar'];
    private const MOTHER_FIRST = ['Sri', 'Ratna', 'Yuni', 'Dewi', 'Endang', 'Wati', 'Ani', 'Tuti', 'Fitri', 'Lina', 'Rina', 'Nia', 'Wulan', 'Ika', 'Dian'];

    public function run(): void
    {
        DB::transaction(function () {
            $school = School::firstOrFail();
            $academicYear = AcademicYear::where('is_active', true)->firstOrFail();
            $gradeLevels = GradeLevel::orderBy('level_number')->get()->keyBy('level_number');

            $this->addHolidays($academicYear);

            [$classrooms, $guruStaff] = $this->buildClassroomsAndGuru($school, $gradeLevels, $academicYear);
            $nonGuruStaff = $this->buildNonGuruStaff();
            $allStaff = [...$guruStaff, ...$nonGuruStaff];

            $students = $this->buildStudentsAndParents($classrooms);

            $schoolDays = $this->lastSchoolDays(18, $academicYear);

            $this->seedStudentAttendance($students, $classrooms, $schoolDays);
            $this->seedStaffAttendance($allStaff, $schoolDays);
            $this->seedLeaveRequests($allStaff);
            $pickupsByStudent = $this->seedAuthorizedPickups($students);
            $this->seedPickupLogs($students, $pickupsByStudent, $allStaff, $schoolDays);
            $this->seedInvoices($students);

            $this->command?->info('Demo data seeded: '.count($students).' siswa, '.count($classrooms).' rombel, '.count($allStaff).' staff.');
        });
    }

    /**
     * @return array{0: array<int, Classroom>, 1: array<int, Staff>} classrooms keyed by grade level, guru staff list
     */
    private function buildClassroomsAndGuru(School $school, $gradeLevels, AcademicYear $academicYear): array
    {
        $existingGuruUser = User::where('email', 'guru@jacos.sch.id')->first();
        $existingGuruStaff = Staff::where('user_id', $existingGuruUser->id)->first();

        $classroom3A = Classroom::firstOrCreate(
            ['grade_level_id' => $gradeLevels[3]->id, 'academic_year_id' => $academicYear->id, 'name' => '3A'],
            ['homeroom_teacher_id' => $existingGuruStaff->id]
        );

        $classrooms = [3 => $classroom3A];
        $guruStaff = [$existingGuruStaff];

        $guruNames = [
            1 => 'Rina Marlina', 2 => 'Dedi Kurniawan', 4 => 'Wulan Sari',
            5 => 'Hadi Santoso', 6 => 'Fitriani Rahayu',
        ];

        $i = 2;
        foreach ($guruNames as $level => $name) {
            $email = "guru{$i}@jacos.sch.id";
            $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'role' => 'guru', 'password' => 'password']);
            $staff = Staff::updateOrCreate(
                ['user_id' => $user->id],
                ['name' => $name, 'type' => 'guru', 'position' => 'Wali Kelas', 'joined_at' => '2022-07-01']
            );
            $guruStaff[] = $staff;

            $classrooms[$level] = Classroom::firstOrCreate(
                ['grade_level_id' => $gradeLevels[$level]->id, 'academic_year_id' => $academicYear->id, 'name' => "{$level}A"],
                ['homeroom_teacher_id' => $staff->id]
            );

            $i++;
        }

        // FeeStructure per grade level sudah dibuat DatabaseSeeder — pastikan tetap ada.
        foreach ($gradeLevels as $level => $gradeLevel) {
            FeeStructure::firstOrCreate(
                ['grade_level_id' => $gradeLevel->id, 'label' => 'SPP Bulanan'],
                ['monthly_amount' => 800000 + ($level * 50000)]
            );
        }

        return [$classrooms, $guruStaff];
    }

    /** @return array<int, Staff> */
    private function buildNonGuruStaff(): array
    {
        $existingUser = User::where('email', 'staff@jacos.sch.id')->first();
        $existing = Staff::updateOrCreate(
            ['user_id' => $existingUser->id],
            ['name' => $existingUser->name, 'type' => 'non_guru', 'position' => 'Satpam', 'joined_at' => '2021-01-10']
        );

        $extra = [
            ['email' => 'staff2@jacos.sch.id', 'name' => 'Yayat Supriatna', 'position' => 'Staff Tata Usaha'],
            ['email' => 'staff3@jacos.sch.id', 'name' => 'Maryati', 'position' => 'Petugas Kebersihan'],
        ];

        $staffList = [$existing];
        foreach ($extra as $row) {
            $user = User::updateOrCreate(['email' => $row['email']], ['name' => $row['name'], 'role' => 'staff', 'password' => 'password']);
            $staffList[] = Staff::updateOrCreate(
                ['user_id' => $user->id],
                ['name' => $row['name'], 'type' => 'non_guru', 'position' => $row['position'], 'joined_at' => '2023-03-01']
            );
        }

        return $staffList;
    }

    /** @return array<int, Student> */
    private function buildStudentsAndParents(array $classrooms): array
    {
        $students = [];
        $nisSeq = 2;
        $nameSeq = 0;

        $existingStudent = Student::where('nis', '2026001')->firstOrFail();
        $existingParentUser = User::where('email', 'ortu@jacos.sch.id')->first();
        $students[] = $existingStudent;
        $this->ensureConsent($existingStudent, $existingParentUser);

        $parentSeq = 2;

        foreach ($classrooms as $level => $classroom) {
            $countForClassroom = $classroom->id === $existingStudent->classroom_id ? 4 : 5;

            for ($n = 0; $n < $countForClassroom; $n++) {
                $isMale = $nameSeq % 2 === 0;
                $firstNames = $isMale ? self::MALE_FIRST : self::FEMALE_FIRST;
                $firstName = $firstNames[$nameSeq % count($firstNames)];
                $lastName = self::LAST[intdiv($nameSeq, 3) % count(self::LAST)];
                $studentName = "{$firstName} {$lastName}";
                $nis = '2026'.str_pad((string) $nisSeq, 3, '0', STR_PAD_LEFT);

                $student = Student::firstOrCreate(
                    ['nis' => $nis],
                    [
                        'classroom_id' => $classroom->id,
                        'name' => $studentName,
                        'gender' => $isMale ? 'male' : 'female',
                        'status' => 'active',
                        'birth_date' => Carbon::create(2026 - (9 + $level), rand(1, 12), rand(1, 28)),
                    ]
                );
                $students[] = $student;

                $parentIsFather = $nameSeq % 2 === 0;
                $parentFirst = $parentIsFather
                    ? self::FATHER_FIRST[$nameSeq % count(self::FATHER_FIRST)]
                    : self::MOTHER_FIRST[$nameSeq % count(self::MOTHER_FIRST)];
                $parentName = "{$parentFirst} {$lastName}";
                $parentEmail = "ortu{$parentSeq}@jacos.sch.id";

                $parentUser = User::updateOrCreate(
                    ['email' => $parentEmail],
                    ['name' => $parentName, 'role' => 'orang_tua', 'password' => 'password']
                );
                $parentUser->children()->syncWithoutDetaching([
                    $student->id => ['relationship' => $parentIsFather ? 'Ayah' : 'Ibu'],
                ]);
                $this->ensureConsent($student, $parentUser);

                $nisSeq++;
                $nameSeq++;
                $parentSeq++;
            }
        }

        return $students;
    }

    private function ensureConsent(Student $student, User $parent): void
    {
        StudentConsent::updateOrCreate(
            ['student_id' => $student->id, 'parent_id' => $parent->id],
            ['consented_at' => now()->subDays(rand(5, 40)), 'consent_version' => 'v1', 'withdrawn_at' => null]
        );
    }

    private function addHolidays(AcademicYear $academicYear): void
    {
        $holidays = [
            ['date' => '2026-08-17', 'label' => 'Hari Kemerdekaan RI'],
            ['date' => '2026-07-20', 'label' => 'Libur Tahun Ajaran Baru'],
        ];

        foreach ($holidays as $h) {
            AcademicCalendarHoliday::firstOrCreate([
                'academic_year_id' => $academicYear->id,
                'date' => $h['date'],
            ], ['label' => $h['label']]);
        }
    }

    /** @return array<int, string> tanggal (Y-m-d) hari sekolah N hari terakhir, exclude weekend & libur */
    private function lastSchoolDays(int $count, AcademicYear $academicYear): array
    {
        $holidayDates = AcademicCalendarHoliday::where('academic_year_id', $academicYear->id)->pluck('date')
            ->map(fn ($d) => $d->format('Y-m-d'))->all();

        $days = [];
        $cursor = Carbon::today();
        while (count($days) < $count) {
            if (! $cursor->isWeekend() && ! in_array($cursor->format('Y-m-d'), $holidayDates, true)) {
                $days[] = $cursor->format('Y-m-d');
            }
            $cursor->subDay();
        }

        return array_reverse($days);
    }

    private function seedStudentAttendance(array $students, array $classrooms, array $schoolDays): void
    {
        $classroomTeacherUserId = [];
        foreach ($classrooms as $classroom) {
            $classroomTeacherUserId[$classroom->id] = $classroom->homeroomTeacher?->user_id;
        }

        foreach ($students as $student) {
            $recordedBy = $classroomTeacherUserId[$student->classroom_id] ?? User::where('email', 'admin@jacos.sch.id')->value('id');

            foreach ($schoolDays as $date) {
                $roll = rand(1, 100);
                $status = match (true) {
                    $roll <= 85 => 'hadir',
                    $roll <= 92 => 'izin',
                    $roll <= 97 => 'sakit',
                    default => 'alpa',
                };
                $note = match ($status) {
                    'izin' => 'Ada keperluan keluarga.',
                    'sakit' => 'Demam, istirahat di rumah.',
                    default => null,
                };

                StudentAttendance::updateOrCreate(
                    ['student_id' => $student->id, 'date' => $date],
                    ['status' => $status, 'note' => $note, 'recorded_by' => $recordedBy]
                );
            }
        }
    }

    private function seedStaffAttendance(array $allStaff, array $schoolDays): void
    {
        foreach ($allStaff as $staff) {
            foreach ($schoolDays as $date) {
                if (rand(1, 100) <= 5) {
                    continue; // sesekali staff absen/cuti, tidak check-in
                }

                $checkIn = Carbon::parse("{$date} 07:00:00")->addMinutes(rand(0, 35));
                $checkOut = Carbon::parse("{$date} 15:00:00")->addMinutes(rand(0, 40));

                StaffAttendance::updateOrCreate(
                    ['staff_id' => $staff->id, 'date' => $date],
                    ['check_in_time' => $checkIn, 'check_out_time' => $checkOut]
                );
            }
        }
    }

    private function seedLeaveRequests(array $allStaff): void
    {
        $admin = User::where('email', 'admin@jacos.sch.id')->first();
        $types = ['sakit', 'izin', 'tahunan'];

        $requests = [
            ['status' => 'approved', 'daysAgoStart' => 12, 'span' => 2, 'note' => 'Disetujui, semoga lekas sembuh.'],
            ['status' => 'approved', 'daysAgoStart' => 20, 'span' => 3, 'note' => 'Disetujui sesuai kuota cuti tahunan.'],
            ['status' => 'rejected', 'daysAgoStart' => 8, 'span' => 1, 'note' => 'Jadwal bentrok dengan ujian sekolah, mohon ajukan tanggal lain.'],
            ['status' => 'pending', 'daysAgoStart' => -2, 'span' => 1, 'note' => null],
            ['status' => 'pending', 'daysAgoStart' => -5, 'span' => 2, 'note' => null],
        ];

        foreach ($requests as $i => $row) {
            $staff = $allStaff[$i % count($allStaff)];
            $start = Carbon::today()->subDays($row['daysAgoStart']);
            $end = (clone $start)->addDays($row['span']);
            $type = $types[$i % count($types)];

            $reasons = [
                'sakit' => 'Kurang sehat, perlu istirahat sesuai anjuran dokter.',
                'izin' => 'Ada urusan keluarga mendesak.',
                'tahunan' => 'Mengambil jatah cuti tahunan.',
            ];

            LeaveRequest::firstOrCreate([
                'staff_id' => $staff->id,
                'start_date' => $start->format('Y-m-d'),
                'type' => $type,
            ], [
                'end_date' => $end->format('Y-m-d'),
                'reason' => $reasons[$type],
                'status' => $row['status'],
                'reviewed_by' => $row['status'] === 'pending' ? null : $admin->id,
                'review_note' => $row['note'],
                'reviewed_at' => $row['status'] === 'pending' ? null : $start->copy()->addHours(3),
            ]);
        }
    }

    /** @return array<int, array<int, AuthorizedPickup>> keyed by student id */
    private function seedAuthorizedPickups(array $students): array
    {
        $byStudent = [];

        foreach ($students as $student) {
            $parent = $student->parents()->first();
            $pickups = [];

            if ($parent) {
                $pickups[] = AuthorizedPickup::firstOrCreate(
                    ['student_id' => $student->id, 'name' => $parent->name],
                    ['relationship' => $parent->pivot->relationship ?? 'Orang Tua']
                );
            }

            if (rand(1, 100) <= 40) {
                $driverNames = ['Mang Ujang (Supir)', 'Pak Kardi (Supir)', 'Bu Yati (Pengasuh)', 'Nenek Aminah'];
                $name = $driverNames[array_rand($driverNames)];
                $pickups[] = AuthorizedPickup::firstOrCreate(
                    ['student_id' => $student->id, 'name' => $name],
                    ['relationship' => str_contains($name, 'Supir') ? 'Supir' : (str_contains($name, 'Nenek') ? 'Nenek' : 'Pengasuh')]
                );
            }

            $byStudent[$student->id] = $pickups;
        }

        return $byStudent;
    }

    private function seedPickupLogs(array $students, array $pickupsByStudent, array $allStaff, array $schoolDays): void
    {
        $verifiers = array_values(array_filter($allStaff, fn ($s) => $s->type === 'non_guru' || $s->position === 'Wali Kelas'));
        $verifierUserIds = array_map(fn ($s) => $s->user_id, $verifiers) ?: [User::where('email', 'staff@jacos.sch.id')->value('id')];

        // Hari ini sengaja disisakan sebagian belum dijemput, biar dashboard "Belum Dijemput" ada isinya.
        $daysToLog = array_slice($schoolDays, 0, -1);

        foreach ($students as $student) {
            $pickups = $pickupsByStudent[$student->id] ?? [];
            if (empty($pickups)) {
                continue;
            }

            foreach ($daysToLog as $date) {
                $attended = StudentAttendance::where('student_id', $student->id)->where('date', $date)->value('status');
                if ($attended !== 'hadir') {
                    continue;
                }
                if (PickupLog::where('student_id', $student->id)->where('date', $date)->exists()) {
                    continue;
                }

                $pickup = $pickups[array_rand($pickups)];
                $method = rand(1, 100) <= 70 ? 'qr' : 'manual';
                $checkedOutAt = Carbon::parse("{$date} 15:00:00")->addMinutes(rand(0, 45));

                PickupLog::create([
                    'student_id' => $student->id,
                    'authorized_pickup_id' => $pickup->id,
                    'verified_by' => $verifierUserIds[array_rand($verifierUserIds)],
                    'method' => $method,
                    'note' => $method === 'manual' ? 'QR tidak terbawa, verifikasi manual.' : null,
                    'date' => $date,
                    'checked_out_at' => $checkedOutAt,
                ]);
            }
        }
    }

    private function nextInvoiceNumber(string $year, string $month): string
    {
        $key = "{$year}-{$month}";
        if (! isset($this->invoiceSequence[$key])) {
            $max = Invoice::where('invoice_number', 'like', "INV/{$year}/{$month}/%")->count();
            $this->invoiceSequence[$key] = $max;
        }
        $this->invoiceSequence[$key]++;

        return sprintf('INV/%s/%s/%04d', $year, $month, $this->invoiceSequence[$key]);
    }

    private function seedInvoices(array $students): void
    {
        $admin = User::where('email', 'admin@jacos.sch.id')->first();
        $periods = ['2026-06', '2026-07', '2026-08'];

        foreach ($students as $student) {
            $gradeLevelId = $student->classroom?->grade_level_id;
            $amount = FeeStructure::where('grade_level_id', $gradeLevelId)->value('monthly_amount') ?? 850000;

            foreach ($periods as $period) {
                if (Invoice::where('student_id', $student->id)->where('period', $period)->exists()) {
                    continue;
                }

                [$year, $month] = explode('-', $period);
                $dueDate = Carbon::parse("{$period}-01")->addMonth()->addDays(4);

                $isCurrentMonth = $period === '2026-08';
                $roll = rand(1, 100);
                if ($isCurrentMonth) {
                    $status = $roll <= 55 ? 'lunas' : ($roll <= 90 ? 'belum_bayar' : 'terlambat');
                } else {
                    $status = $roll <= 92 ? 'lunas' : 'terlambat';
                }

                $invoice = Invoice::create([
                    'student_id' => $student->id,
                    'invoice_number' => $this->nextInvoiceNumber($year, $month),
                    'period' => $period,
                    'amount' => $amount,
                    'status' => $status,
                    'due_date' => $dueDate,
                ]);

                if ($status === 'lunas') {
                    $methods = ['bank_transfer', 'gopay', 'credit_card', 'qris'];
                    $paidAt = $dueDate->copy()->subDays(rand(1, 10));

                    Payment::create([
                        'invoice_id' => $invoice->id,
                        'gateway_reference' => 'settled-'.Str::lower(str_replace('/', '-', $invoice->invoice_number)),
                        'amount' => $amount,
                        'status' => 'settlement',
                        'method' => $methods[array_rand($methods)],
                        'paid_at' => $paidAt,
                    ]);

                    if (rand(1, 100) <= 15) {
                        $invoice->update(['manual_note' => 'Dibayar tunai di kantor TU.', 'marked_paid_by' => $admin->id]);
                    }
                }
            }
        }

        // Satu invoice contoh berstatus dibatalkan, buat variasi tampilan (mis. siswa pindah pertengahan bulan).
        $sample = $students[array_rand($students)];
        if (! Invoice::where('student_id', $sample->id)->where('period', '2026-05')->exists()) {
            Invoice::create([
                'student_id' => $sample->id,
                'invoice_number' => $this->nextInvoiceNumber('2026', '05'),
                'period' => '2026-05',
                'amount' => 850000,
                'status' => 'dibatalkan',
                'due_date' => '2026-06-05',
                'manual_note' => 'Dibatalkan otomatis — siswa sempat nonaktif pertengahan bulan.',
            ]);
        }
    }

}
