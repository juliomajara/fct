<?php
// index.php — Tabla de tramos por día (AM/PM) con Total por fila + copia L→V + calendario coloreado
declare(strict_types=1);
date_default_timezone_set('Europe/Madrid');

function parse_float($v): float {
    $v = str_replace(',', '.', trim((string)$v));
    return is_numeric($v) ? (float)$v : 0.0;
}
function fmt_dmy(string $ymd): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    return $dt ? $dt->format('d-m-Y') : $ymd;
}
function fmt_hours(float $h): string {
    $s = number_format($h, 2, ',', '');
    return rtrim(rtrim($s, '0'), ',');
}
function parse_minutes(?string $hhmm): ?int {
    $hhmm = trim((string)$hhmm);
    if ($hhmm === '') return null;
    $dt = DateTimeImmutable::createFromFormat('H:i', $hhmm);
    if (!$dt) return null;
    return (int)$dt->format('H')*60 + (int)$dt->format('i');
}
function dur_minutes(?string $in, ?string $out): int {
    $a = parse_minutes($in); $b = parse_minutes($out);
    if ($a === null || $b === null) return 0;
    return max(0, $b - $a);
}

/** No lectivos fijos y rangos */
function build_holidays(): array {
    $single = ['2025-10-13', '2025-11-03', '2025-12-08'];
    $ranges = [
        ['2025-12-22', '2026-01-07'],
        ['2026-03-27', '2026-04-06'],
    ];
    $set = [];
    foreach ($single as $d) $set[$d] = true;
    foreach ($ranges as [$start, $end]) {
        $d = new DateTimeImmutable($start);
        $endD = new DateTimeImmutable($end);
        while ($d <= $endD) {
            $set[$d->format('Y-m-d')] = true;
            $d = $d->modify('+1 day');
        }
    }
    return $set;
}
function is_holiday(DateTimeInterface $d, array $holidays): bool { return isset($holidays[$d->format('Y-m-d')]); }
function weekday_index_iso(DateTimeInterface $d): int { return (int)$d->format('N'); } // 1=L ... 7=D
function month_name_es(int $m): string {
    $n=[1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']; return $n[$m]??(string)$m;
}

$isExportRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf']));

if ($isExportRequest) {
    $payload = $_POST['payload'] ?? '';
    $decoded = json_decode(base64_decode((string)$payload, true) ?: '', true);

    if (!is_array($decoded) || !isset($decoded['result']) || !is_array($decoded['result'])) {
        header('Content-Type: text/plain; charset=utf-8', true, 400);
        echo 'Datos inválidos para exportar.';
        exit;
    }

    $result = $decoded['result'];
    $worked_days = isset($decoded['worked_days']) && is_array($decoded['worked_days']) ? $decoded['worked_days'] : [];
    $warn_msg = isset($decoded['warn']) && is_string($decoded['warn']) ? $decoded['warn'] : '';

    require_once __DIR__.'/fpdf.php';

    $pdf = new FPDF();
    $pdf->SetTitle('Resumen de prácticas');
    $pdf->AddPage();

    $toPdf = static function (string $text): string {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        return $converted !== false ? $converted : $text;
    };

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $toPdf('Resumen del cálculo de prácticas'), 0, 1, 'C');
    $pdf->Ln(2);

    $summary = [
        'Inicio' => isset($result['start']) ? fmt_dmy((string)$result['start']) : '',
        'Fin' => isset($result['end']) ? fmt_dmy((string)$result['end']) : '',
        'Horas totales' => isset($result['total']) ? fmt_hours((float)$result['total']).' h' : '',
        'Días computados' => isset($result['used_days']) ? (string)$result['used_days'] : '',
        'Horas último día' => isset($result['last_day_hours']) ? fmt_hours((float)$result['last_day_hours']).' h' : '',
    ];

    $pdf->SetFont('Arial', '', 12);
    foreach ($summary as $label => $value) {
        if ($value === '') continue;
        $pdf->Cell(60, 8, $toPdf($label.':'), 0, 0);
        $pdf->Cell(0, 8, $toPdf($value), 0, 1);
    }

    if ($warn_msg !== '') {
        $pdf->Ln(4);
        $pdf->SetTextColor(146, 64, 14);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 6, $toPdf($warn_msg));
        $pdf->SetTextColor(0, 0, 0);
    }

    if ($worked_days) {
        ksort($worked_days);
        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $toPdf('Detalle de horas por día'), 0, 1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(120, 7, $toPdf('Fecha'), 1, 0, 'C');
        $pdf->Cell(70, 7, $toPdf('Horas'), 1, 1, 'C');

        $pdf->SetFont('Arial', '', 10);
        foreach ($worked_days as $date => $hours) {
            $pdf->Cell(120, 7, $toPdf(fmt_dmy((string)$date)), 1, 0);
            $pdf->Cell(70, 7, $toPdf(fmt_hours((float)$hours).' h'), 1, 1, 'R');
        }
    }

    $fileName = 'calculo-practicas-'.(new DateTimeImmutable())->format('Ymd_His').'.pdf';
    $pdf->Output('D', $fileName);
    exit;
}

