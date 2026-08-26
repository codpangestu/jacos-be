<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeeStructure;
use Illuminate\Http\Request;

class FeeStructureController extends Controller
{
    /**
     * FR-BE-4.1 — struktur biaya SPP per tingkat/rombel.
     */
    public function index()
    {
        return response()->json(['fee_structures' => FeeStructure::with('gradeLevel:id,name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'label' => ['nullable', 'string', 'max:255'],
            'monthly_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $feeStructure = FeeStructure::updateOrCreate(
            ['grade_level_id' => $data['grade_level_id'], 'label' => $data['label'] ?? 'SPP Bulanan'],
            ['monthly_amount' => $data['monthly_amount']]
        );

        return response()->json(['fee_structure' => $feeStructure], 201);
    }

    public function update(Request $request, FeeStructure $feeStructure)
    {
        $data = $request->validate(['monthly_amount' => ['required', 'numeric', 'min:0']]);
        $feeStructure->update($data);

        return response()->json(['fee_structure' => $feeStructure]);
    }
}
