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

// Собираем карту темпа
$tempoMap = [];
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
        
        if ($eventType == 0x90 || $eventType == 0x80) {
            $offset += 2;
        } elseif ($eventType == 0xC0 || $eventType == 0xD0) {
            $offset += 1;
        } elseif ($eventType == 0xE0 || $eventType == 0xA0 || $eventType == 0xB0) {
            $offset += 2;
        } elseif ($statusByte == 0xFF) {
            $metaType = ord($data[$offset++]);
            $len = readVlv($data, $offset);
            if ($metaType == 0x51 && $len == 3) {
                $b1 = ord($data[$offset]);
                $b2 = ord($data[$offset+1]);
                $b3 = ord($data[$offset+2]);
                $mpq = ($b1 << 16) + ($b2 << 8) + $b3;
                $tempoMap[] = ['tick' => $currentTick, 'mpq' => $mpq];
            }
            $offset += $len;
        }
    }
}

if (empty($tempoMap) || $tempoMap[0]['tick'] > 0) {
    array_unshift($tempoMap, ['tick' => 0, 'mpq' => 500000]);
}
usort($tempoMap, function($a, $b) { return $a['tick'] - $b['tick']; });

function ticksToMs($targetTick, $ticksPerQuarter, $tempoMap) {
    $ms = 0;
    $lastTick = 0;
    $currentMpq = 500000;
    
    foreach ($tempoMap as $point) {
        if ($targetTick <= $point['tick']) {
            $ticksDiff = $targetTick - $lastTick;
            $ms += ($ticksDiff * $currentMpq) / ($ticksPerQuarter * 1000);
            return round($ms);
        } else {
            $ticksDiff = $point['tick'] - $lastTick;
            $ms += ($ticksDiff * $currentMpq) / ($ticksPerQuarter * 1000);
            $lastTick = $point['tick'];
            $currentMpq = $point['mpq'];
        }
    }
    
    $ticksDiff = $targetTick - $lastTick;
    $ms += ($ticksDiff * $currentMpq) / ($ticksPerQuarter * 1000);
    return round($ms);
}

// Перечитываем треки для сбора событий
$offset = 0;
readBytes($data, $offset, 4);
$headerLength = readInt($data, $offset, 4);
$offset += $headerLength;

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
            $ms = ticksToMs($currentTick, $ticksPerQuarter, $tempoMap);
            // Если velocity == 0, это Note Off
            $realVel = ($velocity > 0) ? $velocity : 0;
            $rawEvents[] = ['tick' => $currentTick, 'ms' => $ms, 'note' => $note, 'vel' => $realVel, 'ch' => $channel];
            
        } elseif ($eventType == 0x80) {
            $note = ord($data[$offset++]);
            $velocity = ord($data[$offset++]);
            $ms = ticksToMs($currentTick, $ticksPerQuarter, $tempoMap);
            $rawEvents[] = ['tick' => $currentTick, 'ms' => $ms, 'note' => $note, 'vel' => 0, 'ch' => $channel];
            
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

// Сортируем события по времени (тикам)
usort($rawEvents, function($a, $b) {
    if ($a['tick'] == $b['tick']) {
        return $b['vel'] - $a['vel'];
    }
    return $a['tick'] - $b['tick'];
});

$output = [];
$activeNotes = []; // ключ: канал_нота => ['start_ms' => ..., 'vel' => ...]

foreach ($rawEvents as $ev) {
    $note = $ev['note'];
    $ms = $ev['ms'];
    $vel = $ev['vel'];
    $ch = $ev['ch'];
    
    $key = $ch . "_" . $note;

    if ($vel > 0) {
        // Если нота уже играла — принудительно закрываем её предыдущим моментом
        if (isset($activeNotes[$key])) {
            $startMs = $activeNotes[$key]['start_ms'];
            $duration = max(80, $ms - $startMs);
            $output[] = [$startMs, $note, $activeNotes[$key]['vel'], $duration, $ch];
            unset($activeNotes[$key]);
        }
        
        $activeNotes[$key] = ['start_ms' => $ms, 'vel' => $vel];
    } else {
        // Событие отпускания (Note Off)
        if (isset($activeNotes[$key])) {
            $startMs = $activeNotes[$key]['start_ms'];
            $duration = max(80, $ms - $startMs);
            $output[] = [$startMs, $note, $activeNotes[$key]['vel'], $duration, $ch];
            unset($activeNotes[$key]);
        }
    }
}

// Добиваем зависшие ноты дефолтной длительностью
foreach ($activeNotes as $key => $info) {
    list($ch, $note) = explode('_', $key);
    $output[] = [$info['start_ms'], intval($note), $info['vel'], 400, intval($ch)];
}

// Сорфируем финальный массив по времени старта ноты
usort($output, function($a, $b) {
    return $a[0] - $b[0];
});

$finalOutput = [];
foreach ($output as $item) {
    $finalOutput[] = implode(",", $item);
}

echo implode(";", $finalOutput);
?>
