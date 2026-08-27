<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Helper export CSV generik (streamDownload — tidak perlu load semua baris ke
 * memory sekaligus). Dipakai di endpoint export list Admin (siswa, absensi
 * staff, dst) supaya tombol "Export" beneran unduh file, bukan cuma stub.
 */
class CsvExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<mixed>  $rows
     * @param  callable(mixed): array<int, mixed>  $mapRow
     */
    public static function download(string $filename, array $headers, iterable $rows, callable $mapRow): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $mapRow) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM — biar Excel baca UTF-8 (karakter Indonesia) dengan benar
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $mapRow($row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
