<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Report Card — {{ $report['student']['full_name'] ?? 'Student' }}</title>
  <style>
    body { font-family: Georgia, 'Times New Roman', serif; color: #111; margin: 24px; font-size: 13px; }
    h1, h2, h3 { margin: 0 0 6px; }
    .header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
    .meta { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .meta div { flex: 1; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
    th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
    th { background: #f3f3f3; }
    .summary { margin-top: 8px; }
    .footer { margin-top: 28px; display: flex; justify-content: space-between; }
    .sign { width: 40%; border-top: 1px solid #333; padding-top: 6px; text-align: center; margin-top: 40px; }
    @media print { body { margin: 12px; } .no-print { display: none; } }
  </style>
</head>
<body>
  <p class="no-print"><button onclick="window.print()">Print / Save PDF</button></p>

  <div class="header">
    <h1>{{ $report['school']['name'] ?? 'School' }}</h1>
    <div>School Code: {{ $report['school']['code'] ?? '—' }}</div>
    <h2 style="margin-top:10px;">STUDENT PROGRESS REPORT</h2>
    <div style="font-size:11px;color:#444;">NECTA-oriented format · Generated {{ $report['generated_at'] ?? '' }}</div>
  </div>

  <div class="meta">
    <div>
      <strong>Student:</strong> {{ $report['student']['full_name'] ?? '' }}<br>
      <strong>Admission No:</strong> {{ $report['student']['admission_number'] ?? '—' }}<br>
      <strong>Gender:</strong> {{ $report['student']['gender'] ?? '—' }}
    </div>
    <div>
      <strong>Class:</strong> {{ $report['class']['name'] ?? '—' }}<br>
      <strong>Academic Year:</strong> {{ $report['class']['academic_year'] ?? '—' }}<br>
      <strong>Term:</strong> {{ $report['class']['term'] ?? '—' }}
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Subject</th>
        <th>Code</th>
        <th>Average %</th>
        <th>Grade</th>
        <th>Points</th>
        <th>Remark</th>
      </tr>
    </thead>
    <tbody>
      @forelse(($report['subjects'] ?? []) as $i => $row)
        <tr>
          <td>{{ $i + 1 }}</td>
          <td>{{ $row['subject_name'] }}</td>
          <td>{{ $row['subject_code'] ?? '—' }}</td>
          <td>{{ number_format($row['average_percent'], 1) }}</td>
          <td>{{ $row['grade'] }}</td>
          <td>{{ $row['points'] }}</td>
          <td>{{ $row['remark'] }}</td>
        </tr>
      @empty
        <tr><td colspan="7">No published assessment results for this period.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="summary">
    <strong>Division:</strong> {{ $report['summary']['division'] ?? '—' }} &nbsp;|&nbsp;
    <strong>Avg. Points:</strong> {{ $report['summary']['average_points'] ?? '—' }} &nbsp;|&nbsp;
    <strong>Position:</strong>
    @if(!empty($report['summary']['class_position']))
      {{ $report['summary']['class_position'] }} / {{ $report['summary']['class_size'] }}
    @else
      —
    @endif
  </div>

  <p style="margin-top:16px;"><strong>Head Teacher’s Remark:</strong><br>
    {{ $report['head_teacher_remark'] ?? '' }}
  </p>

  <div class="footer">
    <div class="sign">Class Teacher</div>
    <div class="sign">Head Teacher</div>
  </div>
</body>
</html>