$holidays = build_holidays();

$result = null;
$error  = null;
$warn   = null;
$worked_days = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $total_hours = parse_float($_POST['total_hours'] ?? '0');
    $start_date_str = trim($_POST['start_date'] ?? '');

    // Tramos por día -> horas/día
    $keys = ['mon','tue','wed','thu','fri','sat','sun'];
    $daily = [];
    $dayIndex = [ 'mon'=>1, 'tue'=>2, 'wed'=>3, 'thu'=>4, 'fri'=>5, 'sat'=>6, 'sun'=>7 ];

    foreach ($keys as $k) {
        $am_in  = $_POST[$k.'_am_in']  ?? '';
        $am_out = $_POST[$k.'_am_out'] ?? '';
        $pm_in  = $_POST[$k.'_pm_in']  ?? '';
        $pm_out = $_POST[$k.'_pm_out'] ?? '';
        $mins = dur_minutes($am_in, $am_out) + dur_minutes($pm_in, $pm_out);
        $daily[ $dayIndex[$k] ] = $mins / 60.0;
    }

    if ($total_hours <= 0) {
        $error = "Introduce un número de horas total mayor que 0.";
    } elseif ($start_date_str === '') {
        $error = "Selecciona una fecha de inicio.";
    } else {
        try { $start_date = new DateTimeImmutable($start_date_str); }
        catch (Exception $e) { $error = "Fecha de inicio no válida."; }
    }
    if (!$error && array_sum($daily) <= 0) $error = "Debes configurar algún tramo horario en al menos un día.";

    if (!$error) {
        $remaining = $total_hours; $d = $start_date; $used_days = 0; $max_loop_days = 3650;
        while ($remaining > 0 && $max_loop_days-- > 0) {
            $w = weekday_index_iso($d);
            if (!is_holiday($d, $holidays) && $daily[$w] > 0) {
                $consume = min($remaining, $daily[$w]);
                $remaining -= $consume; $used_days++;
                $worked_days[$d->format('Y-m-d')] = $consume;
                if ($remaining <= 0) { $end_date = $d; $last_day_hours = $consume; break; }
            }
            $d = $d->modify('+1 day');
        }
        if (!isset($end_date)) {
            $error = "No ha sido posible calcular la fecha de fin (¿demasiados no lectivos o ningún día con tramos válidos?).";
        } else {
            $deadline = new DateTimeImmutable('2026-06-10');
            if ($end_date > $deadline) {
                $warn = "⚠️ Las prácticas no se pueden completar a tiempo: la fecha de fin (".fmt_dmy($end_date->format('Y-m-d')).") es posterior al 10-06-2026.";
            }
            $result = [
                'start'=>$start_date->format('Y-m-d'),
                'end'=>$end_date->format('Y-m-d'),
                'total'=>$total_hours,
                'remaining'=>max(0.0,$remaining),
                'last_day_hours'=>$last_day_hours,
                'used_days'=>$used_days,
            ];
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cálculo de fecha de fin por horas + Calendario</title>
<style>
    :root{
        --bg:#f7fafc; --card:#ffffff; --ink:#0b1220; --muted:#6b7280; --line:#e5e7eb;
        --accent:#16a34a; --accent-ink:#05240f;
        --work-bg:#d1fae5; --hol-bg:#ffe4e6; --start-ol:#3b82f6; --end-ol:#f59e0b;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,sans-serif}
    .wrap{max-width:900px;margin:32px auto;padding:0 12px}
    h1{margin:0 0 12px 0;font-size:clamp(22px,2.2vw,28px)}
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px;box-shadow:0 6px 18px rgba(0,0,0,.05)}

    /* Fila superior (Total + Inicio) */
    .row-top{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:10px}
    label{display:block;font-size:12px;color:var(--muted);margin-bottom:3px}
    input[type="number"],input[type="date"],input[type="time"]{
        width:100%; padding:6px 10px; border-radius:8px; border:1px solid var(--line); background:#fff; color:#000;
        outline:none; text-align:center;
    }
    #total_hours{
        width:8ch; max-width:100%; margin-left:auto; margin-right:auto;
    }
    .schedule input[type="time"]{
        width:7ch; min-width:0;
    }
    input:focus{border-color:#cbd5e1; box-shadow:0 0 0 3px rgba(148,163,184,.25)}
    .actions{display:flex;gap:6px;justify-content:flex-end;margin-top:10px}
    button.primary{padding:8px 12px;border:none;border-radius:8px;background:var(--accent);color:var(--accent-ink);font-weight:700;cursor:pointer}
    button.secondary{padding:8px 12px;border:none;border-radius:8px;background:#1e293b;color:#f8fafc;font-weight:600;cursor:pointer}
    .export-form{margin-top:12px;display:flex;justify-content:flex-end}

    /* Tabla de horarios */
    .schedule{width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:12px; overflow:hidden}
    .schedule th, .schedule td{border-bottom:1px solid var(--line); padding:6px}
    .schedule th{background:#f8fafc; font-size:11px; color:#334155; text-align:center}
    .schedule td{vertical-align:middle; text-align:center; font-size:12px}
    .schedule td.label{font-weight:600; text-align:left}
    .schedule tr:last-child td{border-bottom:none}
    .total-badge{display:inline-block; min-width:3ch; padding:2px 6px; border:1px solid var(--line); border-radius:8px; font-weight:700; font-size:12px}

    .error{border:1px solid #fecaca;background:#fff5f5;color:#7f1d1d;padding:8px;border-radius:10px;margin-top:10px}
    .warn{border:1px solid #fde68a;background:#fffbeb;color:#92400e;padding:8px;border-radius:10px;margin-top:10px}
    .result{border:1px solid var(--line);background:#fff;padding:10px;border-radius:12px;margin-top:12px}
    .kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px;margin-top:6px}
    .kpi .box{border:1px solid var(--line);border-radius:10px;padding:8px;text-align:center}
    .box h3{margin:4px 0 2px 0;font-size:12px;color:var(--muted)} .box p{margin:0;font-size:16px;font-weight:700}

    /* Calendario + leyenda colores (2 meses por fila) */
    .cal-wrap{margin-top:12px}
    .cal-legend{display:flex;gap:10px;align-items:center;margin:4px 0 10px 0;font-size:12px;color:var(--muted);flex-wrap:wrap}
    .tag{display:inline-flex;align-items:center;gap:4px}
    .dot{width:12px;height:12px;border-radius:3px;border:1px solid var(--line)}
    .dot.work{background:var(--work-bg)} .dot.hol{background:var(--hol-bg)}
    .dot.start{background:#eef2ff} .dot.end{background:#fff7ed}

    .months{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(220px,1fr))}
    @media (max-width:780px){ .months{grid-template-columns:repeat(1,minmax(220px,1fr))} }
    .month{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff}
    .month h4{margin:0;padding:6px 8px;border-bottom:1px solid var(--line);font-weight:700;font-size:14px}
    .table{display:grid;grid-template-columns:repeat(7,1fr)}
    .dow{font-size:11px;color:#64748b;padding:4px 6px;border-bottom:1px solid var(--line);text-align:center;background:#f8fafc}
    .day{min-height:36px;border-bottom:1px solid #f1f5f9;border-right:1px solid #f1f5f9;padding:4px 6px}
    .day:nth-child(7n){border-right:none}
    .day.empty{background:#f8fafc}
    .day .num{font-size:11px;color:#111827}
    .is-start{outline:2px solid var(--start-ol);outline-offset:-2px;border-radius:8px}
    .is-end{outline:2px solid var(--end-ol);outline-offset:-2px;border-radius:8px} /* línea continua */
    .empresa{background:#d1fae5}   /* verde */
    .nolectivo{background:#ffe4e6} /* rojo */
    .num small{font-size:11px;color:#059669}
</style>
</head>
<body>
<div class="wrap">
    <h1>Calcular fecha de fin según horas por día</h1>
    <div class="card">
        <form method="post" action="" id="calcForm">
            <!-- Fila superior -->
            <div class="row-top">
                <div class="field">
                    <label for="total_hours">Número total de horas</label>
                    <input type="number" inputmode="decimal" step="0.25" min="0" id="total_hours" name="total_hours"
                           placeholder="120" value="<?= htmlspecialchars($_POST['total_hours'] ?? '') ?>">
                </div>
                <div class="field" style="max-width:240px">
                    <label for="start_date">Fecha de inicio</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>">
                </div>
            </div>

            <!-- Tabla de horarios Día | AM in/out | PM in/out | Total -->
            <table class="schedule" aria-label="Horario semanal">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th>Inicio</th>
                        <th>Fin</th>
                        <th>Inicio</th>
                        <th>Fin</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $days = [
                        'mon'=>'Lunes','tue'=>'Martes','wed'=>'Miércoles','thu'=>'Jueves',
                        'fri'=>'Viernes','sat'=>'Sábado','sun'=>'Domingo'
                    ];
                    foreach ($days as $k=>$label): ?>
                        <tr data-day="<?= $k ?>">
                            <td class="label"><?= $label ?></td>
                            <td><input type="time" id="<?= $k ?>_am_in"  name="<?= $k ?>_am_in"  value="<?= htmlspecialchars($_POST[$k.'_am_in'] ?? '') ?>"></td>
                            <td><input type="time" id="<?= $k ?>_am_out" name="<?= $k ?>_am_out" value="<?= htmlspecialchars($_POST[$k.'_am_out'] ?? '') ?>"></td>
                            <td><input type="time" id="<?= $k ?>_pm_in"  name="<?= $k ?>_pm_in"  value="<?= htmlspecialchars($_POST[$k.'_pm_in'] ?? '') ?>"></td>
                            <td><input type="time" id="<?= $k ?>_pm_out" name="<?= $k ?>_pm_out" value="<?= htmlspecialchars($_POST[$k.'_pm_out'] ?? '') ?>"></td>
                            <td><span id="<?= $k ?>_total" class="total-badge">0</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="actions">
                <button type="submit" class="primary">Calcular</button>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($result): ?>
                <?php if ($warn): ?><div class="warn"><?= htmlspecialchars($warn) ?></div><?php endif; ?>
                <div class="result">
                    <strong>Resultado</strong>
                    <div class="kpi">
                        <div class="box"><h3>Inicio</h3><p><?= fmt_dmy($result['start']) ?></p></div>
                        <div class="box"><h3>Fin</h3><p><?= fmt_dmy($result['end']) ?></p></div>
                        <div class="box"><h3>Horas totales</h3><p><?= htmlspecialchars((string)$result['total']) ?></p></div>
                        <div class="box"><h3>Días computados</h3><p><?= htmlspecialchars((string)$result['used_days']) ?></p></div>
                        <div class="box"><h3>Horas último día</h3><p><?= fmt_hours((float)$result['last_day_hours']) ?> h</p></div>
                    </div>

                    <?php
                    $exportData = [
                        'result' => $result,
                        'worked_days' => $worked_days,
                        'warn' => $warn,
                    ];
                    $exportJson = json_encode($exportData, JSON_UNESCAPED_UNICODE);
                    $exportPayload = base64_encode($exportJson !== false ? $exportJson : '{}');
                    ?>
                    <form method="post" class="export-form">
                        <input type="hidden" name="export_pdf" value="1">
                        <input type="hidden" name="payload" value="<?= htmlspecialchars($exportPayload, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="secondary">Exportar a PDF</button>
                    </form>

                    <!-- Calendario + leyenda colores -->
                    <div class="cal-wrap">
                        <div class="cal-legend">
                            <span class="tag"><span class="dot work"></span> Empresa</span>
                            <span class="tag"><span class="dot hol"></span> No lectivo</span>
                            <span class="tag"><span class="dot start"></span> Inicio</span>
                            <span class="tag"><span class="dot end"></span> Fin</span>
                        </div>
                        <div class="months">
                            <?php
                            $calStart = new DateTimeImmutable($result['start']);
                            $calEnd   = new DateTimeImmutable($result['end']);
                            $cur = new DateTimeImmutable($calStart->format('Y-m-01'));
                            $limit = 0;
                            while ($cur <= new DateTimeImmutable($calEnd->format('Y-m-01')) && $limit++ < 60) {
                                $y = (int)$cur->format('Y'); $m = (int)$cur->format('n'); $daysInMonth = (int)$cur->format('t');
                                $firstDow = (int)$cur->format('N');
                                echo '<div class="month"><h4>'.month_name_es($m).' '.$y.'</h4><div class="table">';
                                foreach (['L','M','X','J','V','S','D'] as $dw) echo '<div class="dow">'.$dw.'</div>';
                                for ($i=1; $i<$firstDow; $i++) echo '<div class="day empty"></div>';
                                for ($day=1; $day <= $daysInMonth; $day++) {
                                    $dateStr = sprintf('%04d-%02d-%02d', $y, $m, $day);
                                    $dateObj = new DateTimeImmutable($dateStr);
                                    $classes = ['day'];

                                    $inSpan = ($dateObj >= $calStart && $dateObj <= $calEnd);
                                    $isWeekend = in_array((int)$dateObj->format('N'), [6,7], true);
                                    $isWork  = isset($worked_days[$dateStr]);
                                    $isHoliday = isset($holidays[$dateStr]);
                                    $isNoLectivo = $inSpan && ($isHoliday || ($isWeekend && !$isWork));

                                    if ($dateStr === $result['start']) $classes[] = 'is-start';
                                    if ($dateStr === $result['end'])   $classes[] = 'is-end';
                                    if ($isNoLectivo) $classes[] = 'nolectivo';
                                    if ($isWork)      $classes[] = 'empresa';

                                    echo '<div class="'.implode(' ',$classes).'">';
                                    echo '<div class="num">'.$day;
                                    if ($isWork) {
                                        $hrs = fmt_hours((float)$worked_days[$dateStr]);
                                        echo ' <small>('.$hrs.' h)</small>';
                                    }
                                    echo '</div></div>';
                                }
                                $cellsFilled = ($firstDow-1) + $daysInMonth;
                                $remaining = (7 - ($cellsFilled % 7)) % 7;
                                for ($r=0; $r<$remaining; $r++) echo '<div class="day empty"></div>';
                                echo '</div></div>';
                                $cur = $cur->modify('first day of next month');
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
/* ===== Utilidades de tiempo ===== */
function parseMinutes(hhmm){
  const v = (hhmm || '').trim();
  if(!v) return null;
  const m = v.match(/^(\d{2}):(\d{2})$/);
  if(!m) return null;
  const h = parseInt(m[1],10), min = parseInt(m[2],10);
  return h*60 + min;
}
function durMinutes(a,b){
  const A = parseMinutes(a), B = parseMinutes(b);
  if(A===null || B===null) return 0;
  return Math.max(0, B - A);
}
function fmtHours(num){
  const s = (Math.round(num*100)/100).toFixed(2).replace('.',',');
  return s.replace(/,00$/,'').replace(/,(\d)0$/,',$1');
}

/* ===== Cálculo de total por día (SIN disparar eventos) ===== */
function calcDayTotal(day){
  const get = id => document.getElementById(id);
  const amIn  = get(day+'_am_in')?.value || '';
  const amOut = get(day+'_am_out')?.value || '';
  const pmIn  = get(day+'_pm_in')?.value || '';
  const pmOut = get(day+'_pm_out')?.value || '';
  const mins = durMinutes(amIn, amOut) + durMinutes(pmIn, pmOut);
  const hours = mins/60;
  const badge = get(day+'_total');
  if(badge) badge.textContent = fmtHours(hours) || '0';
}

/* Inicializa escuchas para recalcular el total del día al editar */
(function(){
  const days = ['mon','tue','wed','thu','fri','sat','sun'];
  const fields = ['am_in','am_out','pm_in','pm_out'];

  days.forEach(day=>{
    fields.forEach(f=>{
      const el = document.getElementById(day+'_'+f);
      if(!el) return;
      el.addEventListener('input', ()=>calcDayTotal(day));
      el.addEventListener('change', ()=>calcDayTotal(day));
    });
    // cálculo inicial
    calcDayTotal(day);
  });
})();

/* ===== Copia Lunes -> Mar–Vie (HH:MM completas) sin bucles =====
   - Copia si el destino está vacío o si todavía tiene el valor anterior copiado.
   - No sobrescribe si ya lo cambiaste a mano.
*/
(function(){
  const fields = ['am_in','am_out','pm_in','pm_out'];
  const targets = ['tue','wed','thu','fri'];
  const lastMonVal = {}; // recuerda el último valor de lunes por campo

  function get(id){ return document.getElementById(id); }

  function setupField(field){
    const monId = 'mon_' + field;
    const monEl = get(monId);
    if(!monEl) return;

    // valor inicial (por si viene precargado)
    lastMonVal[field] = (monEl.value || '').trim();

    function propagate(){
      const newVal = (monEl.value || '').trim();
      const prevVal = lastMonVal[field];

      targets.forEach(day=>{
        const dst = get(day+'_'+field);
        if(!dst) return;
        const cur = (dst.value || '').trim();

        if(cur === '' || cur === prevVal){
          dst.value = newVal;       // copia HH:MM
          calcDayTotal(day);        // recalcula total del día destino
        }
      });

      lastMonVal[field] = newVal;   // actualiza referencia
      calcDayTotal('mon');          // recalcula lunes sin disparar eventos
    }

    monEl.addEventListener('input', propagate);
    monEl.addEventListener('change', propagate);
  }

  fields.forEach(setupField);
})();
</script>

</body>
</html>
