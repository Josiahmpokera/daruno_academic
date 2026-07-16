<?php

namespace App\Support;

/**
 * NECTA-oriented grading helpers (CSEE / ACSEE style bands).
 * Division mapping is simplified for secondary report cards.
 */
class NectaGrading
{
    /**
     * @return array{grade: string, points: int, remark: string}
     */
    public static function gradeFromPercent(float $percent): array
    {
        $percent = max(0, min(100, $percent));

        return match (true) {
            $percent >= 75 => ['grade' => 'A', 'points' => 1, 'remark' => 'Excellent'],
            $percent >= 65 => ['grade' => 'B', 'points' => 2, 'remark' => 'Very Good'],
            $percent >= 45 => ['grade' => 'C', 'points' => 3, 'remark' => 'Good'],
            $percent >= 30 => ['grade' => 'D', 'points' => 4, 'remark' => 'Satisfactory'],
            default => ['grade' => 'F', 'points' => 5, 'remark' => 'Fail'],
        };
    }

    /**
     * Division from average grade points (lower is better).
     */
    public static function divisionFromAveragePoints(float $avgPoints): string
    {
        return match (true) {
            $avgPoints <= 1.5 => 'I',
            $avgPoints <= 2.5 => 'II',
            $avgPoints <= 3.5 => 'III',
            $avgPoints <= 4.5 => 'IV',
            default => '0',
        };
    }
}
