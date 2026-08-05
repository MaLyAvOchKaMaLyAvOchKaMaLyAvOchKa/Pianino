<?php
header("Content-Type: text/plain; charset=utf-8");

$filename = isset($_GET['file']) ? $_GET['file'] : 'song.mid';

if (!file_exists($filename)) {
    echo "ERROR: File not found";
    exit;
}

$data = file_get_contents($filename);
$offset = 0;

function readBytes(&$data, &$offset, $length) {
    $bytes = substr($data, $offset, $length);
    $offset += $length;
    return $bytes;
}

function readInt(&$data, &$offset, $length) {
    $bytes = readBytes($data, $offset, $length);
    $val = 0;
    for ($i = 0; $i < $length; $i++) {
        $val = ($val << 8) + ord($bytes[$i]);
    }
    return $val;
}

function readVlv(&$data, &$offset) {
    $val = 0;
    while (true) {
        $byte = ord($data[$offset++]);
        $val = ($val << 7) + ($byte & 0x7F);
        if (($byte & 0x80) == 0) break;
    }
    return $val;
}

$chunkType = readBytes($data, $offset, 4);
if ($chunkType !== "MThd") {
    echo "ERROR: Invalid MIDI file";
    exit;
}

$headerLength = readInt($data, $offset, 4);
$format = readInt($data, $offset, 2);
$tracksCount = readInt($data, $offset, 2);
$division = readInt($data, $offset, 2);

$ticksPerQuarter = ($division & 0x8000) ? 120 : $division;
$rawEvents = [];

for ($t = 0; $t < $tracksCount; $t++) {
    $chunkType = readBytes($data, $offset, 4);
    $chunkLength = readInt($data, $offset, 4);
    $trackEnd = $offset + $chunkLength;
    
    $currentTick = 0;
    $runningStatus = 0;
    
    while ($offset < $trackEnd) {
        $deltaTime = readVlv($data, $offset);
        $currentTick += $deltaTime;
        
        $statusByte = ord($data[$offset]);
        if ($statusByte & 0x80) {
            $runningStatus = $statusByte;
            $offset++;
        } else {
            $statusByte = $runningStatus;
        }
        
        $eventType = $statusByte & 0xF0;
        $channel = $statusByte & 0x0F;
        
        if ($eventType == 0x90) {
            $note = ord($data[$offset++]);
            $velocity = ord($data[$offset++]);
            
            $ms = round(($currentTick * 500000) / ($ticksPerQuarter * 1000));
            // Если velocity == 0, это эквивалентно Note Off
            $realVel = ($velocity > 0) ? $velocity : 0;
            $rawEvents[] = ['ms' => $ms, 'note' => $note, 'vel' => $realVel, 'ch' => $channel];
            
        } elseif ($eventType == 0x80) {
            $note = ord($data[$offset++]);
            $velocity = ord($data[$offset++]);
            
            $ms = round(($currentTick * 500000) / ($ticksPerQuarter * 1000));
            $rawEvents[] = ['ms' => $ms, 'note' => $note, 'vel' => 0, 'ch' => $channel];
            
        } elseif ($eventType == 0xC0 || $eventType == 0xD0) {
            $offset += 1;
        } elseif ($eventType == 0xE0 || $eventType == 0xA0 || $eventType == 0xB0) {
            $offset += 2;
        } elseif ($statusByte == 0xFF) {
            $metaType = ord($data[$offset++]);
            $len = readVlv($data, $offset);
            $offset += $len;
        }
    }
}

// Сортируем события по времени
usort($rawEvents, function($a, $b) {
    if ($a['ms'] == $b['ms']) {
        return $b['vel'] - $a['vel']; // Сначала Note On, потом Note Off при равенстве времени
    }
    return $a['ms'] - $b['ms'];
});

$output = [];
$activeNotes = []; // [канал_нота] => индекс в $output

foreach ($rawEvents as $ev) {
    $note = $ev['note'];
    $ms = $ev['ms'];
    $vel = $ev['vel'];
    $ch = $ev['ch'];
    
    $key = $ch . "_" . $note;

    if ($vel > 0) {
        // Если нота уже была зажата — закрываем старую перед открытием новой
        if (isset($activeNotes[$key])) {
            $idx = $activeNotes[$key];
            $startMs = $output[$idx][0];
            $output[$idx][3] = max(80, $ms - $startMs);
            unset($activeNotes[$key]);
        }

        $activeNotes[$key] = count($output);
        // Формат: [время_старта, нота, громкость, длительность_пока_заглушка, канал]
        $output[] = [$ms, $note, $vel, 300, $ch];
    } else {
        // Событие отпускания
        if (isset($activeNotes[$key])) {
            $idx = $activeNotes[$key];
            $startMs = $output[$idx][0];
            $duration = $ms - $startMs;
            
            // Защита от нулевых или отрицательных длительностей
            $output[$idx][3] = max(80, $duration);
            unset($activeNotes[$key]);
        }
    }
}

// Защита для нот, у которых вообще не было события Note Off в файле
foreach ($activeNotes as $key => $idx) {
    $output[$idx][3] = 400; // Стандартная мягкая длина для зависших нот
}

// Собираем обратно в строку для E2
$finalOutput = [];
foreach ($output as $item) {
    $finalOutput[] = implode(",", $item);
}

echo implode(";", $finalOutput);
?>
