<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\DismissalSetting;
use App\Models\FeeStructure;
use App\Models\GradeLevel;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $school = School::firstOrCreate(['name' => 'Jakarta Cosmopolite Islamic School']);

        DismissalSetting::firstOrCreate(['school_id' => $school->id]);

        $accounts = [
            ['name' => 'Admin JACOS', 'email' => 'admin@jacos.sch.id', 'role' => 'admin'],
            ['name' => 'Guru JACOS', 'email' => 'guru@jacos.sch.id', 'role' => 'guru'],
            ['name' => 'Orang Tua JACOS', 'email' => 'ortu@jacos.sch.id', 'role' => 'orang_tua'],
            ['name' => 'Staff JACOS', 'email' => 'staff@jacos.sch.id', 'role' => 'staff'],
        ];

        $users = [];
        foreach ($accounts as $account) {
            $users[$account['role']] = User::updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'role' => $account['role'], 'password' => 'password']
            );
        }

        $guruStaff = Staff::updateOrCreate(
            ['user_id' => $users['guru']->id],
            ['name' => $users['guru']->name, 'type' => 'guru', 'position' => 'Wali Kelas']
        );

        Staff::updateOrCreate(
            ['user_id' => $users['staff']->id],
            ['name' => $users['staff']->name, 'type' => 'non_guru', 'position' => 'Satpam']
        );

        // Kelas 1-6 sebagai data referensi tetap (bukan CRUD admin, lihat PRD §4).
        $gradeLevels = [];
        foreach (range(1, 6) as $level) {
            $gradeLevels[$level] = GradeLevel::firstOrCreate(
                ['school_id' => $school->id, 'level_number' => $level],
                ['name' => "Kelas {$level}"]
            );
        }

        foreach ($gradeLevels as $level => $gradeLevel) {
            FeeStructure::firstOrCreate(
                ['grade_level_id' => $gradeLevel->id, 'label' => 'SPP Bulanan'],
                ['monthly_amount' => 800000 + ($level * 50000)]
            );
        }

        $academicYear = AcademicYear::firstOrCreate(
            ['label' => '2026/2027'],
            ['school_id' => $school->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true]
        );

        $classroom3A = Classroom::firstOrCreate(
            ['grade_level_id' => $gradeLevels[3]->id, 'academic_year_id' => $academicYear->id, 'name' => '3A'],
            ['homeroom_teacher_id' => $guruStaff->id]
        );

        $student = Student::firstOrCreate(
            ['nis' => '2026001'],
            ['classroom_id' => $classroom3A->id, 'name' => 'Ahmad Fauzi', 'gender' => 'male', 'status' => 'active']
        );

        $users['orang_tua']->children()->syncWithoutDetaching([$student->id => ['relationship' => 'Ayah']]);
    }
}
